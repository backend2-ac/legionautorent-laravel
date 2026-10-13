<?php

namespace App\Console\Commands;

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\CarImage;
use App\Models\City;
use App\Models\ContentBlock;
use App\Models\FAQ;
use App\Models\InterfaceText;
use App\Models\MenuLink;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Models\Translation;
use App\Services\PublicContent;
use App\Services\SeoRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishLocales extends Command
{
    protected $signature = 'legion:publish-locales';

    protected $description = 'Install complete KK/EN release content and editable system-page SEO; preserve existing editorial values.';

    public function handle(): int
    {
        if (! SiteSettings::find(1)) {
            $this->error('Import the catalogue before publishing translations.');

            return self::FAILURE;
        }
        $data = json_decode(file_get_contents(resource_path('data/published-locales.json')), true, 512, JSON_THROW_ON_ERROR);
        $interface = json_decode(file_get_contents(resource_path('data/interface-translations.json')), true, 512, JSON_THROW_ON_ERROR);
        $models = ['Car' => Car::class, 'CarImage' => CarImage::class, 'CarCategory' => CarCategory::class, 'City' => City::class, 'Page' => Page::class, 'ContentBlock' => ContentBlock::class, 'FAQ' => FAQ::class, 'SiteSettings' => SiteSettings::class];
        $count = 0;
        DB::transaction(function () use ($data, $interface, $models, &$count): void {
            request()->attributes->set('legion.installing_system_pages', true);
            try {
                foreach ($data['system_pages'] as $row) {
                    Page::firstOrCreate(['path' => $row['path']], ['slug' => $row['slug'], 'title' => $row['title'], 'seo_title' => $row['title'].' | LEGIONAUTORENT', 'seo_h1' => $row['h1'], 'seo_description' => $row['description'], 'body' => '<p>'.$row['description'].'</p>', 'robots' => $row['noindex'] ? 'noindex,follow' : 'index,follow', 'active' => true, 'show_in_footer' => false]);
                }
            } finally {
                request()->attributes->remove('legion.installing_system_pages');
            }
            foreach ($data['source_fixes'] ?? [] as $row) {
                $model = $models[$row['model']]::find($row['object']);
                if ($model && $model->{$row['field']} === $row['old']) {
                    $model->{$row['field']} = $row['new'];
                    $model->save();
                }
            }
            foreach ($data['release_corrections'] ?? [] as $row) {
                $model = $models[$row['model']]::find($row['object']);
                $translation = $model?->translations()->where('language', $row['language'])->first();
                if ($translation && $translation->{$row['field']} === $row['old']) {
                    $translation->{$row['field']} = $row['new'];
                    $translation->save();
                }
            }
            foreach ($data['translations'] as $row) {
                $class = $models[$row['model']];
                $model = $row['model'] === 'Page' && is_string($row['object'])
                    ? Page::where('path', $row['object'])->first() : $class::find($row['object']);
                if (! $model) {
                    continue;
                }
                $translation = Translation::firstOrNew(['content_type_id' => $model->contentTypeId(), 'object_id' => $model->id, 'language' => $row['language']]);
                // Re-running deployment must not replace an administrator's published edits.
                if ($translation->exists && $translation->published) {
                    continue;
                }
                foreach ($row['fields'] as $field => $value) {
                    if (blank($translation->$field)) {
                        $translation->$field = $value;
                    }
                }
                $translation->published = true;
                $translation->save();
                $count++;
            }
            foreach (array_unique([...array_keys($interface['kk']), ...array_keys($interface['en'])]) as $source) {
                $text = InterfaceText::firstOrNew(['source' => $source]);
                $text->ru = $text->ru ?: $source;
                foreach (['kk', 'en'] as $language) {
                    if (blank($text->$language) && isset($interface[$language][$source])) {
                        $text->$language = $interface[$language][$source];
                    }
                }
                $text->save();
            }
            foreach (MenuLink::all() as $link) {
                foreach (['kk', 'en'] as $language) {
                    $field = 'label_'.$language;
                    if (blank($link->$field)) {
                        $link->$field = $interface[$language][$link->label] ?? $link->label;
                    }
                }
                $link->save();
            }
        });
        SeoRelease::apply();
        PublicContent::forget();
        $this->info('Published '.$count.' translations. Existing published edits preserved; system-page SEO is editable in Pages.');

        return self::SUCCESS;
    }
}
