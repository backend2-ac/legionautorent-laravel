<?php

namespace App\Filament;

use App\Models\Car;
use App\Models\CarDiscount;
use App\Models\CarImage;
use App\Models\CarPrice;
use App\Models\CarSpecification;
use App\Services\CmsValidation;
use App\Services\DiscountPresets;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class CarForm
{
    public static function schema(): array
    {
        $main = CmsFields::inputs(Car::class, [], ['name', 'cities', 'active', 'accepts_requests', 'featured', 'description']);
        foreach ($main as $field) {
            if ($field->getName() === 'cities') {
                $field->label('Город')->multiple(false)->dehydrated(false)->required()->rules(['integer'])->validationMessages(['integer' => 'Выберите один город.'])->live()
                    ->afterStateUpdated(fn (Set $set) => $set('city_selection_changed', true))
                    ->helperText(function (?Car $record): string {
                        if ($record && $record->cities->count() > 1) {
                            return 'Из прежнего сайта сохранены города: '.$record->cities->pluck('name')->implode(', ').'. Пока вы не меняете город, эти связи сохраняются. Выбор города оставит у машины только его.';
                        }

                        return 'Автомобиль появится в каталоге выбранного города. Можно выбрать один город.';
                    })
                    ->suffixAction(Action::make('keepSelectedCity')->label('Оставить только выбранный город')->icon('heroicon-o-map-pin')
                        ->visible(fn (?Car $record): bool => $record !== null && $record->cities->count() > 1)
                        ->requiresConfirmation()->modalHeading('Оставить автомобиль в одном городе?')
                        ->modalDescription('После общего сохранения машина будет доступна только в выбранном городе. Её адрес, фотографии и SEO сохранятся.')
                        ->action(fn (Set $set) => $set('city_selection_changed', true)))
                    ->saveRelationshipsUsing(function (Select $component, Get $get): void {
                        $record = $component->getRecord();
                        $current = $record->cities()->pluck('locations_city.id');
                        if ($current->count() > 1 && ! $get('city_selection_changed') && (string) $component->getState() === (string) $current->first()) {
                            return;
                        }
                        $component->saveStateToRelationship();
                    });
            }
            if ($field->getName() === 'featured') {
                $field->label('Показывать первыми')->helperText('Поднимает машину в начале каталога при сортировке «Рекомендуемые». Отдельной рамки или значка не добавляет.');
            }
            if ($field->getName() === 'name') {
                $field->helperText('Например: Toyota Camry XV 80. Отдельное поле модели заполнять не нужно.')->live(onBlur: true)->afterStateUpdated(function (?string $state, string $operation, Get $get, Set $set): void {
                    if ($operation === 'create' && ! $get('slug')) {
                        $set('slug', Str::slug($state ?? ''));
                        $set('seo_title', $state);
                        $set('seo_h1', 'Аренда '.($state ?? ''));
                    }
                });
            }
        }

        $order = array_flip(['name', 'cities', 'description', 'active', 'accepts_requests', 'featured']);
        usort($main, fn ($first, $second): int => $order[$first->getName()] <=> $order[$second->getName()]);
        $main[] = Hidden::make('city_selection_changed')->default(false)->dehydrated(false);

        $photos = self::related('images', CarImage::class, ['car_id', 'legacy_url', 'sort_order'])
            ->label('Галерея автомобиля')->addActionLabel('Добавить фотографию')
            ->orderColumn('sort_order')->reorderable()->reorderableWithButtons()
            ->grid(['default' => 1, 'xl' => 2])->collapsible()
            ->itemLabel(fn (array $state): string => ($state['is_main'] ?? false) ? 'Главная фотография' : (($state['caption'] ?? '') ?: 'Фотография'))
            ->helperText('Загрузите фото и выберите одно главное. Остальные можно перетаскивать. Все изменения сохраняются общей кнопкой «Сохранить».')
            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (collect(is_array($value) ? $value : [])->where('is_main', true)->count() > 1) {
                    $fail('Выберите только одно главное фото: выключите отметку у предыдущего.');
                }
            }]);

        $prices = self::related('prices', CarPrice::class, ['car_id'])
            ->label('Тарифы по сроку аренды')->addActionLabel('Добавить тариф')
            ->itemLabel(fn (array $state): string => ($state['label'] ?? '') ?: 'Тариф')
            ->helperText('Необязательно. Без дополнительных тарифов используется основная цена в сутки. Пустое «До дней» означает любой больший срок.')
            ->rules([self::rangeRule()]);
        $discounts = self::related('discounts', CarDiscount::class, ['car_id'])
            ->label('Скидки по сроку аренды')->addActionLabel('Добавить скидку')
            ->itemLabel(fn (array $state): string => ($state['label'] ?? '') ?: 'Скидка')
            ->helperText('Можно заполнить вручную или применить готовый набор выше. После применения все строки можно изменить. Пустое «До дней» означает любой больший срок. Соседние диапазоны могут иметь общую границу.')
            ->rules([self::rangeRule()]);

        return [Tabs::make('Карточка автомобиля')->persistTabInQueryString('tab')->columnSpanFull()->tabs([
            Tab::make('Автомобиль')->schema([Section::make('Основные данные')->description('Название, один город, описание и показ автомобиля на сайте.')->schema($main)->columns(['default' => 1, 'lg' => 2])]),
            Tab::make('Фотографии')->schema([$photos]),
            Tab::make('Цены и скидки')->schema([
                Section::make('Основная цена')->description('На сайте везде показывается цена «от»: цена в сутки минус самая большая скидка. Её можно задать вручную.')->schema(self::priceInputs())->columns(['default' => 1, 'lg' => 2]),
                $prices, self::discountPicker(), $discounts,
            ]),
            Tab::make('Характеристики')->schema([
                Section::make('Параметры автомобиля')->schema(CmsFields::inputs(Car::class, [], ['year', 'engine', 'transmission', 'drive', 'seats', 'doors', 'color', 'fuel']))->columns(['default' => 1, 'lg' => 2]),
                self::related('extra_specs', CarSpecification::class, ['car_id', 'sort_order'])->label('Дополнительные характеристики')->orderColumn('sort_order')->reorderable()->addActionLabel('Добавить характеристику')->itemLabel(fn (array $state): string => ($state['label'] ?? '') ?: 'Характеристика'),
            ]),
            Tab::make('SEO и адрес')->schema([
                Section::make('Поисковая выдача')->description('Существующие адреса и SEO-тексты сохраняйте. Изменять можно осознанно, после проверки.')->schema(CmsFields::inputs(Car::class, [], ['seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots']))->columns(['default' => 1, 'lg' => 2]),
                Section::make('Адрес страницы')->schema(CmsFields::inputs(Car::class, [], ['slug', 'legacy_path']))->columns(['default' => 1, 'lg' => 2]),
                Section::make('Картинка и текст при отправке ссылки')->schema(CmsFields::inputs(Car::class, [], ['og_title', 'og_description', 'og_image']))->columns(['default' => 1, 'lg' => 2])->collapsible()->collapsed(),
                Section::make('Исходные технические данные')->schema(CmsFields::inputs(Car::class, [], ['legacy_id', 'legacy_meta', 'sort_order']))->collapsible()->collapsed(),
            ]),
        ])];
    }

    /**
     * @return array<int, Field>
     */
    private static function priceInputs(): array
    {
        $inputs = CmsFields::inputs(Car::class, [], ['base_price', 'price_from', 'deposit', 'mileage_limit']);
        foreach ($inputs as $input) {
            if ($input instanceof TextInput && $input->getName() === 'price_from') {
                $input->numeric()->minValue(1)->suffix('₸')
                    ->placeholder(fn (?Car $record): string => $record ? 'Автоматически: '.number_format($record->fromPrice(), 0, '.', ' ').' ₸' : 'Посчитается автоматически')
                    ->helperText('Пусто — цена в сутки минус самая большая скидка. Пример: 250 000 ₸ и скидка 30% → от 175 000 ₸.');
            }
        }

        return $inputs;
    }

    private static function discountPicker(): Section
    {
        return Section::make('Как заполнить скидки')->description('Используйте уже сохранённые скидки или добавьте свои. Основная цена и тарифы не изменятся.')->schema([
            Radio::make('discount_mode')->label('Способ заполнения')
                ->options(['preset' => 'Выбрать готовый набор', 'manual' => 'Заполнить вручную'])
                ->default(fn (?Car $record): string => $record ? 'manual' : 'preset')->inline()->live()->dehydrated(false)
                ->afterStateHydrated(function (Radio $component, ?Car $record): void {
                    if (blank($component->getState())) {
                        $component->state($record ? 'manual' : 'preset');
                    }
                })
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if ($state === 'preset' && blank($get('discount_preset'))) {
                        $set('discount_preset', array_key_first(DiscountPresets::options()));
                    }
                }),
            Select::make('discount_preset')->label('Готовый набор скидок')
                ->options(fn (): array => DiscountPresets::options())
                ->default(fn (): ?string => array_key_first(DiscountPresets::options()))
                ->native(false)->searchable()->live()->dehydrated(false)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('discount_mode') === 'preset')
                ->helperText('Наборы берутся из сохранённых карточек и не дублируются. Сохраните свои скидки у машины — их можно будет выбрать для других машин.'),
            Actions::make([
                Action::make('applyDiscountPreset')->label('Применить набор')->icon('heroicon-o-document-duplicate')
                    ->disabled(fn (Get $get): bool => blank($get('discount_preset')) || ! self::canApplyPreset())
                    ->requiresConfirmation(fn (Get $get): bool => count($get('discounts') ?? []) > 0)
                    ->modalHeading('Заменить скидки в этой карточке?')
                    ->modalSubmitActionLabel('Применить скидки')
                    ->modalDescription('Изменятся только строки скидок в форме. Другие автомобили не затронуты. Для записи изменений нажмите общую кнопку «Сохранить».')
                    ->action(function (Get $get, Set $set): void {
                        abort_unless(self::canApplyPreset(), 403);
                        $keys = array_keys($get('discounts') ?? []);
                        $rows = [];
                        foreach (DiscountPresets::rows((string) $get('discount_preset')) as $index => $row) {
                            $rows[$keys[$index] ?? (string) Str::uuid()] = $row;
                        }
                        $set('discounts', $rows);
                    }),
            ])->key('discountPresetActions')->visible(fn (Get $get): bool => $get('discount_mode') === 'preset'),
        ])->columnSpanFull();
    }

    private static function canApplyPreset(): bool
    {
        foreach (['change', 'add', 'delete'] as $permission) {
            if (! (auth()->user()?->hasCmsPermission($permission, 'CarDiscount') ?? false)) {
                return false;
            }
        }

        return true;
    }

    private static function related(string $relationship, string $model, array $except): Repeater
    {
        $fields = CmsFields::inputs($model, $except);
        foreach ($fields as $field) {
            if ($model === CarDiscount::class && $field->getName() === 'label') {
                $field->label('Название скидки')->helperText('Например: «7–15 дней» или «Длительная аренда».');
            }
            if (in_array($field->getName(), ['min_days', 'daily_price', 'percent'])) {
                $field->minValue(1);
            }
            if ($field->getName() === 'percent') {
                $field->maxValue(99);
            }
            if ($field->getName() === 'max_days') {
                $field->minValue(1);
            }
        }

        return Repeater::make($relationship)->relationship($relationship)->schema($fields)
            ->columns(['default' => 1, 'lg' => 2])->defaultItems(0)->columnSpanFull()
            ->addable(fn (): bool => auth()->user()?->hasCmsPermission('add', class_basename($model)) ?? false)
            ->deletable(fn (): bool => auth()->user()?->hasCmsPermission('delete', class_basename($model)) ?? false)
            ->disabled(fn (): bool => ! (auth()->user()?->hasCmsPermission('change', class_basename($model)) ?? false))
            ->deleteAction(fn ($action) => $action->requiresConfirmation())
            ->saveRelationshipsUsing(fn (Repeater $component) => self::saveRelated($component));
    }

    private static function rangeRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $rows = array_values(is_array($value) ? $value : []);
            foreach ($rows as $index => $row) {
                $from = (int) ($row['min_days'] ?? 0);
                $to = filled($row['max_days'] ?? null) ? (int) $row['max_days'] : PHP_INT_MAX;
                if ($from < 1 || $to < $from) {
                    $fail('Проверьте диапазон №'.($index + 1).': от 1 дня, окончание не раньше начала.');

                    return;
                }
                foreach (array_slice($rows, $index + 1) as $other) {
                    $otherFrom = (int) ($other['min_days'] ?? 0);
                    $otherTo = filled($other['max_days'] ?? null) ? (int) $other['max_days'] : PHP_INT_MAX;
                    if (CmsValidation::rangesOverlap($from, $to, $otherFrom, $otherTo)) {
                        $fail('Диапазоны пересекаются. Исправьте сроки до сохранения.');

                        return;
                    }
                }
            }
        };
    }

    private static function saveRelated(Repeater $component): void
    {
        $car = $component->getRecord();
        $relationship = $component->getRelationshipName();
        if ($relationship === 'images') {
            $selectedKey = collect($component->getRawState())->filter(fn (array $item): bool => (bool) ($item['is_main'] ?? false))->keys()->first();
            $selectedId = is_string($selectedKey) && str_starts_with($selectedKey, 'record-') ? (int) substr($selectedKey, 7) : 0;
            $car->images()->where('is_main', true)->where('id', '!=', $selectedId)->update(['is_main' => false]);
        }
        request()->attributes->set('legion.validated_car_relation', ['car_id' => $car->id, 'relationship' => $relationship]);
        try {
            $component->saveToRelationship();
        } finally {
            request()->attributes->remove('legion.validated_car_relation');
        }
    }
}
