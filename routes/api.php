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
use GuzzleHttp\Client; // Utilisé pour la validation Steam

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Fichier central définissant toutes les routes de l'API TrophyCalc.
|
*/

Route::middleware('api')->group(function () {

    // --- ROUTE PUBLIQUE SIMPLE ---
    Route::get('/status', fn() => response()->json(['status' => 'API is running']));

    // --- ROUTES PUBLIQUES DE RECHERCHE ---
    Route::prefix('search')->group(function () {

        // Recherche globale de jeux Steam
        Route::get('/games', function (Request $request) {
            $query = $request->query('q');
            if (!$query || strlen($query) < 3) {
                return response()->json(['message' => 'Terme de recherche trop court (min 3 caractères).'], 400);
            }

            $appListCacheKey = 'steam_app_list';
            $appListCacheDuration = 60 * 60 * 24; // 24h
            $allApps = Cache::remember($appListCacheKey, $appListCacheDuration, function () {
                 Log::info("CACHE MISS: Récupération liste complète apps Steam.");
                 try {
                     $response = Http::timeout(30)->get('https://api.steampowered.com/ISteamApps/GetAppList/v2/');
                     if ($response->failed()) { Log::error("Échec GetAppList Steam."); return null; }
                     return $response->json('applist.apps', []);
                 } catch (\Exception $e) { Log::error("Erreur critique GetAppList: " . $e->getMessage()); return null;}
            });
            if ($allApps === null) {
                return response()->json(['message' => 'Impossible de récupérer la liste des jeux depuis Steam.'], 503);
            }

            $searchQueryLower = strtolower($query);
            $initialResults = collect($allApps)
                ->filter(fn($app) => isset($app['name']) && $app['name'] !== '' && str_contains(strtolower($app['name']), $searchQueryLower))
                ->take(50);

            $filteredGames = collect([]);
            $appDetailCacheDuration = 60 * 60 * 24 * 7; // 1 semaine

            foreach ($initialResults as $app) {
                $appId = $app['appid'];
                $detailsCacheKey = "appdetails_{$appId}";
                $details = Cache::remember($detailsCacheKey, $appDetailCacheDuration, function () use ($appId) {
                     // Log::info("CACHE MISS (AppDetails): Détails pour {$appId}"); // Optionnel
                     try {
                         $response = Http::timeout(5)->get("https://store.steampowered.com/api/appdetails", ['appids' => $appId, 'l' => 'french']);
                         if ($response->successful() && isset($response->json()[$appId]['success']) && $response->json()[$appId]['success'] === true) {
                             return $response->json()[$appId]['data'];
                         }
                         return null;
                     } catch (\Exception $e) { return null; }
                });

                if ($details && isset($details['type']) && $details['type'] === 'game') {
                    $filteredGames->push([
                        'appid' => $appId,
                        'name' => $details['name'] ?? $app['name'],
                        'header_image' => $details['header_image'] ?? "https://cdn.akamai.steamstatic.com/steam/apps/{$appId}/header.jpg"
                    ]);
                }
                if ($filteredGames->count() >= 20) { break; }
                usleep(50000); // Pause légère
            }
            return response()->json($filteredGames->values());
        }); // Fin /search/games

        // Recherche globale d'utilisateurs TrophyCalc
        Route::get('/users', function (Request $request) {
            $query = $request->query('q');
            if (!$query || strlen($query) < 3) {
                return response()->json(['message' => 'Terme de recherche trop court (min 3 caractères).'], 400);
            }
            $users = User::where('name', 'LIKE', "%{$query}%")
                         ->select('id', 'name', 'avatar', 'steam_id_64')
                         ->whereNotNull('steam_id_64')
                         ->take(10)
                         ->get();
            return response()->json($users);
        }); // Fin /search/users
    }); // Fin groupe /search

    // --- ROUTE PUBLIQUE POUR AFFICHER UN PROFIL ---
     Route::get('/profiles/steam/{steam_id_64}', function (string $steam_id_64) {
        $user = User::where('steam_id_64', $steam_id_64)
                    ->select('id', 'name', 'avatar', 'steam_id_64', 'created_at')
                    ->firstOrFail(); // Renvoie 404 si non trouvé
        return response()->json($user);
    })->name('profiles.steam');


    // --- GROUPE D'AUTHENTIFICATION STEAM ---
    Route::prefix('auth/steam')->group(function () {
        // 1.1 Redirige vers Steam
        Route::get('/redirect', function () {
            $params = [
                'openid.ns'         => 'http://specs.openid.net/auth/2.0',
                'openid.mode'       => 'checkid_setup',
                'openid.return_to'  => route('auth.steam.callback'),
                'openid.realm'      => config('app.url'),
                'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
            ];
            $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);
            return Redirect::to($steam_login_url);
        })->name('auth.steam.redirect');

        // 1.2 Gère le retour de Steam
        Route::get('/callback', function (Request $request) {
             try {
                $raw_params = $request->all();
                $params_for_steam = [];
                foreach ($raw_params as $key => $value) { $params_for_steam[str_replace('_', '.', $key)] = $value; }
                $params_for_steam['openid.mode'] = 'check_authentication';

                $client = new Client();
                $response = $client->post('https://steamcommunity.com/openid/login', ['form_params' => $params_for_steam]);
                $response_body = (string)$response->getBody();

                if (str_contains($response_body, 'is_valid:true')) {
                    $claimed_id = $request->input('openid_claimed_id');
                    $steam_id_64 = basename($claimed_id);
                    $api_key = env('STEAM_SECRET');

                    $profile_response = Http::get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', ['key' => $api_key, 'steamids' => $steam_id_64]);
                    if ($profile_response->failed() || empty($profile_response->json('response.players'))) {
                        Log::error("Échec récupération profil Steam pour {$steam_id_64} après validation.");
                        return Redirect::to('http://localhost:5173/login-failed?error=profile_not_found');
                    }
                    $player = $profile_response->json('response.players')[0];

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

                    $token = $user->createToken('auth_token')->plainTextToken;
                    $frontend_callback_url = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') . '/auth/callback';
                    $redirect_url = $frontend_callback_url . '?token=' . $token;
                    return Redirect::to($redirect_url);

                } else {
                    Log::warning("Échec validation Steam", ['params' => $request->all(), 'steam_response' => $response_body]);
                    return Redirect::to('http://localhost:5173/login-failed?error=steam_validation_failed');
                }
            } catch (\Exception $e) {
                Log::error("Erreur critique pendant callback Steam: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return Redirect::to('http://localhost:5173/login-failed?error=critical_error');
            }
        })->name('auth.steam.callback');
    }); // Fin groupe /auth/steam


    // --- ROUTES PROTÉGÉES (NÉCESSITENT UN TOKEN SANCTUM VALIDE) ---
    Route::middleware('auth:sanctum')->prefix('user')->group(function () {

        // Renvoie les infos de l'utilisateur actuellement connecté
        Route::get('/', function (Request $request) {
            return $request->user();
        });

        // Renvoie la complétion globale (avec cache)
        Route::get('/stats/global-completion', function (Request $request) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');
            $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "global_completion_{$steamId}";
            $cacheDuration = 60 * 60 * 24; // 24h

            $stats = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {
                Log::info("CACHE MISS: Calcul complétion globale pour {$steamId}");
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey,
                    'steamid' => $steamId,
                    'format' => 'json'
                ]);
                if ($gamesResponse->failed()) { Log::error("Échec GetOwnedGames (global comp) {$steamId}"); return null; }
                $ownedGames = $gamesResponse->json('response.games', []);

                $totalPossible = 0; $totalUnlocked = 0;
                foreach ($ownedGames as $game) {
                    $appId = $game['appid'];
                    try {
                        $achievementsResponse = Http::timeout(15)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                            'appid' => $appId, 'key' => $apiKey, 'steamid' => $steamId
                        ]);
                        if ($achievementsResponse->successful()) {
                            $data = $achievementsResponse->json();
                            if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === true && isset($data['playerstats']['achievements'])) {
                                $achievements = $data['playerstats']['achievements'];
                                $totalPossible += count($achievements);
                                foreach ($achievements as $ach) { if ($ach['achieved'] == 1) { $totalUnlocked++; } }
                            }
                        }
                        usleep(150000);
                    } catch (\Exception $e) { Log::warning("Erreur API Succès (global comp) {$appId}/{$steamId}: " . $e->getMessage()); usleep(150000); }
                }
                Log::info("Fin calcul global comp {$steamId}. Possible: {$totalPossible}, Débloqués: {$totalUnlocked}");
                $rate = ($totalPossible > 0) ? round(($totalUnlocked / $totalPossible) * 100, 2) : 0;
                return [
                    'total_possible' => $totalPossible,
                    'total_unlocked' => $totalUnlocked,
                    'completion_percentage' => $rate,
                    'calculated_at' => now()->toIso8601String()
                ];
            });
            if ($stats === null) { return response()->json(['message' => 'Erreur calcul stats.'], 500); }
            return response()->json($stats);
        });

        // Renvoie les 5 derniers succès (avec détails et cache)
        Route::get('/achievements/latest', function (Request $request) {
            $user = $request->user(); $apiKey = env('STEAM_SECRET'); $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }

            $cacheKey = "latest_achievements_details_{$steamId}"; $cacheDuration = 60 * 60; // 1h

            $latestAchievementsWithDetails = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {
                Log::info("CACHE MISS: Récupération détails derniers succès {$steamId}");
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json', 'include_appinfo' => true
                ]);
                if ($gamesResponse->failed()) { return []; } $ownedGames = $gamesResponse->json('response.games', []);
                $allUnlocked = [];
                foreach ($ownedGames as $game) {
                    $appId = $game['appid']; $gameName = $game['name'];
                    try {
                        $achievementsResponse = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                            'appid' => $appId, 'key' => $apiKey, 'steamid' => $steamId,
                        ]);
                        if ($achievementsResponse->successful()) {
                            $data = $achievementsResponse->json();
                            if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === true && isset($data['playerstats']['achievements'])) {
                                foreach ($data['playerstats']['achievements'] as $ach) {
                                    if ($ach['achieved'] == 1 && isset($ach['unlocktime']) && $ach['unlocktime'] > 0) {
                                        $allUnlocked[] = [
                                            'app_id' => $appId, 'game_name' => $gameName,
                                            'api_name' => $ach['apiname'], 'unlock_time' => $ach['unlocktime'],
                                        ];
                                    }
                                }
                            }
                        }
                        usleep(100000);
                    } catch (\Exception $e) { usleep(100000); }
                }
                usort($allUnlocked, fn($a, $b) => $b['unlock_time'] <=> $a['unlock_time']);
                $latest = array_slice($allUnlocked, 0, 5);
                $details = []; $appIds = collect($latest)->pluck('app_id')->unique()->values()->all();
                foreach ($appIds as $appId) {
                    try {
                        $schema = Cache::remember("game_schema_{$appId}", 60*60*12, function () use ($apiKey, $appId) {
                            $schemaResponse = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
                                'appid' => $appId, 'key' => $apiKey, 'l' => 'french'
                            ]);
                            if ($schemaResponse->failed()) return null;
                            return $schemaResponse->json('game.availableGameStats.achievements', []);
                        });
                        if ($schema !== null) { $details[$appId] = collect($schema)->keyBy('name'); }
                        else { $details[$appId] = collect([]); } usleep(50000);
                    } catch (\Exception $e) { $details[$appId] = collect([]); usleep(50000); }
                }
                $results = collect($latest)->map(function ($ach) use ($details) {
                    $detailsCollection = $details[$ach['app_id']] ?? collect([]);
                    $achDetails = $detailsCollection->get($ach['api_name']);
                    return [
                        'app_id'      => $ach['app_id'],
                        'game_name'   => $ach['game_name'],
                        'api_name'    => $ach['api_name'],
                        'unlock_time' => $ach['unlock_time'],
                        'name'        => $achDetails['displayName'] ?? $ach['api_name'],
                        'description' => $achDetails['description'] ?? null,
                        'icon'        => $achDetails['icon'] ?? null,
                        'icon_gray'   => $achDetails['icongray'] ?? null,
                        'hidden'      => isset($achDetails['hidden']) ? (bool)$achDetails['hidden'] : false,
                    ];
                })->all();
                return $results;
            });
            return response()->json($latestAchievementsWithDetails);
        });

        // Renvoie la liste des jeux de l'utilisateur connecté
        Route::get('/games', function (Request $request) {
            $user = $request->user(); $apiKey = env('STEAM_SECRET'); $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }
            $response = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json',
                'include_appinfo' => true, 'include_played_free_games' => true,
            ]);
            if ($response->failed()) { return response()->json(['message' => 'Impossible de contacter Steam.'], 502); }
            $games = $response->json('response.games', []);
            $formattedGames = collect($games)->map(function ($game) {
                return [
                    'app_id' => $game['appid'],
                    'name' => $game['name'],
                    'playtime_hours' => round($game['playtime_forever'] / 60, 1),
                    'icon_url' => $game['img_icon_url'] ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$game['appid']}/{$game['img_icon_url']}.jpg" : null,
                ];
            })->sortByDesc('playtime_hours')->values();
            return response()->json(['game_count' => $response->json('response.game_count', 0), 'games' => $formattedGames]);
        });

        // Renvoie les succès d'un jeu spécifique (avec détails)
        Route::get('/games/{app_id}/achievements', function (Request $request, $app_id) {
            $user = $request->user(); $apiKey = env('STEAM_SECRET'); $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }
            try {
                $playerAchievementsResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                    'appid' => $app_id, 'key' => $apiKey, 'steamid' => $steamId, 'l' => 'french'
                ]);
                if ($playerAchievementsResponse->failed()) { return response()->json(['message' => 'Impossible de contacter l\'API Steam (PlayerAchievements).'], 502); }
                $playerData = $playerAchievementsResponse->json();
                if (!isset($playerData['playerstats']['success']) || $playerData['playerstats']['success'] !== true) {
                    return response()->json(['status' => 'info', 'message' => $playerData['playerstats']['message'] ?? 'Succès non disponibles (PlayerAchievements).'], 404);
                }
                $playerAchievements = collect($playerData['playerstats']['achievements'] ?? [])->keyBy('apiname');
                $schemaResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
                    'appid' => $app_id, 'key' => $apiKey, 'l' => 'french'
                ]);
                if ($schemaResponse->failed()) { return response()->json(['message' => 'Impossible de contacter l\'API Steam (SchemaForGame).'], 502); }
                $schemaAchievements = collect($schemaResponse->json('game.availableGameStats.achievements', []));
                $mergedAchievements = $schemaAchievements->map(function ($schemaAch) use ($playerAchievements) {
                    $playerAch = $playerAchievements->get($schemaAch['name']);
                    return [
                        'api_name' => $schemaAch['name'],
                        'name' => $schemaAch['displayName'],
                        'description' => $schemaAch['description'] ?? 'Pas de description disponible.',
                        'icon' => $schemaAch['icon'],
                        'icon_gray' => $schemaAch['icongray'],
                        'hidden' => (bool)$schemaAch['hidden'],
                        'achieved' => $playerAch ? (bool)$playerAch['achieved'] : false,
                        'unlock_time' => $playerAch && $playerAch['achieved'] ? $playerAch['unlocktime'] : null,
                        'percent' => $schemaAch['percent'] ?? null,
                    ];
                })->sortByDesc('achieved')->values();
                $totalCount = $mergedAchievements->count(); $unlockedCount = $mergedAchievements->where('achieved', true)->count();
                return response()->json([
                    'status' => 'success',
                    'game_name' => $playerData['playerstats']['gameName'] ?? $schemaResponse->json('game.gameName') ?? "Jeu ID: {$app_id}",
                    'achievements' => $mergedAchievements,
                    'total_count' => $totalCount,
                    'unlocked_count' => $unlockedCount
                ]);
            } catch (\Exception $e) {
                 Log::error("Erreur API Succès DÉTAILS pour {$app_id} / {$steamId}: " . $e->getMessage());
                 return response()->json(['status' => 'error', 'message' => 'Erreur interne lors de la récupération des détails des succès.'], 500);
            }
        });

        // Renvoie les jeux presque terminés (avec cache)
        Route::get('/games/nearly-completed', function (Request $request) {
            $user = $request->user(); $apiKey = env('STEAM_SECRET'); $steamId = $user->steam_id_64;
            if (!$steamId) { return response()->json(['message' => 'Aucun Steam ID.'], 404); }
            $cacheKey = "nearly_completed_games_{$steamId}"; $cacheDuration = 60 * 60 * 6; // 6h
            $nearlyCompletedGames = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {
                Log::info("CACHE MISS: Calcul jeux presque terminés {$steamId}");
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json', 'include_appinfo' => true
                ]);
                if ($gamesResponse->failed()) { return []; } $ownedGames = $gamesResponse->json('response.games', []);
                $nearlyCompletedList = [];
                foreach ($ownedGames as $game) {
                    $appId = $game['appid']; $gameName = $game['name'];
                    try {
                        $achievementsResponse = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                            'appid' => $appId, 'key' => $apiKey, 'steamid' => $steamId,
                        ]);
                        if ($achievementsResponse->successful()) {
                            $data = $achievementsResponse->json();
                            if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === true && isset($data['playerstats']['achievements'])) {
                                $achievements = $data['playerstats']['achievements'];
                                $total = count($achievements);
                                $unlocked = 0;
                                if ($total > 0) {
                                    foreach ($achievements as $ach) { if ($ach['achieved'] == 1) { $unlocked++; } }
                                    $percentage = round(($unlocked / $total) * 100);
                                    if ($percentage >= 80 && $percentage < 100) {
                                        $nearlyCompletedList[] = [
                                            'app_id' => $appId, 'name' => $gameName, 'percentage' => $percentage,
                                            'unlocked' => $unlocked, 'total' => $total,
                                            'icon_url' => $game['img_icon_url'] ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$appId}/{$game['img_icon_url']}.jpg" : null,
                                        ];
                                    }
                                }
                            }
                        }
                        usleep(100000);
                    } catch (\Exception $e) { usleep(100000); }
                }
                usort($nearlyCompletedList, fn($a, $b) => $b['percentage'] <=> $a['percentage']);
                Log::info("Fin calcul jeux presque terminés {$steamId}. Trouvés : " . count($nearlyCompletedList));
                return array_slice($nearlyCompletedList, 0, 5);
            });
            return response()->json($nearlyCompletedGames);
        });

    }); // Fin du groupe /user

}); // Fin du middleware api
