<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Models\Product;
use App\Models\RestockProduct;
use App\Services\ProductService;
use App\Services\Theme\ThemeSyncBeacon;
use App\Traits\PushNotificationTrait;
use FilesystemIterator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakesThePushService;

/**
 * The topics that Saudi apps and the browser panels already hold keep receiving messages only while the backend keeps naming them the same.
 * A topic is a name the backend publishes to (FCM) or hands to a client to subscribe to. This pins every one of them: a hand-written list
 * (the name, what the backend does with it, the file that does it), a scan of the code that fails on a topic name that is not on the list
 * or on a new place that publishes to a topic, and the name as it leaves the server (published) or is handed to the browser (subscribed).
 * A topic renamed or added is then a decision made on this list, not an accident.
 *
 * What is NOT covered: the apps. The customer app, the seller app and the delivery-man app subscribe to their topics in their own code;
 * no test here (or anywhere in this repository) reads that code, so a topic renamed on that side is not seen by this test.
 */
class PushTopicNamesTest extends ApiAddressTestCase
{
    use FakesThePushService;

    private const SCANNED_DIRECTORIES = ['app', 'Modules', 'routes', 'config'];

    private const CONTROLLER = 'app/Http/Controllers/Admin/Settings/BusinessSettingsController.php';

    private const TRAIT = 'app/Traits/PushNotificationTrait.php';

    private const SETTINGS_HELPER = 'app/Utils/settings.php';

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

    /** A message whose first key is the topic: where a topic reaches FCM. */
    private const FCM_MESSAGE_TO_A_TOPIC = '/[\'"]message[\'"]\s*=>\s*\[\s*[\'"]topic[\'"]/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->configureThePushService();
    }

    /** @return array<string, list<string>> the topic names written in the code, with the files that write them */
    private function topicNamesWrittenInTheCode(): array
    {
        $written = [];
        foreach ($this->phpFilesOfTheCode() as $file => $source) {
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
        $written = $this->topicNamesWrittenInTheCode();

        $this->assertSame([], array_values(array_diff(array_keys($written), $listed)), 'a topic is written in the code that this list does not know: a renamed or a new topic is a decision, add it here on purpose');
        $this->assertEqualsCanonicalizing(
            ['maintenance_mode_start_deliveryman', 'maintenance_mode_start_user_app', 'maintenance_mode_start_vendor', 'sixvalley', 'theme_updates_user_app'],
            array_keys($written),
            'the scan finds the five topics that are written as text; demo_reset and the restock topics are built, and pinned on the wire below',
        );
    }

    public function test_a_topic_reaches_the_push_service_from_the_known_places_only(): void
    {
        $messagesToATopic = [];
        $campaignCalls = [];
        $clientSubscriptions = [];
        foreach ($this->phpFilesOfTheCode() as $file => $source) {
            if (preg_match(self::FCM_MESSAGE_TO_A_TOPIC, $source) === 1) {
                $messagesToATopic[] = $file;
            }
            if (($calls = preg_match_all('/(?<!function )\bsendPushNotificationToTopic\s*\(/', $source)) > 0) {
                $campaignCalls[$file] = $calls;
            }
            if (($calls = preg_match_all('/->subscribeToTopic\s*\(/', $source)) > 0) {
                $clientSubscriptions[$file] = $calls;
            }
        }
        sort($messagesToATopic);
        ksort($campaignCalls);

        $this->assertSame(
            ['app/Listeners/MaintenanceModeNotificationListener.php', 'app/Listeners/RestockProductNotificationListener.php', 'app/Services/Theme/ThemeSyncBeacon.php', self::TRAIT],
            $messagesToATopic,
            'a file builds an FCM message to a topic that this list does not know',
        );
        $this->assertSame(['app/Http/Controllers/Admin/Notification/NotificationController.php' => 2, self::TRAIT => 1], $campaignCalls, 'a campaign push to a topic is sent from a new place');
        $this->assertSame(['app/Http/Controllers/FirebaseController.php' => 1], $clientSubscriptions, 'a client is subscribed to a topic from a new place');
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

    public function test_the_demo_reset_alert_is_published_on_the_topic_demo_reset(): void
    {
        $this->publisher()->demoReset();

        $this->assertSame(['demo_reset'], $this->topicsPublished());
    }

    public function test_the_browser_panels_subscribe_to_sixvalley_the_maintenance_topic_and_the_restock_topics_of_the_customer(): void
    {
        $this->assertSame(['sixvalley', 'maintenance_mode_start_user_app'], getFCMTopicListToSubscribe());

        session()->put('customer_fcm_topic', ['restock_7_product_restock_9_topic']);

        $this->assertSame(['restock_7_product_restock_9_topic', 'sixvalley', 'maintenance_mode_start_user_app'], getFCMTopicListToSubscribe());
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
