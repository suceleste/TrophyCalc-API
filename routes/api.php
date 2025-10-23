<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Http; // On utilise le client HTTP intégré à Laravel, c'est plus propre.
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    Route::get('/search/games', function (Request $request) {
        $query = $request->query('q');
        if (!$query || strlen($query) < 3) {
            return response()->json(['message' => 'Terme de recherche trop court (min 3 caractères).'], 400);
        }

        // 1. Récupérer la liste COMPLÈTE des jeux Steam (avec cache)
        $appListCacheKey = 'steam_app_list';
        $appListCacheDuration = 60 * 60 * 24; // Cache de 24h
        $allApps = Cache::remember($appListCacheKey, $appListCacheDuration, function () { /* ... (logique GetAppList identique) ... */ });
        if ($allApps === null) {
            return response()->json(['message' => 'Impossible de récupérer la liste des jeux depuis Steam.'], 503);
        }

        // 2. Premier Filtrage par nom (insensible à la casse)
        $searchQueryLower = strtolower($query);
        $initialResults = collect($allApps)
            ->filter(fn($app) => isset($app['name']) && str_contains(strtolower($app['name']), $searchQueryLower))
            ->take(50); // Prend un peu plus au début pour avoir de la marge après le 2e filtre

        // 3. NOUVEAU : Vérifier le type de chaque résultat via l'API appdetails
        $filteredGames = collect([]); // Collection pour stocker les vrais jeux
        $appDetailCacheDuration = 60 * 60 * 24 * 7; // Cache 1 semaine pour les détails d'un jeu

        foreach ($initialResults as $app) {
            $appId = $app['appid'];
            $detailsCacheKey = "appdetails_{$appId}";

            // On met en cache la réponse de appdetails
            $details = Cache::remember($detailsCacheKey, $appDetailCacheDuration, function () use ($appId) {
                Log::info("CACHE MISS (AppDetails): Récupération détails pour {$appId}");
                try {
                    // Appel à l'API appdetails (store API, pas Web API)
                    $response = Http::timeout(5)->get("https://store.steampowered.com/api/appdetails", [
                        'appids' => $appId,
                        'l' => 'french' // Demander en français si possible
                    ]);
                    if ($response->successful() && isset($response->json()[$appId]['success']) && $response->json()[$appId]['success'] === true) {
                        return $response->json()[$appId]['data']; // Retourne seulement la section 'data'
                    }
                    Log::warning("Échec ou réponse invalide appdetails pour {$appId}");
                    return null; // Retourne null si échec
                } catch (\Exception $e) {
                     Log::error("Erreur critique appdetails pour {$appId}: " . $e->getMessage());
                     return null;
                }
            });

            // Si on a récupéré les détails ET que le type est 'game'
            if ($details && isset($details['type']) && $details['type'] === 'game') {
                $filteredGames->push([
                    'appid' => $appId,
                    'name' => $details['name'] ?? $app['name'], // Prend le nom des détails si dispo
                    'header_image' => $details['header_image'] ?? "https://cdn.akamai.steamstatic.com/steam/apps/{$appId}/header.jpg" // Prend l'URL directe si dispo
                ]);
            }

            // Limiter le nombre final de résultats et ajouter une pause
            if ($filteredGames->count() >= 20) {
                 break; // Arrête la boucle si on a assez de résultats
            }
            usleep(50000); // Petite pause entre les appels appdetails

        } // Fin foreach

        // 4. Renvoyer les résultats filtrés
        return response()->json($filteredGames->values()); // Renvoie les vrais jeux trouvés

    }); // Fin /search/games

    Route::get('/search/users', function (Request $request) {
        $query = $request->query('q');
        if (!$query || strlen($query) < 3) {
            return response()->json(['message' => 'Terme de recherche trop court (min 3 caractères).'], 400);
        }

        // Recherche dans la BDD locale (insensible à la casse par défaut avec SQLite/MySQL Collation)
        $users = User::where('name', 'LIKE', "%{$query}%")
                     ->select('id', 'name', 'avatar', 'steam_id_64') // Colonnes publiques
                     ->whereNotNull('steam_id_64') // Uniquement profils Steam liés
                     ->take(10) // Limite
                     ->get();

        return response()->json($users);
    }); // Fin /search/users

    Route::get('/profiles/steam/{steam_id_64}', function (string $steam_id_64) {

        // 1. Chercher l'utilisateur dans notre BDD par son SteamID
        //    firstOrFail() renvoie une erreur 404 automatiquement si non trouvé.
        $user = User::where('steam_id_64', $steam_id_64)
                    ->select('id', 'name', 'avatar', 'steam_id_64', 'created_at') // Sélectionne les infos publiques
                    ->firstOrFail();

        // 2. Renvoyer les informations
        return response()->json($user);

    })->name('profiles.steam'); // On nomme la route pour une utilisation future

    Route::middleware('auth:sanctum')->prefix('user')->group(function () {
        // Renvoie les succès pour un jeu spécifique de l'utilisateur connecté
        Route::get('/games/{app_id}/achievements', function (Request $request, $app_id) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');

            if (!$user->steam_id_64) {
                return response()->json(['message' => 'Aucun Steam ID associé.'], 404);
            }

            try {
                // --- APPEL 1 : Statut du joueur (GetPlayerAchievements) ---
                $playerAchievementsResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                    'appid' => $app_id,
                    'key' => $apiKey,
                    'steamid' => $user->steam_id_64,
                    'l' => 'french'
                ]);

                if ($playerAchievementsResponse->failed()) {
                    return response()->json(['message' => 'Impossible de contacter l\'API Steam (PlayerAchievements).'], 502);
                }
                $playerData = $playerAchievementsResponse->json();

                if (!isset($playerData['playerstats']['success']) || $playerData['playerstats']['success'] !== true) {
                    return response()->json([
                        'status' => 'info',
                        'message' => $playerData['playerstats']['message'] ?? 'Succès non disponibles (PlayerAchievements).'
                    ], 404);
                }
                // On stocke les succès du joueur dans un format facile à chercher (clé = apiname)
                $playerAchievements = collect($playerData['playerstats']['achievements'] ?? [])
                                        ->keyBy('apiname'); // Très important pour la fusion

                // --- APPEL 2 : Schéma global du jeu (GetSchemaForGame) ---
                $schemaResponse = Http::get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
                    'appid' => $app_id,
                    'key' => $apiKey,
                    'l' => 'french'
                ]);

                if ($schemaResponse->failed()) {
                    return response()->json(['message' => 'Impossible de contacter l\'API Steam (SchemaForGame).'], 502);
                }
                // On récupère les détails de tous les succès du jeu
                $schemaAchievements = collect($schemaResponse->json('game.availableGameStats.achievements', []));

                // --- FUSION DES DONNÉES ---
                $mergedAchievements = $schemaAchievements->map(function ($schemaAch) use ($playerAchievements) {
                    // On cherche le statut du joueur pour ce succès
                    $playerAch = $playerAchievements->get($schemaAch['name']); // 'name' dans le schéma correspond à 'apiname' chez le joueur

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
                })->sortByDesc('achieved')->values(); // Trié

                $totalCount = $mergedAchievements->count();
                $unlockedCount = $mergedAchievements->where('achieved', true)->count();

                // --- RÉPONSE FINALE ---
                return response()->json([
                    'status' => 'success',
                    'game_name' => $playerData['playerstats']['gameName'] ?? $schemaResponse->json('game.gameName') ?? "Jeu ID: {$app_id}",
                    'achievements' => $mergedAchievements,
                    'total_count' => $totalCount,
                    'unlocked_count' => $unlockedCount,
                ]);

            } catch (\Exception $e) {
                //Log::error("Erreur API Succès Steam DÉTAILS pour {$app_id} / {$user->steam_id_64}: " . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => 'Erreur interne lors de la récupération des détails des succès.'], 500);
            }
        });

        Route::get('/achievements/latest', function (Request $request) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');
            $steamId = $user->steam_id_64;

            if (!$steamId) {
                return response()->json(['message' => 'Aucun Steam ID associé.'], 404);
            }

            $cacheKey = "latest_achievements_details_{$steamId}";
            $cacheDuration = 60 * 60; // 1 heure

            $latestAchievementsWithDetails = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {

                Log::info("CACHE MISS: Récupération détails derniers succès pour {$steamId}");

                // 1. Récupérer tous les jeux possédés
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey,
                    'steamid' => $steamId,
                    'format' => 'json',
                    'include_appinfo' => true // Besoin du nom du jeu
                ]);
                if ($gamesResponse->failed()) {
                    Log::error("Échec GetOwnedGames pour {$steamId} (derniers succès)");
                    return []; // Retourne tableau vide en cas d'erreur API
                }
                $ownedGames = $gamesResponse->json('response.games', []);
                Log::info("Nombre de jeux trouvés (derniers succès) : " . count($ownedGames));

                $allUnlockedAchievements = [];

                // 2. Boucler pour récupérer les succès débloqués
                foreach ($ownedGames as $game) {
                    $appId = $game['appid'];
                    $gameName = $game['name'];
                    try {
                        $achievementsResponse = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                            'appid' => $appId,
                            'key' => $apiKey,
                            'steamid' => $steamId,
                        ]);
                        if ($achievementsResponse->successful()) {
                            $data = $achievementsResponse->json();
                            if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === true && isset($data['playerstats']['achievements'])) {
                                foreach ($data['playerstats']['achievements'] as $ach) {
                                    if ($ach['achieved'] == 1 && isset($ach['unlocktime']) && $ach['unlocktime'] > 0) {
                                        $allUnlockedAchievements[] = [
                                            'app_id'      => $appId,
                                            'game_name'   => $gameName,
                                            'api_name'    => $ach['apiname'],
                                            'unlock_time' => $ach['unlocktime'],
                                        ];
                                    }
                                }
                            }
                        }
                        usleep(100000); // Pause
                    } catch (\Exception $e) {
                        Log::warning("Erreur API Succès (derniers succès) pour {$appId}/{$steamId}: " . $e->getMessage());
                        usleep(100000);
                    }
                } // Fin foreach jeux

                // 3. Trier et garder les 5 plus récents
                usort($allUnlockedAchievements, fn($a, $b) => $b['unlock_time'] <=> $a['unlock_time']);
                $latestAchievements = array_slice($allUnlockedAchievements, 0, 5);
                Log::info("Nombre de succès récents trouvés avant détails : " . count($latestAchievements));

                // 4. Récupérer les détails pour ces 5 succès
                $achievementDetails = [];
                $appIdsToFetch = collect($latestAchievements)->pluck('app_id')->unique()->values()->all(); // Assure que c'est un tableau simple
                Log::info("AppIDs pour lesquels récupérer les schémas : ", $appIdsToFetch);

                foreach ($appIdsToFetch as $appIdToFetch) {
                    try {
                        // Mettre en cache la réponse du schéma
                        $schema = Cache::remember("game_schema_{$appIdToFetch}", 60*60*12, function () use ($apiKey, $appIdToFetch) {
                            Log::info("CACHE MISS (Schema): Récupération schéma pour {$appIdToFetch}");
                            $schemaResponse = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
                                'appid' => $appIdToFetch, 'key' => $apiKey, 'l' => 'french'
                            ]);
                            if ($schemaResponse->failed()) {
                                Log::error("Échec GetSchemaForGame pour {$appIdToFetch}");
                                return null;
                            }
                            return $schemaResponse->json('game.availableGameStats.achievements', []);
                        });

                        if ($schema !== null) { // Vérifie si le schéma a été récupéré
                            // Stocker les détails indexés par api_name
                            $achievementDetails[$appIdToFetch] = collect($schema)->keyBy('name');
                            Log::info("Schéma chargé pour {$appIdToFetch}. Nombre de succès dans schéma: " . count($achievementDetails[$appIdToFetch]));
                        } else {
                            Log::warning("Schéma non récupéré ou vide pour {$appIdToFetch}.");
                            $achievementDetails[$appIdToFetch] = collect([]); // Met une collection vide pour éviter les erreurs
                        }
                        usleep(50000); // Petite pause

                    } catch (\Exception $e) {
                        Log::warning("Erreur API Schema (derniers succès) pour {$appIdToFetch}: " . $e->getMessage());
                        usleep(50000);
                        $achievementDetails[$appIdToFetch] = collect([]); // Met une collection vide en cas d'erreur
                    }
                } // Fin foreach appIdsToFetch

                // 5. Fusionner les détails dans les 5 derniers succès
                $results = collect($latestAchievements)->map(function ($ach) use ($achievementDetails) {
                    // S'assurer que la clé existe avant d'essayer d'y accéder
                    $detailsCollection = $achievementDetails[$ach['app_id']] ?? collect([]);
                    $details = $detailsCollection->get($ach['api_name']); // Utilise get() qui retourne null si non trouvé

                    return [
                        'app_id'      => $ach['app_id'],
                        'game_name'   => $ach['game_name'],
                        'api_name'    => $ach['api_name'],
                        'unlock_time' => $ach['unlock_time'],
                        // Ajout des détails avec vérification
                        'name'        => $details['displayName'] ?? $ach['api_name'], // Vrai nom ou fallback
                        'description' => $details['description'] ?? null,
                        'icon'        => $details['icon'] ?? null,
                        'icon_gray'   => $details['icongray'] ?? null,
                        'hidden'      => isset($details['hidden']) ? (bool)$details['hidden'] : false,
                    ];
                })->all(); // Convertit la collection en tableau simple

                Log::info("Fin fusion détails. Nombre de résultats finaux: " . count($results));
                return $results; // Retourne le tableau final à mettre en cache

            }); // Fin de Cache::remember

            // Renvoyer les succès (depuis cache ou calcul)
            return response()->json($latestAchievementsWithDetails);
        });

        Route::get('/games/nearly-completed', function (Request $request) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');
            $steamId = $user->steam_id_64;

            if (!$steamId) { /* ... */ }

            $cacheKey = "nearly_completed_games_{$steamId}";
            // Cache un peu plus court, peut-être 6 heures ?
            $cacheDuration = 60 * 60 * 6;

            $nearlyCompletedGames = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {

                Log::info("CACHE MISS: Calcul jeux presque terminés pour {$steamId}");

                // 1. Récupérer tous les jeux (avec nom/info)
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json', 'include_appinfo' => true
                ]);
                if ($gamesResponse->failed()) { return []; }
                $ownedGames = $gamesResponse->json('response.games', []);

                $nearlyCompletedList = [];
                $gameCounter = 0;

                // 2. Boucler sur chaque jeu pour calculer son %
                foreach ($ownedGames as $game) {
                    $appId = $game['appid'];
                    $gameName = $game['name'];
                    $gameCounter++;
                    // Log::info("Calcul % jeu {$gameCounter}/" . count($ownedGames) . " - AppID: {$appId}"); // Optionnel

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

                                if ($total > 0) { // On ne compte que les jeux ayant des succès
                                    foreach ($achievements as $ach) {
                                        if ($ach['achieved'] == 1) {
                                            $unlocked++;
                                        }
                                    }
                                    $percentage = round(($unlocked / $total) * 100);

                                    // Le filtre ! Entre 80% et 99% (inclus)
                                    if ($percentage >= 80 && $percentage < 100) {
                                        $nearlyCompletedList[] = [
                                            'app_id' => $appId,
                                            'name' => $gameName,
                                            'percentage' => $percentage,
                                            'unlocked' => $unlocked,
                                            'total' => $total,
                                            'icon_url' => $game['img_icon_url'] ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$appId}/{$game['img_icon_url']}.jpg" : null,
                                        ];
                                    }
                                }
                            }
                        }
                        usleep(100000); // Pause

                    } catch (\Exception $e) {
                        Log::warning("Erreur API Succès (nearly completed) pour {$appId}/{$steamId}: " . $e->getMessage());
                        usleep(100000);
                    }
                } // Fin foreach

                // 3. Trier par pourcentage décroissant et prendre les 5 premiers
                usort($nearlyCompletedList, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

                Log::info("Fin calcul jeux presque terminés pour {$steamId}. Trouvés : " . count($nearlyCompletedList));
                return array_slice($nearlyCompletedList, 0, 5); // Garde les 5 premiers max

            }); // Fin Cache::remember

            return response()->json($nearlyCompletedGames);
        });

        Route::get('/stats/global-completion', function (Request $request) {
            $user = $request->user();
            $apiKey = env('STEAM_SECRET');
            $steamId = $user->steam_id_64;

            if (!$steamId) {
                return response()->json(['message' => 'Aucun Steam ID associé.'], 404);
            }

            // --- ENVELOPPE DU CACHE ---
            $cacheKey = "global_completion_{$steamId}";
            $cacheDuration = 60 * 60 * 24; // 24 heures

            // On utilise Cache::remember autour de TOUTE la logique de calcul
            $stats = Cache::remember($cacheKey, $cacheDuration, function () use ($apiKey, $steamId) {

                // ==========================================================
                // TON CODE FONCTIONNEL VA EXACTEMENT ICI, À L'INTÉRIEUR
                // ==========================================================
                Log::info("CACHE MISS: Calcul complétion globale pour {$steamId}");

                // 1. Récupérer tous les jeux
                $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                    'key' => $apiKey,
                    'steamid' => $steamId,
                    'format' => 'json',
                    'include_appinfo' => false // Pas besoin ici
                ]);
                if ($gamesResponse->failed()) {
                    Log::error("Échec GetOwnedGames pour {$steamId} lors du calcul cache.");
                    return null; // Important: retourne null si le calcul initial échoue
                }
                $ownedGames = $gamesResponse->json('response.games', []);
                Log::info("Nombre de jeux trouvés (cache calc) : " . count($ownedGames));

                $totalAchievementsPossible = 0;
                $totalAchievementsUnlocked = 0;
                $gameCounter = 0;

                // 2. Boucler sur chaque jeu
                foreach ($ownedGames as $game) {
                    $appId = $game['appid'];
                    $gameCounter++;
                    //Log::info("Traitement jeu {$gameCounter}/" . count($ownedGames) . " - AppID: {$appId}"); // Optionnel

                    try {
                        $achievementsResponse = Http::timeout(15)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                            'appid' => $appId,
                            'key' => $apiKey,
                            'steamid' => $steamId,
                        ]);
                        if ($achievementsResponse->successful()) {
                            $data = $achievementsResponse->json();
                            if (isset($data['playerstats']['success']) && $data['playerstats']['success'] === true && isset($data['playerstats']['achievements'])) {
                                $achievements = $data['playerstats']['achievements'];
                                $totalAchievementsPossible += count($achievements);
                                foreach ($achievements as $ach) {
                                    if ($ach['achieved'] == 1) {
                                        $totalAchievementsUnlocked++;
                                    }
                                }
                            }
                        }
                        usleep(150000);
                    } catch (\Exception $e) {
                        Log::warning("Erreur API Succès pendant calcul global (cache calc) pour {$appId}/{$steamId}: " . $e->getMessage());
                        usleep(150000);
                    }
                } // Fin foreach

                Log::info("Fin calcul (cache calc). Possible: {$totalAchievementsPossible}, Débloqués: {$totalAchievementsUnlocked}");

                // 3. Calcul
                $globalCompletionRate = ($totalAchievementsPossible > 0)
                                        ? round(($totalAchievementsUnlocked / $totalAchievementsPossible) * 100, 2)
                                        : 0;
                Log::info("Calcul terminé (cache calc). Pourcentage: {$globalCompletionRate}%");

                // 4. Renvoyer le tableau de données à mettre en cache
                return [
                    'total_possible' => $totalAchievementsPossible,
                    'total_unlocked' => $totalAchievementsUnlocked,
                    'completion_percentage' => $globalCompletionRate,
                    'calculated_at' => now()->toIso8601String() // Heure du calcul
                ];
                // ==========================================================
                // FIN DE TON CODE FONCTIONNEL
                // ==========================================================

            }); // Fin de Cache::remember

            // Gestion si le calcul a retourné null
            if ($stats === null) {
                return response()->json(['message' => 'Erreur lors du calcul initial des statistiques.'], 500);
            }

            // Renvoyer les stats (cache ou calcul)
            return response()->json($stats);
        });
    });


});

