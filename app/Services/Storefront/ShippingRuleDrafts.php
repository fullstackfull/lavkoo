<?php

namespace App\Services\Storefront;

use App\Models\ShippingMethod;
use App\Models\ShippingRuleDraft;
use App\Models\Scopes\StaffCountryShippingScope;
use App\Services\AuditLogger;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Copying a shipping rule towards another market, as an unpublished draft, and publishing one afterwards (H1-14's last criterion).
 *
 * The owner's ruling of 2026-11-25, carried out: a copy is a DRAFT that is not published, does not affect the live market, and carries no
 * carrier credentials, contracts or sensitive information; publishing is a separate action with permissions and an audit trail. The plan's own
 * words were that copying "creates a draft instead of sharing", and the difference matters -- sharing would mean two markets reading one row,
 * so a price typed for Riyadh would move when somebody in Kuwait edited it.
 *
 * **Three things this refuses, each because it would be a lie rather than merely a mistake.**
 *
 *  - Copying a rule towards the market it already belongs to. There is nothing to draft: the market has the rule.
 *  - Copying towards a market the platform does not serve. A draft for a market that cannot be opened is a decision nobody can ever act on.
 *  - Publishing a draft twice. The second publish would make a second live method with the same name and price, and a shop would offer the
 *    same delivery option twice at the same price with no way to tell them apart.
 *
 * **What publishing does and does not do.** It writes ONE `shipping_methods` row, in the target market, SWITCHED OFF, owned by the
 * administrator who published it -- never by the source's owner, because a rule owned by a shop in another country is not that shop's to be
 * given away. Switched off, so publishing by itself changes nothing a customer sees: somebody still has to turn the option on, which is the
 * existing, audited action for that. The draft then records who published it and which method it became, so it can never be published again.
 *
 * **Permissions are the staff country scope's, not a new idea.** An employee may copy FROM a market they work with and TOWARDS a market they
 * work with, and may publish only into a market they work with. An unrestricted administrator may do all of it, which is every administrator
 * while the staff country scopes are off.
 *
 * Dormant: nothing here runs until the drafts table exists, and `available()` is what every caller asks first.
 */
#[Singleton]
final class ShippingRuleDrafts
{
    public const TABLE = 'shipping_rule_drafts';

    /** What a draft of the platform-wide rule records as its source market. */
    public const PLATFORM_WIDE = '';

    public function __construct(
        private readonly StorefrontShippingMethods $methods,
        private readonly StaffCountryScope $staff,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Whether copying is available at all: the drafts table and the market column both have to be there. */
    public function available(): bool
    {
        return Schema::hasTable(self::TABLE) && $this->methods->tableCarriesAMarket();
    }

    /**
     * Copies one live shipping rule towards another market as an unpublished draft.
     *
     * The source is read ONCE, here, and what is copied is frozen into the draft: a publish next week writes what was copied and reviewed,
     * not whatever the source has become since.
     *
     * @throws InvalidArgumentException with the language key of why it was refused
     */
    public function copyTowards(int|string $methodId, string $targetCountry, ?int $by = null, ?string $byName = null): ShippingRuleDraft
    {
        $this->refuse($this->available() ? null : 'copying_a_shipping_rule_is_not_available_on_this_installation');

        $target = $this->countryKey($targetCountry);
        // The source is read WITHOUT the staff scope and then checked explicitly, so the refusal says "not your market" rather than "no such
        // rule": an employee copying a rule they may not touch is a different mistake from naming a rule that does not exist.
        $method = ShippingMethod::withoutGlobalScope(StaffCountryShippingScope::class)->find($methodId);
        // An unmarked rule is the DEFAULT market's -- the rule everything else here follows -- so it is resolved to that market before the
        // comparison. Otherwise copying the platform's own rule towards the default storefront would read as a copy between two markets.
        $source = $method === null ? null : $this->marketOf($method);
        $source = $source === self::PLATFORM_WIDE && $method !== null ? $this->defaultCountry() : $source;

        $this->refuse(match (true) {
            $method === null => 'that_shipping_rule_does_not_exist',
            $target === self::PLATFORM_WIDE => 'say_which_market_this_shipping_rule_is_copied_towards',
            !$this->platformServes($target) => 'the_platform_does_not_serve_that_market',
            $source === $target => 'that_market_already_has_this_shipping_rule',
            !$this->staff->allows($source) => 'your_account_does_not_work_with_the_market_this_rule_belongs_to',
            !$this->staff->allows($target) => 'your_account_does_not_work_with_the_market_you_are_copying_towards',
            default => null,
        });

        return DB::transaction(function () use ($method, $source, $target, $by, $byName): ShippingRuleDraft {
            $draft = ShippingRuleDraft::updateOrCreate(
                ['target_country' => $target, 'source_method_id' => $method->getKey()],
                [
                    'source_country' => $source,
                    // The three things a delivery option IS to a customer. No credentials, no owner, no switch.
                    'title' => $method->title,
                    'cost' => $method->cost,
                    'duration' => $method->duration,
                    'copied_by' => $by,
                    'copied_by_name' => $byName,
                    'published_at' => null,
                    'published_by' => null,
                    'published_by_name' => null,
                    'published_method_id' => null,
                ],
            );

            $this->audit->record(
                action: 'shipping_rule_draft.copied',
                subject: ['type' => 'shipping_rule_draft', 'id' => $draft->id],
                after: ['target_country' => $target, 'source_country' => $source, 'source_method_id' => $method->getKey()],
                context: ['by' => $byName],
            );

            return $draft;
        });
    }

    /**
     * Publishes a draft as a live shipping method of its target market, switched OFF.
     *
     * @throws InvalidArgumentException with the language key of why it was refused
     */
    public function publish(int|string $draftId, ?int $by = null, ?string $byName = null): ShippingMethod
    {
        $this->refuse($this->available() ? null : 'copying_a_shipping_rule_is_not_available_on_this_installation');

        return DB::transaction(function () use ($draftId, $by, $byName): ShippingMethod {
            $draft = ShippingRuleDraft::query()->lockForUpdate()->find($draftId);

            $this->refuse(match (true) {
                $draft === null => 'that_shipping_rule_draft_does_not_exist',
                $draft->isPublished() => 'that_shipping_rule_draft_has_already_been_published',
                !$this->platformServes($draft->target_country) => 'the_platform_does_not_serve_that_market',
                !$this->staff->allows($draft->target_country) => 'your_account_does_not_work_with_the_market_you_are_publishing_into',
                default => null,
            });

            $method = ShippingMethod::query()->create([
                'creator_id' => $by,
                'creator_type' => 'admin',
                'title' => $draft->title,
                'cost' => $draft->cost,
                'duration' => $draft->duration,
                // Switched off, so publishing by itself changes nothing a customer sees. Turning it on is the existing audited action.
                'status' => false,
                StorefrontShippingMethods::COLUMN => $draft->target_country,
            ]);

            $draft->forceFill([
                'published_at' => now(),
                'published_by' => $by,
                'published_by_name' => $byName,
                'published_method_id' => $method->getKey(),
            ])->save();

            $this->audit->record(
                action: 'shipping_rule_draft.published',
                subject: ['type' => 'shipping_rule_draft', 'id' => $draft->id],
                after: ['target_country' => $draft->target_country, 'shipping_method_id' => $method->getKey(), 'status' => false],
                context: ['by' => $byName],
            );

            return $method;
        });
    }

    /**
     * The drafts waiting for a decision, narrowed to the markets the current request may work with.
     *
     * @return Collection<int, ShippingRuleDraft>
     */
    public function waiting(): Collection
    {
        // An empty collection built in PHP, not a query that can never match: before the migration the table is not there at all, and
        // `where 1 = 0` would still ask the database for it.
        if (!$this->available()) {
            return new Collection();
        }

        $countries = $this->staff->boundCountries();

        return ShippingRuleDraft::query()
            ->waiting()
            ->when($countries !== null, static fn ($query) => $query->whereIn('target_country', $countries ?? []))
            ->orderBy('target_country')
            ->orderBy('id')
            ->get();
    }

    /** The market a live method belongs to: its own, or the platform-wide one when it names none. */
    private function marketOf(ShippingMethod $method): string
    {
        $market = $method->getAttribute(StorefrontShippingMethods::COLUMN);

        return is_string($market) ? strtoupper(trim($market)) : self::PLATFORM_WIDE;
    }

    private function platformServes(string $country): bool
    {
        return in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true);
    }

    private function defaultCountry(): string
    {
        return StorefrontResolver::fromConfig()->defaultCountry();
    }

    private function countryKey(?string $country): string
    {
        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::PLATFORM_WIDE;
    }

    private function refuse(?string $key): void
    {
        if ($key !== null) {
            throw new InvalidArgumentException($key);
        }
    }
}
