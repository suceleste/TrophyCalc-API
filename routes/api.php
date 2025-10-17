<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str; // Assurez-vous que Str est importé


Route::middleware('api')->group(function () {

    // Notre route de statut (publique)
    Route::get('/status', function () {
        return response()->json(['status' => 'API is running']);
    });

    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        // Si on arrive ici, c'est que le token est valide.
        // Laravel a automatiquement retrouvé l'utilisateur correspondant.
        return $request->user();
    });

    // 1.1 Route de Redirection: Envoie l'utilisateur vers la page de connexion Steam
    Route::get('/auth/steam/redirect', function () {
        $params = [
            'openid.ns'          => 'http://specs.openid.net/auth/2.0',
            'openid.mode'        => 'checkid_setup',
            'openid.return_to'   => env('STEAM_REDIRECT'),
            'openid.realm'       => 'http://127.0.0.1:8000',
            'openid.identity'    => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id'  => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);

        return Redirect::to($steam_login_url);
    });

    // 1.2 Route de Callback: Reçoit les données de Steam et vérifie la signature

    Route::get('/auth/steam/callback', function (Illuminate\Http\Request $request) {
        try {
            $raw_params = $request->all();
            $params_for_steam = [];
            foreach ($raw_params as $key => $value) {
                $params_for_steam[str_replace('_', '.', $key)] = $value;
            }
            $params_for_steam['openid.mode'] = 'check_authentication';

            $client = new Client();
            $response = $client->post('https://steamcommunity.com/openid/login', [
                'form_params' => $params_for_steam
            ]);
            $response_body = (string)$response->getBody();

            if (str_contains($response_body, 'is_valid:true')) {
                // ================== LA NOUVELLE LOGIQUE COMMENCE ICI ==================
                
                // 1. On a le SteamID
                $claimed_id = $request->input('openid_claimed_id');
                $steam_id_64 = basename($claimed_id);
                $api_key = env('STEAM_SECRET');

                // 2. On récupère les informations du profil
                $profile_response = $client->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
                    'query' => ['key' => $api_key, 'steamids' => $steam_id_64]
                ]);
                $player_data = json_decode((string)$profile_response->getBody(), true)['response']['players'][0] ?? null;

                if (!$player_data) {
                    return response()->json(['message' => 'Profil Steam non trouvé ou privé.'], 404);
                }

                // 3. On sauvegarde ou met à jour l'utilisateur dans notre base de données
                $user = User::updateOrCreate(
                    ['steam_id_64' => $steam_id_64], // Condition de recherche
                    [
                        'name' => $player_data['personaname'],
                        'email' => "{$steam_id_64}@steam.trophycalc",
                        'password' => Hash::make(Str::random(20)),
                        'avatar' => $player_data['avatarfull'],
                        'profile_url' => $player_data['profileurl'],
                        'profile_updated_at' => now(),
                    ]
                );

                $token = $user->createToken('auth_token')->plainTextToken;

                // 5. On construit l'URL de redirection vers notre frontend
                // On ajoute le token comme paramètre pour que le frontend puisse le récupérer
                $frontend_url = 'http://localhost:5173/auth/callback?token=' . $token;

                // 6. On redirige l'utilisateur vers le frontend avec son token
                return Redirect::to($frontend_url);

            } else {
                return Redirect::to('http://localhost:5173/login-failed');
            }

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur critique lors de la connexion.',
                'exception_message' => $e->getMessage()
            ], 500);
        }
    });
    // ==========================================================
    // 2. ENDPOINTS D'ACCÈS AUX DONNÉES STEAM (Basé sur le SteamID)
    // ==========================================================

    Route::prefix('user/{steam_id_64}')->group(function () {

        // 2.1 Récupérer les informations de profil (Pseudo, Avatar)
        Route::get('/profile', function ($steam_id_64) {
            $api_key = env('STEAM_SECRET');
            $client = new Client();
            $url = 'http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/';

            try {
                $response = $client->get($url, [
                    'query' => ['key' => $api_key, 'steamids' => $steam_id_64]
                ]);

                $data = json_decode((string)$response->getBody(), true);
                $player_summary = $data['response']['players'][0] ?? null;

                if (!$player_summary) {
                    return response()->json(['status' => 'error', 'message' => 'Profil introuvable ou privé.'], 404);
                }

                return response()->json([
                    'status' => 'success',
                    'pseudo' => $player_summary['personaname'],
                    'avatar' => $player_summary['avatarfull'],
                    'url' => $player_summary['profileurl'],
                    'steam_id' => $player_summary['steamid'],
                ]);

            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Erreur API lors de la récupération du profil.'], 500);
            }
        });

        // 2.2 Récupérer le temps de jeu pour un jeu spécifique
        Route::get('/playtime/{app_id}', function ($steam_id_64, $app_id) {
            $api_key = env('STEAM_SECRET');
            $client = new Client();
            $url = 'http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/';

            try {
                $response = $client->get($url, [
                    'query' => [
                        'key' => $api_key,
                        'steamid' => $steam_id_64,
                        'format' => 'json',
                        'include_appinfo' => true,
                    ]
                ]);

                $data = json_decode((string)$response->getBody(), true);
                $games = $data['response']['games'] ?? [];

                // Filtrer le jeu spécifique côté PHP (ID du jeu doit être un entier)
                $target_game = collect($games)->firstWhere('appid', (int)$app_id);

                if (!$target_game) {
                    return response()->json(['status' => 'not_found', 'message' => 'Jeu non trouvé dans la bibliothèque.'], 404);
                }

                $playtime_minutes = $target_game['playtime_forever'] ?? 0;
                $playtime_hours = round($playtime_minutes / 60, 1);

                return response()->json([
                    'status' => 'success',
                    'game_name' => $target_game['name'],
                    'app_id' => $app_id,
                    'playtime_hours' => $playtime_hours,
                    'avertissement' => 'Peut être inexact pour certains jeux (limite API).'
                ]);

            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Erreur API lors de la récupération du temps de jeu.'], 500);
            }
        });

        // 2.3 Récupérer les succès pour un jeu spécifique
        Route::get('/achievements/{app_id}', function ($steam_id_64, $app_id) {
            $api_key = env('STEAM_SECRET');
            $client = new Client();
            $url = 'http://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/';

            try {
                $response = $client->get($url, [
                    'query' => [
                        'appid' => $app_id,
                        'key' => $api_key,
                        'steamid' => $steam_id_64,
                        'l' => 'fr',
                    ]
                ]);

                $data = json_decode((string)$response->getBody(), true);

                // Gérer le cas où l'API renvoie une erreur interne (jeu non supporté)
                if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === false) {
                    return response()->json([
                        'status' => 'info',
                        'message' => 'Ce jeu ne supporte pas la récupération des succès Steam via l\'API.'
                    ]);
                }

                $achievements = $data['playerstats']['achievements'] ?? [];

                $unlocked_count = collect($achievements)->where('achieved', 1)->count();
                $total_count = count($achievements);
                $game_name = $data['playerstats']['gameName'] ?? "Jeu ID: $app_id";

                return response()->json([
                    'status' => 'success',
                    'game_name' => $game_name,
                    'unlocked_count' => $unlocked_count,
                    'total_count' => $total_count,
                    'pourcentage' => round(($unlocked_count / max(1, $total_count)) * 100, 2) . '%',
                    'achievements' => collect($achievements)->map(function($ach) {
                        return [
                            // Rendu robuste pour éviter l'erreur "Undefined array key"
                            'name' => $ach['displayName'] ?? $ach['apiname'] ?? 'Nom inconnu',
                            'description' => $ach['description'] ?? 'Pas de description.',
                            'achieved' => $ach['achieved'] ? true : false,
                        ];
                    })
                ]);

            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Erreur API lors de la récupération des succès.'], 500);
            }
        });


        // routes/api.php (à l'intérieur du Route::prefix('user/{steam_id_64}')->group(function () { ... }))

        Route::get('/games', function ($steam_id_64) {
            
            // NOTE : La logique de BDD a été retirée. Nous utilisons uniquement l'API Steam.
            $api_key = env('STEAM_SECRET');
            $client = new GuzzleHttp\Client();
            $url = 'http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/';

            try {
                $response = $client->get($url, [
                    'query' => [
                        'key' => $api_key,
                        'steamid' => $steam_id_64,
                        'format' => 'json',
                        'include_appinfo' => true,
                        'include_played_free_games' => true,
                    ]
                ]);

                $data = json_decode((string)$response->getBody(), true);
                
                $games = $data['response']['games'] ?? [];
                $total_games = $data['response']['game_count'] ?? 0;

                // Formater la liste pour le Front-end
                $formatted_games = collect($games)->map(function($game) {
                    $playtime_minutes = $game['playtime_forever'] ?? 0;
                    $playtime_hours = round($playtime_minutes / 60, 1);
                    $img_icon_url = $game['img_icon_url'] ?? null;
                    $img_logo_url = $game['img_logo_url'] ?? null;

                    return [
                        'app_id' => $game['appid'],
                        'name' => $game['name'],
                        'playtime_minutes' => $playtime_minutes,
                        'playtime_hours' => $playtime_hours,
                        'icon_url' => $img_icon_url ? "http://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$img_icon_url}.jpg" : null,
                        'logo_url' => $img_logo_url ? "http://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$img_logo_url}.jpg" : null,
                    ];
                })
                ->sortByDesc('playtime_minutes') 
                ->values();

                // Réponse de succès
                return response()->json([
                    'status' => 'success',
                    'game_count' => $total_games,
                    'games' => $formatted_games,
                ]);

            } catch (\Exception $e) {
                // En cas d'échec API (ex: clé non valide, ou Steam est lent)
                return response()->json(['status' => 'error', 'message' => 'Erreur API lors de la récupération de la bibliothèque de jeux.'], 500);
            }
        });
    });

});

