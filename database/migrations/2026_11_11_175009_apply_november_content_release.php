<?php

use App\Models\ContentBlock;
use App\Models\Page;
use App\Models\SiteSection;
use App\Models\SiteSettings;
use App\Models\Translation;
use App\Services\PublicContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * November 2026 content release: the "About" section, the Partners page and copy that no longer asks for a form.
 *
 * Every change is applied only while the record still holds the original imported text, so edits made in the admin are kept.
 */
return new class extends Migration
{
    /**
     * @var list<array{kind: string, old: array{0: string, 1: string}, ru: array{0: string, 1: string}, kk: array{0: string, 1: string}, en: array{0: string, 1: string}}>
     */
    private const BLOCKS = [
        [
            'kind' => 'benefit',
            'old' => ['Быстрое оформление', 'Выберите автомобиль, оставьте заявку и согласуйте детали с менеджером.'],
            'ru' => ['Работаем 24/7', 'Без выходных и праздников: выдадим и примем автомобиль в удобное для вас время.'],
            'kk' => ['Тәулік бойы жұмыс істейміз', 'Демалыс пен мерекесіз: көлікті сізге ыңғайлы уақытта береміз және қабылдаймыз.'],
            'en' => ['Open 24/7', 'No days off or holidays: we hand over and take back the car whenever suits you.'],
        ],
        [
            'kind' => 'benefit',
            'old' => ['Доставка автомобиля', 'Согласуйте с менеджером получение автомобиля в удобной точке вашего города.'],
            'ru' => ['Бесплатная подача', 'Привезём и заберём автомобиль в любой точке города — это входит в стоимость аренды.'],
            'kk' => ['Тегін жеткізу', 'Көлікті қаланың кез келген нүктесіне жеткізіп, сол жерден қабылдаймыз — бұл жалдау бағасына кіреді.'],
            'en' => ['Free delivery', 'We deliver and collect the car anywhere in the city — it is included in the rental price.'],
        ],
        [
            'kind' => 'step',
            'old' => ['Позвоните нам', 'Звонок на бронирование выбранного автомобиля на сайте.'],
            'ru' => ['Напишите или позвоните', 'Напишите в WhatsApp или позвоните — подберём автомобиль под ваши даты.'],
            'kk' => ['Жазыңыз немесе қоңырау шалыңыз', 'WhatsApp-қа жазыңыз немесе қоңырау шалыңыз — күндеріңізге көлік таңдап береміз.'],
            'en' => ['Message or call us', 'Message us on WhatsApp or call — we will pick a car for your dates.'],
        ],
        [
            'kind' => 'step',
            'old' => ['Получите автомобиль', 'Доставка автомобиля в любую точку вашего города.'],
            'ru' => ['Получите автомобиль', 'Бесплатно привезём автомобиль в любую точку города.'],
            'kk' => ['Автокөлікті алыңыз', 'Көлікті қаланың кез келген нүктесіне тегін жеткіземіз.'],
            'en' => ['Receive your car', 'We deliver the car free of charge anywhere in the city.'],
        ],
        [
            'kind' => 'condition',
            'old' => ['Получение и возврат', 'Согласуйте время и место получения и возврата автомобиля с менеджером.'],
            'ru' => ['Получение и возврат', 'Подача и приём автомобиля в любой точке города входят в стоимость аренды. Время согласуйте с менеджером.'],
            'kk' => ['Алу және қайтару', 'Көлікті қаланың кез келген нүктесінде беру және қабылдау жалдау бағасына кіреді. Уақытын менеджермен келісіңіз.'],
            'en' => ['Pickup and return', 'Delivery and return anywhere in the city are included in the rental price. Agree the time with the manager.'],
        ],
    ];

    public function up(): void
    {
        if (! SiteSettings::find(1)) {
            return;
        }

        DB::transaction(function (): void {
            $this->updateBlocks();
            if (! SiteSection::where('key', 'about')->exists()) {
                SiteSection::create(['key' => 'about', 'active' => true, 'sort_order' => 45]);
            }
            $this->createPartnersPage();
        });
        PublicContent::forget();
    }

    public function down(): void
    {
        SiteSection::where('key', 'about')->delete();
    }

    private function updateBlocks(): void
    {
        foreach (self::BLOCKS as $change) {
            $block = ContentBlock::where('kind', $change['kind'])->where('title', $change['old'][0])->where('text', $change['old'][1])->first();
            if (! $block) {
                continue;
            }
            $block->update(['title' => $change['ru'][0], 'text' => $change['ru'][1]]);
            foreach (['kk', 'en'] as $language) {
                Translation::where('content_type_id', $block->contentTypeId())->where('object_id', $block->id)->where('language', $language)
                    ->update(['title' => $change[$language][0], 'content' => $change[$language][1], 'updated_at' => now()]);
            }
        }
    }

    private function createPartnersPage(): void
    {
        if (Page::where('path', '/partners/')->exists()) {
            return;
        }
        $page = Page::create([
            'title' => 'Партнёрам',
            'slug' => 'partners',
            'path' => '/partners/',
            'seo_title' => 'Партнёрам — субаренда автомобиля и сотрудничество | LEGIONAUTORENT',
            'seo_description' => 'Станьте партнёром LEGIONAUTORENT: передайте автомобиль в субаренду или предложите сотрудничество для гостей и сотрудников. Астана, Костанай, Усть-Каменогорск, Павлодар.',
            'seo_h1' => 'Партнёрам LEGIONAUTORENT',
            'intro' => 'Субаренда автомобилей и сотрудничество с компаниями.',
            'body' => '<h2>Мы ищем партнёров</h2><p>LEGIONAUTORENT открыт к сотрудничеству. Восемь лет мы сдаём автомобили в аренду без водителя в Астане, Костанае, Усть-Каменогорске и Павлодаре и строим партнёрства, которые выгодны обеим сторонам.</p>'
                .'<h2>Владельцам автомобилей</h2><p>Передайте автомобиль нам в субаренду и получайте доход, пока машина работает. Мы находим клиентов, оформляем договоры и следим за состоянием автомобиля.</p><ul><li>доход от автомобиля, который иначе простаивает;</li><li>договор и понятные условия;</li><li>проверка арендаторов и контроль возврата;</li><li>подачу и приём автомобиля берём на себя.</li></ul>'
                .'<h2>Компаниям</h2><p>Сотрудничаем с гостиницами, ресторанами, туристическими и ивент-агентствами, корпоративными клиентами. Ваши гости и сотрудники получают автомобиль с подачей в любую точку города и поддержкой 24/7. Условия обсудим индивидуально.</p>'
                .'<h2>Как начать</h2><p>Напишите нам в WhatsApp или позвоните — расскажем об условиях и подготовим предложение.</p>',
            'active' => true,
            'show_in_footer' => true,
            'legacy_meta' => ['release' => '2026-11'],
        ]);
        $translations = [
            'kk' => [
                'name' => 'Серіктестерге',
                'title' => 'Серіктестерге — көлікті қосалқы жалға беру және ынтымақтастық | LEGIONAUTORENT',
                'description' => 'LEGIONAUTORENT серіктесі болыңыз: көлігіңізді қосалқы жалға беріңіз немесе қонақтар мен қызметкерлерге арналған ынтымақтастықты ұсыныңыз. Астана, Қостанай, Өскемен, Павлодар.',
                'h1' => 'LEGIONAUTORENT серіктестеріне',
                'intro' => 'Көліктерді қосалқы жалға беру және компаниялармен ынтымақтастық.',
                'content' => '<h2>Біз серіктестер іздейміз</h2><p>LEGIONAUTORENT ынтымақтастыққа ашық. Сегіз жыл бойы Астанада, Қостанайда, Өскеменде және Павлодарда жүргізушісіз көлік жалға беріп келеміз және екі жаққа да тиімді серіктестік құрамыз.</p>'
                    .'<h2>Көлік иелеріне</h2><p>Көлігіңізді бізге қосалқы жалға беріп, ол жұмыс істеп тұрғанда табыс алыңыз. Біз клиенттерді табамыз, шарт жасаймыз және көліктің жай-күйін қадағалаймыз.</p><ul><li>бос тұрған көліктен табыс;</li><li>шарт және түсінікті шарттар;</li><li>жалға алушыларды тексеру және қайтаруды бақылау;</li><li>көлікті жеткізу мен қабылдауды өзімізге аламыз.</li></ul>'
                    .'<h2>Компанияларға</h2><p>Қонақүйлермен, мейрамханалармен, туристік және ивент-агенттіктермен, корпоративтік клиенттермен жұмыс істейміз. Қонақтарыңыз бен қызметкерлеріңіз көлікті қаланың кез келген нүктесіне жеткізумен және тәулік бойы қолдаумен алады. Шарттарды жеке талқылаймыз.</p>'
                    .'<h2>Қалай бастауға болады</h2><p>Бізге WhatsApp-қа жазыңыз немесе қоңырау шалыңыз — шарттарды айтып, ұсыныс дайындаймыз.</p>',
            ],
            'en' => [
                'name' => 'Partners',
                'title' => 'Partners — car sublease and cooperation | LEGIONAUTORENT',
                'description' => 'Become a LEGIONAUTORENT partner: sublease your car to us or offer car rental to your guests and staff. Astana, Kostanay, Ust-Kamenogorsk, Pavlodar.',
                'h1' => 'Partners of LEGIONAUTORENT',
                'intro' => 'Car sublease and cooperation with companies.',
                'content' => '<h2>We are looking for partners</h2><p>LEGIONAUTORENT is open to cooperation. For eight years we have rented out self-drive cars in Astana, Kostanay, Ust-Kamenogorsk and Pavlodar, and we build partnerships that benefit both sides.</p>'
                    .'<h2>For car owners</h2><p>Sublease your car to us and earn while it works. We find clients, sign the contracts and look after the car.</p><ul><li>income from a car that would otherwise stand idle;</li><li>a contract and clear terms;</li><li>renter checks and return control;</li><li>we handle delivery and return of the car.</li></ul>'
                    .'<h2>For companies</h2><p>We work with hotels, restaurants, travel and event agencies and corporate clients. Your guests and staff get a car delivered anywhere in the city with 24/7 support. Terms are agreed individually.</p>'
                    .'<h2>How to start</h2><p>Message us on WhatsApp or call — we will explain the terms and prepare an offer.</p>',
            ],
        ];
        foreach ($translations as $language => $values) {
            Translation::create(['content_type_id' => $page->contentTypeId(), 'object_id' => $page->id, 'language' => $language, 'published' => true, ...$values]);
        }
    }
};
