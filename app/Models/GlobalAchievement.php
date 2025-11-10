<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalAchievement extends Model
{
    protected $primaryKey = ['app_id', 'api_name'];

    protected $fillable = [
        'app_id',
        'api_name',
        'global_percent',
        'xp_value'
    ];
}
