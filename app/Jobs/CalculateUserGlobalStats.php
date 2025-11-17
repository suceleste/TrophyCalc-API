<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\User; // <-- Importe le modèle User
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CalculateUserGlobalStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Notre Job aura besoin de savoir pour qui il travaille
    protected $user;

    /**
     * Crée une nouvelle instance du Job.
     * On "injecte" l'utilisateur pour lequel on doit travailler.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Exécute le Job. C'est ici que se passe tout le travail !
     * C'est ce que "l'ouvrier" va faire en arrière-plan.
     */
    public function handle(): void
    {
        // On récupère les infos (exactement comme dans l'ancienne route)
        $apiKey = env('STEAM_SECRET');
        $steamId = $this->user->steam_id_64;

        Log::info("JOB DÉMARRÉ: Calcul complétion globale pour {$steamId}");

        // --- 1. Récupérer les jeux ---
        $gamesResponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'key' => $apiKey, 'steamid' => $steamId, 'include_played_free_games' => 1, 'format' => 'json'
        ]);
        if ($gamesResponse->failed()) {
             Log::error("JOB ÉCHEC: Échec GetOwnedGames pour {$steamId}.");
             return; // Arrête le job
        }
        $ownedGames = $gamesResponse->json('response.games', []);
        Log::info("JOB INFO: Nombre de jeux trouvés pour {$steamId} : " . count($ownedGames));

        $totalAchievementsPossible = 0;
        $totalAchievementsUnlocked = 0;
        $gameCounter = 0;

        // --- 2. Boucler sur chaque jeu ---
        foreach ($ownedGames as $game) {
            $appId = $game['appid'];
            $gameCounter++;
            Log::info("JOB INFO: Traitement jeu {$gameCounter}/" . count($ownedGames) . " - AppID: {$appId} pour {$steamId}");

            try {
                $achievementsResponse = Http::timeout(15)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                    'appid' => $appId, 'key' => $apiKey, 'steamid' => $steamId,
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
                usleep(150000); // Garde la pause
            } catch (\Exception $e) {
                 Log::warning("JOB ERREUR: Erreur API Succès pour {$appId}/{$steamId}: " . $e->getMessage());
                 usleep(150000);
            }
        } // Fin foreach

        Log::info("JOB INFO: Fin boucle jeux {$steamId}. Possible: {$totalAchievementsPossible}, Débloqués: {$totalAchievementsUnlocked}");

        // --- 3. Calculer ---
        $globalCompletionRate = ($totalAchievementsPossible > 0)
                                ? round(($totalAchievementsUnlocked / $totalAchievementsPossible) * 100, 2)
                                : 0;
        Log::info("JOB INFO: Calcul terminé {$steamId}. Pourcentage: {$globalCompletionRate}%");

        // --- 4. Sauvegarder le résultat dans le Cache ---
        // (On utilise le même cache que l'API lisait)
        $cacheKey = "global_completion_{$steamId}";
        $cacheDuration = 60 * 60 * 24; // 24h
        Cache::put($cacheKey, [
            'total_possible' => $totalAchievementsPossible,
            'total_unlocked' => $totalAchievementsUnlocked,
            'completion_percentage' => $globalCompletionRate,
            'calculated_at' => now()->toIso8601String()
        ], $cacheDuration);

        Log::info("JOB TERMINÉ: Stats pour {$steamId} sauvegardées dans le cache.");
    }
}
