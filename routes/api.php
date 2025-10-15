<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;
use GuzzleHttp\Client;
use Illuminate\Support\Collection; // Pour la fonction collect()


// On crée un groupe de routes qui seront préfixées par 'api'
Route::middleware('api')->group(function () {

    // Notre route de statut
    Route::get('/status', function () {
        return response()->json([
            'status' => 'API is running'
        ]);
    });

    // C'est ici que nous ajouterons nos futures routes API

});

// 1.1 Route de Redirection: Envoie l'utilisateur vers la page de connexion Steam
Route::get('/auth/steam/redirect', function () {
    $params = [
        'openid.ns'          => 'http://specs.openid.net/auth/2.0',
        'openid.mode'        => 'checkid_setup',
        'openid.return_to'   => env('STEAM_REDIRECT'),
        'openid.realm'       => 'http://127.0.0.1:5173',
        'openid.identity'    => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id'  => 'http://specs.openid.net/auth/2.0/identifier_select',
    ];

    $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);

    return Redirect::to($steam_login_url);
});

// 1.2 Route de Callback: Reçoit les données de Steam et vérifie la signature
Route::get('/auth/steam/callback', function (Request $request) {
    $raw_params = $request->query();

    // Reconstruire les paramètres avec les points d'origine pour la vérification OpenID (SOLUTION CLÉ)
    $params_to_send = [];
    foreach ($raw_params as $key => $value) {
        $new_key = str_replace('_', '.', $key);
        $params_to_send[$new_key] = $value;
    }

    $params_to_send['openid.mode'] = 'check_authentication';
    $body = http_build_query($params_to_send);

    $client = new Client();
    $steam_verification_url = 'https://steamcommunity.com/openid/login';

    try {
        $response = $client->post($steam_verification_url, [
            'body' => $body,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        $response_body = (string)$response->getBody();

        if (strpos($response_body, 'is_valid:true') !== false) {
            $claimed_id = $request->input('openid_claimed_id');
            $steam_id_64 = basename($claimed_id);

            // Connexion réussie, renvoie le SteamID
            return response()->json([
                'status' => 'success',
                'message' => 'Authentification Steam réussie. ID prêt à l\'emploi.',
                'steam_id' => $steam_id_64,
            ]);

        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation échouée. Signature Steam invalide.',
                'response' => $response_body
            ], 401);
        }

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur de communication avec le serveur Steam.',
            'exception' => $e->getMessage()
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

    Route::get('/games', function ($steam_id_64) {
        $api_key = env('STEAM_SECRET');
        $client = new Client();
        $url = 'http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/';

        try {
            $response = $client->get($url, [
                'query' => [
                    'key' => $api_key,
                    'steamid' => $steam_id_64,
                    'format' => 'json',
                    'include_appinfo' => true,      // Pour obtenir le nom du jeu et les icônes
                    'include_played_free_games' => true, // Inclure les jeux gratuits joués
                ]
            ]);

            $data = json_decode((string)$response->getBody(), true);
            
            $games = $data['response']['games'] ?? [];
            $total_games = $data['response']['game_count'] ?? 0;

            // Formater la liste pour être plus propre pour le front-end
             $formatted_games = collect($games)->map(function($game) {
                // Créer et stocker les minutes brutes DANS le tableau
                $playtime_minutes = $game['playtime_forever'] ?? 0;
                $playtime_hours = round($playtime_minutes / 60, 1);
                
                // Rendre les URLs d'icônes robustes
                $img_icon_url = $game['img_icon_url'] ?? null;
                $img_logo_url = $game['img_logo_url'] ?? null;

                return [
                    'app_id' => $game['appid'],
                    'name' => $game['name'],
                    'playtime_minutes' => $playtime_minutes, // <-- LA CLÉ EST MAINTENANT DANS LE TABLEAU
                    'playtime_hours' => $playtime_hours,
                    'icon_url' => $img_icon_url ? "http://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$img_icon_url}.jpg" : null,
                    'logo_url' => $img_logo_url ? "http://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$img_logo_url}.jpg" : null,
                ];
            })
            // CORRECTION CRITIQUE : Trier par la clé du tableau ('playtime_minutes')
            ->sortByDesc('playtime_minutes') 
            ->values(); // Réindexer les clés numériques

            return response()->json([
                'status' => 'success',
                'game_count' => $total_games,
                'games' => $formatted_games,
            ]);

        } catch (\Exception $e) {
            dd('Erreur API lors de la récupération de la bibliothèque de jeux.', $e->getMessage());
        }
    });

});
