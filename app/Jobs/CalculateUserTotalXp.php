<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\GlobalAchievement;
use App\Models\UserGameScore;
use App\Services\SteamService;
use App\Jobs\SyncGameAchievements;

/**
 * Class CalculateUserTotalXp
 *
 * Job "Dispatcher" (Chef de Chantier).
 *
 * Ce Job est responsable de l'orchestration de la mise à jour globale.
 * Il ne réalise aucun calcul lourd. Son rôle est de récupérer la liste
 * complète des jeux d'un utilisateur et de déléguer le traitement
 * à des workers unitaires (SyncGameAchievements).
 *
 * @package App\Jobs
 */
class CalculateUserTotalXp implements ShouldQueue
{
    use Queueable;

    /**
     * L'instance de l'utilisateur à mettre à jour.
     *
     * @var User
     */
    protected User $user;

    /**
     * Crée une nouvelle instance du Job.
     *
     * @param User $user L'utilisateur cible.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Exécute la logique du Dispatcher.
     *
     * Flux d'exécution :
     * 1. Récupération de la liste des jeux possédés (OwnedGames).
     * 2. Vérification de sécurité (Liste vide ?).
     * 3. Boucle "Fan-Out" : Dispatch d'un Job SyncGameAchievements par jeu.
     *
     * @param SteamService $steamService Service injecté pour la communication API.
     * @return void
     */
    public function handle(SteamService $steamService): void
    {
        Log::channel('steam')->info("------ Démarage Du Job Dispatcher CUTXP -------");
        $games = $steamService->getOwnedGames($this->user->steam_id_64);

        if(empty($games)) {
            Log::channel('steam')->warning("[Dispatcher CUTXP] Erreur Games Empty ( SteamId = {$this->user->steam_id_64} )");
            return ;
        }

        $count = count($games);
        Log::channel('steam')->info("[DISPATCHER CUTXP] {$count} jeux trouvés. Distribution des tâches...");

        foreach ($games as $game) {
            SyncGameAchievements::dispatch($this->user, $game['appid']);
        }

        Log::channel('steam')->info("------ END Job Dispatcher CUTXP ------");
    }
}
