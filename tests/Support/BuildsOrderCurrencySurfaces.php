<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The world of the surfaces that print what an order was sold for: a platform that runs the multi-currency model with the Saudi riyal as its
 * default (3.75 to the dollar) next to the Kuwaiti dinar (0.307), the six storefronts open, and the few tables the invoice template reads.
 *
 * It is a world, not a measurement: nothing here loads a currency, puts one in the session or asks a money helper anything. The figures a test
 * reads come from the templates the real routes render, so a surface that reads the session or today's rate shows it.
 */
trait BuildsOrderCurrencySurfaces
{
    /** The dollar the platform's rates are quoted against: every converter divides the default currency's rate by this one. */
    private const DOLLAR_ID = 7;

    protected function buildOrderCurrencySurfaceWorld(): void
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));

        $this->buildSixStorefrontCheckoutWorld();
        $this->quotePlatformRatesAgainstTheDollar();
        $this->createInvoicePageTables();
    }

    /**
     * The platform the defect needs: amounts are held in a base unit and re-converted at the rate of whichever currency is in force, so a
     * surface that reads the request's currency instead of the order's prints a different number from the one that was charged.
     */
    private function quotePlatformRatesAgainstTheDollar(): void
    {
        DB::table('currencies')->insert(['id' => self::DOLLAR_ID, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('currencies')->where('id', 1)->update(['exchange_rate' => 3.75, 'symbol' => 'SAR']);
        DB::table('currencies')->where('id', 3)->update(['exchange_rate' => 0.307, 'symbol' => 'KD']);
        cache()->forget('active_currency_list');

        $this->setSetting('currency_model', 'multi_currency');
        $this->setSetting('system_default_currency', '1');
        $this->setSetting('decimal_point_settings', '2');
        $this->setSetting('currency_symbol_position', 'right');
    }

    /** What the invoice template reads besides the order: the edit history it looks for and the offline payments it lists. */
    private function createInvoicePageTables(): void
    {
        if (!Schema::hasTable('order_edit_histories')) {
            Schema::create('order_edit_histories', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->decimal('order_due_amount', 16, 2)->default(0);
                $table->string('order_due_payment_status')->nullable();
                $table->decimal('order_return_amount', 16, 2)->default(0);
                $table->string('order_return_payment_status')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('offline_payments')) {
            Schema::create('offline_payments', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->longText('payment_info')->nullable();
                $table->timestamps();
            });
        }
    }

    /** The master administrator and the panel's own middleware, so the panel's routes can be called as an administrator calls them. */
    protected function openTheAdminPanel(): static
    {
        if (!Schema::hasTable('admin_roles')) {
            Schema::create('admin_roles', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->text('module_access')->nullable();
                $table->integer('status')->default(1);
                $table->timestamps();
            });
            DB::table('admin_roles')->insert(['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1]);
        }

        if (!Schema::hasTable('admins')) {
            Schema::create('admins', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('f_name')->nullable();
                $table->string('email')->nullable();
                $table->unsignedBigInteger('admin_role_id')->default(1);
                $table->integer('status')->default(1);
                $table->timestamps();
            });
            DB::table('admins')->insert(['id' => 1, 'name' => 'Master', 'f_name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1, 'status' => 1]);
        }

        $this->asAFreshVisitor();
        $this->actingAs(\App\Models\Admin::findOrFail(1), 'admin');

        return $this;
    }

    /**
     * The order e-mail, switched on the way an operator switches it on: a mail service with a status, and the two templates the checkout sends.
     * Without it a checkout sends nothing at all, and the document this measures does not exist.
     */
    protected function switchTheOrderMailOn(): void
    {
        if (!Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function ($table): void {
                $table->id();
                $table->string('template_name');
                $table->string('user_type');
                $table->string('template_design_name');
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
                $table->tinyInteger('button_content_status')->default(1);
                $table->tinyInteger('product_information_status')->default(1);
                $table->tinyInteger('order_information_status')->default(1);
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
            DB::table('email_templates')->insert([
                ['template_name' => 'order-place', 'user_type' => 'customer', 'template_design_name' => 'order-place', 'title' => 'Order placed', 'body' => 'Thank you', 'status' => 1],
                ['template_name' => 'order-received', 'user_type' => 'admin', 'template_design_name' => 'order-received', 'title' => 'Order received', 'body' => 'New order', 'status' => 1],
            ]);
        }

        if (!Schema::hasTable('social_medias')) {
            Schema::create('social_medias', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('link')->nullable();
                $table->integer('status')->default(1);
                $table->timestamps();
            });
        }

        $this->setSetting('mail_config', json_encode(['status' => 1, 'name' => 'Shop', 'host' => 'smtp.example.test', 'driver' => 'smtp', 'port' => '587', 'username' => 'shop', 'email_id' => 'shop@example.test', 'encryption' => 'tls', 'password' => 'secret']));
        config(['mail.default' => 'array']);
    }

    /**
     * The documents a real checkout's order e-mails are made of, written where production writes them: the listener that sends them is queued,
     * so it is run the way the worker runs it, with the request and the session of the checkout gone.
     *
     * @return list<string>
     */
    protected function orderMailsAQueueWorkerWrites(callable $checkout): array
    {
        Queue::fake();
        $checkout();
        $jobs = Queue::pushedJobs()[CallQueuedListener::class] ?? [];
        $this->asAQueueWorker();

        return $this->captureEveryRender('email-templates.index', function () use ($jobs): void {
            foreach ($jobs as $queued) {
                app($queued['job']->class)->{$queued['job']->method}(...$queued['job']->data);
            }
        });
    }

    /** A real cash-on-delivery checkout of the API, served from one storefront, and the order it saved. */
    protected function placeOrderFrom(string $country, int $productId): object
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom($customer, $country, $productId);
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson('/api/v1/customer/order/place')->assertOk();
        $this->flushHeaders();

        $order = DB::table('orders')->latest('id')->first();
        $order->customer = $customer;

        return $order;
    }

    /**
     * The HTML of a template a real route renders, captured in that request: the route is called, and the template reports itself while the
     * request that asked for it is still being served, so what is captured is what the session, the storefront and the settings of that
     * request produce. The route goes on to do what it does with the document (writes a PDF, sends a mail), which this throws away.
     */
    protected function captureRender(string $template, callable $route): string
    {
        return $this->captureEveryRender($template, $route)[0] ?? '';
    }

    /**
     * The same, for a route that renders the template more than once: the order e-mail is sent to the customer and to the seller, and each
     * copy is its own document.
     *
     * @return list<string>
     */
    protected function captureEveryRender(string $template, callable $route): array
    {
        $documents = [];
        $rendering = false;

        View::composer($template, function ($view) use (&$documents, &$rendering): void {
            if ($rendering) {
                return;
            }

            $rendering = true;

            try {
                $documents[] = $view->render();
            } finally {
                $rendering = false;
            }
        });

        ob_start();

        try {
            $route();
        } finally {
            ob_end_clean();
        }

        return $documents;
    }

    /**
     * Calls a route that writes its document straight to the output, as the invoice routes do, and keeps the bytes out of the test's own output.
     *
     * @param callable(): \Illuminate\Testing\TestResponse $route
     */
    protected function callRouteThatWritesAFile(callable $route): \Illuminate\Testing\TestResponse
    {
        ob_start();

        try {
            return $route();
        } finally {
            ob_end_clean();
        }
    }

    /** Every amount the document prints, with the currency it is labelled with, in the order they appear. */
    protected function moneyPrintedIn(string $html): array
    {
        preg_match_all('/(?:[\d,]+\.\d{2,3}\s*(?:SAR|KD|KWD|AED|\$)|(?:SAR|KD|KWD|AED|\$)\s*[\d,]+\.\d{2,3})/u', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * What a queue worker has when it sends the order e-mail: no request bound to a storefront, no session of the customer who checked out, and
     * no currency anywhere. The listener that sends the mail is queued (ShouldQueue), so this is where that document is written in production —
     * not in the checkout request, whose storefront would have answered for it.
     */
    protected function asAQueueWorker(): void
    {
        $this->asAFreshVisitor();
        app()->instance('request', \Illuminate\Http\Request::create('/'));
    }

    /** Signs the customer in the way the website does, from a request that names no storefront: the default storefront serves it. */
    protected function asTheCustomerOnTheWebsite(User $customer): static
    {
        $this->asAFreshVisitor();
        $this->actingAs($customer, 'customer');

        return $this;
    }

    /**
     * A request that starts where a real one starts: no session, no headers and no storefront bound to the container. The web server hands each
     * request a container of its own; one test process hands the next request whatever the last one left, and the storefront a checkout bound
     * would otherwise answer for the document that is being measured.
     */
    private function asAFreshVisitor(): void
    {
        $this->flushSession();
        $this->flushHeaders();
        app()->forgetInstance(\App\Services\Storefront\StorefrontContext::class);
    }
}
