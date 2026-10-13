<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Resources\CarBrandResource;
use App\Filament\Resources\CarCategoryResource;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\CityResource;
use App\Filament\Resources\Pages\EditCar;
use App\Filament\Resources\SiteSettingsResource;
use App\Models\BookingRequest;
use App\Models\Car;
use App\Models\CarImage;
use App\Models\City;
use App\Models\Group;
use App\Models\SiteSettings;
use App\Models\User;
use App\Services\PublicContent;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\CatalogueTestCase;

class AdminFeaturesTest extends CatalogueTestCase
{
    public function test_owner_can_open_all_cms_sections_and_car_relations(): void
    {
        $this->actingAs($this->owner());
        foreach (File::files(app_path('Filament/Resources')) as $file) {
            $class = 'App\\Filament\\Resources\\'.$file->getBasename('.php');
            if ($class === 'App\\Filament\\Resources\\CmsResource') {
                continue;
            }$this->get($class::getUrl('index'))->assertOk();
        }$this->get(CarResource::getUrl('edit', ['record' => Car::first()]))->assertOk();
        $this->get(SiteSettingsResource::getUrl('edit', ['record' => 1]))->assertOk();
    }

    public function test_manager_can_process_leads_and_cannot_edit_site_or_users(): void
    {
        $manager = User::create(['username' => 'test-manager', 'password' => Hash::make('testing-password'), 'is_staff' => true, 'is_active' => true]);
        $group = Group::where('name', 'Менеджер')->first() ?? Group::whereHas('permissions', fn ($q) => $q->where('codename', 'change_bookingrequest'))->first();
        $this->assertNotNull($group);
        $manager->groups()->attach($group);
        $this->actingAs($manager);
        $this->get('/admin/leads')->assertOk();
        $this->get('/admin/site-settings')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_cms_saves_are_audited_without_passwords(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $car = Car::first();
        $car->description .= ' Test change.';
        $car->save();
        $this->assertDatabaseHas('django_admin_log', ['user_id' => $owner->id, 'object_id' => (string) $car->id, 'action_flag' => 2]);
    }

    public function test_filament_login_accepts_and_rehashes_a_django_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $password = 'test-password-only';
        $hash = 'pbkdf2_sha256$10000$test-salt$'.base64_encode(hash_pbkdf2('sha256', $password, 'test-salt', 10000, 0, true));
        $owner = User::create(['username' => 'test-django-owner', 'password' => $hash, 'is_staff' => true, 'is_active' => true, 'is_superuser' => true]);
        Livewire::test(Login::class)
            ->fillForm(['email' => $owner->username, 'password' => $password])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
        $this->assertStringStartsWith('$2y$', $owner->fresh()->password);
        $this->assertTrue(Hash::check($password, $owner->fresh()->password));
    }

    public function test_car_edit_form_saves_without_changing_its_seo_or_url(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner());
        $car = Car::first();
        $url = $car->legacy_path;
        $title = $car->seo_title;
        Livewire::test(EditCar::class, ['record' => $car->id])
            ->fillForm(['description' => 'Test description only.'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Test description only.', $car->fresh()->description);
        $this->assertSame($url, $car->fresh()->legacy_path);
        $this->assertSame($title, $car->fresh()->seo_title);
    }

    public function test_photo_upload_creates_responsive_derivatives_on_the_media_disk(): void
    {
        Storage::fake('media');
        $photo = UploadedFile::fake()->image('test-car.jpg', 1600, 1000);
        $path = $photo->store('cars/originals', 'media');
        $record = CarImage::create(['car_id' => Car::first()->id, 'original' => $path, 'is_main' => false]);
        $record->refresh();
        foreach (['image', 'small', 'card_image', 'card_small'] as $field) {
            Storage::disk('media')->assertExists($record->$field);
        }
        $this->assertSame(1200, $record->width);
        $this->assertSame(960, $record->card_width);
    }

    public function test_published_content_cache_is_invalidated_after_a_cms_save(): void
    {
        $site = SiteSettings::find(1);
        $this->get('/')->assertOk();
        $site->footer_text = 'Updated footer from the test database.';
        $site->save();
        $this->get('/')->assertOk()->assertSee('Updated footer from the test database.');
    }

    public function test_admin_protects_records_that_are_referenced_by_leads_or_cars(): void
    {
        $this->actingAs($this->owner());
        $car = Car::with('cities')->first();
        $city = $car->cities->first();
        BookingRequest::create(['car_id' => $car->id, 'city_id' => $city->id, 'name' => 'Test client', 'phone' => '+77012345678', 'consent' => true]);
        $this->assertFalse(CarResource::canDelete($car));
        $this->assertFalse(CityResource::canDelete($city));
        $this->assertFalse(CarBrandResource::canDelete($car->brand));
        $this->assertFalse(CarCategoryResource::canDelete($car->category));
    }

    public function test_public_content_survives_a_real_file_cache_round_trip(): void
    {
        config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/testing/content-cache')]);
        Cache::purge('file');
        PublicContent::forget();
        $first = PublicContent::all();
        request()->attributes->remove('legion_public_content');
        $second = PublicContent::all();
        $this->assertInstanceOf(City::class, $second['cities']->first());
        $this->assertSame($first['site']->getAttributes(), $second['site']->getAttributes());
        $this->get('/')->assertOk();
        PublicContent::forget();
    }
}
