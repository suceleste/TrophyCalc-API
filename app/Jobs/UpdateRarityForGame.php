<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Traits\CalculatesXp;
use App\Models\GlobalAchievement;

class UpdateRarityForGame implements ShouldQueue
{
    use Queueable;
    use CalculatesXp;

    protected $app_id;

    /**
     * Create a new job instance.
     */
    public function __construct(string $app_id)
    {
        $this->app_id = $app_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[JOB START] Calculate For This Game : {$this->app_id}');
        try{

            $response = Http::get('https://api.steampowered.com/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v0002/',
            [
                'gameid' => $this->app_id,
                'format' => 'json'
            ]);

            if ($response->failed()){ Log::error('[JOB ERROR] Response Failed'); return; }
            if (empty($response->json())){ Log::error('[JOB ERROR] Response Empty'); return; }

            $achievements = $response->json('achievementpercentages.achievements');
            $dataToUpsert = [];
            foreach ($achievements as $ach){

                $percent = $ach['percent'];
                $xpValue = $this->getXpFromRarity($percent);

                $dataToUpsert[] = [
                    'app_id'            => $this->app_id,
                    'api_name'          => $ach['name'],
                    'global_percent'    => $percent,
                    'xp_value'          => $xpValue,
                    'created_at'        => now(),
                    'updated_at'        => now()
                ];
            }

            if (!empty($dataToUpsert)){

                GlobalAchievement::upsert(
                    $dataToUpsert,
                    ['app_id', 'api_name'],
                    ['global_percent', 'xp_value', 'updated_at']
                );
            }

            Log::info('[JOB END] Game {$this->app_id} Is Save');

        }catch (\Exception $e){
            Log::error('[JOB ERROR] Function Not Launch : {$e}');
            return;
        }
    }
}
