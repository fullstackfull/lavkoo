<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ApplySeoRedirects;
use App\Models\Redirect;
use App\Services\Seo\RedirectResolverService;
use App\Services\Storefront\StorefrontMarketRows;
use App\Services\Storefront\StorefrontPathPrefix;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSeoRedirectRules;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * An SEO redirect belongs to one market (A1-V2).
 *
 * A rule maps one path of the shop to another, and with six markets the same path means a different page in each: `/offers` in Kuwait is not
 * `/offers` in Riyadh. One rule applied to every market sends a shopper of another market to a page that is not theirs -- with a 301, which
 * a search engine and a browser both remember, so it is not a mistake a later fix undoes.
 *
 * The first test is the one that protects what exists: while storefronts are off, every rule applies everywhere, exactly as it does today.
 *
 * **What this file proves, and what it demonstrably did NOT.** It asks the middleware for its rules with a storefront already BOUND
 * (`->serve()`), which measures the narrowing rule and nothing about the website: on a real web request nothing has bound a storefront when
 * this middleware runs, because it is `web` GROUP middleware and the resolver is ROUTE middleware. So every assertion below passed while
 * the narrowing was inert on every page of the live shop. That is the exact fault the rules of this project name -- a narrowing that reads a
 * bound context proves nothing until something on that route binds it -- and it is why
 * {@see StorefrontSeoRedirectWebsiteTest} exists and drives the real route instead. Keep both: this file pins the RULE (which rows belong
 * to which market, the one cache entry, the migration), that one pins the WEBSITE.
 *
 * The hit accounting IS driven, because two markets can now hold a rule for the same path and the count an operator reads must not be the
 * other market's.
 */
class StorefrontSeoRedirectTest extends TestCase
{
    use BuildsSeoRedirectRules;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildTheRedirectTable();
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA', 'gcc.storefronts.countries.KW.active' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('redirects');
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return list<string> the paths the rules served to one market redirect FROM */
    private function rulesServedIn(string $market): array
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $market)->serve(function (): array {
            $middleware = new class(app(RedirectResolverService::class), app(StorefrontPathPrefix::class)) extends ApplySeoRedirects {
                /** @return array<int, array<string, mixed>> */
                public function rules(): array
                {
                    return $this->loadRules();
                }
            };

            return array_map(static fn (array $rule): string => (string) $rule['from_path'], $middleware->rules());
        });
    }

    public function test_with_storefronts_off_every_rule_applies_everywhere(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->aRuleOf(null, '/old-saudi', '/new-saudi');
        $this->aRuleOf('KW', '/old-kuwait', '/new-kuwait');

        $this->assertSame(['/old-saudi', '/old-kuwait'], $this->rulesServedIn('KW'), 'the platform as it ships: one rule set for the shop');
    }

    public function test_a_rule_of_one_market_is_not_served_to_another(): void
    {
        $this->aRuleOf('KW', '/old-kuwait', '/new-kuwait');
        $this->aRuleOf('SA', '/old-saudi', '/new-saudi');

        $this->assertSame(['/old-kuwait'], $this->rulesServedIn('KW'));
        $this->assertSame(['/old-saudi'], $this->rulesServedIn('SA'));
    }

    /** A rule written before this column existed is the default storefront's, and only its own market reads it. */
    public function test_a_rule_that_names_no_market_belongs_to_the_default_storefront(): void
    {
        $this->aRuleOf(null, '/old', '/new');

        $this->assertSame(['/old'], $this->rulesServedIn('SA'));
        $this->assertSame([], $this->rulesServedIn('KW'), 'Kuwait does not inherit the Saudi shop\'s redirects');
    }

    public function test_the_default_storefront_reads_its_own_rules_and_the_unmarked_ones(): void
    {
        $this->aRuleOf(null, '/unmarked', '/new');
        $this->aRuleOf('SA', '/saudi', '/new');
        $this->aRuleOf('KW', '/kuwait', '/new');

        $this->assertSame(['/unmarked', '/saudi'], $this->rulesServedIn('SA'));
    }

    /** One cache entry still holds every rule, so an operator's save, which forgets that one key, keeps clearing it for every market. */
    public function test_the_cache_is_one_entry_for_the_whole_platform(): void
    {
        $this->aRuleOf('KW', '/old-kuwait', '/new-kuwait');
        $this->rulesServedIn('KW');

        $this->assertTrue(Cache::has(Redirect::ACTIVE_CACHE_KEY), 'the one key the admin screens forget');

        $this->aRuleOf('SA', '/old-saudi', '/new-saudi');
        $this->assertSame([], $this->rulesServedIn('SA'), 'the cached set is read, as it is today');

        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
        $this->assertSame(['/old-saudi'], $this->rulesServedIn('SA'), 'and the save of a rule clears it for every market at once');
    }

    /** Two markets may hold a rule for the same path, and the hit of one is not counted against the other's rule. */
    public function test_a_hit_is_counted_against_the_rule_of_the_market_that_was_served(): void
    {
        // The SAUDI rule first, deliberately: `->limit(1)->update()` with no order takes whichever row the engine reaches first, so a test
        // that inserted the served market's rule first would pass with no narrowing at all.
        $saudi = $this->aRuleOf('SA', '/offers', '/saudi-offers');
        $kuwaiti = $this->aRuleOf('KW', '/offers', '/kuwait-offers');

        StorefrontResolver::fromConfig()->context(explicitChoice: 'KW')->serve(function (): void {
            $middleware = new class(app(RedirectResolverService::class), app(StorefrontPathPrefix::class)) extends ApplySeoRedirects {
                public function hit(string $path): void
                {
                    $this->recordHit($path);
                }
            };
            $middleware->hit('/offers');
        });

        $this->assertSame(1, (int) DB::table('redirects')->where('id', $kuwaiti)->value('hits'));
        $this->assertSame(0, (int) DB::table('redirects')->where('id', $saudi)->value('hits'), 'the Saudi rule was not touched');
    }

    /** The migration refuses to go back while a rule names a market, because dropping the column would point the Saudi shop elsewhere. */
    public function test_the_migration_refuses_to_roll_back_while_a_market_has_its_own_rules(): void
    {
        $this->aRuleOf('KW', '/old-kuwait', '/new-kuwait');

        $this->expectException(\RuntimeException::class);

        (require database_path('migrations/' . $this->redirectMarketMigration()))->down();
    }

    public function test_the_migration_rolls_back_when_no_rule_names_a_market(): void
    {
        $this->aRuleOf(null, '/old', '/new');

        (require database_path('migrations/' . $this->redirectMarketMigration()))->down();

        $this->assertFalse(Schema::hasColumn('redirects', StorefrontMarketRows::COLUMN));
    }
}
