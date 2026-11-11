<?php

namespace App\Filament\Resources;

use App\Filament\CarForm;
use App\Filament\RelationManagers\TranslationsRelationManager;
use App\Filament\Resources\Pages\CreateCar;
use App\Filament\Resources\Pages\EditCar;
use App\Filament\Resources\Pages\ListCar;
use App\Models\Car;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CarResource extends CmsResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = Car::class;

    protected static ?string $slug = 'cars';

    protected static string|\UnitEnum|null $navigationGroup = 'Автопарк';

    protected static ?string $modelLabel = 'Автомобиль';

    protected static ?string $pluralModelLabel = 'Автомобили';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(CarForm::schema())->columns(1);
    }

    public static function getRelations(): array
    {
        return [TranslationsRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['images', 'cities', 'discounts']))->columns([
            ImageColumn::make('main_image.card_image')->label('Фото')->disk('media')->imageWidth(88)->imageHeight(56),
            TextColumn::make('name')->label('Автомобиль')->searchable()->forceSearchCaseInsensitive()->sortable()->description(fn (Car $record): string => $record->legacy_path),
            TextColumn::make('cities.name')->label('Города')->badge(),
            TextColumn::make('base_price')->label('Цена / сутки')->numeric(decimalPlaces: 0, thousandsSeparator: ' ')->suffix(' ₸')->sortable(),
            TextColumn::make('from_price')->label('На сайте «от»')->state(fn (Car $record): int => $record->fromPrice())->numeric(decimalPlaces: 0, thousandsSeparator: ' ')->prefix('от ')->suffix(' ₸'),
            IconColumn::make('active')->label('На сайте')->boolean(),
            TextColumn::make('sort_order')->label('Порядок')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('cities')->label('Город')->relationship('cities', 'name')->preload(),
            TernaryFilter::make('active')->label('Опубликовано'),
        ])->recordActions([EditAction::make()->label('Открыть карточку')])->paginated([25, 50, 100])->defaultSort('sort_order')->reorderable('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ListCar::route('/'), 'create' => CreateCar::route('/create'), 'edit' => EditCar::route('/{record}/edit')];
    }
}
