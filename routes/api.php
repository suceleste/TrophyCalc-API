<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Http; // On utilise le client HTTP intégré à Laravel, c'est plus propre.
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use GuzzleHttp\Client;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Toutes nos routes sont définies ici. Elles sont groupées sous le middleware 'api'
| pour garantir un comportement cohérent et sécurisé.
|
*/

Route::middleware('api')->group(function () {

    // --- ROUTE PUBLIQUE ---
    // Pour vérifier que l'API est en ligne.
    Route::get('/status', fn() => response()->json(['status' => 'API is running']));


    // --- GROUPE D'AUTHENTIFICATION STEAM ---
    Route::prefix('auth/steam')->group(function () {
        
        // 1.1 Redirige l'utilisateur vers Steam pour se connecter
        Route::get('/redirect', function () {
            $params = [
                'openid.ns'         => 'http://specs.openid.net/auth/2.0',
                'openid.mode'       => 'checkid_setup',
                'openid.return_to'  => route('auth.steam.callback'), // On utilise le nom de la route, c'est plus robuste
                'openid.realm'      => config('app.url'),
                'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
            ];
            $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);
            return Redirect::to($steam_login_url);
        })->name('auth.steam.redirect'); // On nomme la route pour pouvoir y faire référence

        Route::get('/callback', function (Request $request) {
            try {
                // On prépare les paramètres pour la validation
                $raw_params = $request->all();
                $params_for_steam = [];
                foreach ($raw_params as $key => $value) {
                    $params_for_steam[str_replace('_', '.', $key)] = $value;
                }
                $params_for_steam['openid.mode'] = 'check_authentication';

                // ==========================================================
                // CORRECTION : ON REVIENT À GUZZLE, QUI EST 100% FIABLE AVEC STEAM
                // ==========================================================
                $client = new Client();
                $response = $client->post('https://steamcommunity.com/openid/login', [
                    'form_params' => $params_for_steam
                ]);
                $response_body = (string)$response->getBody();
                // ==========================================================

                if (str_contains($response_body, 'is_valid:true')) {
                    $claimed_id = $request->input('openid_claimed_id');
                    $steam_id_64 = basename($claimed_id);
                    $api_key = env('STEAM_SECRET');

                    // On récupère les infos du profil
                    $profile_response = Http::get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
                        'key' => $api_key, 'steamids' => $steam_id_64
                    ]);
                    
                    if ($profile_response->failed() || empty($profile_response->json('response.players'))) {
                        return Redirect::to('http://localhost:5173/login-failed?error=profile_not_found');
                    }
                    $player = $profile_response->json('response.players')[0];

                    // On crée ou met à jour l'utilisateur
                    $user = User::updateOrCreate(
                        ['steam_id_64' => $steam_id_64],
                        [
                            'name' => $player['personaname'],
                            'email' => "{$steam_id_64}@steam.trophycalc",
                            'password' => Hash::make(Str::random(20)),
                            'avatar' => $player['avatarfull'],
                            'profile_url' => $player['profileurl'],
                            'profile_updated_at' => now(),
                        ]
                    );

                    // On génère un token et on redirige vers le frontend
                    $token = $user->createToken('auth_token')->plainTextToken;
                    $frontend_url = 'http://localhost:5173/auth/callback?token=' . $token;
                    return Redirect::to($frontend_url);

                } else {
                    return Redirect::to('http://localhost:5173/login-failed?error=steam_validation_failed');
                }
            } catch (\Exception $e) {
                // En cas d'erreur critique, on redirige avec un message d'erreur
                return Redirect::to('http://localhost:5173/login-failed?error=critical_error');
            }
        })->name('auth.steam.callback');
    });


    // --- ROUTES PROTÉGÉES (NÉCESSITENT UN TOKEN) ---
    // Ce groupe est sécurisé. Seul un utilisateur connecté peut accéder à ces routes.
    Route::middleware('auth:sanctum')->prefix('user')->group(function () {
        
        // Renvoie les infos de l'utilisateur actuellement connecté
        Route::get('/', function (Request $request) {
            return $request->user();
        });

        // Renvoie la liste des jeux de l'utilisateur connecté
        Route::get('/games', function (Request $request) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');

            if (!$user->steam_id_64) {
                return response()->json(['message' => 'Aucun Steam ID associé à cet utilisateur.'], 404);
            }

            $response = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                'key' => $apiKey,
                'steamid' => $user->steam_id_64,
                'format' => 'json',
                'include_appinfo' => true,
                'include_played_free_games' => true,
            ]);

            if ($response->failed()) {
                return response()->json(['message' => 'Impossible de contacter l\'API de Steam.'], 502);
            }

            $games = $response->json('response.games', []);
            
            $formattedGames = collect($games)->map(function ($game) {
                return [
                    'app_id' => $game['appid'],
                    'name' => $game['name'],
                    'playtime_hours' => round($game['playtime_forever'] / 60, 1),
                    'icon_url' => $game['img_icon_url'] ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$game['img_icon_url']}.jpg" : null,
                ];
            })->sortByDesc('playtime_hours')->values();

            return response()->json([
                'game_count' => $response->json('response.game_count', 0),
                'games' => $formattedGames
            ]);
        });

        // Renvoie les succès pour un jeu spécifique de l'utilisateur connecté
        Route::get('/games/{app_id}/achievements', function (Request $request, $app_id) {
            $user = $request->user();
            // ... (Ici viendra la logique pour récupérer les succès)
        });
        
    });

});