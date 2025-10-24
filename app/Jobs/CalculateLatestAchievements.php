<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CalculateLatestAchievements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;

    /**
     * Crée une nouvelle instance du Job.
     * @param \App\Models\User $user L'utilisateur pour lequel calculer les succès.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Exécute le Job (tâche de fond).
     */
    public function handle(): void
    {
        $apiKey = env('STEAM_SECRET');
        $steamId = $this->user->steam_id_64;
        $cacheKey = "latest_achievements_details_{$steamId}";
        $cacheDuration = 60 * 60; // 1 heure

        Log::info("[JOB] Démarrage: Calcul derniers succès pour {$steamId}");

        try {
            // 1. Récupérer tous les jeux possédés
            $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json', 'include_appinfo' => true
            ]);
            if ($gamesResponse->failed()) {
                Log::error("[JOB] Échec GetOwnedGames pour {$steamId}");
                return; // Arrête le job
            }
            $ownedGames = $gamesResponse->json('response.games', []);
            $allUnlocked = [];

            // 2. Boucler pour récupérer les succès débloqués
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
                    usleep(100000); // Pause
                } catch (\Exception $e) { usleep(100000); }
            }

            // 3. Trier et garder les 5 plus récents
            usort($allUnlocked, fn($a, $b) => $b['unlock_time'] <=> $a['unlock_time']);
            $latest = array_slice($allUnlocked, 0, 5);

            // 4. Récupérer les détails pour ces 5 succès
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

            // 5. Fusionner les détails
            $results = collect($latest)->map(function ($ach) use ($details) {
                $detailsCollection = $details[$ach['app_id']] ?? collect([]);
                $achDetails = $detailsCollection->get($ach['api_name']);
                return [
                    'app_id'      => $ach['app_id'], 'game_name'   => $ach['game_name'],
                    'api_name'    => $ach['api_name'], 'unlock_time' => $ach['unlock_time'],
                    'name'        => $achDetails['displayName'] ?? $ach['api_name'],
                    'description' => $achDetails['description'] ?? null,
                    'icon'        => $achDetails['icon'] ?? null,
                    'icon_gray'   => $achDetails['icongray'] ?? null,
                    'hidden'      => isset($achDetails['hidden']) ? (bool)$achDetails['hidden'] : false,
                ];
            })->all();

            // 6. Mettre le résultat final en cache
            Cache::put($cacheKey, $results, $cacheDuration);
            Log::info("[JOB] Terminé: Derniers succès pour {$steamId} mis en cache.");

        } catch (\Exception $e) {
            Log::error("[JOB] Erreur Critique (LatestAchievements) pour {$steamId}: " . $e->getMessage());
        }
    }
}
