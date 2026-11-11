<?php

namespace App\Support;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\City;
use Illuminate\Support\HtmlString;

class PublicForm implements \IteratorAggregate
{
    public array $fields = [];

    public array $errors = [];

    public $non_field_errors;

    public function __construct(string $kind = 'filter', array $initial = [], array $errors = [])
    {
        $this->errors = $errors;
        $this->non_field_errors = new HtmlString(isset($errors['_global']) ? '<p class="errorlist">'.e($errors['_global'][0]).'</p>' : '');
        $defs = ['city' => ['Город', 'select', false], 'min_price' => ['Цена от', 'number', false], 'max_price' => ['Цена до', 'number', false], 'sort' => ['Сортировка', 'select', false]];
        foreach ($defs as $n => [$l,$t,$req]) {
            $opts = [];
            if ($t === 'select') {
                $by = $kind === 'filter' ? 'slug' : 'id';
                $items = match ($n) {
                    'car' => Car::public()->where('accepts_requests', true)->with('translations')->get(),'city' => City::where('active', true)->with('translations')->orderBy('sort_order')->get(),'brand' => CarBrand::all(),'category' => CarCategory::where('active', true)->with('translations')->orderBy('sort_order')->get(),default => collect()
                };
                if ($items->isNotEmpty()) {
                    $opts = ['' => site_text(match ($n) {
                        'city' => 'Все города','brand' => 'Все марки','category' => 'Все классы',default => 'Помогите выбрать автомобиль'
                    })];
                    foreach ($items as $item) {
                        $opts[$item->$by] = localized($item, 'name');
                    }
                } else {
                    $opts = match ($n) {
                        'transmission' => ['' => 'Любая коробка', 'automatic' => 'Автомат', 'manual' => 'Механика'],'drive' => ['' => 'Любой привод', 'front' => 'Передний', 'rear' => 'Задний', 'all' => 'Полный'],'sort' => ['' => 'Популярные', 'price' => 'Сначала дешевле', '-price' => 'Сначала дороже', 'new' => 'Новые в каталоге'],default => []
                    };
                }$opts = array_map('site_text', $opts);
            }$this->fields[$n] = new BoundField($n, site_text($l), $t, $initial[$n] ?? '', $req, $opts, $errors[$n] ?? []);
        }
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->fields);
    }

    public function __get($key)
    {
        return $this->fields[$key] ?? null;
    }
}
