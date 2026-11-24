<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\SaudiLiteralScanner;

/**
 * The closed list of Saudi literals the platform's own code carries, so that a new one cannot be added unnoticed.
 *
 * ## What this proves, and what it does not
 *
 * The GCC plan has to account for every place the code says "Saudi" in a literal: the riyal's ISO code, the Saudi
 * dialling code, and the country code written into a provider payload, a setting default or a comparison. This guard
 * reads the code and compares what it finds with the list below. It fails in BOTH directions, which is the whole point:
 * a literal the list does not name fails it, and a list entry nothing matches any more fails it too, so the list cannot
 * rot into a comment that used to be true.
 *
 * It does NOT prove that no Saudi literal reaches a non-Saudi order. That is a behaviour, a scan cannot show it, and the
 * criterion it belongs to stays open until behaviour tests cover each site. What this guard gives is the inventory those
 * tests have to work through, and the certainty that the inventory is complete today.
 *
 * ## Why a literal being here is not a defect
 *
 * Three of the five categories are correct as they stand. A `world-list` entry is one row of a list of every country,
 * currency or language the platform knows: the riyal belongs in a currency list the way the dinar does. A `not-saudi`
 * entry is a false positive this guard keeps on purpose, because "sa" is also the ISO code of Sanskrit and of an older
 * customer build's locale, and a reader who meets it should be told so rather than left to work it out. A
 * `saudi-only-rule` entry is code that is Saudi by design — a courier that only ships inside the Kingdom, a phone
 * normaliser for Saudi numbers — and the GCC question for it is whether another market reaches it at all, not whether
 * the literal is wrong.
 *
 * The two that the plan has to move are `platform-default`, a platform-wide money default that a storefront with its own
 * currency must not inherit, and `provider-default`, the Saudi default of a per-account provider setting. The verifier
 * note on requirement M2-19 already records that the provider defaults ARE the defaults of per-account settings rather
 * than hard-wired values, which this inventory confirms site by site.
 *
 * ## Why it lives in tests/Unit/Architecture
 *
 * It reads files and compares strings: no database, no HTTP, nothing to set up, so it belongs with the other guards that
 * describe the shape of the codebase rather than its behaviour. It is deliberately strict about counts. A refactor that
 * adds or removes an occurrence makes it fail, and that is the behaviour asked for: somebody then reads the new line and
 * either accounts for it here or stops.
 */
class SaudiLiteralsAllowListTest extends TestCase
{
    /** The trees that are the platform's own code. Everything else (vendor, tests, resources) is out of scope by design. */
    private const ROOTS = ['app', 'config', 'Modules'];

    private const WORLD_LIST = 'world-list';
    private const NOT_SAUDI = 'not-saudi';
    private const SAUDI_ONLY_RULE = 'saudi-only-rule';
    private const PLATFORM_DEFAULT = 'platform-default';
    private const PROVIDER_DEFAULT = 'provider-default';

    /** One row of the six-market list, beside AE, KW, QA, BH and OM: a list the GCC plan owns, not a rule and not a world list. */
    private const MARKET_LIST = 'market-list';

    private const CATEGORIES = [self::WORLD_LIST, self::NOT_SAUDI, self::SAUDI_ONLY_RULE, self::PLATFORM_DEFAULT, self::PROVIDER_DEFAULT, self::MARKET_LIST];

    /**
     * Every Saudi literal in the platform's code: the file, the literal, how many times it occurs, which kind of thing it
     * is and which GCC item answers for it.
     *
     * @var array<string, array<string, array{count: int, category: string, owner: string, why: string}>>
     */
    private const ALLOWED = [
        'app/Enums/GlobalConstant.php' => [
            'SA' => ['count' => 2, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every country', 'why' => 'two rows of the country lists, beside every other country'],
            '966' => ['count' => 2, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every dialling code', 'why' => 'one row of the dialling-code list, whose name text also carries +966'],
            'SAR' => ['count' => 1, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every currency', 'why' => 'one row of the currency list, beside the dinar and the dirham'],
            'sa' => ['count' => 1, 'category' => self::NOT_SAUDI, 'owner' => 'none', 'why' => 'the ISO code of Sanskrit in the language list, not Saudi Arabia'],
        ],
        'app/Library/Constant.php' => [
            'SA' => ['count' => 2, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every country', 'why' => 'the older copy of the same lists that GlobalConstant holds'],
            '966' => ['count' => 2, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every dialling code', 'why' => 'the older copy of the same lists that GlobalConstant holds'],
            'SAR' => ['count' => 1, 'category' => self::WORLD_LIST, 'owner' => 'none: a list of every currency', 'why' => 'the older copy of the same lists that GlobalConstant holds'],
            'sa' => ['count' => 1, 'category' => self::NOT_SAUDI, 'owner' => 'none', 'why' => 'the ISO code of Sanskrit in the language list, not Saudi Arabia'],
        ],
        'app/Traits/PaymentGatewayTrait.php' => [
            'SAR' => ['count' => 7, 'category' => self::WORLD_LIST, 'owner' => 'GCC-05 (storefront currency) reads these lists, it does not own them', 'why' => 'the riyal named in seven gateways\' lists of the currencies they take, each beside many others; GCC-05 asks a gateway whether it takes a storefront\'s currency by reading exactly these lists'],
        ],
        'app/Http/Middleware/APILocalizationMiddleware.php' => [
            'sa' => ['count' => 1, 'category' => self::NOT_SAUDI, 'owner' => 'none', 'why' => 'older customer-app builds sent a country code where a locale was expected, so sa is mapped to ar; it chooses a language, never a market'],
        ],
        'app/Services/SellerCenter/Shell.php' => [
            'sa' => ['count' => 2, 'category' => self::NOT_SAUDI, 'owner' => 'none', 'why' => 'a language code in the seller centre\'s right-to-left and Arabic lists, beside sy; it chooses a text direction, never a market'],
        ],
        'app/Services/Fulfillment/Support/CurrencyCode.php' => [
            'SAR' => ['count' => 2, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); M2-19', 'why' => 'the fulfillment layer falls back to the riyal when the config names no currency, so a storefront with its own currency must not read this'],
        ],
        'app/Services/OperationalCosts/Support/FxConverter.php' => [
            'SAR' => ['count' => 2, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); M2-19', 'why' => 'operational costs are converted against a base currency that falls back to the riyal'],
        ],
        'app/Services/MyFatoorah/GatewayConfigRepository.php' => [
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); D2 payment gateways', 'why' => 'the MyFatoorah gateway currency falls back to the riyal when the config names none'],
        ],
        'app/Http/Controllers/Admin/Accounting/OdooReconciliationController.php' => [
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); H2 Odoo', 'why' => 'the reconciliation screen reads the riyal when the request names no currency'],
        ],
        'config/fulfillment.php' => [
            'SAR' => ['count' => 2, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); M2-19', 'why' => 'the fulfillment currency\'s own default, and the map that reads this platform\'s spelling KSA as the riyal'],
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'M2-19; H1 couriers', 'why' => 'the fulfillment layer\'s default country'],
        ],
        'config/myfatoorah.php' => [
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); D2 payment gateways', 'why' => 'the gateway currency\'s own default'],
        ],
        'config/operational_costs.php' => [
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency)', 'why' => 'the base currency\'s own default'],
        ],
        'app/Services/SalesChannels/Amazon/AmazonConnector.php' => [
            'SAR' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'a listing update falls back to the riyal when neither the update nor the account names a currency'],
        ],
        'app/Services/SalesChannels/Support/ChannelSettings.php' => [
            'SA' => ['count' => 2, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'the Trendyol storefronts the platform knows (SA, AE, KW) and the Saudi default of the storefront_code setting'],
            'sa' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'the Saudi default of a channel account\'s country_code setting'],
        ],
        'app/Services/SalesChannels/Trendyol/TrendyolLookups.php' => [
            'SA' => ['count' => 2, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'the storefronts whose carriers are known (SA, AE) and the Saudi default read from the account setting'],
        ],
        'app/Services/SalesChannels/Trendyol/TrendyolClient.php' => [
            'SA' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'the Saudi default read from the account\'s storefront_code setting'],
        ],
        'app/Services/SalesChannels/Noon/NoonConnector.php' => [
            'sa' => ['count' => 2, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels; M2-09', 'why' => 'the Saudi default read from the account\'s country_code setting, twice on one expression'],
        ],
        'app/Http/Controllers/Admin/SalesChannels/ChannelLabels.php' => [
            'SA' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H2 sales channels', 'why' => 'the choices offered for the Trendyol storefront setting (SA, AE, KW, none), already more than Saudi'],
        ],
        'app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php' => [
            'SA' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H1 couriers; M2-09', 'why' => 'the Saudi default of the account\'s country_code setting'],
        ],
        'app/Services/Fulfillment/Providers/M5azn/M5aznPayloadBuilder.php' => [
            'SA' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'H1 couriers; M2-09', 'why' => 'the payload reads country_code from the account config, falling back to Saudi'],
        ],
        'app/Services/Fulfillment/Support/MarketPhone.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); C-15', 'why' => 'the market a shipment is read as when the order records none, which is what every order placed before markets existed is'],
        ],
        'app/Services/Fulfillment/Support/SaudiPhone.php' => [
            '966' => ['count' => 3, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'M2-19; H1 couriers', 'why' => 'the normaliser that turns every spelling of a Saudi number into one form; a non-Saudi number must not be given to it. Three occurrences: the Saudi row of the trunk-zero dialling-code list, the prefix the normaliser reads, and the prefix the two-spellings comparison refuses to answer for'],
        ],
        'app/Services/MyFatoorah/PaymentInitiationService.php' => [
            '966' => ['count' => 3, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'M2-19; D2 payment gateways', 'why' => 'splitting a Saudi number into its dialling code and local part, longest prefix first'],
        ],
        'app/Services/SalesChannels/Dispatch/BuyerEchoScrubber.php' => [
            '966' => ['count' => 3, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'M2-19; H2 sales channels', 'why' => 'every spelling of a Saudi number is scrubbed out of what a channel echoes back; a number of another market would not be scrubbed'],
        ],
        'app/Services/SalesChannels/Dispatch/ChannelRecipient.php' => [
            'SA' => ['count' => 1, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'M2-19; H2 sales channels', 'why' => 'a recipient outside Saudi Arabia is refused by this comparison'],
        ],
        'app/Services/SalesChannels/Dispatch/DispatchGate.php' => [
            'SA' => ['count' => 1, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'M2-19; H2 sales channels', 'why' => 'a dispatch whose destination country is not Saudi Arabia is refused by this comparison'],
        ],
        'app/Services/Storefront/StorefrontContext.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); M2-19', 'why' => 'UNSCOPED_COUNTRY: the country a request belonging to no storefront is read as, which is what the whole platform was before storefronts existed. A storefront request never reads it'],
        ],
        'app/Services/Storefront/StorefrontResolver.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); M2-19', 'why' => 'BUILT_IN_COUNTRY: the one storefront the platform resolves to while none is configured'],
            'sa' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); M2-19', 'why' => 'the url path of that built-in storefront'],
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); M2-19', 'why' => 'the currency of that built-in storefront'],
        ],
        'app/Services/Staff/StaffCountryScope.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'K-09 (staff country scopes); M2-19', 'why' => 'TABLE_DEFAULT_COUNTRY: the default of the scope column, so a staff row written before anyone is given a country reads as the market the platform already served'],
        ],
        'app/Services/Catalog/ProductCodeScope.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'B-04..B-07 (product identity per country); M2-19', 'why' => 'TABLE_DEFAULT_COUNTRY: the default of the product country column, so a product row written before the identity migration reads as the market the platform already served'],
        ],
        'app/Services/PlatformAdmin/PlatformMarkets.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-20 (owner super admin); M2-19', 'why' => 'FALLBACK_DEFAULT_COUNTRY: the market the control tower opens on when the configuration names none'],
        ],
        'app/Services/Marketing/MarketingConsent.php' => [
            'SA' => ['count' => 1, 'category' => self::MARKET_LIST, 'owner' => 'A2-13/I1-08 (marketing consent per market)', 'why' => 'one row of MARKETS, beside AE, KW, QA, BH and OM'],
        ],
        'app/Services/Marketing/MarketingMarket.php' => [
            'SA' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'A2-13/I1-08 (marketing consent per market); M2-19', 'why' => 'the home market a configuration naming none, or naming something that is not one of the six, falls back to'],
        ],
        'app/Services/SalesChannels/Support/ChannelAccountCountry.php' => [
            'SA' => ['count' => 1, 'category' => self::PROVIDER_DEFAULT, 'owner' => 'the sales channels (M2-19)', 'why' => 'one row of the map from a marketplace id to its country: A17E79C6D8DWNP is the Saudi marketplace, and the map carries the other markets beside it'],
        ],
        'config/marketing.php' => [
            'SA' => ['count' => 2, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'A2-13/I1-08 (marketing consent per market); M2-19', 'why' => 'two occurrences: the SA row of the per-market consent-policy map, which reads its own environment variable like the other five, and the home market a configuration naming none falls back to'],
        ],
        'config/gcc.php' => [
            'SA' => ['count' => 4, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); M2-19', 'why' => 'four occurrences: the default of GCC_DEFAULT_COUNTRY, the SA row of the storefront list beside the other five, the SA row of the phone rules beside the other five (C-15), and the one entry of `grandfathered` -- the market whose behaviour must not change when the switches go on'],
            '966' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); C-15', 'why' => "the Kingdom's dialling code in the SA row of the phone rules, beside the dialling codes of the other five markets; it moved here out of the Torod payload builder, which no longer carries one"],
            'sa' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-02/GCC-03 (storefronts); M2-19', 'why' => 'the url path of the SA storefront, beside the paths of the other five'],
            'SAR' => ['count' => 1, 'category' => self::PLATFORM_DEFAULT, 'owner' => 'GCC-05 (storefront currency); M2-19', 'why' => 'the currency of the SA storefront, beside the currencies of the other five'],
        ],
        'app/Services/Address/AddressCountry.php' => [
            'sa' => ['count' => 1, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'the address system (docs/address/README.md); M2-19', 'why' => 'one of the spellings by which an address book entry is recognised as Saudi'],
            'SA' => ['count' => 1, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'the address system (docs/address/README.md); M2-19', 'why' => 'the code isoCode() answers with once isSaudi() has recognised one of those spellings, before it looks through the five other markets'],
        ],
        'app/Services/Address/AddressImporter.php' => [
            'SA' => ['count' => 2, 'category' => self::SAUDI_ONLY_RULE, 'owner' => 'the address system (docs/address/README.md); M2-19', 'why' => 'the importer finds and creates the Saudi regions it seeds'],
        ],
    ];

    public function test_every_saudi_literal_in_the_platforms_code_is_named_in_this_list(): void
    {
        $found = $this->countsFound();

        $this->assertNotSame([], $found, 'the scan found no Saudi literal at all, so this guard proved nothing: check the roots and the scanner');

        $unaccounted = [];
        foreach ($found as $file => $literals) {
            foreach ($literals as $literal => $count) {
                $allowed = self::ALLOWED[$file][$literal]['count'] ?? null;
                if ($allowed === null) {
                    $unaccounted[] = sprintf('%s carries the literal %s %d time(s) and this list does not name it', $file, $literal, $count);
                } elseif ($allowed !== $count) {
                    $unaccounted[] = sprintf('%s carries the literal %s %d time(s), this list says %d', $file, $literal, $count, $allowed);
                }
            }
        }

        $this->assertSame(
            [],
            $unaccounted,
            "A Saudi literal is in the code that this list does not account for. Read the line, then add it to ALLOWED with the category it belongs to and the GCC item that answers for it — or take the literal out of the code:\n - " . implode("\n - ", $unaccounted),
        );
    }

    public function test_this_list_names_nothing_that_is_no_longer_in_the_code(): void
    {
        $found = $this->countsFound();

        $stale = [];
        foreach (self::ALLOWED as $file => $literals) {
            foreach (array_keys($literals) as $literal) {
                if (!isset($found[$file][$literal])) {
                    $stale[] = sprintf('%s is listed as carrying %s and it does not', $file, $literal);
                }
            }
        }

        $this->assertSame(
            [],
            $stale,
            "This list names a Saudi literal the code no longer carries, so it is out of date and would hide the next real one:\n - " . implode("\n - ", $stale),
        );
    }

    public function test_every_entry_says_which_kind_of_literal_it_is_and_who_answers_for_it(): void
    {
        foreach (self::ALLOWED as $file => $literals) {
            foreach ($literals as $literal => $entry) {
                $where = $file . ' / ' . $literal;
                $this->assertContains($entry['category'], self::CATEGORIES, $where . ' has a category that is not one of the six');
                $this->assertNotSame('', trim($entry['owner']), $where . ' names no GCC item and no reason why none owns it');
                $this->assertNotSame('', trim($entry['why']), $where . ' says nothing about what the literal is doing there');
                $this->assertGreaterThan(0, $entry['count'], $where . ' is listed with no occurrence');
            }
        }
    }

    public function test_the_two_money_categories_are_the_ones_the_plan_still_has_to_move(): void
    {
        $toMove = [];
        foreach (self::ALLOWED as $file => $literals) {
            foreach ($literals as $literal => $entry) {
                if (in_array($entry['category'], [self::PLATFORM_DEFAULT, self::PROVIDER_DEFAULT], true)) {
                    $toMove[] = $file . ' / ' . $literal;
                }
            }
        }

        // Not an aspiration: it is the inventory the behaviour tests of M2-19 and M2-09 have to work through, and it must
        // not quietly empty itself. A literal that moves to a per-storefront or per-account setting leaves this list by
        // being deleted from ALLOWED, which the staleness test above forces somebody to do deliberately.
        $this->assertNotSame([], $toMove, 'no platform or provider default is listed any more: if that is really so, delete this test with the reason');

        // 17 until the GCC branches were integrated, then 30. The thirteen are not new code written here: they arrived with the lanes
        // that were merged, each a default rather than a hard-wired market, and they are listed one by one in ALLOWED above --
        // StorefrontContext's UNSCOPED_COUNTRY; StorefrontResolver's BUILT_IN_COUNTRY with its path and currency; the two
        // TABLE_DEFAULT_COUNTRY column defaults (StaffCountryScope, ProductCodeScope); PlatformMarkets' FALLBACK_DEFAULT_COUNTRY;
        // MarketingMarket's home market; the Amazon SA marketplace id in ChannelAccountCountry; config/marketing.php's policy row and
        // home market; and config/gcc.php's default country, SA storefront path and currency. The number rises deliberately and in the
        // open: this list is the inventory the behaviour tests of M2-19 and M2-09 have to work through, and it is now thirteen longer.
        //
        // Then 32, with C-15's per-market phone rules: MarketPhone's COUNTRY_OF_LAST_RESORT, the market a shipment is read as when the
        // order records none, and the Kingdom's dialling code in the SA row of config/gcc.php's phone rules, beside the other five
        // markets' -- which is where it moved to out of the Torod payload builder, whose entry is gone from ALLOWED because the builder
        // no longer carries one. That move is the shape every line of this inventory is meant to take, and it took one off the Saudi-only
        // list rather than off this one, because a row of the six-market table is still a Saudi value written in code.
        $this->assertCount(32, $toMove, "the number of Saudi defaults the plan still has to move changed. If a default moved to a setting, delete its entry and lower this number; if one was added, that is a regression:\n - " . implode("\n - ", $toMove));
    }

    public function test_the_scanner_reads_literals_and_not_prose(): void
    {
        $scanner = new SaudiLiteralScanner();

        $code = <<<'PHP'
        <?php
        // A comment about +966 and 'SAR' and 'SA' proves nothing.
        /** A docblock naming SAR and 966 and 'sa'. */
        $currency = 'SAR';
        $dialling = '00966';
        $country = "SA";
        $language = 'sa';
        $number = 966;
        $interpolated = "966{$local}";
        $other = 'SARAH';
        $lower = 'sar';
        PHP;

        $literals = array_map(fn (array $finding) => $finding['literal'], $scanner->findingsIn($code, 'fixture.php'));

        $this->assertSame(
            ['SAR', '966', 'SA', 'sa', '966', '966'],
            $literals,
            'the scanner must read the six literals and skip the comment, the docblock, SARAH and the lower-case sar',
        );
    }

    /**
     * How many times each Saudi literal occurs in each file of the platform's code.
     *
     * @return array<string, array<string, int>>
     */
    private function countsFound(): array
    {
        $scanner = new SaudiLiteralScanner();
        $root = dirname(__DIR__, 3);
        $counts = [];

        foreach (self::ROOTS as $tree) {
            $directory = $root . '/' . $tree;
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
                foreach ($scanner->findingsIn(file_get_contents($file->getPathname()), $relative) as $finding) {
                    $counts[$finding['file']][$finding['literal']] = ($counts[$finding['file']][$finding['literal']] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
