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

class CalculateNearlyCompletedGames implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;

    /**
     * Crée une nouvelle instance du Job.
     * @param \App\Models\User $user L'utilisateur pour lequel calculer les jeux.
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
        $cacheKey = "nearly_completed_games_{$steamId}";
        $cacheDuration = 60 * 60 * 6; // 6 heures

        Log::info("[JOB] Démarrage: Calcul jeux presque terminés pour {$steamId}");

        try {
            // 1. Récupérer tous les jeux (avec nom/info)
            $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
                'key' => $apiKey, 'steamid' => $steamId, 'format' => 'json', 'include_appinfo' => true
            ]);
            if ($gamesResponse->failed()) {
                Log::error("[JOB] Échec GetOwnedGames (NearlyCompleted) pour {$steamId}");
                return; // Arrête le job
            }
            $ownedGames = $gamesResponse->json('response.games', []);
            $nearlyCompletedList = [];

            // 2. Boucler sur chaque jeu pour calculer son %
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

                                // Le filtre ! Entre 80% et 99% (inclus)
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
                    usleep(100000); // Pause
                } catch (\Exception $e) { usleep(100000); }
            } // Fin foreach

            // 3. Trier par pourcentage décroissant et prendre les 5 premiers
            usort($nearlyCompletedList, fn($a, $b) => $b['percentage'] <=> $a['percentage']);
            $results = array_slice($nearlyCompletedList, 0, 5);

            // 4. Mettre le résultat final en cache
            Cache::put($cacheKey, $results, $cacheDuration);
            Log::info("[JOB] Terminé: Jeux presque terminés pour {$steamId} mis en cache.");

        } catch (\Exception $e) {
            Log::error("[JOB] Erreur Critique (NearlyCompleted) pour {$steamId}: " . $e->getMessage());
        }
    }
}
