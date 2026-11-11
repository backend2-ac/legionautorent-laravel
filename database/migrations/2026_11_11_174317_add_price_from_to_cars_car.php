<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional manual "from" price; when empty the site derives it from the daily price and the largest discount.
     */
    public function up(): void
    {
        Schema::table('cars_car', function (Blueprint $table): void {
            $table->decimal('price_from', 12, 0)->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('cars_car', function (Blueprint $table): void {
            $table->dropColumn('price_from');
        });
    }
};
