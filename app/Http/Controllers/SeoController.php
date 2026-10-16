<?php

namespace App\Http\Controllers;

use App\Models\Car;
use App\Models\CarCategory;
use App\Models\City;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Services\Seo;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function robots(): Response
    {
        if (config('legion.staging')) {
            $text = "User-agent: *\nDisallow: /\n";
        } else {
            $url = config('legion.site_url').'/sitemap.xml';
            $site = SiteSettings::findOrFail(1);
            $text = $site->robots_text ? str_replace('{sitemap_url}', $url, $site->robots_text) : "User-agent: *\nDisallow: /".config('legion.admin_path')."/\nDisallow: /booking/\nDisallow: /callback/\nDisallow: /request-success/\n";
            if (! str_contains($text, 'Sitemap:')) {
                $text .= "\nSitemap: ".$url."\n";
            }
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(?string $section = null): Response
    {
        $sections = ['cities', 'cars', 'categories', 'pages'];
        $url = config('legion.site_url');
        $x = new \XMLWriter;
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        if (! $section) {
            $x->startElement('sitemapindex');
            $x->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            foreach ($sections as $s) {
                $x->startElement('sitemap');
                $x->writeElement('loc', $url.'/sitemap-'.$s.'.xml');
                $x->endElement();
            }
        } else {
            abort_unless(in_array($section, $sections), 404);
            $objects = (match ($section) {
                'cities' => City::where('active', true),'cars' => Car::public(),'categories' => CarCategory::where('active', true),'pages' => Page::where('active', true)
            })->where('robots', 'index,follow')->with('translations')->get();
            if ($section === 'pages') {
                $catalogPaths = City::where('active', true)->get()->map(fn (City $city) => $city->getCatalogPath());
                $objects = $objects->reject(fn (Page $page) => $page->path === '/cars/' || (str_starts_with($page->path, '/cars/') && ! $catalogPaths->contains($page->path)));
            }
            $entries = $objects->map(fn ($o) => [$o->getAbsoluteUrl(), Seo::languages($o), $o->updated_at?->toAtomString()])->all();
            if ($section === 'pages') {
                $existing = array_column($entries, 0);
                foreach (['/faq/' => Seo::faqLanguages()] as $path => $languages) {
                    if (! in_array($path, $existing)) {
                        $entries[] = [$path, $languages, null];
                    }
                }
            }$x->startElement('urlset');
            $x->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $x->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
            foreach ($entries as [$path,$langs,$date]) {
                foreach ($langs as $lang) {
                    $x->startElement('url');
                    $x->writeElement('loc', $url.language_url($path, $lang));
                    if ($date) {
                        $x->writeElement('lastmod', $date);
                    }foreach ([...$langs, 'x-default'] as $alternate) {
                        $x->startElement('xhtml:link');
                        $x->writeAttribute('rel', 'alternate');
                        $x->writeAttribute('hreflang', $alternate);
                        $x->writeAttribute('href', $url.($alternate === 'x-default' ? $path : language_url($path, $alternate)));
                        $x->endElement();
                    }$x->endElement();
                }
            }
        }$x->endElement();
        $x->endDocument();

        return response($x->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function legacyImage(string $path): \Symfony\Component\HttpFoundation\Response
    {
        if ($path === 'legionautorent.svg') {
            return response()->file(public_path('static/img/logo.svg'), ['Content-Type' => 'image/svg+xml']);
        }$manifest = json_decode(file_get_contents(resource_path('data/legacy-media.json')), true);
        foreach ($manifest as $url => $data) {
            if (parse_url($url, PHP_URL_PATH) === '/img/'.$path) {
                return redirect('/media/'.$data['original'], 301);
            }
        }abort(404);
    }
}
