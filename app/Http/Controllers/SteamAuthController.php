<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use App\Models\User;
use App\Services\SteamService;

class SteamAuthController extends Controller
{

    public function redirect (){
        $params = [
                'openid.ns'         => 'http://specs.openid.net/auth/2.0',
                'openid.mode'       => 'checkid_setup',
                'openid.return_to'  => route('auth.steam.callback'), // URL de retour (notre route ci-dessous)
                'openid.realm'      => config('app.url'), // L'adresse de notre site
                'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
            ];
        $steam_login_url = 'https://steamcommunity.com/openid/login?' . http_build_query($params);

        return Redirect::to($steam_login_url);
    }

    public function callback (Request $request, SteamService $steamService) {
        try {

            $steam_id_64 = $steamService->validateLogin($request);
            if(!$steam_id_64) {
                Log::channel('steam')->warning("Echec validation Steam", ["params" => $request->all()]);
                return Redirect::to(env('FRONTEND_URL', 'https://localhost:5173'));
            }

            $player = $steamService->getUserProfile($steam_id_64);
            if(!$player){
                Log::channel('steam')->error("Echec recuperation profil Steam pour {$steam_id_64}");
                return Redirect::to(env('FRONTEND_URL') . '/login-failed?error=profile_not_found');
            }

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

            return Redirect::to($frontend_callback_url . '?token=' . $token);

        } catch (\Exception $e) {
            Log::channel('steam')->error("Erreur critique pendant callback Steam: " . $e->getMessage());
            return Redirect::to(env('FRONTEND_URL', 'http://localhost:5173') . '/login-failed?error=critical_error');
        }
    }
}
