<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGameScore extends Model
{
    protected $primaryKey = ['user_id', 'app_id'];

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'app_id',
        'xp_score',
        'is_completed',
        'unlocked_count',
        'total_count'
    ];
}
