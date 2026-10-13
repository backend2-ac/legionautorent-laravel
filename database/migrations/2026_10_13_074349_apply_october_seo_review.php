<?php

use App\Services\SeoRelease;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SeoRelease::apply();
    }

    public function down(): void
    {
        // Keep published content and subsequent administrator edits when rolling code back.
    }
};
