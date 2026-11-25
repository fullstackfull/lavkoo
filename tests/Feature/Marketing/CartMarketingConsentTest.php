<?php

namespace Tests\Feature\Marketing;

use App\Http\Controllers\Admin\CartMarketingController;
use App\Models\NotificationTemplateMapping;
use App\Services\ChatwootService;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RecordingChatwootService;

/**
 * The admin WhatsApp cart-marketing send, with the gate ON. I1-08 criterion 1 for the WhatsApp channel.
 *
 * WHAT IS DRIVEN, said plainly: the controller's real `send` method, resolved from the container with a real Request,
 * so the real validation, the real per-customer loop and the real gate call all run. NOT the HTTP route — that sits
 * behind the admin guard, the admin-panel activation check and the `module:3rd_party_setup` switch, none of which this
 * branch touches and none of which has a fixture in this repository. ChatwootService is replaced by a double that
 * writes down what it was asked to send, because the real one cannot be constructed without live credentials.
 */
class CartMarketingConsentTest extends MarketingConsentTestCase
{
    private RecordingChatwootService $chatwoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMappingTable();

        $this->chatwoot = new RecordingChatwootService();
        $this->app->instance(ChatwootService::class, $this->chatwoot);
    }

    public function test_a_customer_who_has_not_agreed_is_skipped_and_nothing_is_sent_to_them(): void
    {
        $this->switchConsentOn();

        $silent = $this->makeCustomer(email: 'wa-silent@example.test', phone: '+966500000001');
        $this->makeAbandonedCart(customerId: (int) $silent->id, groupId: 'wa-group-silent');

        $this->send(customerIds: [(int) $silent->id]);

        $this->assertSame([], $this->chatwoot->phonesSentTo());
    }

    public function test_a_customer_who_agreed_to_whatsapp_cart_marketing_is_sent_to(): void
    {
        $this->switchConsentOn();

        $agreed = $this->makeCustomer(email: 'wa-agreed@example.test', phone: '+966500000002');
        $this->makeAbandonedCart(customerId: (int) $agreed->id, groupId: 'wa-group-agreed');
        $this->grantWhatsAppConsent(customerId: (int) $agreed->id);

        $this->send(customerIds: [(int) $agreed->id]);

        $this->assertSame(['+966500000002'], $this->chatwoot->phonesSentTo());
    }

    /** One admin action, two chosen customers: only the one who agreed is written to. */
    public function test_one_send_reaches_only_the_customers_who_agreed(): void
    {
        $this->switchConsentOn();

        $agreed = $this->makeCustomer(email: 'wa-mixed-agreed@example.test', phone: '+966500000003');
        $silent = $this->makeCustomer(email: 'wa-mixed-silent@example.test', phone: '+966500000004');
        $this->makeAbandonedCart(customerId: (int) $agreed->id, groupId: 'wa-group-mixed-a');
        $this->makeAbandonedCart(customerId: (int) $silent->id, groupId: 'wa-group-mixed-b');
        $this->grantWhatsAppConsent(customerId: (int) $agreed->id);

        $response = $this->send(customerIds: [(int) $agreed->id, (int) $silent->id]);

        $this->assertSame(['+966500000003'], $this->chatwoot->phonesSentTo());
        // Byte for byte, because the sentence an administrator reads is the only report they get of a skip, and
        // because an untranslated one was what the review found here.
        //
        // The skip clause is asked of `translate()` rather than spelled out, and that is the repair of a second defect in the
        // same sentence: `skipped_for_lack_of_whatsapp_marketing_consent` was defined in NO locale, so the text pinned here was
        // the placeholder `translate()` INVENTED from the key -- and it invented it by appending a line to
        // `resources/lang/<locale>/new-messages.php` on every send. Spelling it out again would pin whatever the next
        // placeholder happens to be; asking for the translation pins that the sentence is assembled from a real one.
        $this->assertSame(
            'Cart marketing sent successfully to 1 customer(s). ' . translate('skipped_for_lack_of_whatsapp_marketing_consent') . ': 1.',
            (string) $response->getSession()->get('success'),
        );
        $this->assertSame(
            'Skipped for lack of WhatsApp marketing consent',
            translate('skipped_for_lack_of_whatsapp_marketing_consent'),
            'the English of the skip clause changed; an administrator reads this sentence',
        );
    }

    /** Agreeing to marketing e-mail is not agreeing to a WhatsApp template. */
    public function test_consent_for_email_does_not_let_a_whatsapp_template_through(): void
    {
        $this->switchConsentOn();

        $customer = $this->makeCustomer(email: 'wa-wrong-channel@example.test', phone: '+966500000005');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'wa-group-wrong-channel');
        $this->grantWhatsAppConsent(customerId: (int) $customer->id, channel: MarketingConsent::CHANNEL_EMAIL);

        $this->send(customerIds: [(int) $customer->id]);

        $this->assertSame([], $this->chatwoot->phonesSentTo());
    }

    /** Withdrawing stops the NEXT send of the same screen. I1-08 criterion 2 for the WhatsApp channel. */
    public function test_withdrawing_stops_the_next_whatsapp_send(): void
    {
        $this->switchConsentOn();

        $customer = $this->makeCustomer(email: 'wa-withdraw@example.test', phone: '+966500000006');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'wa-group-withdraw');
        $this->grantWhatsAppConsent(customerId: (int) $customer->id);

        $this->send(customerIds: [(int) $customer->id]);
        $this->assertCount(1, $this->chatwoot->sent);

        app(MarketingConsentRecorder::class)->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'SA',
            channel: MarketingConsent::CHANNEL_WHATSAPP,
            purpose: MarketingConsent::PURPOSE_CART_MARKETING,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->send(customerIds: [(int) $customer->id]);

        $this->assertCount(1, $this->chatwoot->sent, 'A second message went out after the customer withdrew.');
    }

    /** THE SHIPPED STATE: with the gate off the send reaches everybody the admin picked, exactly as today. */
    public function test_with_the_switch_off_every_chosen_customer_is_sent_to(): void
    {
        $one = $this->makeCustomer(email: 'wa-off-one@example.test', phone: '+966500000007');
        $other = $this->makeCustomer(email: 'wa-off-two@example.test', phone: '+966500000008');
        $this->makeAbandonedCart(customerId: (int) $one->id, groupId: 'wa-group-off-a');
        $this->makeAbandonedCart(customerId: (int) $other->id, groupId: 'wa-group-off-b');

        $this->assertSame(0, DB::table('customer_marketing_consents')->count());

        $response = $this->send(customerIds: [(int) $one->id, (int) $other->id]);

        $this->assertEqualsCanonicalizing(['+966500000007', '+966500000008'], $this->chatwoot->phonesSentTo());
        // The message an administrator reads is the message they read before this branch.
        $this->assertSame('Cart marketing sent successfully to 2 customer(s).', (string) $response->getSession()->get('success'));
    }

    /**
     * THE DORMANCY OF THIS SENDER, MEASURED RATHER THAN READ.
     *
     * The test above proves the recipients and the administrator's sentence do not move. It does not prove the claim
     * the code makes, which is that with the gate off nothing is even looked up — and an independent review removed
     * the `$consentRequired &&` short-circuit, so that the market resolver inside the call's own arguments ran and
     * queried shipping_addresses with the switch off, and all 90 tests stayed green.
     *
     * So the statements are counted. The other sender has had this from the start; this one was asserted by reading.
     */
    public function test_with_the_switch_off_the_send_reads_no_consent_and_no_address(): void
    {
        $one = $this->makeCustomer(email: 'wa-quiet-one@example.test', phone: '+966500000011');
        $this->makeAbandonedCart(customerId: (int) $one->id, groupId: 'wa-group-quiet');

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->send(customerIds: [(int) $one->id]);

        $this->assertNotEmpty($statements, 'The send made no queries at all, so this measures nothing.');
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('customer_marketing_consents', $statement);
            $this->assertStringNotContainsString('shipping_addresses', $statement);
        }
    }

    /** @param array<int, int> $customerIds */
    private function send(array $customerIds): RedirectResponse
    {
        $mapping = NotificationTemplateMapping::create([
            'provider' => 'chatwoot',
            'template_name' => 'cart_marketing_v1',
            'template_language' => 'en',
            'template_category' => 'MARKETING',
            'event' => 'cart_marketing',
            'variable_mapping' => ['1' => 'customer_name'],
            'enabled' => true,
        ]);

        $request = Request::create('/admin/integrations/cart-marketing/send', 'POST', [
            'mapping_id' => $mapping->id,
            'customer_ids' => $customerIds,
        ]);
        $request->setLaravelSession($this->app['session.store']);

        return app()->call([app(CartMarketingController::class), 'send'], ['request' => $request]);
    }

    private function grantWhatsAppConsent(int $customerId, string $channel = MarketingConsent::CHANNEL_WHATSAPP): void
    {
        app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: $customerId,
            market: 'SA',
            channel: $channel,
            purpose: MarketingConsent::PURPOSE_CART_MARKETING,
            source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
        );
    }

    private function createMappingTable(): void
    {
        Schema::create('notification_template_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('template_name')->nullable();
            $table->string('template_language')->nullable();
            $table->string('template_category')->nullable();
            $table->string('event')->nullable();
            $table->string('recipient_field')->nullable();
            $table->text('variable_mapping')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('event_condition')->nullable();
            $table->timestamps();
        });
    }
}
