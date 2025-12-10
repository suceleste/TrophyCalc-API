<?php

namespace App\Jobs;

use App\Models\GlobalAchievement;
use App\Models\User;
use App\Models\UserGameScore;
use App\Services\SteamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;


/**
 * Class CalculateUserTotalXp
 *
 * Job "worker".
 *
 * Ce Job Calcule l'xp pour le jeu avec l'uitlisateur fournit
 * et enregistre tout ça dans la bdd
 *
 * @package App\Jobs
 */
class SyncGameAchievements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * L'instance de l'utilisateur à mettre à jour.
     *
     * @var User
     * @var int
     */
    protected User $user;
    protected int $appId;

    /**
     * Crée une nouvelle instance du Job.
     *
     * @param User $user L'utilisateur cible.
     * @param int $appId Le jeu cible
     */
    public function __construct(User $user, int $appId){
        $this->user = $user;
        $this->appId = $appId;
    }

    /**
     * Exécute le calcul de L'XP.
     *
     * Flux d'exécution :
     * 1. Récupération de la liste des succès possédés.
     * 2. Vérification de sécurité (Liste vide ?).
     * 3. Sum de l'xp_value de chaque succès du jeu
     * 4. Enregistre dans la BDD
     *
     * @param SteamService $steamService Service injecté pour la communication API.
     * @return void
     */
    public function handle(SteamService $steamService): void
    {
        Log::channel('steam')->info('-- Démarrage Du Job SyncGA --');

        $Xp = 0;
        $achievements = $steamService->getPlayerAchievements($this->user->steam_id_64, $this->appId);

        if(empty($achievements)){
            Log::channel('steam')->warning("[SyncGA] Erreur GameAchievements empty : " . $this->appId);
            return ;
        }

        $scoreMemory = UserGameScore::where('user_id', $this->user->id)
            ->where('app_id', $this->appId)
            ->first();

        $totalCountApi = count($achievements);
        if( $scoreMemory && $scoreMemory->is_completed && $scoreMemory->total_count === $totalCountApi )
        {
            Log::channel('steam')->info('[SyncGA] Calcul XP Inchangé ( appID = ' . $this->appId . ', SteamId = ' . $this->user->steam_id_64 . ') : ' . $scoreMemory->xp_score . "XP");
            return ;
        }

        $collection = collect($achievements);
        $unlocked = $collection->where('achieved', 1);
        $unlockedCount = $unlocked->count();


        if ($unlockedCount > 0) {
            $unlockedApiName = $unlocked->pluck('apiname')->toArray();

            $Xp = GlobalAchievement::where('app_id', $this->appId)
                ->whereIn('api_name', $unlockedApiName)
                ->sum('xp_value');
            $Xp += ($unlockedCount === $totalCountApi && $totalCountApi > 0) ? 1000 : 0;
            Log::channel('steam')->info('[SyncGA] Calcul XP ( appID = ' . $this->appId . ', SteamId = ' . $this->user->steam_id_64 . ') : ' . $Xp . "XP");
        }

        UserGameScore::updateOrCreate([
            'user_id' => $this->user->id,
            'app_id' => $this->appId
        ],
        [
            'xp_score' => $Xp,
            'unlocked_count' => $unlockedCount,
            'total_count' => $totalCountApi,
            'is_completed' => ($unlockedCount === $totalCountApi && $totalCountApi > 0) ? true : false,
            'updated_at' => now()
        ]);

        Log::channel('steam')->info("[SyncGA] XP Sauvegardé ( appId = {$this->appId}, SteamId = {$this->user->steam_id_64} ) : {$Xp} XP");

        $totalXp = UserGameScore::where('user_id', $this->user->id)
                                ->sum('xp_score');

        $gamesCompleted = UserGameScore::where('user_id', $this->user->id)
                                        ->where('is_completed', true)
                                        ->count();

        $this->user->update([
            'total_xp' => $totalXp,
            'games_completed' => $gamesCompleted,
            'profile_updated_at' => now()
        ]);

        Log::channel('steam')->info("[SyncGA] Total User ( SteamId = {$this->user->steam_id_64} ) Mise a Jour : {$totalXp} XP / {$gamesCompleted} GC");

    }
}
