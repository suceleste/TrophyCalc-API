<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use GuzzleHttp\Client;


class SteamService
{

    /**
     * Valide L'authenticité de la connexion via OpenId.
     *
     * Steam renvoie les paramètres en POST. Nous devons vérifier leur signature
     * en renvoyant une requête de validation 'check_authentification' à Steam.
     *
     * @param Request $params La requête entrante contenant les paramètres OpenId.
     * @return string|false Retourne le SteamID64 si valide, sinon false.
     */
    public function validateLogin(Request $params)
    {
        // On convertit les tirets (_) de PHP en points (.)
        // car l'API OpenId de Steam utilise des points (ex:openid.mode)
        $raw_params = $params->all();
        $params_for_steam = [];

        foreach ($raw_params as $key => $value) {
            $params_for_steam[str_replace('_', '.', $key)] = $value;
        }
        $params_for_steam['openid.mode'] = 'check_authentication';

        // On utilise Guzzle directement car Http facade a parfois du mal
        // avec les vieux formats form-params de OpenID 2.0.
        $client = new Client();
        $response = $client->post('https://steamcommunity.com/openid/login', [
            'form_params' => $params_for_steam
        ]);
        $response_body = (string)$response->getBody();

        if(str_contains($response_body, 'is_valid:true')){
            // Le claimed_id ressemble à : https://steamcommunity.com/openid/id/76561198000000000
            // On extrait l
            return basename($params->input('openid_claimed_id'));
        }

        return false;
    }

    /**
     * Récupère les informations publiques du profil Steam.
     *
     * @param string $steamId64 L'identifiant unique Steam (17 chiffres).
     * @return array|null Un tableau associatif des données (personaname, avatar...) ou null si échec.
     */
    public function getUserProfile(string $steamId64)
    {
        $profile_response = Http::get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
            'key' => env('STEAM_SECRET'), // @todo: Déplacer dans config/services.php
            'steamids' => $steamId64
        ]);

        if ($profile_response->failed() || empty($profile_response->json('response.players'))) {
            return null;
        }

        $players = $profile_response->json('response.players');

        // L'API renvoie un tableau vide si l'ID n'existe pas, même avec un status 200.
        return $players[0] ?? null;
    }

    /**
     * Récupère la liste de jeux complète de l'utilisateur
     *
     * @param string $steamId64 L'identifiant unique Steam.
     * @return array<int, array> Un tableau complet des jeux du joueur ou un tableau vide [] si échec
     */
    public function getOwnedGames(string $steamId64): array
    {
        $response = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'key' => env('STEAM_SECRET'),
            'steamid' => $steamId64,
            'include_played_free_games' => 1,
            'format' =>'json'
        ]);

        if($response->failed()){
            Log::error("[SteamService] Steam API Error (GetOwnedGames): " . $response->body());
            return [];
        }

        Log::channel('steam')->info("[SteamService] Jeux Récupérer ( SteamId = {$steamId64} )");
        return $response->json('response.games') ?? [];
    }


    /**
     * Récupère la liste des succès d'un jeux spécifique pour un joueur
     *
     * @param string $steamId64 L'identifiant unique Steam
     * @param string $appId L'identifiant unique du jeu Steam
     * @return array<int, array>|null Un tableau des succès d'un jeu spécifique pour le joueur ou null si échec
     */
    public function getPlayerAchievements(string $steamId64, string $appId): ?array
    {
        $response = Http::timeout(10)->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
            'key' => env('STEAM_SECRET'),
            'steamid' => $steamId64,
            'appid' => $appId,
            'l' => 'french'
        ]);

        if ($response->failed()){
            Log::channel('steam')->warning("[SteamService] Response Failed : " . $response->body());
            return null;
        }

        Log::channel('steam')->info("[SteamService] Réponse d'achivements Validé (appId = {$appId}, steamId =  {$steamId64})");
        return $response->json('playerstats.achievements');
    }
}
