<?php

use App\Models\ContentBlock;
use App\Models\SiteSettings;
use App\Services\PublicContent;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the Instagram section with reels from @legionautorent.kz, only while no video has been added in the admin.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const REELS = [
        ['LEGIONAUTORENT: 24/7 и бесплатная подача', 'https://www.instagram.com/reel/C24YIDkNRTJ/'],
        ['Lexus LX 570 Superior', 'https://www.instagram.com/reel/Csa425fOTTv/'],
        ['Brabus 700 S63 AMG', 'https://www.instagram.com/reel/De7aiHbuvxS/'],
        ['Chevrolet Tahoe RST', 'https://www.instagram.com/reel/DeRSDjSu_Dh/'],
        ['Toyota Camry XV80 Luxe Safety', 'https://www.instagram.com/reel/DdTR3PrOOfU/'],
        ['Hyundai Sonata N-Line', 'https://www.instagram.com/reel/DdyfMJBOzWE/'],
    ];

    public function up(): void
    {
        if (! SiteSettings::find(1) || ContentBlock::where('kind', 'reel')->exists()) {
            return;
        }
        foreach (self::REELS as $position => [$title, $url]) {
            ContentBlock::create(['kind' => 'reel', 'title' => $title, 'text' => $url, 'active' => true, 'sort_order' => $position + 1]);
        }
        PublicContent::forget();
    }

    public function down(): void
    {
        ContentBlock::where('kind', 'reel')->whereIn('text', array_column(self::REELS, 1))->get()->each->delete();
    }
};
