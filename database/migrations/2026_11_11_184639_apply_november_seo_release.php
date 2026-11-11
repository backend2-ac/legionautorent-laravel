<?php

use App\Models\City;
use App\Models\FAQ;
use App\Models\Page;
use App\Models\SiteSettings;
use App\Models\Translation;
use App\Services\PublicContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * November 2026 SEO release: one brand spelling, readable city headings with ₸, and fuller FAQ/About metadata.
 *
 * Texts are changed only while they still hold the imported values, so later admin edits are preserved.
 */
return new class extends Migration
{
    private const BRAND_VARIANTS = ['Legion Auto Rent', 'LegionAutorent', 'Legionautorent'];

    /**
     * @var array<string, array{old: array{0: string, 1: string}, ru: array{0: string, 1: string}, kk: array{0: string, 1: string}, en: array{0: string, 1: string}}>
     */
    private const PAGES = [
        '/faq/' => [
            'old' => ['Вопросы об аренде | LEGIONAUTORENT', 'Ответы на вопросы об аренде автомобилей.'],
            'ru' => ['Вопросы об аренде авто без водителя | LEGIONAUTORENT', 'Ответы на частые вопросы об аренде авто без водителя: документы, залог, подача автомобиля и оплата. Астана, Костанай, Усть-Каменогорск, Павлодар.'],
            'kk' => ['Жүргізушісіз көлік жалдау туралы сұрақтар | LEGIONAUTORENT', 'Жүргізушісіз көлік жалдау туралы жиі қойылатын сұрақтарға жауаптар: құжаттар, кепіл, көлікті жеткізу және төлем. Астана, Қостанай, Өскемен, Павлодар.'],
            'en' => ['Self-drive car rental FAQ | LEGIONAUTORENT', 'Answers to common questions about self-drive car rental: documents, deposit, car delivery and payment. Astana, Kostanay, Ust-Kamenogorsk, Pavlodar.'],
        ],
        '/about/' => [
            'old' => ['О LEGIONAUTORENT | LEGIONAUTORENT', 'Автомобиль под ваши планы.'],
            'ru' => ['О компании — 8 лет проката авто в Казахстане | LEGIONAUTORENT', 'LEGIONAUTORENT — 8 лет проката автомобилей без водителя: филиалы в Астане, Костанае, Усть-Каменогорске и Павлодаре, работа 24/7 и бесплатная подача по городу.'],
            'kk' => ['Компания туралы — Қазақстанда 8 жыл көлік жалдау | LEGIONAUTORENT', 'LEGIONAUTORENT — 8 жыл жүргізушісіз көлік жалдау: Астанада, Қостанайда, Өскеменде және Павлодарда филиалдар, тәулік бойы жұмыс және қала ішінде тегін жеткізу.'],
            'en' => ['About us — 8 years of car rental in Kazakhstan | LEGIONAUTORENT', 'LEGIONAUTORENT: 8 years of self-drive car rental with branches in Astana, Kostanay, Ust-Kamenogorsk and Pavlodar, open 24/7 with free delivery in the city.'],
        ],
    ];

    /**
     * @var array{ru: array{0: string, 1: string}, kk: array{0: string, 1: string}, en: array{0: string, 1: string}}
     */
    private const ABOUT_BODY = [
        'ru' => ['<h2>Прокат автомобилей без водителя</h2><p>LEGIONAUTORENT предлагает автомобили разных классов',
            '<h2>8 лет проката автомобилей без водителя</h2><p>LEGIONAUTORENT работает на рынке проката восемь лет. Филиалы компании открыты в Астане, Костанае, Усть-Каменогорске и Павлодаре, а по договорённости автомобиль можно взять для поездки в другие города Казахстана. В каталоге — реальные фотографии, цены и скидки на длительную аренду.</p><h2>Как арендовать</h2><p>Выберите автомобиль и напишите нам в WhatsApp или позвоните. Менеджер подтвердит наличие на ваши даты, стоимость, документы и условия договора. Мы работаем 24/7, без выходных, а подача и приём автомобиля в любой точке города входят в стоимость аренды.</p><p><a href="/cars/">Выбрать автомобиль</a> · <a href="/contacts/">Связаться с нами</a></p>'],
        'kk' => ['<h2>Жүргізушісіз автокөлік жалға алу</h2><p>LEGIONAUTORENT Астана, Қостанай',
            '<h2>Жүргізушісіз көлік жалдауда 8 жыл</h2><p>LEGIONAUTORENT жалдау нарығында сегіз жыл жұмыс істеп келеді. Компанияның филиалдары Астанада, Қостанайда, Өскеменде және Павлодарда ашық, ал келісім бойынша көлікті Қазақстанның басқа қалаларына сапарға алуға болады. Каталогта нақты фотосуреттер, бағалар және ұзақ мерзімді жалдауға жеңілдіктер бар.</p><h2>Қалай жалға алуға болады</h2><p>Көлікті таңдап, бізге WhatsApp-қа жазыңыз немесе қоңырау шалыңыз. Менеджер көліктің сіздің күндеріңізге бос екенін, бағасын, құжаттарды және шарт талаптарын растайды. Біз тәулік бойы, демалыссыз жұмыс істейміз, ал көлікті қаланың кез келген нүктесінде беру және қабылдау жалдау бағасына кіреді.</p><p><a href="/kk/cars/">Автокөлік таңдау</a> · <a href="/kk/contacts/">Бізбен байланысу</a></p>'],
        'en' => ['<h2>Self-drive car rental</h2><p>LEGIONAUTORENT offers vehicles',
            '<h2>8 years of self-drive car rental</h2><p>LEGIONAUTORENT has been in the car rental market for eight years. We have branches in Astana, Kostanay, Ust-Kamenogorsk and Pavlodar, and by arrangement you can take a car to other cities of Kazakhstan. The catalogue shows real photos, prices and discounts for longer rentals.</p><h2>How to rent</h2><p>Choose a car and message us on WhatsApp or call. A manager will confirm availability for your dates, the price, documents and contract terms. We work 24/7 with no days off, and delivery and return anywhere in the city are included in the rental price.</p><p><a href="/en/cars/">Choose a car</a> · <a href="/en/contacts/">Contact us</a></p>'],
    ];

    /**
     * @var array<int, array{old: string, ru: string, kk: string, en: string}>
     */
    private const FAQ_ANSWERS = [
        1 => [
            'old' => 'Выберите автомобиль и отправьте заявку с телефоном и желаемыми датами. Менеджер свяжется с вами, уточнит наличие и условия. Заявка сама по себе не подтверждает бронирование.',
            'ru' => 'Выберите автомобиль и напишите нам в WhatsApp или позвоните. Менеджер уточнит наличие на ваши даты и условия аренды. Бронирование подтверждается после согласования с менеджером.',
            'kk' => 'Көлікті таңдап, бізге WhatsApp-қа жазыңыз немесе қоңырау шалыңыз. Менеджер көліктің сіздің күндеріңізге бос екенін және жалдау шарттарын нақтылайды. Брондау менеджермен келіскеннен кейін расталады.',
            'en' => 'Choose a car and message us on WhatsApp or call. Our manager will confirm availability for your dates and the rental terms. The booking is confirmed once agreed with the manager.',
        ],
        5 => [
            'old' => 'Место получения и возможность доставки согласуйте с менеджером при оформлении заявки.',
            'ru' => 'Да. Подача и приём автомобиля в любой точке города входят в стоимость аренды. Время и адрес согласуйте с менеджером.',
            'kk' => 'Иә. Көлікті қаланың кез келген нүктесінде беру және қабылдау жалдау бағасына кіреді. Уақыты мен мекенжайын менеджермен келісіңіз.',
            'en' => 'Yes. Delivery and return anywhere in the city are included in the rental price. Agree the time and address with the manager.',
        ],
    ];

    public function up(): void
    {
        if (! SiteSettings::find(1)) {
            return;
        }

        DB::transaction(function (): void {
            $this->unifyBrand();
            $this->readableCityHeadings();
            $this->pageMetadata();
            $this->faqAnswers();
        });
        PublicContent::forget();
    }

    public function down(): void
    {
        // Content-only release: the previous wording is not restored automatically.
    }

    private function unifyBrand(): void
    {
        $columns = [
            'pages_page' => ['seo_title', 'seo_description', 'og_title', 'og_description', 'seo_h1', 'body'],
            'locations_city' => ['seo_title', 'seo_description', 'og_title', 'og_description', 'seo_h1'],
            'cars_carcategory' => ['seo_title', 'seo_description', 'og_title', 'og_description', 'seo_h1'],
            'seo_translation' => ['title', 'description', 'og_title', 'og_description', 'h1'],
        ];
        foreach ($columns as $table => $fields) {
            foreach ($fields as $field) {
                foreach (self::BRAND_VARIANTS as $variant) {
                    DB::table($table)->where($field, 'like', '%'.$variant.'%')->update([
                        $field => DB::raw('REPLACE('.$field.', '.DB::getPdo()->quote($variant).", 'LEGIONAUTORENT')"),
                    ]);
                }
            }
        }
    }

    private function readableCityHeadings(): void
    {
        foreach (City::all() as $city) {
            if ($city->name_in && preg_match('/^ПРОКАТ\s+АВТО\s+БЕЗ\s+ВОДИТЕЛЯ\s+В\s+.+,\s*ЦЕНЫ\s+ОТ\s+([\d\s]+?)\s*ТГ\s*\/\s*СУТКИ$/u', (string) $city->seo_h1, $match)) {
                DB::table('locations_city')->where('id', $city->id)->update([
                    'seo_h1' => 'Прокат авто без водителя в '.$city->name_in.', цены от '.preg_replace('/\s+/u', ' ', trim($match[1])).' ₸/сутки',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function pageMetadata(): void
    {
        foreach (self::PAGES as $path => $change) {
            $page = Page::where('path', $path)->first();
            if (! $page) {
                continue;
            }
            if ($page->seo_title === $change['old'][0] && $page->seo_description === $change['old'][1]) {
                DB::table('pages_page')->where('id', $page->id)->update([
                    'seo_title' => $change['ru'][0], 'seo_description' => $change['ru'][1],
                    'og_title' => $change['ru'][0], 'og_description' => $change['ru'][1], 'updated_at' => now(),
                ]);
            }
            foreach (['kk', 'en'] as $language) {
                Translation::where('content_type_id', $page->contentTypeId())->where('object_id', $page->id)->where('language', $language)
                    ->where('description', '!=', $change[$language][1])
                    ->whereIn('title', [$this->oldTitle($path, $language)])
                    ->update(['title' => $change[$language][0], 'description' => $change[$language][1], 'og_title' => '', 'og_description' => '', 'updated_at' => now()]);
            }
            if ($path === '/about/') {
                $this->aboutBody($page);
            }
        }
    }

    private function faqAnswers(): void
    {
        foreach (self::FAQ_ANSWERS as $id => $change) {
            $faq = FAQ::where('id', $id)->where('answer', $change['old'])->first();
            if (! $faq) {
                continue;
            }
            DB::table('pages_faq')->where('id', $faq->id)->update(['answer' => $change['ru']]);
            foreach (['kk', 'en'] as $language) {
                Translation::where('content_type_id', $faq->contentTypeId())->where('object_id', $faq->id)->where('language', $language)
                    ->update(['content' => $change[$language], 'updated_at' => now()]);
            }
        }
    }

    private function oldTitle(string $path, string $language): string
    {
        return [
            '/faq/' => ['kk' => 'Жалға алу туралы сұрақтар | LEGIONAUTORENT', 'en' => 'Rental questions | LEGIONAUTORENT'],
            '/about/' => ['kk' => 'LEGIONAUTORENT туралы | LEGIONAUTORENT', 'en' => 'About LEGIONAUTORENT | LEGIONAUTORENT'],
        ][$path][$language];
    }

    private function aboutBody(Page $page): void
    {
        if (str_starts_with((string) $page->body, self::ABOUT_BODY['ru'][0])) {
            DB::table('pages_page')->where('id', $page->id)->update(['body' => self::ABOUT_BODY['ru'][1], 'updated_at' => now()]);
        }
        foreach (['kk', 'en'] as $language) {
            Translation::where('content_type_id', $page->contentTypeId())->where('object_id', $page->id)->where('language', $language)
                ->where('content', 'like', self::ABOUT_BODY[$language][0].'%')
                ->update(['content' => self::ABOUT_BODY[$language][1], 'updated_at' => now()]);
        }
    }
};
