<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// On crée un groupe de routes qui seront préfixées par 'api'
Route::middleware('api')->group(function () {

    // Notre route de statut
    Route::get('/status', function () {
        return response()->json([
            'status' => 'API is running'
        ]);
    });

    // C'est ici que nous ajouterons nos futures routes API

});
