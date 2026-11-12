<?php

use App\Models\MenuLink;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Services\PublicContent;
use Illuminate\Database\Migrations\Migration;

/**
 * Put the partners page in the header menu (before Contacts) and in the mobile menu; editable in the admin under Menu.
 */
return new class extends Migration
{
    private const PATH = '/partners/';

    /**
     * @var array<string, int>
     */
    private const SORT_ORDER = ['main' => 15, 'mobile' => 25];

    public function up(): void
    {
        if (! SiteSettings::find(1) || ! Page::where('path', self::PATH)->exists()) {
            return;
        }
        foreach (self::SORT_ORDER as $area => $sortOrder) {
            if (! MenuLink::where('area', $area)->where('path', self::PATH)->exists()) {
                MenuLink::create(['area' => $area, 'label' => 'Партнёрам', 'label_kk' => 'Серіктестерге', 'label_en' => 'Partners', 'path' => self::PATH, 'sort_order' => $sortOrder, 'active' => true]);
            }
        }
        PublicContent::forget();
    }

    public function down(): void
    {
        MenuLink::where('path', self::PATH)->delete();
        PublicContent::forget();
    }
};
