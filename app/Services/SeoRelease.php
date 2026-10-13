<?php

namespace App\Services;

use App\Models\City;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Models\Translation;
use Illuminate\Support\Facades\DB;

class SeoRelease
{
    public static function apply(): void
    {
        if (! SiteSettings::find(1)) {
            return;
        }

        $review = json_decode(file_get_contents(resource_path('data/seo-review-2026-10.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($review): void {
            foreach ($review['pages'] as $row) {
                $class = $row['model'] === 'City' ? City::class : Page::class;
                $pathField = $class === City::class ? 'legacy_path' : 'path';
                $model = $class::where($pathField, $row['path'])->first();
                if (! $model && isset($row['create'])) {
                    request()->attributes->set('legion.installing_system_pages', true);
                    try {
                        $model = Page::create(['path' => $row['path'], ...$row['create']]);
                    } finally {
                        request()->attributes->remove('legion.installing_system_pages');
                    }
                }
                if (! $model || ($model->legacy_meta['seo_review'] ?? null) === $review['version']) {
                    continue;
                }

                $fields = $row['fields'];
                $fields['og_title'] = $fields['seo_title'] ?? $model->seo_title;
                $fields['og_description'] = $fields['seo_description'] ?? $model->seo_description;
                $fields['legacy_meta'] = [...($model->legacy_meta ?? []), 'seo_review' => $review['version']];
                $fields['updated_at'] = now();
                $model->forceFill($fields);
                DB::table($model->getTable())->where('id', $model->id)->update($model->getDirty());

                foreach ($row['translations'] ?? [] as $language => $values) {
                    Translation::firstOrCreate(['content_type_id' => $model->contentTypeId(), 'object_id' => $model->id, 'language' => $language], [...$values, 'published' => true]);
                }
            }
        });
        PublicContent::forget();
    }
}
