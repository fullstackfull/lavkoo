<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\AuditLogger;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
use App\Services\SalesChannels\Contracts\ResolvesChannelBrands;
use App\Services\SalesChannels\Data\ListingRequirements;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place a channel listing is drafted and checked, for every channel.
 *
 * A draft is one intention per (account, product, variant) — the table's unique key — so asking twice edits the first instead of making a second. It holds the CHANNEL's fields;
 * the store's product is only read (its code becomes the seller SKU when nobody chose one), never written.
 *
 * Checking is two questions. First the STORE's own product (`ProductListingReadiness`): a name, a description, a barcode or code, a category, a thumbnail and enough images,
 * and for a product sold per variant a variant that exists and has a price. Then the CHANNEL's own fields: what it insists on and, where it says so, the values it accepts. A
 * channel that cannot be asked (its read switch is off, the call failed) leaves the requirements UNKNOWN, and an unknown requirement is never treated as "nothing is required":
 * the draft stays a draft.
 */
class ListingDraftService
{
    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly RegulatedProductGate $regulated,
        private readonly ProductListingReadiness $readiness,
        private readonly ChannelProductImages $images,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Creates or edits the draft for one product on one account. Editing the fields sends it back to DRAFT: what the channel accepted before says nothing about the new values.
     *
     * @param array<string, mixed> $payload the channel's own field names and values; merged into what the draft already holds
     */
    public function draftFor(SalesChannelAccount $account, int $productId, string $variantKey = '', ?string $productType = null, array $payload = []): ChannelListingDraft
    {
        $draft = $this->existing($account, $productId, $variantKey);
        $payload = $this->withTheSellerSku($payload, $draft, $productId);

        if ($draft === null) {
            return $this->create($account, $productId, $variantKey, $productType, $payload);
        }

        $merged = ($draft->payload ?? []) + [];
        foreach ($payload as $field => $value) {
            $merged[$field] = $value;
        }
        $changed = $merged !== ($draft->payload ?? []) || ($productType !== null && $productType !== $draft->product_type);

        $draft->forceFill([
            'payload' => $merged,
            'product_type' => $productType ?? $draft->product_type,
            // An accepted listing stays linked: editing it is an update of that listing, never a second one.
            'state' => $changed ? ChannelListingDraft::DRAFT : $draft->state,
            'problems' => $changed ? null : $draft->problems,
            'refusal_code' => null,
        ])->save();

        return $draft;
    }

    /**
     * Records the clearance a regulated product needs: the reference of the licence, registration or decision that allows it on this channel, and the person who recorded it.
     *
     * The person must hold the `sales_channels` module — it is checked against their role, not taken from a request — and the reference must say something. Nothing else counts
     * as a clearance: not an admin id on a command line, and not a request that merely has one.
     */
    public function recordClearance(ChannelListingDraft $draft, string $reference, int $adminId): ListingOutcome
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ListingOutcome::refused('regulatory_clearance_needs_a_reference');
        }
        if (!$this->regulated->mayRecordClearance($adminId)) {
            return ListingOutcome::refused(RegulatedProductGate::CLEARANCE_NOT_PERMITTED);
        }

        $draft->forceFill(['regulatory_clearance' => ['reference' => mb_substr($reference, 0, 190), 'by' => $adminId, 'at' => now()->toIso8601String()], 'refusal_code' => null])->save();
        $this->audit->record(
            action: 'sales_channel.listing_regulatory_clearance_recorded',
            subject: $draft,
            context: ['draft_id' => $draft->id, 'product_id' => $draft->product_id, 'sales_channel' => $draft->sales_channel, 'reference' => mb_substr($reference, 0, 190), 'by' => $adminId],
        );

        return ListingOutcome::validated();
    }

    /** Checks the draft against what the channel says it requires, writes what it found, and says whether it may be sent. */
    public function validate(ChannelListingDraft $draft): ListingOutcome
    {
        if (in_array($draft->state, [ChannelListingDraft::SUBMITTED, ChannelListingDraft::ACCEPTED], true) && ($draft->problems ?? []) === []) {
            // Already with the channel, and nothing changed since: there is nothing to check again.
            return $draft->state === ChannelListingDraft::ACCEPTED ? ListingOutcome::accepted($draft->product_mapping_id) : ListingOutcome::working();
        }
        if ((string) $draft->product_type === '') {
            return $this->found($draft, ['product_type'], 'listing_product_type_missing');
        }

        // The store's own product first: a channel will take a listing with no image and no barcode and refuse it days later.
        if (($ownData = $this->readiness->problemsFor((int) $draft->product_id, (string) $draft->variant_key)) !== []) {
            return $this->found($draft, $ownData, 'product_data_not_ready');
        }

        $requirements = $this->requirementsFor($draft);
        if ($requirements->unknown) {
            return $this->found($draft, [], 'listing_requirements_unknown');
        }

        $payload = (array) ($draft->payload ?? []);
        // A channel that keeps its own brand list needs its own id for the brand, which is not something a person can know. Where the draft carries a NAME and the channel
        // wants an id, the name is turned into one here, so the product is not sent and refused days later for a brand that was spelled perfectly well.
        $payload = $this->withTheChannelsBrandId($draft, $requirements, $payload);
        // ... and a channel that insists on photographs needs URLs it can fetch. The shop's own images are turned into those
        // here for the same reason: a person cannot type them, and a product sent without them is refused by the channel days
        // later -- or worse, taken with our placeholder PNG as somebody's product photograph.
        [$payload, $imageProblem] = $this->withTheProductsImages($draft, $requirements, $payload);
        // The image refusal JOINS the other problems rather than replacing them. Returning early on it would hide a missing
        // brand id behind an image one, and a person would fix the images, press the button again, and be told about the brand
        // -- two visits for one screen's worth of truth.
        $problems = [
            ...($imageProblem === null ? [] : [$imageProblem]),
            ...$requirements->missingFrom($payload),
            ...$requirements->refusedValuesIn($payload),
        ];
        if ($problems !== []) {
            return $this->found($draft, $problems, null);
        }

        $draft->forceFill(['state' => ChannelListingDraft::VALIDATED, 'problems' => null, 'refusal_code' => null])->save();

        return ListingOutcome::validated();
    }

    /** What the channel requires, from the draft when that answer is still fresh, else asked again and stored. */
    /**
     * Turns the brand NAME on a draft into the CHANNEL's own brand id, where the channel keeps a brand list and insists on the id.
     *
     * It resolves and never chooses. Exactly one match is written onto the draft; several are reported as a problem with the names, for a person to pick between, because
     * several brands can carry one name and putting our product under another company's brand is not a thing to guess at. A channel that could not be asked leaves the draft
     * exactly as it was: the requirement then reports the id as missing, which is true, rather than the draft being quietly passed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the payload as the requirements should judge it
     */
    private function withTheChannelsBrandId(ChannelListingDraft $draft, ListingRequirements $requirements, array $payload): array
    {
        if (!in_array('brandId', [...$requirements->required, ...$requirements->optional], true)) {
            return $payload;
        }
        if (trim((string) ($payload['brandId'] ?? '')) !== '') {
            return $payload;
        }
        $name = trim((string) ($payload['brand'] ?? $payload['brandName'] ?? ''));
        $account = $draft->account;
        if ($name === '' || $account === null) {
            return $payload;
        }

        try {
            $connector = $this->connectors->for($account);
        } catch (Throwable) {
            return $payload;
        }
        if (!$connector instanceof ResolvesChannelBrands) {
            return $payload;
        }

        try {
            $brands = $connector->brandsNamed($account, $name);
        } catch (Throwable) {
            // Could not be asked. The id stays missing, which is the truth, and the draft stays a draft.
            return $payload;
        }

        if (count($brands) !== 1) {
            // None: the brand has to be registered with the channel first, which is an errand and not a field. Several: a person picks. Either way the names are shown as
            // they came -- they are the channel's words.
            $draft->forceFill(['problems' => array_values(array_unique([...(array) ($draft->problems ?? []), ...array_map(static fn (array $brand): string => (string) $brand['name'], $brands) ?: ['brandId']]))])->save();

            return $payload;
        }

        $payload['brandId'] = (string) $brands[0]['id'];
        $draft->forceFill(['payload' => $payload])->save();

        return $payload;
    }

    /**
     * Fills the channel's image field from the shop's own product photographs.
     *
     * Three things it will not do, and each is a way a listing goes wrong quietly:
     *
     *  - it does not invent a URL. `ChannelProductImages` refuses rather than falling back, so a product whose files are gone,
     *    or whose store is served over http, or whose host no marketplace can reach, is REPORTED -- it does not become a
     *    listing with our grey placeholder in place of a photograph.
     *  - it does not overwrite what a person put there. A draft that already carries `images` was filled deliberately, perhaps
     *    with URLs on a CDN, and replacing them with ours would undo somebody's work without saying so.
     *  - it does not add images to a channel that did not ask for them. The field is filled only when the requirements name
     *    it, so a channel with no image field is never sent one.
     *
     * The shape is the channel's: Trendyol documents `images` as a list of objects each carrying a `url`, and a maximum of
     * eight per barcode, so eight is what is asked for.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: string|null} the payload, and the refusal when there is one
     */
    private function withTheProductsImages(ChannelListingDraft $draft, ListingRequirements $requirements, array $payload): array
    {
        $field = 'images';
        if (!in_array($field, [...$requirements->required, ...$requirements->optional, ...$requirements->alwaysRequired], true)) {
            return [$payload, null];
        }
        if (($payload[$field] ?? []) !== []) {
            return [$payload, null];
        }

        $limit = max(1, (int) config('sales_channels.listing.images_at_most', 8));
        $resolved = $this->images->forProduct((int) $draft->product_id, $limit);
        if ($resolved['refusal'] !== null) {
            // Only a refusal for a field the channel REQUIRES stops the draft. Where images are merely optional, the listing
            // is better off without them than not at all, and the reason is still worth recording on the draft.
            return in_array($field, [...$requirements->required, ...$requirements->alwaysRequired], true)
                ? [$payload, $resolved['refusal']]
                : [$payload, null];
        }

        $payload[$field] = array_map(static fn (string $url): array => ['url' => $url], $resolved['urls']);
        $draft->forceFill(['payload' => $payload])->save();

        return [$payload, null];
    }

    public function requirementsFor(ChannelListingDraft $draft): ListingRequirements
    {
        $hours = (int) config('sales_channels.listing.requirements_fresh_hours', 24);
        $stored = (array) ($draft->requirements ?? []);
        $fresh = $draft->requirements_read_at !== null && $draft->requirements_read_at->gt(now()->subHours(max(1, $hours)));
        if ($fresh && ($stored['unknown'] ?? true) === false && ($stored['product_type'] ?? null) === $draft->product_type) {
            return new ListingRequirements(
                productType: (string) $stored['product_type'],
                required: array_values((array) ($stored['required'] ?? [])),
                optional: array_values((array) ($stored['optional'] ?? [])),
                allowedValues: (array) ($stored['allowed_values'] ?? []),
                readAt: $draft->requirements_read_at,
            );
        }

        $account = $draft->account;
        if ($account === null) {
            return ListingRequirements::unknown((string) $draft->product_type);
        }
        try {
            $connector = $this->connectors->for($account);
        } catch (Throwable) {
            // A channel with no connector yet says nothing about its requirements, and nothing is assumed for it.
            $connector = null;
        }
        $requirements = ListingRequirements::unknown((string) $draft->product_type);

        if ($connector instanceof DescribesListingRequirements) {
            try {
                $requirements = $connector->listingRequirements($account, (string) $draft->product_type);
            } catch (Throwable) {
                // What the channel said may repeat the product's own fields; only the fact that it could not be read is kept.
                $requirements = ListingRequirements::unknown((string) $draft->product_type);
            }
        }
        if ($requirements->unknown) {
            $requirements = $this->declaredBy($account, (string) $draft->product_type) ?? $requirements;
        }

        $draft->forceFill(['requirements' => $requirements->toArray(), 'requirements_read_at' => $requirements->unknown ? null : now()])->save();

        return $requirements;
    }

    /**
     * The owner's own list of required fields for a product type, kept on the account (`listing_requirements`), for a channel that will not say what it needs. It is used ONLY
     * when the channel could not be asked, it is recorded as the owner's and not the channel's, and without it a draft simply stays a draft.
     */
    private function declaredBy(SalesChannelAccount $account, string $productType): ?ListingRequirements
    {
        $declared = (array) $account->setting('listing_requirements', []);
        $entry = $declared[$productType] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $required = array_values(array_filter(array_map('strval', (array) ($entry['required'] ?? []))));
        if ($required === []) {
            return null;
        }

        return ListingRequirements::declared(
            productType: $productType,
            required: $required,
            optional: array_values(array_filter(array_map('strval', (array) ($entry['optional'] ?? [])))),
            allowedValues: array_map(static fn ($values): array => array_values(array_map('strval', (array) $values)), (array) ($entry['allowed_values'] ?? [])),
        );
    }

    private function existing(SalesChannelAccount $account, int $productId, string $variantKey): ?ChannelListingDraft
    {
        return ChannelListingDraft::where('account_id', $account->id)->where('product_id', $productId)->where('variant_key', $variantKey)->first();
    }

    /** @param array<string, mixed> $payload */
    private function create(SalesChannelAccount $account, int $productId, string $variantKey, ?string $productType, array $payload): ChannelListingDraft
    {
        $values = [
            'account_id' => $account->id,
            'sales_channel' => $account->channel,
            'product_id' => $productId,
            'variant_key' => $variantKey,
            'product_type' => $productType,
            'payload' => $payload,
            'state' => ChannelListingDraft::DRAFT,
            // A product that is already listed on this account is an update from the start, not a new listing.
            'product_mapping_id' => SalesChannelProductMapping::where('account_id', $account->id)->where('product_id', $productId)
                ->where('variant_key', $variantKey)->value('id'),
        ];

        try {
            return ChannelListingDraft::create($values);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($account, $productId, $variantKey) ?? throw new UniqueConstraintViolationException('', '', [], new \RuntimeException('draft'));
        }
    }

    /**
     * The seller's own identifier for the listing. It is what makes a second call set the same listing instead of making another, so it is decided once: what the draft already
     * has, else what the caller gave, else the product's own code.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withTheSellerSku(array $payload, ?ChannelListingDraft $draft, int $productId): array
    {
        $existing = (string) (($draft->payload ?? [])['sku'] ?? '');
        if ($existing !== '') {
            $payload['sku'] = $existing;

            return $payload;
        }
        if ((string) ($payload['sku'] ?? '') !== '') {
            return $payload;
        }
        $code = DB::table('products')->where('id', $productId)->value('code');
        if ((string) $code !== '') {
            $payload['sku'] = (string) $code;
        }

        return $payload;
    }

    /**
     * @param list<string> $problems
     * @return ListingOutcome
     */
    private function found(ChannelListingDraft $draft, array $problems, ?string $reason): ListingOutcome
    {
        $draft->forceFill(['state' => ChannelListingDraft::DRAFT, 'problems' => $problems, 'refusal_code' => $reason])->save();

        return ListingOutcome::refused($reason ?? 'listing_fields_missing', $problems);
    }
}
