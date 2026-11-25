<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\Admin\Notification\NotificationController;
use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Http\Requests\Admin\NotificationRequest;
use App\Models\Product;
use App\Models\RestockProduct;
use App\Services\ProductService;
use App\Services\Theme\ThemeSyncBeacon;
use App\Traits\PushNotificationTrait;
use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakesThePushService;

/**
 * The topics that Saudi apps and the browser panels already hold keep receiving messages only while the backend keeps naming them the same.
 * A topic is a name the backend publishes to (FCM) or hands to a client to subscribe to. This pins every one of them in four ways: a hand-written
 * list (the name, what the backend does with it, the file that does it); a scan that fails on a topic name written in the code that is not on
 * the list; a scan of every way a message reaches FCM, each against a hand-written list (the files that call the one sending method or name the
 * FCM host, the value of the topic key of every message those files build, the arguments of every call of the campaign push and of the client
 * subscription); and the name as it leaves the server (published) or is handed to the browser (subscribed), through the real code of each
 * publisher. A topic renamed or added is then a decision made on this list, not an accident.
 *
 * What the scans do not see: a message that is not built from a literal `topic` key, or that reaches the sending method by another name than
 * its own, or that is sent by another HTTP call than the two that name the FCM host (the legacy sender of app/Utils/Helpers.php addresses a
 * device token and is not read further); and the apps. The customer app, the seller app and the delivery-man app subscribe to their topics in
 * their own code; no test here (or anywhere in this repository) reads that code, so a topic renamed on that side is not seen by this test.
 */
class PushTopicNamesTest extends ApiAddressTestCase
{
    use FakesThePushService;

    private const SCANNED_DIRECTORIES = ['app', 'Modules', 'routes', 'config'];

    private const CONTROLLER = 'app/Http/Controllers/Admin/Settings/BusinessSettingsController.php';

    private const TRAIT = 'app/Traits/PushNotificationTrait.php';

    private const SETTINGS_HELPER = 'app/Utils/settings.php';

    private const CAMPAIGN_CONTROLLER = 'app/Http/Controllers/Admin/Notification/NotificationController.php';

    /**
     * @var list<array{name: string, use: string, file: string, text: string}> every topic, the file that uses it and the text of that file that proves it
     */
    private const TOPICS = [
        ['name' => 'sixvalley', 'use' => 'the topic of every campaign push (the admin campaign and its resend name no other)', 'file' => self::TRAIT, 'text' => 'string $topic = \'sixvalley\''],
        ['name' => 'sixvalley', 'use' => 'subscribed to by the website, the admin panel and the vendor panel', 'file' => self::SETTINGS_HELPER, 'text' => '$topics = [\'sixvalley\', \'maintenance_mode_start_user_app\']'],
        ['name' => 'maintenance_mode_start_user_app', 'use' => 'published to when the maintenance switch of the customer apps changes', 'file' => self::CONTROLLER, 'text' => 'topic: \'maintenance_mode_start_user_app\''],
        ['name' => 'maintenance_mode_start_user_app', 'use' => 'subscribed to by the website, the admin panel and the vendor panel', 'file' => self::SETTINGS_HELPER, 'text' => '$topics = [\'sixvalley\', \'maintenance_mode_start_user_app\']'],
        ['name' => 'maintenance_mode_start_vendor', 'use' => 'published to when the maintenance switch of the vendor app changes', 'file' => self::CONTROLLER, 'text' => 'topic: \'maintenance_mode_start_vendor\''],
        ['name' => 'maintenance_mode_start_deliveryman', 'use' => 'published to when the maintenance switch of the delivery-man app changes', 'file' => self::CONTROLLER, 'text' => 'topic: \'maintenance_mode_start_deliveryman\''],
        ['name' => 'theme_updates_user_app', 'use' => 'published to by the theme beacon on every publish; every customer app subscribes at startup', 'file' => 'app/Services/Theme/ThemeSyncBeacon.php', 'text' => 'public const TOPIC = \'theme_updates_user_app\''],
        ['name' => 'demo_reset', 'use' => 'published to before the demo database is wiped (the type of the message is its topic)', 'file' => self::TRAIT, 'text' => '\'type\' => \'demo_reset\''],
        ['name' => 'restock_{id}_product_restock_{product_id}_topic', 'use' => 'one per restock request: handed to the customer app as fcm_topic, published to when the product is restocked', 'file' => 'app/Utils/product.php', 'text' => '\'restock_\' . $restockRequest[\'id\'] . \'_product_restock_\' . $restockRequest->product_id . \'_topic\''],
    ];

    /** The pages that subscribe the browser to the list of getFCMTopicListToSubscribe(): the admin panel, the vendor panel and the website. */
    private const PAGES_THAT_SUBSCRIBE_TO_THE_LIST = [
        'resources/views/layouts/admin/partials/_firebase-script.blade.php',
        'resources/views/vendor-views/auth/forgot-password/index.blade.php',
        'resources/themes/default/layouts/front-end/partials/_firebase-script.blade.php',
    ];

    /** The ways this code base writes a topic name: a named argument, an array key, a variable or a constant called topic. A name an FCM topic can have only. */
    private const TOPIC_NAME_WRITTEN = [
        '/\btopic\s*:\s*([\'"])(?<name>[\w.~%-]+)\1/',
        '/([\'"])topic\1\s*=>\s*([\'"])(?<name>[\w.~%-]+)\2/',
        '/\$topic\s*=\s*([\'"])(?<name>[\w.~%-]+)\1/',
        '/\bconst\s+(?:\w+_)?TOPIC\s*=\s*([\'"])(?<name>[\w.~%-]+)\1/',
    ];

    /** The value of the `topic` key of a message, wherever in the message the key is written. */
    private const TOPIC_KEY_OF_A_MESSAGE = '/[\'"]topic[\'"]\s*=>\s*(?<value>[^,\n]+?)\s*(?:,|\n)/';

    /** The method every message reaches FCM through, and the host a message that does not go through it must name. */
    private const SENDING_METHOD = 'sendNotificationToHttp';

    private const FCM_HOST = 'fcm.googleapis.com';

    /** @var array<string, int> every file that calls the sending method, with how many times: a device is addressed by a token and a topic by a name, both keys of the message it is given */
    private const SENDERS = [
        'app/Listeners/MaintenanceModeNotificationListener.php' => 1,
        'app/Listeners/RequestProductRestockListener.php' => 1,
        'app/Listeners/RestockProductNotificationListener.php' => 1,
        'app/Services/Theme/ThemeSyncBeacon.php' => 1,
        self::TRAIT => 3,
    ];

    /** @var list<string> every file that names the FCM host: the trait and the legacy sender of the delivery-man notifications */
    private const FILES_THAT_NAME_THE_FCM_HOST = [self::TRAIT, 'app/Utils/Helpers.php'];

    /** @var array<string, list<string>> the value of the `topic` key of every message the senders build: where each one gets the topic it publishes to */
    private const TOPIC_KEYS = [
        'app/Listeners/MaintenanceModeNotificationListener.php' => ['$data[\'topic\']'],
        'app/Listeners/RestockProductNotificationListener.php' => ['$data[\'topic\']'],
        'app/Services/Theme/ThemeSyncBeacon.php' => ['self::TOPIC'],
        self::TRAIT => ['$topic'],
    ];

    /**
     * @var array<string, list<string>> the arguments of every call of the campaign push: the admin broadcast and its resend each name the
     * topic of ONE market they are for (I1-04), which is the platform-wide `sixvalley` when the broadcast names no market -- see
     * BroadcastMarkets::topicsFor(); the demo reset names its own
     */
    private const CAMPAIGN_CALLS = [
        self::CAMPAIGN_CONTROLLER => ['data: $notification, topic: $topic', 'data: $notification, topic: $topic'],
        self::TRAIT => ['data: $data, topic: $data[\'type\']'],
    ];

    /** @var array<string, list<string>> the arguments of every call that subscribes a client to a topic: the name the client asked for */
    private const CLIENT_SUBSCRIPTIONS = ['app/Http/Controllers/FirebaseController.php' => ['$topic, $token']];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.mode' => 'live']);
        $this->createOrderPipelineTables();
        $this->configureThePushService();
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, list<string>> the topic names written in the code, with the files that write them
     */
    private function topicNamesWrittenInTheCode(array $sources): array
    {
        $written = [];
        foreach ($sources as $file => $source) {
            $names = [];
            foreach (self::TOPIC_NAME_WRITTEN as $pattern) {
                preg_match_all($pattern, $source, $matches);
                $names = [...$names, ...$matches['name']];
            }
            if (preg_match_all('/\$topics\s*=\s*\[(?<list>[^\]]*)\]/', $source, $lists) > 0) {
                foreach ($lists['list'] as $list) {
                    preg_match_all('/([\'"])(?<name>[\w.~%-]+)\1/', $list, $matches);
                    $names = [...$names, ...$matches['name']];
                }
            }
            foreach ($names as $name) {
                $written[$name][] = $file;
            }
        }
        ksort($written);

        return array_map(static fn (array $files): array => array_values(array_unique($files)), $written);
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, list<string>> the arguments of every call of the method as written (its own definition is not a call), by the file that calls it
     */
    private function callsOf(string $method, array $sources): array
    {
        $calls = [];
        foreach ($sources as $file => $source) {
            preg_match_all('/(?<!function )\b' . $method . '\s*(?<arguments>\((?:[^()]++|(?&arguments))*\))/', $source, $matches);
            if ($matches['arguments'] !== []) {
                $calls[$file] = array_map(static fn (string $arguments): string => trim((string) preg_replace('/\s+/', ' ', substr($arguments, 1, -1))), $matches['arguments']);
            }
        }
        ksort($calls);

        return $calls;
    }

    /** @return array<string, string> source by path relative to the project, for every PHP file of the scanned directories */
    private function phpFilesOfTheCode(): array
    {
        $sources = [];
        foreach (self::SCANNED_DIRECTORIES as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        $this->assertGreaterThan(500, count($sources), 'the scan read the code: a scan of nothing passes');

        return $sources;
    }

    /** @return list<string> */
    private function topicsPublished(): array
    {
        return array_map(static fn ($push): string => $push['message']['topic'], $this->pushesSent());
    }

    /** A class that publishes the way the listeners and controllers do: through the trait, with nothing of its own. */
    private function publisher(): object
    {
        return new class {
            use PushNotificationTrait;

            public function campaign(array $campaign): void
            {
                $this->sendPushNotificationToTopic($campaign);
            }

            public function demoReset(): void
            {
                $this->demoResetNotification();
            }
        };
    }

    private function createTheNotificationTables(): void
    {
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('cm_firebase_token')->nullable();
        });
        Schema::create('notifications', static function (Blueprint $table): void {
            $table->id();
            $table->string('sent_by')->nullable();
            $table->string('sent_to')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->string('image')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function test_every_topic_of_the_list_is_still_used_in_the_file_the_list_names(): void
    {
        foreach (self::TOPICS as $topic) {
            $this->assertStringContainsString(
                $topic['text'],
                (string) file_get_contents(base_path($topic['file'])),
                "{$topic['name']} ({$topic['use']}) is no longer used in {$topic['file']}: the apps that hold it stop receiving",
            );
        }
        foreach (self::PAGES_THAT_SUBSCRIBE_TO_THE_LIST as $page) {
            $this->assertStringContainsString('getFCMTopicListToSubscribe()', (string) file_get_contents(base_path($page)), "{$page} no longer subscribes the browser to the list of topics");
        }
    }

    public function test_the_code_names_no_topic_that_is_not_on_the_list_and_none_of_the_literal_topics_is_gone(): void
    {
        $listed = array_column(self::TOPICS, 'name');
        $written = $this->topicNamesWrittenInTheCode(sources: $this->phpFilesOfTheCode());

        $this->assertSame([], array_values(array_diff(array_keys($written), $listed)), 'a topic is written in the code that this list does not know: a renamed or a new topic is a decision, add it here on purpose');
        $this->assertEqualsCanonicalizing(
            ['maintenance_mode_start_deliveryman', 'maintenance_mode_start_user_app', 'maintenance_mode_start_vendor', 'sixvalley', 'theme_updates_user_app'],
            array_keys($written),
            'the scan finds the five topics that are written as text; demo_reset and the restock topics are built, and pinned on the wire below',
        );
    }

    public function test_only_the_known_files_send_a_message_to_the_push_service(): void
    {
        $sources = $this->phpFilesOfTheCode();

        $this->assertSame(self::SENDERS, array_map('count', $this->callsOf(method: self::SENDING_METHOD, sources: $sources)), 'a file sends a message to FCM that this list does not know: say here which topic or device it addresses');
        $filesThatNameTheHost = array_keys(array_filter($sources, static fn (string $source): bool => str_contains($source, self::FCM_HOST)));
        sort($filesThatNameTheHost);
        $this->assertSame(self::FILES_THAT_NAME_THE_FCM_HOST, $filesThatNameTheHost, 'a file reaches FCM without the sending method: say here which topic or device it addresses');
    }

    public function test_every_message_the_senders_build_names_its_topic_where_the_list_says(): void
    {
        $sources = $this->phpFilesOfTheCode();

        $topicKeys = [];
        foreach (array_keys(self::SENDERS) as $file) {
            if (preg_match_all(self::TOPIC_KEY_OF_A_MESSAGE, $sources[$file], $matches) > 0) {
                $topicKeys[$file] = $matches['value'];
            }
        }
        ksort($topicKeys);

        $this->assertSame(self::TOPIC_KEYS, $topicKeys, 'a message is addressed to a topic from a place this list does not know, or the topic of a known message comes from somewhere else');
    }

    public function test_the_campaign_push_is_called_with_the_arguments_the_list_names_and_a_client_is_subscribed_from_the_known_place_only(): void
    {
        $sources = $this->phpFilesOfTheCode();

        $this->assertSame(self::CAMPAIGN_CALLS, $this->callsOf(method: 'sendPushNotificationToTopic', sources: $sources), 'a campaign push is sent from a new place or names a topic of its own');
        $this->assertSame(self::CLIENT_SUBSCRIPTIONS, $this->callsOf(method: 'subscribeToTopic', sources: $sources), 'a client is subscribed to a topic from a new place');
    }

    public function test_the_theme_beacon_publishes_on_the_topic_every_customer_app_subscribes_to_at_startup(): void
    {
        (new ThemeSyncBeacon())->announce(revision: 7);

        $this->assertSame(['theme_updates_user_app'], $this->topicsPublished());
        $this->assertSame(self::FCM_SEND_URL, $this->pushesSent()[0]->url());
    }

    public function test_the_maintenance_switches_publish_on_the_three_maintenance_topics(): void
    {
        DB::table('business_settings')->insert(['type' => 'business_mode', 'value' => 'multi']);
        $everySystem = ['maintenance_mode' => 1, 'user_app' => 1, 'user_website' => 1, 'vendor_app' => 1, 'deliveryman_app' => 1, 'vendor_panel' => 1, 'maintenance_duration' => 'until_change'];

        $response = app(BusinessSettingsController::class)->updateSystemMode(Request::create(
            uri: '/admin/business-settings/maintenance-mode',
            method: 'POST',
            parameters: $everySystem,
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        ));

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(['maintenance_mode_start_user_app', 'maintenance_mode_start_vendor', 'maintenance_mode_start_deliveryman'], $this->topicsPublished());
    }

    public function test_a_campaign_push_goes_to_sixvalley_when_no_topic_is_named(): void
    {
        $this->publisher()->campaign(['title' => 'Weekend sale', 'description' => 'Ten per cent off', 'type' => 'notification']);

        $this->assertSame(['sixvalley'], $this->topicsPublished());
    }

    public function test_the_admin_campaign_and_its_resend_are_published_on_sixvalley(): void
    {
        $this->createTheNotificationTables();
        $controller = app(NotificationController::class);

        $controller->add(NotificationRequest::create(uri: '/admin/notification/add', method: 'POST', parameters: ['title' => 'Weekend sale', 'description' => 'Ten per cent off']));
        $response = $controller->resendNotification(Request::create(uri: '/admin/notification/resend-notification', method: 'POST', parameters: ['id' => DB::table('notifications')->value('id')]));

        $this->assertTrue($response->getData(true)['success'], 'the resend reached the push');
        $this->assertSame(['sixvalley', 'sixvalley'], $this->topicsPublished());
    }

    public function test_the_demo_reset_alert_is_published_on_the_topic_demo_reset(): void
    {
        $this->publisher()->demoReset();

        $this->assertSame(['demo_reset'], $this->topicsPublished());
    }

    public function test_the_browser_panels_subscribe_to_sixvalley_the_maintenance_topic_and_the_restock_topics_of_the_customer(): void
    {
        $this->assertEqualsCanonicalizing(['sixvalley', 'maintenance_mode_start_user_app'], getFCMTopicListToSubscribe());

        session()->put('customer_fcm_topic', ['restock_7_product_restock_9_topic']);

        $this->assertEqualsCanonicalizing(['restock_7_product_restock_9_topic', 'sixvalley', 'maintenance_mode_start_user_app'], getFCMTopicListToSubscribe());
    }

    public function test_a_restock_request_has_its_own_topic_and_a_restock_notification_is_published_on_it(): void
    {
        $restockRequest = (new RestockProduct())->forceFill(['id' => 7, 'product_id' => 9]);
        $restockRequest->setRelation('product', (new Product())->forceFill(['id' => 9, 'name' => 'Paracetamol 500', 'slug' => 'paracetamol-500', 'thumbnail' => 'paracetamol.png']));

        $this->assertSame('restock_7_product_restock_9_topic', getRestockProductFCMTopic(restockRequest: $restockRequest));

        app(ProductService::class)->sendRestockProductNotification(restockRequest: $restockRequest, type: 'restocked');

        $this->assertSame(['restock_7_product_restock_9_topic'], $this->topicsPublished());
    }
}
