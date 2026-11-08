<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
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
 * Checking means asking the channel what it requires and comparing: the fields it insists on and, where it says so, the values it accepts. A channel that cannot be asked
 * (its read switch is off, the call failed) leaves the requirements UNKNOWN, and an unknown requirement is never treated as "nothing is required": the draft stays a draft.
 */
class ListingDraftService
{
    public function __construct(private readonly ChannelConnectorRegistry $connectors)
    {
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

        $requirements = $this->requirementsFor($draft);
        if ($requirements->unknown) {
            return $this->found($draft, [], 'listing_requirements_unknown');
        }

        $payload = (array) ($draft->payload ?? []);
        $problems = [...$requirements->missingFrom($payload), ...$requirements->refusedValuesIn($payload)];
        if ($problems !== []) {
            return $this->found($draft, $problems, null);
        }

        $draft->forceFill(['state' => ChannelListingDraft::VALIDATED, 'problems' => null, 'refusal_code' => null])->save();

        return ListingOutcome::validated();
    }

    /** What the channel requires, from the draft when that answer is still fresh, else asked again and stored. */
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
