<?php

namespace Tests\Feature\Storefront;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminder;
use App\Models\User;
use App\Services\Retention\AbandonedCartService;
use App\Services\Storefront\StorefrontLifecycle;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\StopsAStorefront;

/**
 * I1-07.2: the reminder of an abandoned cart invites a purchase, so a cart whose products belong to a storefront that may not sell is neither selected nor reminded nor recorded, and is
 * reminded once the storefront sells again. The carts of the Saudi storefront (selling) and of the UAE storefront are read in every state that may not sell (paused in each of the three
 * modes, a draft, ready, active or ready without approved legal texts) and in the control that sells, and nothing changes while storefronts are off.
 */
class MarketingStopAbandonedCartTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;
    use StopsAStorefront;

    private const SAUDI_GROUP = 'group-saudi';

    private const EMIRATI_GROUP = 'group-emirati';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        $this->buildTheReminderLedger();
        $this->switchStorefrontsOn();
        DB::table('business_settings')->insert(['type' => AbandonedCartService::SETTING_ENABLED, 'value' => '1']);
        clearWebConfigCacheKeys();
        Mail::fake();
    }

    private function buildTheReminderLedger(): void
    {
        Schema::create('abandoned_cart_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('cart_group_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('email')->nullable();
            $table->unsignedInteger('stage')->default(1);
            $table->decimal('cart_value', 16, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_order_id')->nullable();
            $table->timestamps();
        });
    }

    private function anAbandonedCart(string $group, int $productId, int $hoursIdle = 8): User
    {
        $customer = $this->makeCustomer(['email' => "{$group}@example.test"]);

        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => $group, 'product_id' => $productId, 'quantity' => 1, 'price' => 250,
            'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'product_type' => 'physical',
            'created_at' => now()->subHours($hoursIdle), 'updated_at' => now()->subHours($hoursIdle),
        ]);

        return $customer;
    }

    private function aSaudiAndAnEmiratiCart(): void
    {
        $this->anAbandonedCart(group: self::SAUDI_GROUP, productId: 1);
        $this->anAbandonedCart(group: self::EMIRATI_GROUP, productId: 2);
    }

    /** @return list<string> the carts the job would remind now */
    private function selectedGroups(): array
    {
        return app(AbandonedCartService::class)->findAbandoned()->pluck('cart_group_id')->sort()->values()->all();
    }

    /** @return list<string> the carts that have a reminder in the ledger */
    private function recordedGroups(): array
    {
        return AbandonedCartReminder::query()->orderBy('cart_group_id')->pluck('cart_group_id')->all();
    }

    /** @return list<string> the carts a reminder was mailed about */
    private function mailedGroups(): array
    {
        return Mail::sent(AbandonedCartReminderMail::class)->map(fn (AbandonedCartReminderMail $mail): string => $mail->reminder->cart_group_id)->sort()->values()->all();
    }

    // ---- the selection ----------------------------------------------------------------------------------------------------------

    public function test_the_carts_of_the_storefronts_that_sell_are_selected_and_reminded_and_recorded(): void
    {
        $this->aSaudiAndAnEmiratiCart();

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->selectedGroups());

        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->mailedGroups());
        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->recordedGroups());
    }

    #[DataProvider('statesThatMayNotSell')]
    public function test_a_cart_of_a_storefront_that_may_not_sell_is_not_selected(Closure $stopTheEmirates): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $stopTheEmirates($this);

        $this->assertSame([self::SAUDI_GROUP], $this->selectedGroups());
    }

    #[DataProvider('statesThatMayNotSell')]
    public function test_a_cart_of_a_storefront_that_may_not_sell_is_neither_reminded_nor_recorded(Closure $stopTheEmirates): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $stopTheEmirates($this);

        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::SAUDI_GROUP], $this->mailedGroups());
        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());
    }

    public function test_a_cart_that_was_skipped_is_reminded_when_the_storefront_is_resumed(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);
        Artisan::call('cart:remind-abandoned');
        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());

        $this->lifecycle()->resume(country: 'AE', by: self::PERSON, reason: self::REASON);
        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->recordedGroups());
        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->mailedGroups());
    }

    public function test_a_cart_of_a_draft_storefront_is_reminded_once_the_storefront_is_active(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $this->withdrawTheRecordOfTheEmirates();
        Artisan::call('cart:remind-abandoned');
        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());

        $this->lifecycle()->markReady(country: 'AE', by: self::PERSON, reason: self::REASON);
        $this->lifecycle()->activate(country: 'AE', by: self::PERSON, reference: 'Minutes of the launch meeting', reason: self::REASON);
        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->recordedGroups());
    }

    public function test_a_cart_of_a_storefront_switched_off_in_the_configuration_is_neither_reminded_nor_recorded(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        config(['gcc.storefronts.countries.AE.active' => false]);

        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::SAUDI_GROUP], $this->mailedGroups());
        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());
    }

    public function test_the_default_storefront_paused_browse_only_is_not_reminded_and_another_one_still_is(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY, country: 'SA');

        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::EMIRATI_GROUP], $this->mailedGroups());
        $this->assertSame([self::EMIRATI_GROUP], $this->recordedGroups());
    }

    // ---- the command ------------------------------------------------------------------------------------------------------------

    public function test_the_second_reminder_is_skipped_for_a_storefront_that_may_not_sell_in_the_same_way(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        Artisan::call('cart:remind-abandoned', ['--stage' => 2]);

        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());
        $this->assertSame([2], AbandonedCartReminder::query()->pluck('stage')->unique()->values()->all());

        $this->lifecycle()->resume(country: 'AE', by: self::PERSON, reason: self::REASON);
        Artisan::call('cart:remind-abandoned', ['--stage' => 2]);

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->recordedGroups());
    }

    public function test_the_dry_run_lists_only_the_carts_that_would_be_reminded(): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        Artisan::call('cart:remind-abandoned', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString(self::SAUDI_GROUP, $output);
        $this->assertStringNotContainsString(self::EMIRATI_GROUP, $output);
        $this->assertStringContainsString('Dry run: 1 message(s) would be sent.', $output);
        $this->assertSame([], $this->recordedGroups());
    }

    public function test_a_stopped_storefront_does_not_use_up_the_limit_of_a_run(): void
    {
        $this->anAbandonedCart(group: self::SAUDI_GROUP, productId: 1, hoursIdle: 20);
        $this->anAbandonedCart(group: self::EMIRATI_GROUP, productId: 2, hoursIdle: 7);
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        Artisan::call('cart:remind-abandoned', ['--limit' => 1]);

        $this->assertSame([self::SAUDI_GROUP], $this->mailedGroups());
        $this->assertSame([self::SAUDI_GROUP], $this->recordedGroups());
    }

    public function test_a_storefront_stopped_while_a_run_is_under_way_is_not_reminded_by_the_rest_of_it(): void
    {
        $this->anAbandonedCart(group: 'group-emirati-first', productId: 2, hoursIdle: 8);
        $this->anAbandonedCart(group: 'group-emirati-second', productId: 2, hoursIdle: 9);
        $stopped = false;
        Event::listen('eloquent.created: ' . AbandonedCartReminder::class, function () use (&$stopped): void {
            if (!$stopped) {
                $stopped = true;
                $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);
            }
        });

        Artisan::call('cart:remind-abandoned');

        $this->assertSame(['group-emirati-first'], $this->mailedGroups());
        $this->assertSame(['group-emirati-first'], $this->recordedGroups());
        $this->assertStringContainsString('Sent 1 reminder(s). 1 left for later: their storefront may not sell.', Artisan::output());
    }

    public function test_the_run_that_skips_nothing_says_what_it_always_said(): void
    {
        $this->aSaudiAndAnEmiratiCart();

        Artisan::call('cart:remind-abandoned');

        $this->assertStringContainsString('Sent 2 reminder(s).', Artisan::output());
        $this->assertStringNotContainsString('left for later', Artisan::output());
    }

    // ---- storefronts off --------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_nothing_is_skipped_while_storefronts_are_off(Closure $stopTheEmirates): void
    {
        $this->aSaudiAndAnEmiratiCart();
        $stopTheEmirates($this);
        config(['gcc.storefronts.enabled' => false]);

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->selectedGroups());

        Artisan::call('cart:remind-abandoned');

        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->mailedGroups());
        $this->assertSame([self::EMIRATI_GROUP, self::SAUDI_GROUP], $this->recordedGroups());
    }
}
