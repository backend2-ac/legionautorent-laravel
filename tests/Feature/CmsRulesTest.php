<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarDiscount;
use App\Models\CarImage;
use App\Models\Redirect;
use App\Models\SiteSection;
use App\Models\Translation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\CatalogueTestCase;

class CmsRulesTest extends CatalogueTestCase
{
    public function test_imported_production_canonicals_can_be_saved_on_staging(): void
    {
        config(['legion.site_url' => 'https://legionautorent.113.kz']);
        $car = Car::public()->first();

        $car->update(['canonical_url' => 'https://legionautorent.kz'.$car->legacy_path, 'seo_title' => 'Проверка сохранения']);
        $car->update(['canonical_url' => 'https://legionautorent.113.kz'.$car->legacy_path]);
        $this->assertSame('Проверка сохранения', $car->fresh()->seo_title);

        foreach (['https://example.com'.$car->legacy_path, 'https://legionautorent.kz/another-page/', 'https://legionautorent.kz'.$car->legacy_path.'?utm=1'] as $foreign) {
            try {
                $car->update(['canonical_url' => $foreign]);
                $this->fail('Foreign canonical was accepted: '.$foreign);
            } catch (ValidationException) {
                $this->assertNotSame($foreign, $car->fresh()->canonical_url);
            }
        }
    }

    public function test_a_second_main_photo_is_rejected(): void
    {
        $photo = CarImage::where('is_main', false)->first();
        $this->expectException(ValidationException::class);
        $photo->is_main = true;
        $photo->save();
    }

    public function test_overlapping_discounts_are_rejected(): void
    {
        $existing = CarDiscount::first();
        $this->expectException(ValidationException::class);
        CarDiscount::create(['car_id' => $existing->car_id, 'min_days' => $existing->min_days, 'max_days' => $existing->max_days, 'percent' => 5]);
    }

    public function test_incomplete_published_translation_is_rejected(): void
    {
        $car = Car::first();
        $this->expectException(ValidationException::class);
        Translation::create(['content_type_id' => $car->contentTypeId(), 'object_id' => $car->id, 'language' => 'kk', 'published' => true, 'name' => 'Only name']);
    }

    public function test_redirect_cannot_replace_a_working_url(): void
    {
        $this->expectException(ValidationException::class);
        Redirect::create(['old_path' => '/kostanay/', 'new_path' => '/', 'active' => true, 'status_code' => 301]);
    }

    public function test_mandatory_catalogue_section_cannot_be_disabled(): void
    {
        $section = SiteSection::where('key', 'fleet')->first();
        $this->expectException(ValidationException::class);
        $section->active = false;
        $section->save();
    }

    public function test_legacy_password_hashes_continue_to_authenticate(): void
    {
        $password = 'test-password-only';
        $salt = 'test-salt';
        $rounds = 10000;
        $hash = 'pbkdf2_sha256$'.$rounds.'$'.$salt.'$'.base64_encode(hash_pbkdf2('sha256', $password, $salt, $rounds, 0, true));
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::check('wrong', $hash));
        $this->assertTrue(Hash::needsRehash($hash));
    }
}
