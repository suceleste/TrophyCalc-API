<?php

use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\SteamAuth\SteamAuthController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\GlobalAchievement;
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
     * Route publique de Connexion a Steam
     */
    Route::prefix('auth/steam')->controller(SteamAuthController::class)->group( function () {
        Route::get('/redirect', 'redirect')->name('auth.steam.redirect');
        Route::get('/callback', 'callback')->name('auth.steam.callback');
    });

    Route::controller(PublicController::class)->group(function () {
        /**
         * Route de Recherche
         */
        Route::prefix('search')->group(function () {
            Route::get('/games', 'searchGames')->name('search.games');
            Route::get('/users', 'searchUsers')->name('search.users');
        });

        /**
         * Route d'affichage de gameou profile.
         */
        Route::get('/games/{app_id}/achievements', 'showGameAchievements')->name("game.achievemets");
        Route::get('/profile/{steam_id_64}', 'showProfile')->name('profile');
    });


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
        Route::get('/games/{app_id}/progress', function (Request $request, $app_id) {

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
