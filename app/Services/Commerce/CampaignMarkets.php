<?php

namespace App\Services\Commerce;

use App\Models\ExperienceCampaign;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Which markets one experience campaign runs in (I1-15, GCC-17).
 *
 * The engine was already there and only the writer was missing: {@see ExperienceCampaign} carries {@see \App\Traits\HasStorefrontVisibility},
 * so a campaign with a mapping row is served in the markets its rows name and a campaign with none is served everywhere -- the `assigned`
 * mode of {@see StorefrontVisibility}. Nothing in the panel could write such a row, so every campaign anybody created was platform-wide and
 * "a central campaign selects one, several or all markets" was a sentence about a table nobody filled in.
 *
 * The four questions this screen asks about markets are answered here once, for the same reason {@see \App\Services\Notification\BroadcastMarkets}
 * answers the broadcast's: which markets an operator may choose, what is stored, which campaigns they are shown, and which they may change.
 * A second copy of any of them is a campaign that is listed in Kuwait and edited from Saudi Arabia.
 *
 * **No market chosen means EVERY market**, which is the platform of today and every campaign row an installation already has. A market chosen
 * means that market and no other -- and for a RESTRICTED employee, nothing chosen means their OWN markets, because "every market" is a reach
 * their scope does not grant and a form value must never be able to widen it.
 */
#[Singleton]
final class CampaignMarkets
{
    private const ENTITY_TYPE = StorefrontVisibility::EXPERIENCE_CAMPAIGN;

    /** Whether a campaign can name markets at all: storefronts are on and the mapping migration has run. */
    public function applies(): bool
    {
        return StorefrontVisibility::isTracked();
    }

    /**
     * The markets an operator may choose from, narrowed to the employee's own.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        if (!$this->applies()) {
            return [];
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();
        $allowed = $this->scope();

        return $allowed === null ? $configured : array_values(array_intersect($configured, $allowed));
    }

    /** Whether this employee may run a campaign in every market. A restricted employee may not: their reach is their own markets. */
    public function mayChooseEveryMarket(): bool
    {
        return $this->scope() === null;
    }

    /**
     * Whether this employee may create a campaign at all.
     *
     * An employee whose scope grants NO market reaches nobody, and no market named means every market -- so without this question such an
     * employee would create the widest campaign on the platform by choosing nothing.
     */
    public function mayCreate(): bool
    {
        return !$this->applies() || $this->mayChooseEveryMarket() || $this->choices() !== [];
    }

    /**
     * The markets of the chosen value this employee may actually run in, in the order the markets are configured.
     *
     * An empty list is returned ONLY for an employee who may reach every market. For a restricted employee the result is always one or more
     * of their own markets, whatever the form carried -- nothing chosen, a market that is not theirs, or a code that is not a market at all.
     *
     * @return list<string>
     */
    public function accepted(mixed $chosen): array
    {
        if (!$this->applies()) {
            return [];
        }

        $choices = $this->choices();
        $asked = array_values(array_filter(array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            is_array($chosen) ? $chosen : ($chosen === null || $chosen === '' ? [] : [$chosen]),
        )));
        $accepted = $asked === [] ? [] : array_values(array_intersect($choices, $asked));

        return $accepted !== [] || $this->mayChooseEveryMarket() ? $accepted : $choices;
    }

    /**
     * The markets a stored campaign runs in.
     *
     * @return list<string> empty for every market
     */
    public function of(ExperienceCampaign $campaign): array
    {
        return $this->applies()
            ? StorefrontVisibility::storefrontsOf(entityType: self::ENTITY_TYPE, entityId: $campaign->getKey())
            : [];
    }

    /**
     * Writes the markets a campaign runs in, through the one mapping writer. An empty list removes the mapping, which is what runs a
     * campaign everywhere -- so it is written only for an employee who may reach every market, and {@see accepted()} is what guarantees that.
     *
     * @param list<string> $markets
     */
    public function apply(ExperienceCampaign $campaign, array $markets): void
    {
        if (!$this->applies()) {
            return;
        }

        StorefrontVisibility::set(entityType: self::ENTITY_TYPE, entityId: $campaign->getKey(), countries: $markets);
    }

    /**
     * The campaigns this employee is shown: the ones that run in a market of theirs, which includes the ones that name no market because
     * those dress their storefront too. A campaign assigned to another market only is not listed.
     *
     * Filtered in PHP rather than in SQL on purpose: the panel's campaign list is one page of rows, and the mapping's own
     * {@see StorefrontVisibility::isVisible()} is then the single reading of what "assigned" means. A second SQL spelling of that rule is a
     * second answer waiting to disagree with the storefront's.
     *
     * @param Collection<int, ExperienceCampaign> $campaigns
     * @return Collection<int, ExperienceCampaign>
     */
    public function listedFor(Collection $campaigns): Collection
    {
        $allowed = $this->applies() ? $this->scope() : null;

        if ($allowed === null) {
            return $campaigns;
        }

        return $campaigns->filter(fn (ExperienceCampaign $campaign): bool => $this->runsInAnyOf($campaign, $allowed))->values();
    }

    /**
     * Whether this employee may change this campaign.
     *
     * A campaign that names NO market runs in every market, so changing it changes markets that are not theirs -- it is refused rather than
     * narrowed, because silently confining somebody else's platform-wide campaign to Kuwait is a bigger surprise than being told no. A
     * campaign that names a market outside their scope is refused for the same reason.
     */
    public function mayChange(ExperienceCampaign $campaign): bool
    {
        $allowed = $this->applies() ? $this->scope() : null;

        if ($allowed === null) {
            return true;
        }

        $markets = $this->of($campaign);

        return $markets !== [] && array_diff($markets, $allowed) === [];
    }

    /** @param list<string> $allowed */
    private function runsInAnyOf(ExperienceCampaign $campaign, array $allowed): bool
    {
        foreach ($allowed as $country) {
            if ($campaign->isShownByItsStorefront(country: $country)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null null when the employee is not restricted */
    private function scope(): ?array
    {
        return app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user());
    }
}
