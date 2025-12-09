<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;
use App\Models\User;
use Illuminate\Support\Facades\DB;


class GenerateSitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitemap:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genère le sitemap.xml pour le SEO';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Génération du sitemap en cours...');

        $sitemap = Sitemap::create();

        $sitemap->add(
            Url::create("https://www.trophycalc.com")
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_YEARLY)
                ->setPriority(1.0)
        );
        $sitemap->add(
            Url::create("https://www.trophycalc.com/leaderboard")
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_YEARLY)
                ->setPriority(0.9)
        );
        $sitemap->add(
            Url::create("https://www.trophycalc.com/search")
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_YEARLY)
                ->setPriority(0.8)
        );
        $sitemap->add(
            Url::create("https://www.trophycalc.com/legal")
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_YEARLY)
                ->setPriority(0)
        );

        $users = User::whereNotNull('steam_id_64')->get();
        foreach ($users as $user){
            $sitemap->add(
            Url::create("https://www.trophycalc.com/profile/{$user->steam_id_64}")
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(0.6)
            );
        }

        $path = public_path('sitemap.xml');
        $sitemap->writeToFile($path);
    }
}
