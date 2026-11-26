<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a build that sends NO `X-Storefront` gets (GCC-19; K-14's third criterion, and J-03's installed-build case).
 *
 * This is the one half of that criterion nobody can read off a store listing. Every seller and customer app that is installed TODAY was built
 * before the header existed, and the two apps' current builds send none either while their storefront flag is off -- which is the default, and
 * which the seller app proves on its own side (`test/market_selector/seller_market_choice_request_headers_test.dart`: a choice built with
 * `enabled: false` answers no header at all). So the day storefronts are switched ON for six markets, every phone in the field asks with no
 * header, and what those phones are served is settled HERE, by the resolver, and by nothing in either app.
 *
 * The answer must be Saudi Arabia -- the market this platform has always served -- and it must not depend on anything the visitor's device happens
 * to carry. That is what follows: with storefronts on and all six markets active, a request with no header is SA/SAR; it is still SA when the device
 * sends nothing at all; and it is still SA when the request carries a header the resolver cannot use.
 *
 * The seller app's own version gate is NOT touched by any of this and is not read here: K-14's other two criteria need the live published minimum
 * and the store versions, which are the owner's to supply (see `docs/gcc/audits/2026-11-12-seller-version-verification.md`).
 */
class BuildThatSendsNoStorefrontHeaderTest extends TestCase
{
    /** The six markets of the approved design, so the test is about a platform serving all of them and not about a platform serving one. */
    private const MARKETS = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    public function test_with_six_markets_open_a_build_that_sends_no_header_is_served_the_saudi_storefront(): void
    {
        $this->openEveryMarket();

        $this->probe('/__storefront-probe')->assertOk()->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_it_is_the_configured_default_that_decides_and_not_a_name_in_the_code(): void
    {
        $this->openEveryMarket();
        config(['gcc.storefronts.default_country' => 'KW']);

        // A1-03: which storefront a header-less request gets is a recorded decision read at boot, not a constant. The Saudi answer above is what
        // today's configuration says, and this is the proof that it is the configuration saying it.
        $this->probe('/__storefront-probe')->assertExactJson(['country' => 'KW', 'currency' => 'KWD']);
    }

    #[DataProvider('headersAnOldBuildMightSend')]
    public function test_a_header_the_resolver_cannot_use_is_served_the_default_too(mixed $value): void
    {
        $this->openEveryMarket();

        $this->probe('/__storefront-probe', ['X-Storefront' => $value])->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function headersAnOldBuildMightSend(): array
    {
        return [
            'an empty header' => [''],
            'spaces' => ['   '],
            'a country nobody configured' => ['ZZ'],
            'a country name rather than a code' => ['Saudi Arabia'],
            'the word null' => ['null'],
            'a header sent twice' => [['AE', 'KW']],
        ];
    }

    public function test_the_header_less_request_carries_nothing_of_a_market_away_with_it(): void
    {
        $this->openEveryMarket();

        $response = $this->probe('/__storefront-probe');

        // An installed build is not a browser: it keeps no cookie jar anybody manages, so a storefront remembered in a cookie would be a market
        // chosen for a phone that never chose one, and the next request would be served a market for a reason nobody could see.
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(session()->has('currency_code'));
    }

    public function test_while_storefronts_are_switched_off_a_header_less_request_is_served_exactly_what_it_always_was(): void
    {
        // The state every installation is in today: the switch off, so the resolver serves the default to everybody, header or no header.
        $this->assertFalse((bool) config('gcc.storefronts.enabled'));

        $this->probe('/__storefront-probe')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/__storefront-probe', ['X-Storefront' => 'KW'])->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    private function openEveryMarket(): void
    {
        $settings = ['gcc.storefronts.enabled' => true];
        foreach (self::MARKETS as $market) {
            $settings["gcc.storefronts.countries.{$market}.active"] = true;
        }
        config($settings);

        $this->assertSame(self::MARKETS, array_keys((array) config('gcc.storefronts.countries')), 'the six markets of the approved design, in configuration order');
    }

    private function probe(string $uri, array $headers = []): TestResponse
    {
        Route::middleware('storefront')->get('/__storefront-probe', fn () => response()->json([
            'country' => app(StorefrontContext::class)->country,
            'currency' => app(StorefrontContext::class)->currency,
        ]));

        return $this->withoutExceptionHandling()->get($uri, $headers);
    }
}
