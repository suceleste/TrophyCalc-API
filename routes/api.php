<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\GlobalAchievement;
use App\Models\UserGameScore;
use GuzzleHttp\Client; // Requis pour la validation Steam non-standard
use App\Jobs\CalculateUserGlobalStats; // Importe le Job
use App\Jobs\CalculateLatestAchievements; // Importe le Job
use App\Jobs\CalculateNearlyCompletedGames; // Importe le Job
use App\Jobs\UpdateRarityForGame;
use App\Jobs\CalculateUserTotalXp;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Fichier central définissant toutes les routes de l'API TrophyCalc.
| Les routes sont groupées pour la clarté et la sécurité.
|
*/

Route::middleware('api')->group(function () {

    /**
     * Route publique simple pour vérifier que l'API est en ligne.
     */
    Route::get('/status', fn() => response()->json(['status' => 'API is running']));

    /**
     * Groupe de routes publiques pour la recherche.
     */

    Route::prefix('/games/{app_id}/achievements', function (string $app_id) {
        $apiKey = env('STEAM_SECRET');
        $schemaResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
          'appid' => $app_id, 'key' => $apiKey, 'l' => 'french'
        ]);
        $schemaAchievements = collect($schemaResponse->json('game.availableGameStats.achievements', []));
        $RarityData = GlobalAchievement::where('app_id', $app_id)->pluck('xp_value', 'api_name');

        $mergedAchievements = $schemaAchievements->map(function ($schemaAch) use ($RarityData) {
            $apiName = $schemaAch['name'];
            $xpValue = $RarityData->get($apiName) ?? 10;

            return [
                'api_name'    => $apiName,
                'name'        => $schemaAch['displayName'],
                'description' => $schemaAch['description'] ?? '...',
                'icon'        => $schemaAch['icon'],
                'icon_gray'   => $schemaAch['icongray'],
                'xp_value'    => $xpValue,
                'achieved'    => false,
                'unlock_time' => null
            ];
        });

        return response()->json($mergedAchievements);
    });

    Route::prefix('search')->group(function () {

        Route::get('/games', function (Request $request) {
            $query = $request->query('q');
            if (!$query || strlen($query) < 3) { /* ... return 400 ... */ }

            // 1. Appelle la "nouvelle" API (l'autocomplétion du magasin)
            $response = Http::get('https://store.steampowered.com/api/storesearch', [
                'term' => $query,
                'l' => 'french',
                'cc' => 'FR',
                'v' => '1'
            ]);

            if ($response->failed()) { /* ... return 503 ... */ }

            // 2. Filtre les résultats
            $items = $response->json('items', []);
            $games = collect($items)
                        // Garde que les 'app' (jeux), pas les 'sub' (packs)
                        ->where('type', 'app')
                        ->map(function ($game) {
                            return [
                                'appid' => $game['id'],
                                'name' => $game['name'],
                                'header_image' => $game['tiny_image']
                            ];
                        })
                        ->take(10); // Prend les 10 premiers

            return response()->json($games);
        });
        /**
         * Recherche globale d'utilisateurs inscrits sur TrophyCalc (basée sur notre BDD).
         */
        Route::get('/users', function (Request $request) {
            $query = $request->query('q');
            if (!$query || strlen($query) < 3) {
                return response()->json(['message' => 'Terme de recherche trop court (min 3 caractères).'], 400);
            }
            $users = User::where('name', 'LIKE', "%{$query}%")
                         ->select('id', 'name', 'avatar', 'steam_id_64') // Ne renvoie que les données publiques
                         ->whereNotNull('steam_id_64') // Uniquement les profils liés à Steam
                         ->take(10) // Limite à 10 résultats
                         ->get();
            return response()->json($users);
        }); // Fin /search/users
    }); // Fin groupe /search

    /**
     * Route publique pour récupérer les données de profil d'un utilisateur de TrophyCalc.
     */
     Route::get('/profiles/steam/{steam_id_64}', function (string $steam_id_64) {
        $user = User::where('steam_id_64', $steam_id_64)
                    ->select('id', 'name', 'avatar', 'steam_id_64', 'created_at')
                    ->firstOrFail(); // Renvoie 404 si non trouvé
        return response()->json($user);
    })->name('profiles.steam');


    /**
     * Groupe de routes pour le processus d'authentification Steam.
     */
    Route::prefix('auth/steam')->group(function () {

        /**
         * 1.1 Redirige l'utilisateur vers la page de connexion Steam.
         */
        Route::get('/redirect', function () {
            $params = [
                'openid.ns'         => 'http://specs.openid.net/auth/2.0',
                'openid.mode'       => 'checkid_setup',
                'openid.return_to'  => route('auth.steam.callback'), // URL de retour (notre route ci-dessous)
                'openid.realm'      => config('app.url'), // L'adresse de notre site
                'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
            ];
            $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);
            return Redirect::to($steam_login_url);
        })->name('auth.steam.redirect');

        /**
         * 1.2 Gère le retour de Steam (Callback).
         * Valide la connexion, crée/met à jour l'utilisateur, génère un token
         * et redirige vers le callback du frontend.
         */
        Route::get('/callback', function (Request $request) {
             try {
                // Prépare les paramètres pour la validation (PHP change les '.' en '_')
                $raw_params = $request->all();
                $params_for_steam = [];
                foreach ($raw_params as $key => $value) { $params_for_steam[str_replace('_', '.', $key)] = $value; }
                $params_for_steam['openid.mode'] = 'check_authentication';

                // Valide la réponse avec Guzzle (plus fiable pour ce vieux protocole)
                $client = new Client();
                $response = $client->post('https://steamcommunity.com/openid/login', ['form_params' => $params_for_steam]);
                $response_body = (string)$response->getBody();

                // Si la signature est valide
                if (str_contains($response_body, 'is_valid:true')) {
                    $claimed_id = $request->input('openid_claimed_id');
                    $steam_id_64 = basename($claimed_id);
                    $api_key = env('STEAM_SECRET');

                    // Récupère le profil public Steam
                    $profile_response = Http::get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', ['key' => $api_key, 'steamids' => $steam_id_64]);
                    if ($profile_response->failed() || empty($profile_response->json('response.players'))) {
                        Log::error("Échec récupération profil Steam pour {$steam_id_64} après validation.");
                        return Redirect::to(env('FRONTEND_URL') . '/login-failed?error=profile_not_found');
                    }
                    $player = $profile_response->json('response.players')[0];

                    // Crée ou met à jour l'utilisateur dans notre BDD
                    $user = User::updateOrCreate(
                        ['steam_id_64' => $steam_id_64], // Clé unique pour trouver
                        [ // Données à insérer/mettre à jour
                            'name' => $player['personaname'],
                            'email' => "{$steam_id_64}@steam.trophycalc", // Email unique
                            'password' => Hash::make(Str::random(20)),
                            'avatar' => $player['avatarfull'],
                            'profile_url' => $player['profileurl'],
                            'profile_updated_at' => now(),
                        ]
                    );

                    // Génère un token d'API Sanctum pour le frontend
                    $token = $user->createToken('auth_token')->plainTextToken;

                    // Redirige vers le callback du frontend avec le token
                    $frontend_callback_url = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') . '/auth/callback';
                    $redirect_url = $frontend_callback_url . '?token=' . $token;
                    return Redirect::to($redirect_url);

                } else {
                    // Si la validation Steam échoue
                    Log::warning("Échec validation Steam", ['params' => $request->all(), 'steam_response' => $response_body]);
                    return Redirect::to(env('FRONTEND_URL', 'http://localhost:5173') . '/login-failed?error=steam_validation_failed');
                }
            } catch (\Exception $e) {
                // En cas d'erreur critique
                Log::error("Erreur critique pendant callback Steam: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return Redirect::to(env('FRONTEND_URL', 'http://localhost:5173') . '/login-failed?error=critical_error');
            }
        })->name('auth.steam.callback');
    }); // Fin groupe /auth/steam

    Route::get('/leaderboard', function () {
        $leaderboard = User::where('total_xp', '>', 0)->orderBy('total_xp', 'DESC')->select('name', 'avatar', 'total_xp', 'games_completed', 'steam_id_64')->take(100)->get();

        return response()->json($leaderboard);
    });

    /**
     * ROUTES PROTÉGÉES (NÉCESSITENT UN TOKEN SANCTUM VALIDE)
     * Toutes les routes ici nécessitent un en-tête 'Authorization: Bearer <token>'
     */
    Route::middleware('auth:sanctum')->prefix('user')->group(function () {

        /**
         * Renvoie les infos de l'utilisateur actuellement connecté (basé sur le token).
         */
        Route::get('/', function (Request $request) {
            return $request->user();
        });

        /**
         * Renvoie la complétion globale (DEPUIS LE CACHE)
         * et DÉCLENCHE un calcul en arrière-plan (asynchrone).
         */
        Route::get('/stats/global-completion', function (Request $request) {
            $user = $request->user();
            $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "global_completion_{$steamId}";
            $statsInCache = Cache::get($cacheKey); // Récupère le cache
            $xpCacheKey = "user_xp_stats_{$steamId}";
            $xpInCache = Cache::get($xpCacheKey);

            // Lance le Job en arrière-plan pour rafraîchir
            CalculateUserGlobalStats::dispatch($user);
            CalculateUserTotalXp::dispatch($user);

            if (config('app.debug')) Log::info("Job CalculateUserGlobalStats DÉPÊCHÉ pour {$steamId}");

            // Renvoie les stats en cache (même si elles sont anciennes) ou null
            return response()->json(array_merge($statsInCache ?? [], $xpInCache ?? []));
        });

        /**
         * Renvoie les 5 derniers succès (DEPUIS LE CACHE)
         * et DÉCLENCHE un calcul en arrière-plan (asynchrone).
         */
        Route::get('/achievements/latest', function (Request $request) {
            $user = $request->user();
            $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "latest_achievements_details_{$steamId}";
            $statsInCache = Cache::get($cacheKey); // Récupère le cache

            // Lance le Job en arrière-plan pour rafraîchir
            CalculateLatestAchievements::dispatch($user);

            if (config('app.debug')) Log::info("Job CalculateLatestAchievements DÉPÊCHÉ pour {$steamId}");

            return response()->json($statsInCache);
        });

        /**
         * Renvoie la liste des jeux de l'utilisateur connecté (Cache de 1h).
         */
        Route::get('/games', function (Request $request) {
            $user = $request->user(); $apiKey = env('STEAM_SECRET'); $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "user_games_list_{$steamId}";
            $cacheDuration = 60 * 60; // 1 heure

            // On met en cache la liste des jeux formatée
            $formattedGamesData = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {
                if (config('app.debug')) Log::info("CACHE MISS: Récupération liste de jeux pour {$steamId}");
                $response = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json',
                    'include_appinfo' => true, 'include_played_free_games' => true,
                ]);
                if ($response->failed()) { return null; } // Échec

                $games = $response->json('response.games', []);
                $formattedGames = collect($games)->map(function ($game) {
                    return [
                        'app_id' => $game['appid'],
                        'name' => $game['name'],
                        'playtime_hours' => round($game['playtime_forever'] / 60, 1),
                        'icon_url' => $game['img_icon_url'] ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$game['img_icon_url']}.jpg" : null,
                    ];
                })->sortByDesc('playtime_hours')->values();

                return ['game_count' => $response->json('response.game_count', 0), 'games' => $formattedGames];
            });

            if ($formattedGamesData === null) {
                return response()->json(['message' => 'Impossible de contacter Steam.'], 502);
            }

            return response()->json($formattedGamesData);
        });

        /**
         * Renvoie les succès d'un jeu spécifique (avec détails et cache de 6h).
         */
        Route::get('/games/{app_id}/achievements', function (Request $request, $app_id) {

            $user = $request->user();
            $apiKey = env('STEAM_SECRET');
            $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $rarityCacheKey = "rarity_updated_{$app_id}";
            if (!Cache::has($rarityCacheKey)){
                UpdateRarityForGame::dispatch($app_id);
                Cache::put($rarityCacheKey, true, now()->addDay());
            }

            $cacheKey = "game_achievements_{$steamId}_{$app_id}";
            $cacheDuration = 60 * 60 * 6; // 6 heures

            // On met en cache le résultat de la fusion
            $result = Cache::remember($cacheKey, $cacheDuration, function () use ($app_id, $apiKey, $steamId) {
                if (config('app.debug')) Log::info("CACHE MISS: Récupération succès pour jeu {$app_id} / user {$steamId}");
                try {
                    // Appel 1: Statut du joueur
                    $playerAchievementsResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                        'appid' => $app_id, 'key' => $apiKey, 'steamid' => $steamId, 'l' => 'french'
                    ]);
                    if ($playerAchievementsResponse->failed()) { return ['status' => 'error', 'message' => 'API Steam (Player) inaccessible', 'code' => 502]; }
                    $playerData = $playerAchievementsResponse->json();
                    if (!isset($playerData['playerstats']['success']) || $playerData['playerstats']['success'] !== true) {
                        return ['status' => 'info', 'message' => $playerData['playerstats']['message'] ?? 'Succès non disponibles (Player)'];
                    }
                    $playerAchievements = collect($playerData['playerstats']['achievements'] ?? [])->keyBy('apiname');

                    // Appel 2: Schéma du jeu
                    $schemaResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
                        'appid' => $app_id, 'key' => $apiKey, 'l' => 'french'
                    ]);
                    if ($schemaResponse->failed()) { return ['status' => 'error', 'message' => 'API Steam (Schema) inaccessible', 'code' => 502]; }
                    $schemaAchievements = collect($schemaResponse->json('game.availableGameStats.achievements', []));

                    // Fusion
                    $mergedAchievements = $schemaAchievements->map(function ($schemaAch) use ($playerAchievements) {
                        $playerAch = $playerAchievements->get($schemaAch['name']);
                        return [
                            'api_name' => $schemaAch['name'],
                            'name' => $schemaAch['displayName'],
                            'description' => $schemaAch['description'] ?? 'Pas de description disponible.',
                            'icon' => $schemaAch['icon'],
                            'icon_gray' => $schemaAch['icongray'],
                            'hidden' => (bool)($schemaAch['hidden'] ?? 0),
                            'achieved' => $playerAch ? (bool)$playerAch['achieved'] : false,
                            'unlock_time' => $playerAch && $playerAch['achieved'] ? $playerAch['unlocktime'] : null,
                            'percent' => $schemaAch['percent'] ?? null,
                        ];
                    })->sortByDesc('achieved')->values();

                    $totalCount = $mergedAchievements->count(); $unlockedCount = $mergedAchievements->where('achieved', true)->count();

                    return [
                        'status' => 'success',
                        'game_name' => $playerData['playerstats']['gameName'] ?? $schemaResponse->json('game.gameName') ?? "Jeu ID: {$app_id}",
                        'achievements' => $mergedAchievements,
                        'total_count' => $totalCount,
                        'unlocked_count' => $unlockedCount
                    ];
                } catch (\Exception $e) {
                     Log::error("Erreur API Succès DÉTAILS pour {$app_id} / {$steamId}: " . $e->getMessage());
                     return ['status' => 'error', 'message' => 'Erreur interne.', 'code' => 500];
                }
            });

            // Gère les statuts d'erreur renvoyés par la fonction de cache
            if ($result['status'] === 'error') {
                return response()->json(['message' => $result['message']], $result['code'] ?? 500);
            }
            if ($result['status'] === 'info') {
                return response()->json(['status' => 'info', 'message' => $result['message']], 404);
            }

            return response()->json($result);
        });

        /**
         * Renvoie les jeux presque terminés (DEPUIS LE CACHE)
         * et DÉCLENCHE un calcul en arrière-plan (asynchrone).
         */
        Route::get('/games/nearly-completed', function (Request $request) {
            $user = $request->user();
            $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "nearly_completed_games_{$steamId}";
            $statsInCache = Cache::get($cacheKey); // Récupère le cache

            // Lance le Job en arrière-plan pour rafraîchir
            CalculateNearlyCompletedGames::dispatch($user);

            if (config('app.debug')) Log::info("Job CalculateNearlyCompletedGames DÉPÊCHÉ pour {$steamId}");

            return response()->json($statsInCache);
        });

    }); // Fin du groupe /user

}); // Fin du middleware api
