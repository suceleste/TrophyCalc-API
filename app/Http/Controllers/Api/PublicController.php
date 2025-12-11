<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SteamService;
use Illuminate\Http\Request;
use App\Models\User;

class PublicController extends Controller
{

    /**
     * Recherhce de Jeux depuis l'API Steam
     *
     * @param Request $request la requête contenant le terme 'q'
     * @param SteamService $steamService Service dédié a Steam
     * @return Illuminate\Http\JsonResponse La réponse json des 10 premiers résultats
     */
    public function searchGames(Request $request, SteamService $steamService)
    {
        $query = $request->input('q');
        if(!$query || strlen($query) < 3){ return response()->json(['message' => "Recherche Trop Courte (min. 3 caractères)"], 400); }

        $games = $steamService->searchStoreGames($query);

        $formattedGames = collect($games)
                ->where('type', 'app')
                ->map(function ($game) {
                    return [
                        'appid' => $game['id'],
                        'name' => $game['name'],
                        'header_image' => $game['tiny_image']
                    ];
                })->take(10);

        return response()->json($formattedGames);
    }

    /**
     * Recherche d'utilisateur depuis la bdd
     *
     * @param Request $request La requête contenant le terme 'q'
     * @return Illuminate\Http\JsonResponse La réponse json des 10 premiers résultats
     */
    public function searchUsers(Request $request)
    {
        $query = $request->input('q');
        if(!$query || strlen($query) < 3){ return response()->json(['message' => "Recherche Trop Courte (min. 3 caractères)"], 400); }

        $users = User::where('name', 'LIKE', "%{$query}%")
                ->select('id', 'name', 'avatar', 'steam_id_64')
                ->whereNotNull('steam_id_64')
                ->take(10)
                ->get();

        return response()->json($users);
    }

    /**
     * Retourne la liste des succès steam d'un jeu specifique
     *
     * @param int $appId L'id unique specifique du jeu
     * @param SteamService $steamService Service dédiée a Steam
     * @return Illuminate\Http\JsonResponse La réponse json des succès du jeu
     */
    public function showGameAchievements(int $appId, SteamService $steamService)
    {
        $gameAchievements = $steamService->getGameAchievements($appId);

        $formattedGameAch = collect($gameAchievements["achievements"])
                            ->map(function ($ach) {
                                return [
                                    "apiName" => $ach["name"],
                                    "name" => $ach["displayName"] ?? $ach["name"],
                                    "desc" => $ach["description"] ?? "",
                                    "icon" => $ach["icon"] ?? null,
                                    "iconGray" => $ach["icongray"] ?? null,
                                    "hide" => $ach["hidden"] ?? 0
                                ];
                            });

        $gameAchievements["achievements"] = $formattedGameAch;

        return response()->json($gameAchievements);
    }

    /**
     * Retourne le profil d'un utilisateur
     *
     * @param string $steam_id_64 L'identifiant Steam unique
     * @return Illuminate\Http\JsonResponse La réponse json du profil du joueur
     */
    public function showProfile(string $steam_id_64)
    {
        $user = User::where('steam_id_64', $steam_id_64)
            ->select('id', 'name', 'steam_id_64', 'avatar', 'profile_url', 'total_xp', 'games_completed', 'created_at')
            ->firstOrFail();

        $rank = User::where('total_xp', '>', $user->total_xp)->count() + 1;

        return response()->json([
            "user" => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'steam_id_64' => $user->steam_id_64,
                'profile_url' => $user->profile_url,
                'total_xp' => $user->total_xp,
                'games_completed' => $user->games_completed,
                'rank' => $rank,
                'created_at' => $user->created_at->translatedFormat('d/F/Y')
            ]
        ]);
    }
}
