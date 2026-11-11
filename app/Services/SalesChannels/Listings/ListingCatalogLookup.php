<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\ChannelListingDraft;
use App\Services\AuditLogger;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ReadsCatalog;
use App\Services\SalesChannels\Data\CatalogCandidate;
use App\Services\SalesChannels\Data\CatalogMatch;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asks the channel's own catalogue what it already holds for this product, and keeps the answer on the draft.
 *
 * It exists for one thing the draft cannot get anywhere else: the channel's own PRODUCT TYPE. Without it `DescribesListingRequirements` has nothing to ask about, so the
 * required fields stay unknown and the draft can never leave DRAFT. Today a person types it; the catalogue knows it.
 *
 * WHAT IT WILL NOT DO:
 *
 *  - **It never links.** With several candidates it records all of them and stops. One barcode can sit on several ASINs, and the wrong one is another seller's product: a
 *    listing written against it edits somebody else's offer. `ProductMatcher` holds the same line for the other direction, and for the same reason.
 *  - **It never overwrites a product type somebody chose.** Where the catalogue disagrees with the draft it says so as a problem and leaves the draft's own value alone. It
 *    fills the field only when it is empty, and records that the value came from the catalogue rather than from a person.
 *  - **It never turns "I could not ask" into "there is nothing there".** `CatalogMatch::UNKNOWN` is kept as unknown, with its reason, and no caller may create on it.
 *  - **It does not decide whether to create or update a listing.** That is `CreatesListings::listingFor()` on OUR seller SKU, and nothing here replaces it. A catalogue with
 *    no entry for a barcode says nothing about whether our own SKU is listed; a catalogue entry says nothing about whether the offer on it is ours.
 */
class ListingCatalogLookup
{
    /** The catalogue's word for the kind of thing this is, when it filled in the draft's own empty field. */
    public const FROM_THE_CATALOGUE = 'catalogue';

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * The catalogue's answer for this draft: the stored one while it is still fresh, otherwise a fresh ask.
     *
     * A stored `unknown` is NOT treated as fresh. It is the answer that has to be chased away, so asking again is exactly what should happen; keeping it would freeze a
     * rate-limited minute into a day in which nobody can list anything.
     */
    public function identify(ChannelListingDraft $draft, bool $force = false): CatalogMatch
    {
        $stored = $this->stored($draft);
        if (!$force && $stored !== null) {
            return $stored;
        }

        $account = $draft->account;
        if ($account === null) {
            return CatalogMatch::unknown('account_is_gone');
        }

        try {
            $connector = $this->connectors->for($account);
        } catch (Throwable) {
            $connector = null;
        }
        if (!$connector instanceof ReadsCatalog) {
            // Not a failure and not an empty catalogue: this channel has no catalogue to ask. Recorded so a screen can say so once instead of offering a button that does nothing.
            return $this->record($draft, CatalogMatch::unknown('channel_has_no_catalogue'));
        }

        $identifiers = $this->identifiersOf($draft);
        if ($identifiers === []) {
            return $this->record($draft, CatalogMatch::unknown('product_has_no_barcode_or_code'));
        }

        try {
            $match = $connector->catalogMatch($account, $identifiers);
        } catch (Throwable) {
            // Whatever the channel said may carry the product's own fields back at us; only the fact that it could not be read is kept.
            $match = CatalogMatch::unknown('channel_unreachable', $identifiers);
        }

        return $this->record($draft, $match);
    }

    /**
     * A PERSON chooses one of the candidates. This is the only way `catalog_external_id` is ever written.
     *
     * @return ListingOutcome refused with a code, or validated when the choice was taken
     */
    public function choose(ChannelListingDraft $draft, string $externalId, int $adminId): ListingOutcome
    {
        $match = $this->stored($draft);
        if ($match === null || $match->candidates === []) {
            return ListingOutcome::refused('catalog_not_read_yet');
        }
        $chosen = null;
        foreach ($match->candidates as $candidate) {
            if ($candidate->externalId === $externalId) {
                $chosen = $candidate;
                break;
            }
        }
        if ($chosen === null) {
            return ListingOutcome::refused('catalog_candidate_not_offered');
        }

        $values = ['catalog_external_id' => $chosen->externalId];
        $problems = [];
        if ($chosen->namesItsProductType()) {
            $own = trim((string) $draft->product_type);
            if ($own === '') {
                $values['product_type'] = $chosen->productType;
                // The requirements on the draft were read for the old (empty) product type and say nothing about this one.
                $values['requirements'] = null;
                $values['requirements_read_at'] = null;
            } elseif ($own !== $chosen->productType) {
                // The code carries no channel word: a dynamic suffix would be shown half-translated, which is the one thing ListingLabels exists to prevent. What Amazon
                // calls it is on the draft's own catalog_match for the screen to show beside this.
                $problems[] = 'catalog_product_type_differs';
            }
        }

        $draft->forceFill($values)->save();
        $this->audit->record(
            action: 'sales_channel.listing_catalog_chosen',
            subject: $draft,
            after: ['external_id' => $chosen->externalId, 'product_type' => $values['product_type'] ?? null],
            context: ['by' => $adminId, 'candidates' => count($match->candidates)],
        );

        return $problems === [] ? ListingOutcome::validated() : ListingOutcome::validated($problems);
    }

    /**
     * What a screen should say, as a code: whether a listing may be built on this answer at all.
     *
     * `matched` and `several` both mean the catalogue holds something; only `matched` names one thing. `none` is the definite zero, and `unknown` is the one that must stop a
     * person rather than be read past.
     */
    public function stateOf(ChannelListingDraft $draft): string
    {
        return $this->stored($draft)?->state ?? CatalogMatch::UNKNOWN;
    }

    /** The answer kept on the draft, or null when there is none worth trusting (never read, or read as `unknown`). */
    private function stored(ChannelListingDraft $draft): ?CatalogMatch
    {
        $raw = (array) ($draft->catalog_match ?? []);
        $state = (string) ($raw['state'] ?? '');
        if ($state === '' || $draft->catalog_read_at === null) {
            return null;
        }
        $hours = (int) config('sales_channels.listing.catalog_fresh_hours', 168);
        if ($state === CatalogMatch::UNKNOWN || $draft->catalog_read_at->lt(now()->subHours(max(1, $hours)))) {
            return null;
        }

        $candidates = [];
        foreach ((array) ($raw['candidates'] ?? []) as $candidate) {
            $candidates[] = new CatalogCandidate(
                externalId: (string) ($candidate['external_id'] ?? ''),
                title: $candidate['title'] ?? null,
                brand: $candidate['brand'] ?? null,
                productType: $candidate['product_type'] ?? null,
                identifiers: (array) ($candidate['identifiers'] ?? []),
                imageUrl: $candidate['image_url'] ?? null,
                marketplaceId: $candidate['marketplace_id'] ?? null,
                offeredByAnyone: (bool) ($candidate['offered_by_anyone'] ?? false),
            );
        }

        return new CatalogMatch($state, $candidates, (array) ($raw['asked_with'] ?? []), $draft->catalog_read_at, $raw['reason'] ?? null);
    }

    private function record(ChannelListingDraft $draft, CatalogMatch $match): CatalogMatch
    {
        $values = ['catalog_match' => $match->forTheRecord(), 'catalog_read_at' => now()];

        // The one machine-made fill, and only where there is exactly one candidate AND the draft has chosen nothing: an empty field takes the catalogue's product type, because
        // the alternative is a person guessing the one string the requirements call needs. A field somebody filled is never touched.
        $one = $match->theOne();
        if ($one !== null && $one->namesItsProductType() && trim((string) $draft->product_type) === '') {
            $values['product_type'] = $one->productType;
            $values['requirements'] = null;
            $values['requirements_read_at'] = null;
            $values['catalog_external_id'] = $one->externalId;
        }

        $draft->forceFill($values)->save();

        return $match;
    }

    /**
     * The product's identifiers, as kinds a catalogue can be asked about. The barcode is offered under `barcode` and the connector decides what kind it is by its shape; our
     * own `code` is a seller code, never a GTIN, so it is not offered as one.
     *
     * @return array<string, string>
     */
    private function identifiersOf(ChannelListingDraft $draft): array
    {
        $product = DB::table('products')->where('id', $draft->product_id)->first(['barcode', 'code']);
        if ($product === null) {
            return [];
        }
        $barcode = trim((string) ($product->barcode ?? ''));
        $asin = trim((string) (($draft->payload['asin'] ?? $draft->catalog_external_id) ?? ''));

        return array_filter([
            // An ASIN somebody already recorded is the most trustworthy thing we have: it names one product and no other.
            'ASIN' => $asin,
            'barcode' => $barcode,
        ], static fn (string $value): bool => $value !== '');
    }
}
