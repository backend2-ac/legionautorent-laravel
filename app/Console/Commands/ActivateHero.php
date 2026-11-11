<?php

namespace App\Console\Commands;

use App\Models\SiteSettings;
use Illuminate\Console\Command;

class ActivateHero extends Command
{
    protected $signature = 'hero:activate {master : Public path of the approved master, e.g. /media/hero-ac640dad1c/master.mp4}';

    protected $description = 'Switch the site banner to an uploaded frame sequence without opening the admin';

    public function handle(): int
    {
        $master = (string) $this->argument('master');
        if (! preg_match('#^/(static|media)/[a-zA-Z0-9_./-]+\.mp4$#', $master) || str_contains($master, '..')) {
            $this->error('Use a public path such as /media/hero-xxxx/master.mp4.');

            return self::FAILURE;
        }
        $manifestPath = dirname(public_path(ltrim($master, '/'))).'/sequence.json';
        if (! is_file(public_path(ltrim($master, '/'))) || ! is_file($manifestPath)) {
            $this->error('The master video or its sequence.json is missing on this server.');

            return self::FAILURE;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['master'] ?? null) !== $master) {
            $this->error('sequence.json belongs to a different master.');

            return self::FAILURE;
        }
        foreach (['desktop', 'mobile'] as $variant) {
            foreach ($manifest[$variant]['packs'] ?? [] as $pack) {
                if (! is_file(public_path(ltrim($pack['url'], '/')))) {
                    $this->error('Missing frame pack: '.$pack['url']);

                    return self::FAILURE;
                }
            }
        }

        $site = SiteSettings::findOrFail(1);
        $previous = $site->hero_video_path;
        $site->forceFill(['enable_hero_video' => true, 'hero_video_path' => $master, 'hero_video_fps' => (int) $manifest['fps']])->save();
        $this->info('Banner switched from '.($previous ?: '—').' to '.$master.'.');

        return self::SUCCESS;
    }
}
