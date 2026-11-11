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
 * November 2026: concrete rental terms and the video reviews section.
 *
 * The terms replace the imported page and condition block only while they still hold the original text.
 */
return new class extends Migration
{
    public const TERMS = [
        'old' => ['ru' => '<h2>Документы</h2><p>Для аренды нужны водительское удостоверение и удостоверение личности.</p>', 'kk' => '<h2>Құжаттар</h2><p>Жалға алу үшін жүргізуші куәлігі мен жеке куәлік қажет.</p>', 'en' => '<h2>Documents</h2><p>A driving licence and an identity document are required.</p>'],
        'ru' => '<h2>Возраст и стаж</h2><ul><li>Арендатору должно быть не меньше 21 года.</li><li>Водительский стаж — от 3 лет.</li></ul>'
            .'<h2>Документы</h2><ul><li>Удостоверение личности или паспорт.</li><li>Водительское удостоверение.</li></ul>'
            .'<h2>Оплата</h2><ul><li>Наличными или безналичным переводом.</li><li>Аренда оплачивается до начала поездки.</li></ul>'
            .'<h2>Залог</h2><ul><li>Сумма залога зависит от модели автомобиля.</li><li>Залог возвращается сразу после возврата автомобиля.</li><li>Выдача и возврат оформляются актом приёма-передачи.</li></ul>'
            .'<h2>Пробег</h2><ul><li>До 250 км в сутки.</li><li>Выезд за город оплачивается отдельно.</li><li>Перепробег — от 80 до 200 ₸ за километр в зависимости от автомобиля.</li></ul>'
            .'<h2>Страхование</h2><ul><li>Каждый автомобиль застрахован по ОГПО.</li><li>Управлять автомобилем может только арендатор.</li></ul>'
            .'<h2>Срок аренды</h2><ul><li>Срок отсчитывается с момента подписания акта приёма-передачи.</li><li>Автомобиль нужно вернуть не позднее чем через час после окончания срока; за опоздание начисляется почасовая оплата.</li></ul>'
            .'<h2>Подача и поддержка 24/7</h2><ul><li>Подача и приём автомобиля в любой точке города входят в стоимость аренды.</li><li>Выдача автомобилей и поддержка работают круглосуточно, без выходных.</li></ul>'
            .'<h2>Ограничения</h2><ul><li>Автомобили не сдаются для работы в такси и доставки.</li></ul>',
        'kk' => '<h2>Жас және өтіл</h2><ul><li>Жалға алушы кемінде 21 жаста болуы керек.</li><li>Жүргізу өтілі — 3 жылдан бастап.</li></ul>'
            .'<h2>Құжаттар</h2><ul><li>Жеке куәлік немесе төлқұжат.</li><li>Жүргізуші куәлігі.</li></ul>'
            .'<h2>Төлем</h2><ul><li>Қолма-қол немесе қолма-қолсыз аударым арқылы.</li><li>Жалдау ақысы сапар басталғанға дейін төленеді.</li></ul>'
            .'<h2>Кепіл</h2><ul><li>Кепіл сомасы көлік үлгісіне байланысты.</li><li>Кепіл көлік қайтарылғаннан кейін бірден қайтарылады.</li><li>Көлікті беру және қайтару қабылдау-тапсыру актісімен рәсімделеді.</li></ul>'
            .'<h2>Жүріс</h2><ul><li>Тәулігіне 250 км-ге дейін.</li><li>Қала сыртына шығу бөлек төленеді.</li><li>Артық жүріс — көлікке байланысты бір километрге 80-нен 200 ₸-ге дейін.</li></ul>'
            .'<h2>Сақтандыру</h2><ul><li>Әр көлік МАЖС (ОГПО) бойынша сақтандырылған.</li><li>Көлікті тек жалға алушы басқара алады.</li></ul>'
            .'<h2>Жалдау мерзімі</h2><ul><li>Мерзім қабылдау-тапсыру актісіне қол қойылған сәттен басталады.</li><li>Көлікті мерзім аяқталғаннан кейін бір сағаттан кешіктірмей қайтару керек; кешіккені үшін сағаттық ақы алынады.</li></ul>'
            .'<h2>Жеткізу және 24/7 қолдау</h2><ul><li>Көлікті қаланың кез келген нүктесінде беру және қабылдау жалдау бағасына кіреді.</li><li>Көлік беру және қолдау тәулік бойы, демалыссыз жұмыс істейді.</li></ul>'
            .'<h2>Шектеулер</h2><ul><li>Көліктер такси және жеткізу жұмысына берілмейді.</li></ul>',
        'en' => '<h2>Age and experience</h2><ul><li>The renter must be at least 21 years old.</li><li>At least 3 years of driving experience.</li></ul>'
            .'<h2>Documents</h2><ul><li>ID card or passport.</li><li>Driving licence.</li></ul>'
            .'<h2>Payment</h2><ul><li>Cash or bank transfer.</li><li>The rental is paid before the trip starts.</li></ul>'
            .'<h2>Deposit</h2><ul><li>The deposit depends on the car model.</li><li>The deposit is returned as soon as the car is returned.</li><li>Handover and return are recorded in a handover certificate.</li></ul>'
            .'<h2>Mileage</h2><ul><li>Up to 250 km per day.</li><li>Trips outside the city are charged separately.</li><li>Extra mileage costs 80 to 200 KZT per kilometre depending on the car.</li></ul>'
            .'<h2>Insurance</h2><ul><li>Every car has compulsory third-party liability insurance (OGPO).</li><li>Only the renter may drive the car.</li></ul>'
            .'<h2>Rental period</h2><ul><li>The rental starts when the handover certificate is signed.</li><li>Return the car no later than one hour after the rental ends; late returns are charged by the hour.</li></ul>'
            .'<h2>Delivery and 24/7 support</h2><ul><li>Delivery and return anywhere in the city are included in the rental price.</li><li>Car handover and support work around the clock, with no days off.</li></ul>'
            .'<h2>Restrictions</h2><ul><li>Cars are not rented for taxi or delivery work.</li></ul>',
    ];

    public const CONDITION = [
        'old' => 'Возраст, стаж, депозит, пробег и территорию поездок уточните у менеджера до подписания договора.',
        'ru' => ['Возраст, залог и пробег', 'Арендатору — от 21 года, стаж — от 3 лет. Залог зависит от модели, пробег — до 250 км в сутки.'],
        'kk' => ['Жас, кепіл және жүріс', 'Жалға алушы — 21 жастан, өтілі — 3 жылдан. Кепіл үлгіге байланысты, жүріс — тәулігіне 250 км-ге дейін.'],
        'en' => ['Age, deposit and mileage', 'Renter aged 21+, with 3+ years of driving. The deposit depends on the model; mileage up to 250 km per day.'],
    ];

    public function up(): void
    {
        if (! SiteSettings::find(1)) {
            return;
        }

        DB::transaction(function (): void {
            if (! SiteSection::where('key', 'reviews')->exists()) {
                SiteSection::create(['key' => 'reviews', 'active' => true, 'sort_order' => 48]);
            }
            $this->terms();
            $this->condition();
        });
        PublicContent::forget();
    }

    public function down(): void
    {
        SiteSection::where('key', 'reviews')->delete();
    }

    private function terms(): void
    {
        $page = Page::where('path', '/rental-conditions/')->first();
        if (! $page) {
            return;
        }
        if (str_starts_with((string) $page->body, self::TERMS['old']['ru'])) {
            DB::table('pages_page')->where('id', $page->id)->update(['body' => self::TERMS['ru'], 'updated_at' => now()]);
        }
        foreach (['kk', 'en'] as $language) {
            Translation::where('content_type_id', $page->contentTypeId())->where('object_id', $page->id)->where('language', $language)
                ->where('content', 'like', self::TERMS['old'][$language].'%')
                ->update(['content' => self::TERMS[$language], 'updated_at' => now()]);
        }
    }

    private function condition(): void
    {
        $block = ContentBlock::where('kind', 'condition')->where('text', self::CONDITION['old'])->first();
        if (! $block) {
            return;
        }
        DB::table('core_contentblock')->where('id', $block->id)->update(['title' => self::CONDITION['ru'][0], 'text' => self::CONDITION['ru'][1]]);
        foreach (['kk', 'en'] as $language) {
            Translation::where('content_type_id', $block->contentTypeId())->where('object_id', $block->id)->where('language', $language)
                ->update(['title' => self::CONDITION[$language][0], 'content' => self::CONDITION[$language][1], 'updated_at' => now()]);
        }
    }
};
