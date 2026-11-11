<?php

use App\Models\Redirect;
use App\Models\SiteSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * Cars removed from the fleet still have indexed URLs (they failed with 500 on the old site).
 * Send them permanently to the Astana fleet so their search signals are kept; editable in the admin under Redirects.
 */
return new class extends Migration
{
    private const TARGET = '/cars/astana/';

    public function up(): void
    {
        if (! SiteSettings::find(1)) {
            return;
        }
        foreach (json_decode(file_get_contents(resource_path('data/legacy-500-paths.json')), true, 512, JSON_THROW_ON_ERROR) as $path) {
            if (! Redirect::where('old_path', $path)->exists()) {
                Redirect::create(['old_path' => $path, 'new_path' => self::TARGET, 'status_code' => 301, 'active' => true]);
            }
        }
    }

    public function down(): void
    {
        Redirect::whereIn('old_path', json_decode(file_get_contents(resource_path('data/legacy-500-paths.json')), true))->where('new_path', self::TARGET)->delete();
    }
};
