<?php

namespace Tests\Feature\Marketing;

use App\Events\AddFundToWalletEvent;
use App\Events\DigitalProductOtpVerificationEvent;
use App\Events\OrderStatusEvent;
use App\Events\PasswordResetEvent;
use App\Events\RefundEvent;
use App\Mail\SendMail;
use App\Models\Order;
use App\Services\Marketing\MarketingConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * I1-08 criterion 4: order status, refund, payment and ONE-TIME-CODE messages are sent whatever the marketing consent.
 *
 * Every test here runs with the gate ON, the strictest shipped policy (opt-in for every market) and NOT ONE consent
 * row in the database — the state in which a marketing send is refused. Each one then drives a real event through the
 * real listener and asserts the message still leaves.
 *
 * The one-time-code cases are the security-critical ones: a marketing gate that silenced a password-reset code or a
 * download OTP would lock people out of their own accounts, so they are driven through the real event and the real
 * mail path rather than asserted by reading the code.
 */
class NecessaryNotificationsTest extends MarketingConsentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->switchConsentOn();
        $this->assertSame(0, DB::table('customer_marketing_consents')->count());
    }

    public function test_a_password_reset_code_is_sent_with_no_consent_on_record(): void
    {
        $this->seedEmailTemplate(templateName: 'password-reset', userType: 'customer');
        Mail::fake();

        event(new PasswordResetEvent(
            email: 'reset@example.test',
            data: ['userType' => 'customer', 'templateName' => 'password-reset', 'userName' => 'Test', 'subject' => 'Reset', 'title' => 'Reset', 'verification_code' => '482913'],
        ));

        Mail::assertSent(SendMail::class, 1);
    }

    public function test_a_digital_product_one_time_code_is_sent_with_no_consent_on_record(): void
    {
        $this->seedEmailTemplate(templateName: 'digital-product-otp', userType: 'customer');
        Mail::fake();

        event(new DigitalProductOtpVerificationEvent(
            email: 'otp@example.test',
            data: ['userType' => 'customer', 'templateName' => 'digital-product-otp', 'userName' => 'Test', 'otp' => '774211'],
        ));

        Mail::assertSent(SendMail::class, 1);
    }

    public function test_a_payment_message_is_sent_with_no_consent_on_record(): void
    {
        $this->seedEmailTemplate(templateName: 'add-fund-to-wallet', userType: 'customer');
        Mail::fake();

        event(new AddFundToWalletEvent(
            email: 'wallet@example.test',
            data: ['userType' => 'customer', 'templateName' => 'add-fund-to-wallet', 'userName' => 'Test', 'amount' => '250.00'],
        ));

        Mail::assertSent(SendMail::class, 1);
    }

    public function test_an_order_status_message_is_sent_with_no_consent_on_record(): void
    {
        $order = $this->makeOrderForACustomerWithADevice();
        $this->seedPushMessage(key: 'order_confirmation_message');
        $this->seedPushCredentials();
        $this->fakeFirebase();

        event(new OrderStatusEvent(key: 'confirmed', type: 'customer', order: $order));

        $this->assertPushWasSentToTheDevice();
    }

    public function test_a_refund_message_is_sent_with_no_consent_on_record(): void
    {
        $order = $this->makeOrderForACustomerWithADevice();
        $this->seedPushMessage(key: 'order_refunded_message');
        $this->seedPushCredentials();
        $this->fakeFirebase();

        event(new RefundEvent(status: 'refunded', order: $order, orderDetails: null, refund: []));

        $this->assertPushWasSentToTheDevice();
    }

    /**
     * The structural half, and the one that keeps this true for code nobody has written yet: the complete list of
     * files that consult the marketing gate.
     *
     * Any file that starts asking the gate — a necessary-notification listener above all — turns this red, and
     * whoever added it has to say so here. It reads the whole application, so a new sender cannot hide in a module.
     */
    public function test_only_the_marketing_senders_consult_the_consent_gate(): void
    {
        $callers = [];
        $files = $this->applicationPhpFiles();

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, 'MarketingConsentGate')) {
                $callers[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame(
            [
                // The two senders that ask it directly, on the screen and in the web action.
                'app/Http/Controllers/Admin/CartMarketingController.php',
                // Not a sender: it asks `recordable()` so that a link already in an inbox neither raises before the
                // migration has run nor stops working when an operator switches the feature off. Added in this round
                // after a review measured a QueryException out of this route on both engines.
                'app/Http/Controllers/Web/MarketingUnsubscribeController.php',
                'app/Http/Controllers/Web/WebController.php',
                // The gate itself, and the selection that asks it for the abandoned-cart run.
                'app/Services/Marketing/MarketingConsentGate.php',
                'app/Services/Retention/AbandonedCartService.php',
                // Names the class in a comment explaining that the gate also waits for the table.
                'config/marketing.php',
            ],
            $this->sortedForComparison(paths: $callers),
            'Something new asks the marketing consent gate. A necessary notification must never depend on it. '
            . 'SendAbandonedCartReminders is deliberately NOT here: the gate is asked once by the selection, and the '
            . 'command only carries the market that selection resolved.'
        );
    }

    /** And the other direction: no listener of a necessary notification mentions consent at all. */
    public function test_no_necessary_notification_listener_mentions_consent(): void
    {
        $listeners = [
            'app/Listeners/OrderStatusListener.php',
            'app/Listeners/OrderPlacedListener.php',
            'app/Listeners/RefundListener.php',
            'app/Listeners/PasswordResetListener.php',
            'app/Listeners/EmailVerificationListener.php',
            'app/Listeners/DigitalProductOtpVerificationListener.php',
            'app/Listeners/AddFundToWalletListener.php',
            'app/Listeners/OrderEditDuePaymentListener.php',
            'app/Listeners/OrderEditReturnPaymentListener.php',
            'app/Listeners/DeliverymanPasswordResetListener.php',
        ];

        foreach ($listeners as $listener) {
            $path = base_path($listener);
            $this->assertFileExists($path, "{$listener} was renamed or removed; this guard no longer covers it.");

            $contents = (string) file_get_contents($path);
            foreach (['MarketingConsentGate', 'MarketingConsent::', 'customer_marketing_consents'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $contents, "{$listener} consults the marketing consent.");
            }
        }
    }

    /** The push trait and the mail trait, which every necessary message goes through, must not know about consent. */
    public function test_the_shared_notification_traits_do_not_mention_consent(): void
    {
        foreach (['app/Traits/PushNotificationTrait.php', 'app/Traits/EmailTemplateTrait.php'] as $trait) {
            $contents = (string) file_get_contents(base_path($trait));

            $this->assertStringNotContainsString('MarketingConsent', $contents, "{$trait} consults the marketing consent.");
            $this->assertStringNotContainsString('customer_marketing_consents', $contents, "{$trait} reads the consent table.");
        }
    }

    private function makeOrderForACustomerWithADevice(): Order
    {
        $customer = $this->makeCustomer(email: 'order-notify@example.test');
        DB::table('users')->where('id', $customer->id)->update(['cm_firebase_token' => 'a-device-token']);

        $orderId = DB::table('orders')->insertGetId([
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::findOrFail($orderId);
    }

    private function seedEmailTemplate(string $templateName, string $userType): void
    {
        DB::table('email_templates')->insert([
            'template_name' => $templateName,
            'user_type' => $userType,
            'template_design_name' => 'default',
            'title' => 'Your code',
            'body' => 'Here is your code.',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Firebase credentials shaped like the service-account JSON an administrator pastes in, with a key pair minted
     * here: the real push path signs a JWT with it, so without one the send would fail for want of a credential
     * rather than for want of a consent, and the test would prove nothing.
     */
    private function seedPushCredentials(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($pair, 'This environment cannot mint an RSA key, so the push path cannot be driven.');
        openssl_pkey_export($pair, $privateKey);

        $this->writeBusinessSettings(settings: [
            'push_notification_key' => (string) json_encode([
                'project_id' => 'test-project',
                'client_email' => 'pushing@test-project.iam.gserviceaccount.test',
                'private_key' => $privateKey,
            ]),
        ]);
    }

    private function fakeFirebase(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a-test-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/1'], 200),
        ]);
    }

    /** The message reached Firebase, addressed to this customer's own device. */
    private function assertPushWasSentToTheDevice(): void
    {
        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), 'fcm.googleapis.com')
                && ($request->data()['message']['token'] ?? null) === 'a-device-token';
        });
    }

    private function seedPushMessage(string $key): void
    {
        DB::table('notification_messages')->insert([
            'user_type' => 'customer',
            'key' => $key,
            'message' => 'Your order has moved on.',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function applicationPhpFiles(): array
    {
        $files = [];
        foreach (['app', 'Modules', 'routes', 'config'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        $this->assertGreaterThan(500, count($files), 'The file scan found almost no files, so it is not scanning the application.');

        return $files;
    }

    /**
     * Sorted by depth then name, so the expected list above reads as "the controllers, the command, the services"
     * rather than in whatever order the filesystem hands them over.
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private function sortedForComparison(array $paths): array
    {
        usort($paths, static function (string $one, string $other): int {
            return [substr_count($other, '/'), $one] <=> [substr_count($one, '/'), $other];
        });

        return $paths;
    }
}
