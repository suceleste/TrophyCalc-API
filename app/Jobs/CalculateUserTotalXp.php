<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\GlobalAchievement;
use App\Models\UserGameScore;
use Illuminate\Support\Facades\DB;

class CalculateUserTotalXp implements ShouldQueue
{
    use Queueable;

    protected $user;

    public $timeout = 7200;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $apiKey = env('STEAM_SECRET');
        $steamId = $this->user->steam_id_64;

        Log::info("[JOB START] Calculate Xp to {$steamId}");

        $ownedGamesresponse = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'key' => $apiKey, 'steamid' => $steamId
        ]);
        if ($ownedGamesresponse->failed()){ Log::error("[JOB ERROR] Failed OwnedGames Response API : Game {$steamId}"); return;}
        $ownedGames = $ownedGamesresponse->json('response.games');

        $totalXp = 0;
        $totalCompleted = 0;
        foreach ($ownedGames as $index => $game)
        {
            $appId = $game['appid'];

            $scoreMemory = UserGameScore::where('user_id', $this->user->id)->where('app_id', $appId)->first();
            if( $scoreMemory && $scoreMemory->is_completed )
            {
                $totalXp += $scoreMemory->xp_score;
                $totalCompleted ++;

                Log::info("[JOB] Skip Game {$appId}");

                continue;
            }

            $achievementsresponse = Http::timeout(15)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                    'appid' => $appId, 'key' => $apiKey, 'steamid' => $steamId
                ]);
            if ($achievementsresponse->failed()){ Log::error("[JOB ERROR] Failed Achievement Response API : Game {$appId}"); continue;}
            $achievements = $achievementsresponse->json('playerstats.achievements');
            if (empty($achievements)) { Log::info("[JOB] Pas de succès trouvés pour {$appId}, on saute."); continue; }

            $newTotalCount = count($achievements);
            $newUnlockedCount = 0 ;
            foreach($achievements as $trophy) { if($trophy['achieved']){ $newUnlockedCount += 1; } }
            if($scoreMemory && $scoreMemory->unlocked_count == $newUnlockedCount && $scoreMemory->total_count == $newTotalCount)
            {
                $totalXp += $scoreMemory->xp_score;

                Log::info("[JOB] No Changed Game {$appId}");

                continue;
            }

            $unlockedApiNames = [];
            foreach($achievements as $trophy) { if ($trophy['achieved']) { $unlockedApiNames[] = $trophy['apiname']; } }

            Log::debug("[JOB DEBUG] Prêt pour le SUM()", [
                'appId' => $appId,
                'count_unlocked' => count($unlockedApiNames),
                'names' => $unlockedApiNames // Affiche les noms des succès
            ]);

            $gameXp = GlobalAchievement::where('app_id', $appId)->whereIn('api_name', $unlockedApiNames)->sum('xp_value');
            if ($gameXp > 0 ) {
                $isCompleted = ($newUnlockedCount == $newTotalCount);
                if($isCompleted) { $gameXp += 1000; $totalCompleted ++;}

                $totalXp += $gameXp;

                DB::table('user_game_scores')->updateOrInsert(
                    // 1er paramètre : La "Clé de Recherche" (ton tableau composite)
                    [
                        'user_id' => $this->user->id,
                        'app_id'  => $appId
                    ],

                    // 2ème paramètre : Les "Nouvelles Données" à écrire
                    [
                        'xp_score'       => $gameXp,
                        'is_completed'   => $isCompleted,
                        'unlocked_count' => $newUnlockedCount,
                        'total_count'    => $newTotalCount,
                        'updated_at'     => now(), // On doit le mettre manuellement
                        'created_at'     => now()  // On le met aussi (pour la première fois)
                    ]
                );
            }

            if ($index > 0 && $index % 20 === 0)
            {
                $this->user->update(
                [
                    'total_xp' => $totalXp,
                    'games_completed' => $totalCompleted,
                ]);
            }
            usleep(300000);
        }

        $this->user->update(
                [
                    'total_xp' => $totalXp,
                    'games_completed' => $totalCompleted,
                ]);

        $cacheKey = "user_xp_stats_{$this->user->steam_id_64}";
        Cache::put($cacheKey, [
            'total_xp' => $totalXp,
            'games_completed' => $totalCompleted,
            'calculated_at' => now()->toIso8601String()
        ], now()->addHours(6)); // Cache de 6h

    }
}
