<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $html = null;

        View::composer($template, function ($view) use (&$html): void {
            if ($html !== null) {
                return;
            }

            $html = '';
            $html = $view->render();
        });

        ob_start();

        try {
            $route();
        } finally {
            ob_end_clean();
        }

        return (string) $html;
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

    /** Signs the customer in the way the website does, from a request that names no storefront: the default storefront serves it. */
    protected function asTheCustomerOnTheWebsite(User $customer): static
    {
        $this->flushSession();
        $this->flushHeaders();
        $this->actingAs($customer, 'customer');

        return $this;
    }
}
