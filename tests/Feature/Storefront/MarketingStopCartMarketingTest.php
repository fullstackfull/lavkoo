<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Admin\CartMarketingController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\NotificationTemplateMapping;
use App\Models\User;
use App\Services\ChatwootService;
use App\Services\Storefront\StorefrontLifecycle;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\StopsAStorefront;

/**
 * I1-07.2: the WhatsApp cart-marketing list offers a template that invites a purchase, so a customer is not offered or messaged about a cart whose products belong to a storefront that may
 * not sell, and a customer with carts in two storefronts is told only about the one that sells. The carts of the Saudi storefront (selling) and of the UAE storefront are read in every state
 * that may not sell and in the control that sells, and nothing changes while storefronts are off.
 */
class MarketingStopCartMarketingTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;
    use StopsAStorefront;

    private const SAUDI_PRODUCT = 1;

    private const EMIRATI_PRODUCT = 2;

    /** @var list<array{phone: string, bodyParams: array<string, string>}> */
    private array $messages = [];

    private int $mappingId;

    private int $customerNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        $this->switchStorefrontsOn();
        $this->buildTheTemplateMappings();
        $this->giveAChatwootThatRecordsWhatItSends();
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    private function buildTheTemplateMappings(): void
    {
        Schema::create('notification_template_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('template_name');
            $table->string('template_language');
            $table->string('template_category')->nullable();
            $table->string('event')->nullable();
            $table->string('event_condition')->nullable();
            $table->string('recipient_field')->nullable();
            $table->text('variable_mapping')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        $this->mappingId = NotificationTemplateMapping::create([
            'provider' => 'chatwoot', 'template_name' => 'cart_offer', 'template_language' => 'en', 'template_category' => 'MARKETING', 'event' => 'cart_marketing', 'enabled' => true,
            'variable_mapping' => ['1' => 'customer.full_name', '2' => 'cart.items_count', '3' => 'cart.total'],
        ])->id;
    }

    private function giveAChatwootThatRecordsWhatItSends(): void
    {
        $chatwoot = $this->createMock(ChatwootService::class);
        $chatwoot->method('getWhatsAppTemplate')->willReturn(['name' => 'cart_offer', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, {{2}} items, {{3}} in total']]]);
        $chatwoot->method('sendWhatsAppTemplate')->willReturnCallback(function (string $phone, string $templateName, string $content, array $bodyParams = []): array {
            $this->messages[] = ['phone' => $phone, 'bodyParams' => $bodyParams];

            return [];
        });
        $this->app->instance(ChatwootService::class, $chatwoot);
    }

    private function aCustomerWithCartsIn(int ...$productIds): User
    {
        $this->customerNumber++;
        $customer = $this->makeCustomer(['email' => "cart-marketing-{$this->customerNumber}@example.test", 'phone' => '+96650000' . str_pad((string) $this->customerNumber, 4, '0', STR_PAD_LEFT)]);

        foreach ($productIds as $number => $productId) {
            $this->cartRow(customerId: $customer->id, productId: $productId, price: $productId === self::SAUDI_PRODUCT ? 100 : 250, group: "group-{$customer->id}-{$number}");
        }

        return $customer;
    }

    /** @param list<User> $customers */
    private function sendTheTemplateTo(array $customers)
    {
        return $this->post(route('admin.integrations.cart-marketing.send'), ['mapping_id' => $this->mappingId, 'customer_ids' => array_map(fn (User $customer): int => $customer->id, $customers)]);
    }

    /** @return list<string> the phones that were sent the template */
    private function messagedPhones(): array
    {
        return array_column($this->messages, 'phone');
    }

    /** @return array<int, array<string, mixed>> the customers the list offers, by customer */
    private function offeredCustomers(): array
    {
        return collect(app(CartMarketingController::class)->index()->getData()['customers'])->keyBy('customer_id')->all();
    }

    // ---- the list ---------------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_the_list_leaves_out_a_customer_whose_carts_are_all_in_a_storefront_that_may_not_sell(Closure $stopTheEmirates): void
    {
        $saudi = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT);
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);
        $stopTheEmirates($this);

        $this->assertSame([$saudi->id], array_keys($this->offeredCustomers()));
        $this->assertNotContains($emirati->id, array_keys($this->offeredCustomers()));
    }

    public function test_the_list_offers_a_customer_with_carts_in_two_storefronts_with_the_one_that_sells_only(): void
    {
        $both = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT, self::EMIRATI_PRODUCT);
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        $offered = $this->offeredCustomers()[$both->id];

        $this->assertSame(1, $offered['items_count']);
        $this->assertEqualsWithDelta(100.0, $offered['cart_total'], 0.001);
    }

    public function test_the_list_offers_every_customer_when_every_storefront_sells(): void
    {
        $saudi = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT);
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);

        $this->assertEqualsCanonicalizing([$saudi->id, $emirati->id], array_keys($this->offeredCustomers()));
    }

    // ---- the send ---------------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_a_customer_whose_cart_is_in_a_storefront_that_may_not_sell_is_not_messaged(Closure $stopTheEmirates): void
    {
        $saudi = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT);
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);
        $stopTheEmirates($this);

        $this->sendTheTemplateTo([$saudi, $emirati])->assertSessionHas('error', "Sent: 1, Failed: 1. Customer #{$emirati->id}: Customer cart is in a storefront that may not sell.");

        $this->assertSame([$saudi->phone], $this->messagedPhones());
    }

    public function test_a_customer_whose_cart_is_in_a_storefront_switched_off_in_the_configuration_is_neither_offered_nor_messaged(): void
    {
        $saudi = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT);
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);
        config(['gcc.storefronts.countries.AE.active' => false]);

        $this->assertSame([$saudi->id], array_keys($this->offeredCustomers()));
        $this->sendTheTemplateTo([$saudi, $emirati])->assertSessionHas('error', "Sent: 1, Failed: 1. Customer #{$emirati->id}: Customer cart is in a storefront that may not sell.");

        $this->assertSame([$saudi->phone], $this->messagedPhones());
    }

    public function test_a_customer_with_carts_in_two_storefronts_is_told_only_about_the_one_that_sells(): void
    {
        $both = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT, self::EMIRATI_PRODUCT);
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        $this->sendTheTemplateTo([$both])->assertSessionHas('success');

        $this->assertSame([['phone' => $both->phone, 'bodyParams' => ['1' => trim("{$both->f_name} {$both->l_name}"), '2' => '1', '3' => '100']]], $this->messages);
    }

    public function test_every_customer_is_messaged_when_every_storefront_sells(): void
    {
        $saudi = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT);
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);
        $both = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT, self::EMIRATI_PRODUCT);

        $this->sendTheTemplateTo([$saudi, $emirati, $both])->assertSessionHas('success');

        $this->assertSame([$saudi->phone, $emirati->phone, $both->phone], $this->messagedPhones());
        $this->assertSame(['2' => '2', '3' => '350'], Arr::only($this->messages[2]['bodyParams'], ['2', '3']));
    }

    // ---- storefronts off --------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_nothing_changes_while_storefronts_are_off(Closure $stopTheEmirates): void
    {
        $emirati = $this->aCustomerWithCartsIn(self::EMIRATI_PRODUCT);
        $both = $this->aCustomerWithCartsIn(self::SAUDI_PRODUCT, self::EMIRATI_PRODUCT);
        $stopTheEmirates($this);
        config(['gcc.storefronts.enabled' => false]);

        $this->assertEqualsCanonicalizing([$emirati->id, $both->id], array_keys($this->offeredCustomers()));
        $this->sendTheTemplateTo([$emirati, $both])->assertSessionHas('success');

        $this->assertSame([$emirati->phone, $both->phone], $this->messagedPhones());
        $this->assertSame(['2' => '2', '3' => '350'], Arr::only($this->messages[1]['bodyParams'], ['2', '3']));
    }
}
