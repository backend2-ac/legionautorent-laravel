<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\CreateCar;
use App\Filament\Resources\Pages\CreateFAQ;
use App\Filament\Resources\Pages\CreatePage;
use App\Filament\Resources\Pages\EditCar;
use App\Filament\Resources\Pages\EditFAQ;
use App\Filament\Resources\Pages\EditPage;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\City;
use App\Models\FAQ;
use App\Models\Page;
use App\Models\Permission;
use App\Services\DiscountPresets;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\CatalogueTestCase;

class AdminFillingTest extends CatalogueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner());
    }

    public function test_ready_discounts_are_deduplicated_and_applied_to_the_draft_only(): void
    {
        $this->assertCount(1, DiscountPresets::options());
        $car = Car::with('discounts', 'prices', 'images')->firstOrFail();
        $oldDiscounts = $car->discounts->toArray();
        $oldPrices = $car->prices->toArray();
        $oldImages = $car->images->map(fn ($photo): array => collect($photo->toArray())->except('sort_order')->all())->all();
        $component = Livewire::test(EditCar::class, ['record' => $car->id])->assertFormSet(['discount_mode' => 'manual']);
        $oldKeys = array_keys($component->get('data.discounts'));
        $component->fillForm(['discount_mode' => 'preset', 'discount_preset' => array_key_first(DiscountPresets::options())])
            ->callAction(TestAction::make('applyDiscountPreset')->schemaComponent('discountPresetActions'));
        $this->assertSame($oldDiscounts, $car->discounts()->get()->toArray());
        $rows = $component->get('data.discounts');
        $this->assertSame($oldKeys, array_keys($rows));
        $rows[$oldKeys[0]]['percent'] = 11;
        $component->fillForm(['discounts' => $rows])->call('save')->assertHasNoFormErrors();
        $this->assertSame(11, $car->discounts()->first()->percent);
        $this->assertSame($oldPrices, $car->prices()->get()->toArray());
        $this->assertSame($oldImages, $car->images()->get()->map(fn ($photo): array => collect($photo->toArray())->except('sort_order')->all())->all());
        $this->assertSame($car->seo_title, $car->fresh()->seo_title);
        $this->assertSame($car->legacy_path, $car->fresh()->legacy_path);
        $this->assertCount(2, DiscountPresets::options());
        $this->assertSame(10, Car::where('id', '!=', $car->id)->first()->discounts()->first()->percent);
    }

    public function test_classification_fields_are_removed_and_brand_is_detected_when_saving(): void
    {
        $brand = CarBrand::where('name', 'Toyota')->firstOrFail();
        $city = City::where('slug', 'kostanay')->firstOrFail();
        Livewire::test(CreateCar::class)->assertFormFieldDoesNotExist('model_name')
            ->assertFormFieldDoesNotExist('brand_id')->assertFormFieldDoesNotExist('category_id')->assertFormFieldDoesNotExist('features')
            ->set('data.name', 'Toyota Camry Admin Check')->assertFormSet(['slug' => 'toyota-camry-admin-check'])
            ->fillForm(['cities' => $city->id, 'base_price' => 40000, 'active' => true])->call('create')->assertHasNoFormErrors();
        $car = Car::where('slug', 'toyota-camry-admin-check')->firstOrFail();
        $this->assertSame($brand->id, $car->brand_id);
        $this->assertNull($car->category_id);
        $response = $this->get($car->legacy_path)->assertOk();
        $response->assertSee('Toyota Camry Admin Check');
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $response->getContent(), $scripts);
        $vehicle = collect($scripts[1])->map(fn (string $json): array => json_decode($json, true))->first(fn (array $node): bool => is_array($node['@type'] ?? null) && in_array('Vehicle', $node['@type']));
        $this->assertSame('Toyota', $vehicle['brand']['name']);
    }

    public function test_an_unknown_brand_without_a_class_is_public_and_can_be_edited(): void
    {
        $city = City::where('slug', 'kostanay')->firstOrFail();
        Livewire::test(CreateCar::class)->fillForm([
            'name' => 'Новый автомобиль без классификации', 'slug' => 'no-classification', 'cities' => $city->id,
            'base_price' => 30000, 'active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $car = Car::where('slug', 'no-classification')->firstOrFail();
        $this->assertNull($car->brand_id);
        $this->assertNull($car->category_id);
        $this->assertTrue(Car::public()->whereKey($car->id)->exists());
        $response = $this->get($car->legacy_path)->assertOk()->assertSee('Новый автомобиль без классификации');
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $response->getContent(), $scripts);
        $vehicle = collect($scripts[1])->map(fn (string $json): array => json_decode($json, true))->first(fn (array $node): bool => is_array($node['@type'] ?? null) && in_array('Vehicle', $node['@type']));
        $this->assertArrayNotHasKey('brand', $vehicle);
        $this->get('/cars/kostanay/')->assertOk()->assertSee('Новый автомобиль без классификации')->assertSee('/car/no-classification');
        $this->get($city->legacy_path)->assertOk()->assertSee('Новый автомобиль без классификации');
        $this->get('/sitemap-cars.xml')->assertOk()->assertSee('/car/no-classification');
        Livewire::test(EditCar::class, ['record' => $car->id])->fillForm(['description' => 'Новое описание.'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($car->fresh()->brand_id);
        $this->assertNull($car->fresh()->category_id);
        $this->get($car->legacy_path)->assertOk()->assertSee('Новое описание.');
    }

    public function test_discount_copy_is_disabled_without_permission_to_replace_rows(): void
    {
        $owner = auth()->user();
        $owner->update(['is_superuser' => false]);
        $owner->user_permissions()->attach(Permission::whereIn('codename', ['view_car', 'change_car', 'change_cardiscount'])->pluck('id'));
        $owner->unsetRelation('user_permissions');
        Livewire::test(EditCar::class, ['record' => Car::first()->id])
            ->fillForm(['discount_mode' => 'preset', 'discount_preset' => array_key_first(DiscountPresets::options())])
            ->assertActionDisabled(TestAction::make('applyDiscountPreset')->schemaComponent('discountPresetActions'));
    }

    public function test_new_car_can_use_ready_discounts_and_has_exactly_one_city(): void
    {
        $example = Car::with('cities')->first();
        $component = Livewire::test(CreateCar::class)->fillForm([
            'name' => 'Новая Toyota', 'slug' => 'ready-discounts-test',
            'cities' => $example->cities->first()->id,
            'base_price' => 40000, 'discount_mode' => 'preset', 'discount_preset' => array_key_first(DiscountPresets::options()),
        ])->callAction(TestAction::make('applyDiscountPreset')->schemaComponent('discountPresetActions'));
        $this->assertCount(4, $component->get('data.discounts'));
        $component->call('create')->assertHasNoFormErrors();
        $car = Car::where('slug', 'ready-discounts-test')->firstOrFail();
        $this->assertCount(1, $car->cities);
        $this->assertCount(4, $car->discounts);
        $this->assertSame('/car/ready-discounts-test', $car->legacy_path);
    }

    public function test_multiple_city_input_is_rejected_and_the_car_is_not_created(): void
    {
        $example = Car::first();
        Livewire::test(CreateCar::class)->fillForm([
            'name' => 'Неверный город', 'slug' => 'multiple-cities-rejected',
            'cities' => City::limit(2)->pluck('id')->all(), 'base_price' => 40000,
        ])->call('create')->assertHasFormErrors(['cities']);
        $this->assertFalse(Car::where('slug', 'multiple-cities-rejected')->exists());
    }

    public function test_editing_text_does_not_silently_remove_imported_city_links(): void
    {
        $car = Car::has('cities', '>', 1)->with('cities')->firstOrFail();
        $cities = $car->cities->pluck('id')->all();
        $classification = [$car->brand_id, $car->category_id];
        Livewire::test(EditCar::class, ['record' => $car->id])->fillForm(['description' => 'Описание обновлено.'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($cities, $car->fresh()->cities->pluck('id')->all());
        $this->assertSame($classification, [$car->fresh()->brand_id, $car->fresh()->category_id]);
        $selected = City::whereNotIn('id', $cities)->firstOrFail();
        Livewire::test(EditCar::class, ['record' => $car->id])->fillForm(['cities' => $selected->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$selected->id], $car->fresh()->cities->pluck('id')->all());
        $this->assertSame($car->legacy_path, $car->fresh()->legacy_path);
    }

    public function test_a_page_address_is_generated_from_the_slug(): void
    {
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Новая страница', 'slug' => 'new-editor-page', 'path' => '', 'body' => '<p>Новый текст</p>', 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'new-editor-page')->firstOrFail();
        $this->assertSame('/new-editor-page/', $page->path);
        $this->get($page->path)->assertOk()->assertSee('Новый текст');
    }

    public function test_an_existing_custom_address_is_preserved_when_the_slug_changes(): void
    {
        $page = Page::where('slug', 'privacy')->firstOrFail();
        $path = $page->path;
        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['slug' => 'privacy-editor-slug'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($path, $page->fresh()->path);
        $this->get($path)->assertOk();
    }

    public function test_generated_and_custom_addresses_cannot_shadow_other_routes(): void
    {
        $city = City::where('slug', 'kostanay')->firstOrFail();
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Конфликт', 'slug' => 'kostanay', 'path' => ''])
            ->call('create')->assertHasFormErrors(['path']);
        $this->assertFalse(Page::where('title', 'Конфликт')->exists());
        $this->get($city->legacy_path)->assertOk();
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Служебный адрес', 'slug' => 'custom-reserved', 'path' => '/callback/'])
            ->call('create')->assertHasFormErrors(['path']);
    }

    public function test_faq_default_is_general_without_extra_relationship_fields(): void
    {
        $component = Livewire::test(CreateFAQ::class)->assertFormFieldIsHidden('city_id')->assertFormFieldIsHidden('page_id')->assertFormFieldIsHidden('car_id');
        $component->fillForm(['question' => 'Общий вопрос теста?', 'answer' => '<p>Общий ответ.</p>', 'active' => true])->call('create')->assertHasNoFormErrors();
        $faq = FAQ::where('question', 'Общий вопрос теста?')->firstOrFail();
        $this->assertNull($faq->city_id);
        $this->assertNull($faq->page_id);
        $this->assertNull($faq->car_id);
        $this->get('/kostanay/')->assertSee('Общий вопрос теста?');
        $this->get('/faq/')->assertSee('Общий вопрос теста?');
    }

    public function test_faq_can_be_assigned_to_a_car_and_then_moved_to_a_city(): void
    {
        $car = Car::first();
        $faq = FAQ::first();
        Livewire::test(EditFAQ::class, ['record' => $faq->id])->fillForm(['faq_placement' => 'car', 'car_id' => $car->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($car->id, $faq->fresh()->car_id);
        $this->get($car->legacy_path)->assertSee($faq->question);
        $this->get('/faq/')->assertDontSee($faq->question);
        Livewire::test(EditFAQ::class, ['record' => $faq->id])->assertFormSet(['faq_placement' => 'car']);
        $city = City::where('slug', 'kostanay')->first();
        Livewire::test(EditFAQ::class, ['record' => $faq->id])->fillForm(['faq_placement' => 'city', 'city_id' => $city->id])->call('save')->assertHasNoFormErrors();
        $this->assertNull($faq->fresh()->car_id);
        $this->assertSame($city->id, $faq->fresh()->city_id);
        $this->get($city->legacy_path)->assertSee($faq->question);
        $this->get('/')->assertDontSee($faq->question);
    }

    public function test_faq_requires_its_selected_destination(): void
    {
        $faq = FAQ::first();
        Livewire::test(EditFAQ::class, ['record' => $faq->id])->fillForm(['faq_placement' => 'car', 'car_id' => null])->call('save')->assertHasFormErrors(['car_id']);
        $this->assertNull($faq->fresh()->car_id);
    }
}
