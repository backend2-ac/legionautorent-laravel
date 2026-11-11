<?php

namespace App\Models;

use App\Services\CmsAudit;
use App\Services\CmsValidation;
use App\Services\HeroMedia;
use App\Services\ImageProcessor;
use App\Services\PublicContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function ($m) {
            CmsValidation::validate($m);
            if ($m instanceof SiteSettings) {
                app(HeroMedia::class)->prepare($m);
            }
            if (in_array('updated_at', array_column($m->definition()['fields'] ?? [], 'name'))) {
                $m->updated_at = now();
            }
            if (! $m->exists && in_array('created_at', array_column($m->definition()['fields'] ?? [], 'name'))) {
                $m->created_at = now();
            }
        });
        static::deleting(function ($model): void {
            if ($model instanceof Car) {
                foreach (['images', 'extra_specs'] as $relation) {
                    foreach ($model->$relation as $child) {
                        $child->translations()->delete();
                    }
                }
            }if (! ($model instanceof Translation)) {
                $model->translations()->delete();
            }
        });
        static::deleted(function ($model): void {
            CmsAudit::record($model, 3);
            PublicContent::forget();
        });
        static::saved(function ($m) {
            CmsAudit::record($m, $m->wasRecentlyCreated ? 1 : 2);
            if ($m instanceof CarImage) {
                app(ImageProcessor::class)->process($m);
            }
            PublicContent::forget();
        });
    }

    public function definition(): array
    {
        static $defs = null;
        $defs ??= collect(json_decode(file_get_contents(resource_path('data/cms-schema.json')), true))->keyBy('table');

        return $defs->get($this->getTable(), []);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class, 'object_id')->where('content_type_id', $this->contentTypeId());
    }

    public function contentTypeId(): int
    {
        $d = $this->definition();
        [$a,$m] = explode('.', $d['label']);
        static $types = null;
        $types ??= ContentType::all()->mapWithKeys(fn ($t) => [$t->app_label.'.'.$t->model => $t->id])->all();

        return $types[$a.'.'.$m] ?? 0;
    }

    public function getAttribute(mixed $key): mixed
    {
        if ($key === 'pk') {
            return parent::getAttribute('id');
        }
        if ($key === 'get_absolute_url') {
            return $this->getAbsoluteUrl();
        }
        if ($key === 'from_price') {
            return $this instanceof Car ? $this->fromPrice() : null;
        }
        if ($key === 'main_image') {
            return $this instanceof Car ? $this->images->first() : null;
        }
        if ($key === 'template_name') {
            return 'components.sections.'.parent::getAttribute('key');
        }
        if ($key === 'display_label') {
            return app()->getLocale() === 'ru' ? parent::getAttribute('label') : (parent::getAttribute('label_'.app()->getLocale()) ?: parent::getAttribute('label'));
        }
        if ($key === 'whatsapp_url') {
            return wa_url(config('legion.whatsapp') ?: $this->whatsapp, localized($this, 'whatsapp_message'));
        }
        if ($key === 'get_transmission_display') {
            return site_text(['automatic' => 'Автомат', 'manual' => 'Механика'][$this->transmission] ?? '');
        }
        if ($key === 'get_drive_display') {
            return site_text(['front' => 'Передний', 'rear' => 'Задний', 'all' => 'Полный'][$this->drive] ?? '');
        }
        if ($this instanceof CarImage) {
            if (in_array($key, ['display_url', 'card_url'])) {
                return '/media/'.($key === 'card_url' ? ($this->card_image ?: $this->image ?: $this->original) : ($this->image ?: $this->original));
            }
            if (in_array($key, ['srcset', 'card_srcset'])) {
                $small = $key === 'srcset' ? $this->small : $this->card_small;
                $img = $key === 'srcset' ? $this->image : $this->card_image;
                $width = $key === 'srcset' ? $this->width : $this->card_width;

                return $small && $img && $width > 640 ? '/media/'.$small.' 640w, /media/'.$img.' '.$width.'w' : '';
            }
            if (in_array($key, ['card_avif_srcset', 'avif_srcset'])) {
                $items = ($this->variants ?? [])[$key === 'card_avif_srcset' ? 'card_avif' : 'avif'] ?? [];
                $urls = [];
                foreach ($items as $w => $path) {
                    $urls[] = '/media/'.$path.' '.$w.'w';
                }

                return implode(', ', $urls);
            }
        }
        $value = parent::getAttribute($key);
        if ($value === null && ! $this->exists) {
            foreach ($this->definition()['fields'] ?? [] as $f) {
                if ($f['name'] === $key) {
                    return $f['default'] ?? ($f['type'] === 'JSONField' ? [] : null);
                }
            }
        }

        return $value;
    }

    public function getAbsoluteUrl(): string
    {
        return $this instanceof CarCategory ? '/category/'.$this->slug.'/' : ($this->legacy_path ?? $this->path ?? '/');
    }
}
