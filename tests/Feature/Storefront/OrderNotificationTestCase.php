<?php

namespace Tests\Feature\Storefront;

use App\Events\DigitalProductDownloadEvent;
use App\Events\OrderPlacedEvent;
use App\Mail\SendMail;
use App\Models\EmailTemplate;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\Translation;
use App\Models\User;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * An order that was really sold in a storefront, and the push service at its HTTP boundary, so a test reads the request that would have
 * left the server (I1-06).
 *
 * The order's storefront record is written by the platform's own writer, OrderStorefrontSnapshot::record, from the country of the products
 * on its lines — the same call the checkout makes — so no test here types the record whose effect it then asserts.
 */
abstract class OrderNotificationTestCase extends ApiAddressTestCase
{
    use BuildsStorefrontCatalog;

    protected const FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/lavkoo-test/messages:send';

    /** 'order' in resources/lang/en/messages.php and in resources/lang/sa/messages.php: the title of every order push. */
    protected const TITLE_IN_ENGLISH = 'Order';

    protected const TITLE_IN_ARABIC = 'النظام';

    protected const CUSTOMER_TEXT = 'Order {orderId} is pending';

    protected const SELLER_TEXT = 'A new order {orderId} is waiting';

    protected const DELIVERY_MAN_TEXT = 'Order {orderId} is assigned to you';

    /** What a promoter is told about the order of a customer they referred, in the platform's language and in Arabic. */
    protected const REFERRAL_TEXT_IN_ENGLISH = 'Your referred customer placed order {orderId}';

    protected const REFERRAL_TEXT_IN_ARABIC = 'عميلك طلب {orderId}';

    protected const KW_ORDER = 9101;

    protected const SA_ORDER = 9102;

    /** 30 as the platform stores it, which is 112.5 once converted into the riyal at the rate of 3.75 this fixture gives it. */
    protected const CHARGE_STORED = 30;

    protected const CHARGE_CONVERTED_TO_THE_PLATFORM_CURRENCY = '112.5';

    protected const CHARGE_AS_THE_ORDER_HOLDS_IT = '30';

    /** What one line of the order costs, as it is stored: 375 once converted into the riyal, 100 as a market with its own currency holds it. */
    protected const LINE_PRICE = 100;

    protected const LINE_CONVERTED_TO_THE_PLATFORM_CURRENCY = '375.00SAR';

    protected const LINE_AS_THE_ORDER_HOLDS_IT = '100.000KWD';

    protected const CAPTURING_MAILER = 'array';

    /** Measured on the base branch, with the listeners unwrapped: what a customer order push costs the database. */
    protected const QUERIES_OF_A_CUSTOMER_PUSH = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->addNotificationColumns();
        $this->runStorefrontCountryMigration();
        $this->runOrderSnapshotMigration();
        $this->seedCurrencies();
        $this->useMultiCurrencyWithRiyalAsDefault();
        $this->setStoreLanguages(default: 'en');
        $this->seedNotificationMessages();
        $this->configureThePushService();

        StorefrontCatalog::flush();
    }

    private function addNotificationColumns(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('cm_firebase_token')->nullable();
            $table->string('app_language')->nullable();
        });
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('app_language')->nullable();
        });
        Schema::table('referral_customers', static function (Blueprint $table): void {
            $table->unsignedBigInteger('refer_by')->nullable();
            $table->boolean('ordered_notify')->default(false);
        });
        Schema::table('orders', static fn (Blueprint $table) => $table->decimal('deliveryman_charge', 16, 2)->nullable());
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('app_language')->nullable();
            $table->timestamps();
        });
        Schema::create('deliveryman_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('user_type');
            $table->string('key');
            $table->text('message')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('email_templates', static function (Blueprint $table): void {
            $table->id();
            $table->string('template_name')->nullable();
            $table->string('user_type')->nullable();
            $table->string('template_design_name')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('image')->nullable();
            $table->string('logo')->nullable();
            $table->string('button_name')->nullable();
            $table->string('button_url')->nullable();
            $table->string('footer_text')->nullable();
            $table->string('copyright_text')->nullable();
            $table->text('pages')->nullable();
            $table->text('social_media')->nullable();
            $table->text('hide_field')->nullable();
            $table->integer('button_content_status')->default(1);
            $table->integer('product_information_status')->default(1);
            $table->integer('order_information_status')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('social_medias', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('link')->nullable();
            $table->string('icon')->nullable();
            $table->integer('active_status')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
    }

    /** The e-mail the platform sends when an order is placed, with an Arabic title for the market that reads Arabic. */
    protected function seedOrderPlacedEmailTemplate(string $titleInEnglish, string $titleInArabic, ?string $design = null): void
    {
        $this->seedEmailTemplate(
            templateName: 'order-place',
            userType: 'customer',
            titleInEnglish: $titleInEnglish,
            titleInArabic: $titleInArabic,
            design: $design,
        );
    }

    /** Any of the platform's e-mail templates, with an Arabic title row beside the English one. */
    protected function seedEmailTemplate(string $templateName, string $userType, string $titleInEnglish, string $titleInArabic, ?string $design = null): void
    {
        $template = EmailTemplate::create([
            'template_name' => $templateName, 'user_type' => $userType, 'title' => $titleInEnglish,
            'body' => 'Order {orderId} is on its way', 'status' => 1, 'template_design_name' => $design,
        ]);
        Translation::create([
            'translationable_type' => EmailTemplate::class, 'translationable_id' => $template->id,
            'locale' => 'sa', 'key' => 'title', 'value' => $titleInArabic,
        ]);
    }

    /**
     * The body of the one e-mail that left, as the mailer rendered it. Mail::fake() is deliberately NOT used here: it captures the mailable
     * and leaves the view unrendered, so the amounts would be read afterwards, outside the storefront the send ran in. The real mailer
     * renders inside Mail::to()->send(), which is inside the listener's wrap, exactly as on a server.
     */
    protected function bodyOfTheEmailTheMailerSent(): string
    {
        $messages = Mail::mailer(self::CAPTURING_MAILER)->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages, 'exactly one e-mail left the server');

        return (string) $messages[0]->getOriginalMessage()->getHtmlBody();
    }

    /** Who the one e-mail says it is from, as the mailer was given it: the name and the address of the sender. */
    protected function senderOfTheEmailSent(): array
    {
        $messages = Mail::mailer(self::CAPTURING_MAILER)->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages, 'exactly one e-mail left the server');
        $from = $messages[0]->getOriginalMessage()->getFrom()[0];

        return ['name' => $from->getName(), 'address' => $from->getAddress()];
    }

    /** The mailer that keeps what it was given, so the view is rendered where the send happens. */
    protected function captureTheEmailsTheMailerRenders(): void
    {
        config(['mail.default' => self::CAPTURING_MAILER]);
    }

    /** The title the platform chose for the one e-mail it sent, as it handed it to the mailable. */
    protected function titleOfTheEmailSent(): string
    {
        $titles = [];
        Mail::assertSent(SendMail::class, static function (SendMail $mail) use (&$titles): bool {
            $titles[] = (fn (): string => (string) $this->template['title'])->call($mail);

            return true;
        });

        return $titles[0];
    }

    private function runOrderSnapshotMigration(): void
    {
        $migration = require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php');
        $migration->up();
    }

    private function seedCurrencies(): void
    {
        DB::table('currencies')->where('id', 1)->update(['code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 3.75, 'status' => 1]);

        foreach (['USD' => 1.0, 'KWD' => 0.307, 'AED' => 3.6725] as $code => $rate) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }
    }

    /** The platform the six markets are added to: one base currency, the riyal, and every other amount converted into it. */
    private function useMultiCurrencyWithRiyalAsDefault(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => json_encode('multi_currency')]);
        DB::table('business_settings')->updateOrInsert(['type' => 'system_default_currency'], ['value' => json_encode(1)]);
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => json_encode(2)]);
        Cache::flush();
    }

    protected function setStoreLanguages(string $default): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'language'], ['value' => json_encode([
            ['id' => 1, 'name' => 'English', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => $default === 'en'],
            ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'sa', 'status' => 1, 'default' => $default === 'sa'],
        ])]);
        Cache::flush();
        session()->forget('local');
    }

    private function seedNotificationMessages(): void
    {
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'order_pending_message', 'message' => self::CUSTOMER_TEXT, 'status' => 1]);
        NotificationMessage::create(['user_type' => 'seller', 'key' => 'new_order_message', 'message' => self::SELLER_TEXT, 'status' => 1]);
        NotificationMessage::create(['user_type' => 'delivery_man', 'key' => 'new_order_assigned_message', 'message' => self::DELIVERY_MAN_TEXT, 'status' => 1]);
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'order_refunded_message', 'message' => 'Order {orderId} was refunded', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'order_edit_return_amount_message', 'message' => 'Order {orderId} has an amount to return', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'order_edit_message', 'message' => 'Order {orderId} was edited', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'seller', 'key' => 'order_edit_due_payment_message', 'message' => 'Order {orderId} has a payment due', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'seller', 'key' => 'order_refunded_message', 'message' => 'Order {orderId} was refunded to the buyer', 'status' => 1]);
        NotificationMessage::create(['user_type' => 'customer', 'key' => 'refund_request_canceled_message', 'message' => 'Order {orderId} refund was rejected', 'status' => 1]);
        $referral = NotificationMessage::create(['user_type' => 'customer', 'key' => 'your_referred_customer_has_been_place_order', 'message' => self::REFERRAL_TEXT_IN_ENGLISH, 'status' => 1]);
        Translation::create([
            'translationable_type' => NotificationMessage::class, 'translationable_id' => $referral->id,
            'locale' => 'sa', 'key' => 'message', 'value' => self::REFERRAL_TEXT_IN_ARABIC,
        ]);
    }

    /** The push service as the platform configures it: a service account in the business settings, Google's token endpoint and FCM both answering. */
    private function configureThePushService(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'push_notification_key'], ['value' => json_encode([
            'project_id' => 'lavkoo-test', 'client_email' => 'push@lavkoo-test.example', 'private_key' => config('passport.private_key'),
        ])]);
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'minted-access-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/1']),
        ]);
    }

    protected function switchStorefrontsOn(bool $withTheirOwnCurrency = true): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => $withTheirOwnCurrency,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        StorefrontCatalog::flush();
    }

    /** The storefront a request is being served from, as the middleware binds it. */
    protected function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    /**
     * An order really sold in one storefront: a product of that country, a line that holds it, and the platform's own snapshot writer asked
     * to record what the order was sold in, exactly as the checkout asks it. The switches are turned on for the sale and put back, so a test
     * of the switched-off platform still works on an order that carries a record.
     */
    protected function sellOrderIn(string $country, int $orderId, array $orderColumns = []): Order
    {
        $wasEnabled = config('gcc.storefronts.enabled');
        $wasCurrencyEnabled = config('gcc.storefronts.currency.enabled');
        $this->switchStorefrontsOn();

        $productId = $orderId;
        $this->insertCatalogProduct(id: $productId, name: "product of {$country}", price: 100, stock: 5, country: $country);
        $this->insertOrder($orderId, $orderColumns);
        DB::table('order_details')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'qty' => 1, 'price' => self::LINE_PRICE,
            'product_details' => json_encode(['id' => $productId, 'name' => "product of {$country}", 'product_type' => 'physical']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(OrderStorefrontSnapshot::class)->record(orderId: $orderId);

        config(['gcc.storefronts.enabled' => $wasEnabled, 'gcc.storefronts.currency.enabled' => $wasCurrencyEnabled]);
        StorefrontCatalog::flush();

        return Order::findOrFail($orderId);
    }

    /** How many queries the platform ran while the notification was being built, so an extra lookup cannot slip in unnoticed. */
    protected int $queriesOfTheNotification = 0;

    protected function countTheQueriesOfTheNotification(): void
    {
        $this->queriesOfTheNotification = 0;
        DB::listen(function (): void {
            $this->queriesOfTheNotification++;
        });
    }

    protected function customerWhoReadsArabic(): User
    {
        return $this->makeCustomer(['cm_firebase_token' => 'customer-device', 'app_language' => 'sa', 'f_name' => 'Noura', 'l_name' => 'Al Sabah']);
    }

    protected function sellerWhoReadsArabic(): object
    {
        return $this->sellerWhoReads('sa');
    }

    /** A seller whose own language is whatever is asked for, so a seller notification that read the customer's language instead would show. */
    protected function sellerWhoReads(?string $language): object
    {
        $id = DB::table('sellers')->insertGetId([
            'f_name' => 'Hamad', 'status' => 'approved', 'email' => 'seller@example.test',
            'cm_firebase_token' => 'seller-device', 'app_language' => $language, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id];
    }

    /** The promoter behind a customer, as the referral record names them: the sender finds this one itself, through refer_by. */
    protected function promoterWhoReads(int $customerId, ?string $language): User
    {
        $promoter = $this->makeCustomer(['cm_firebase_token' => 'promoter-device', 'app_language' => $language, 'f_name' => 'Faisal', 'l_name' => 'Al Harbi']);
        DB::table('referral_customers')->insert([
            'user_id' => $customerId, 'refer_by' => $promoter->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $promoter;
    }

    protected function deliveryMan(int $id, string $token, ?string $language): object
    {
        DB::table('delivery_men')->insert([
            'id' => $id, 'f_name' => 'Rider', 'l_name' => (string) $id, 'fcm_token' => $token,
            'app_language' => $language, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id];
    }

    /** The order-placed notification as OrderManager raises it, through the queued listener. */
    protected function queueTheOrderPlacedNotification(Order $order): void
    {
        event(new OrderPlacedEvent(notification: (object) [
            'key' => 'new_order_assigned_message', 'type' => 'delivery_man', 'order' => $order,
        ]));
    }

    /** The order-placed e-mail as OrderManager raises it, through the queued listener. */
    protected function placeOrderMailFor(Order $order, string $userType = 'customer', string $templateName = 'order-place'): void
    {
        event(new OrderPlacedEvent(email: 'buyer@example.test', data: [
            'subject' => 'order placed', 'title' => 'order placed', 'userName' => 'Noura',
            'userType' => $userType, 'templateName' => $templateName, 'order' => $order, 'orderId' => $order->id,
        ]));
    }

    /** The download e-mail a till sends about a digital order, as the three POS controllers raise it, through the queued listener. */
    protected function sendTheDownloadMailFor(Order $order): void
    {
        event(new DigitalProductDownloadEvent(email: 'buyer@example.test', data: [
            'subject' => 'download', 'title' => 'download', 'userName' => 'Noura',
            'userType' => 'customer', 'templateName' => 'digital-product-download', 'order' => $order, 'orderId' => $order->id,
        ]));
    }

    protected function theOnePushSent(): Request
    {
        $pushes = $this->pushesSent();
        $this->assertCount(1, $pushes, 'exactly one push left the server');

        return $pushes[0];
    }

    /** @return list<Request> every push that left the server, in the order it left */
    protected function pushesSent(): array
    {
        return Http::recorded(static fn (Request $request): bool => str_starts_with($request->url(), 'https://fcm.googleapis.com/'))
            ->map(static fn (array $exchange): Request => $exchange[0])
            ->values()
            ->all();
    }
}
