<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class City extends CmsModel
{
    protected $table = 'locations_city';

    protected $casts = ['legacy_meta' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'active' => 'boolean', 'sort_order' => 'integer'];

    public function getCatalogPath(): string
    {
        return '/cars/'.$this->slug.'/';
    }

    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class, 'cars_car_cities', 'city_id', 'car_id');
    }
}
