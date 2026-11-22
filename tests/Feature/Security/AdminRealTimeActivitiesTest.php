<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAdminEmployees;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The poller in the layout of every admin page answers every signed-in employee (a permission on the route would send an employee whose role holds no
 * module round in a circle), and it used to answer each of them with all of it: how many orders are new, which in-house products customers asked to have
 * restocked, how many chat messages wait. It also marked those messages as notified for whoever asked, so an employee without the chat took the
 * notification of the one who has it. It now reports a section only to a role that holds the permission of that section: new orders (orders), restock
 * requests (catalog), chat messages (people). The master admin passes every permission check.
 *
 * The route, its middleware, the controller and its repositories are the real ones, on the few tables they read.
 */
class AdminRealTimeActivitiesTest extends TestCase
{
    use BuildsAdminEmployees;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const POLLER = '/admin/dashboard/real-time-activities';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        $this->buildTheTablesThePollerReads();
        $this->waitingForTheAdmin();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: list<string>, 1: bool, 2: bool, 3: bool}> the permissions the role holds, then whether the answer carries the new orders, the restock requests, the chat messages */
    public static function rolesAndTheSectionsTheyAreToldOf(): array
    {
        return [
            'orders only' => [['orders'], true, false, false],
            'catalog only' => [['catalog'], false, true, false],
            'people only' => [['people'], false, false, true],
            'orders and catalog' => [['orders', 'catalog'], true, true, false],
            'orders and people' => [['orders', 'people'], true, false, true],
            'catalog and people' => [['catalog', 'people'], false, true, true],
            'orders, catalog and people' => [['orders', 'catalog', 'people'], true, true, true],
            'no module at all' => [[], false, false, false],
            'every permission but those three' => [['dashboard', 'pos', 'marketing', 'reports', 'marketplace', 'business_settings', 'system_settings', '3rd_party_setup', 'sales_channels', 'operational_costs', 'themes_and_addons'], false, false, false],
        ];
    }

    /** @param list<string> $held */
    #[DataProvider('rolesAndTheSectionsTheyAreToldOf')]
    public function test_an_employee_is_told_only_of_the_sections_whose_permission_their_role_holds(array $held, bool $orders, bool $restock, bool $chat): void
    {
        $response = $this->poll(employee: $this->employeeHolding(modules: $held));

        $this->assertAnswer(response: $response, orders: $orders, restock: $restock, chat: $chat);
    }

    public function test_the_master_admin_is_told_of_every_section(): void
    {
        $this->assertAnswer(response: $this->poll(employee: Admin::query()->findOrFail(1)), orders: true, restock: true, chat: true);
    }

    public function test_an_employee_without_the_chat_does_not_take_the_notification_of_the_employee_who_has_it(): void
    {
        $this->poll(employee: $this->employeeHolding(modules: ['orders', 'catalog']));
        $this->assertSame(2, $this->messagesWaiting(), 'a role without people marked the chat messages as notified');

        $this->assertAnswer(response: $this->poll(employee: $this->employeeHolding(modules: ['people'])), orders: false, restock: false, chat: true);
        $this->assertSame(0, $this->messagesWaiting(), 'the role with people did not get the messages marked as notified');
    }

    public function test_the_holder_of_the_catalog_is_told_which_product_when_only_one_has_restock_requests(): void
    {
        DB::table('restock_products')->where('product_id', 12)->delete();
        DB::table('restock_product_customers')->insert([['restock_product_id' => 1, 'customer_id' => 5], ['restock_product_id' => 1, 'customer_id' => 6]]);

        $this->poll(employee: $this->employeeHolding(modules: ['catalog']))->assertOk()->assertJson([
            'restockProductCount' => 1,
            'restockProduct' => [
                'title' => 'First product',
                'body' => translate('This_product_has') . ' 2 ' . translate('restock_request'),
                'route' => route('admin.products.request-restock-list'),
            ],
        ]);
        $withoutTheCatalog = $this->poll(employee: $this->employeeHolding(modules: ['orders', 'people']));
        $this->assertSame(0, $withoutTheCatalog->json('restockProductCount'));
        $this->assertSame([], $withoutTheCatalog->json('restockProduct'));
    }

    private function poll(Admin $employee): TestResponse
    {
        return $this->actingAs($employee, 'admin')->getJson(self::POLLER);
    }

    private function assertAnswer(TestResponse $response, bool $orders, bool $restock, bool $chat): void
    {
        $response->assertOk()->assertJson([
            'success' => 1,
            'new_order_count' => $orders ? 2 : 0,
            'restockProductCount' => $restock ? 2 : 0,
            'newMessagesExist' => $chat ? 2 : 0,
            'message' => $chat ? '2 ' . translate('New_Message') : translate('New_Message'),
        ]);
        $this->assertEquals($restock ? $this->restockAlert() : [], $response->json('restockProduct'));
    }

    /** @return array{title: string, body: string, image: string, route: string} what the layout shows when several products have restock requests */
    private function restockAlert(): array
    {
        return [
            'title' => translate('Restock_Request'),
            'body' => '2 ' . translate('products_have_restock_request'),
            'image' => dynamicAsset(path: 'public/assets/back-end/img/icons/restock-request-icon.svg'),
            'route' => route('admin.products.request-restock-list'),
        ];
    }

    private function messagesWaiting(): int
    {
        return DB::table('chattings')->where('seen_notification', 0)->count();
    }

    private function buildTheTablesThePollerReads(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->boolean('checked')->default(false);
        });
        Schema::create('restock_products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });
        Schema::create('chattings', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->text('message')->nullable();
            $table->boolean('seen_by_admin')->default(false);
            $table->string('notification_receiver')->nullable();
            $table->boolean('seen_notification')->default(false);
            $table->timestamps();
        });
    }

    /** Two new orders and one checked, two in-house products that customers asked to have restocked, two chat messages that no one was told of yet. */
    private function waitingForTheAdmin(): void
    {
        DB::table('orders')->insert([
            ['id' => 100001, 'checked' => 0],
            ['id' => 100002, 'checked' => 0],
            ['id' => 100003, 'checked' => 1],
        ]);
        DB::table('products')->insert([
            ['id' => 11, 'name' => 'First product', 'added_by' => 'admin'],
            ['id' => 12, 'name' => 'Second product', 'added_by' => 'admin'],
        ]);
        DB::table('restock_products')->insert([['product_id' => 11], ['product_id' => 12]]);
        DB::table('chattings')->insert([
            ['admin_id' => 0, 'notification_receiver' => 'admin', 'seen_notification' => 0, 'seen_by_admin' => 0],
            ['admin_id' => 0, 'notification_receiver' => 'admin', 'seen_notification' => 0, 'seen_by_admin' => 0],
        ]);
    }
}
