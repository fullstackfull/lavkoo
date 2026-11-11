<?php

namespace App\Services\SalesChannels\Amazon;

/**
 * The document a `JSON_LISTINGS_FEED` submission carries, built to Amazon's published schema.
 *
 * Schema: `amzn/selling-partner-api-models`, `schemas/feeds/listings-feed-schema-v2.json`, which the Listings workflow guide
 * links to as the JSON_LISTINGS_FEED schema. Top level is `header` and `messages`; `header.version` is the const `"2.0"`.
 *
 * ## Three rules here come from things Amazon states, and each one is a bug if ignored
 *
 *  1. **`messageId` is the only name the result ever uses.** The processing report keys its issues by `messageId`, and records
 *     no success at all — so the mapping from messageId to SKU is the only way to learn which SKU failed. It is returned with
 *     the document and has to be kept: a document sent without keeping it is a document whose answer cannot be read.
 *  2. **A SKU may appear only once.** Amazon states that submissions "are not guaranteed to be processed in the same order
 *     that they appear in the feed", so two messages about one SKU are a race whose winner Amazon declines to name. Refused
 *     here rather than sent, because the loser's values would be whatever Amazon happened to apply last.
 *  3. **25,000 messages is the schema's `maxItems`**, and Amazon suggests a minimum of 1,500: below that the per-SKU calls
 *     are the better tool, and the caller is told so instead of being given a feed that wastes one of five submissions per
 *     five minutes. (That rate is Amazon's published figure for this feed type — "five feeds submission per five minutes"
 *     — and Amazon states explicitly that it is NOT the generic `createFeed` rate of 0.0083 requests a second.)
 *
 *  4. **`includedData` is how a report says anything about successes.** The schema's `header.report` object takes
 *     `includedData` and `apiVersion`, both required together, and Amazon states that with them the report "includes the
 *     listings items output from the Selling Partner Listings Items API". That resolves what looked like a contradiction
 *     between Amazon's own sources: the tutorial's claim that the report "indicates which records … were successful"
 *     describes the report WITH `includedData`, and the schema's silence on per-message success describes it without.
 *
 * What this class does NOT do is decide that a listing is live. A feed's acceptance is an acceptance of the submission —
 * Amazon's own count is of messages "accepted, the associated updates **are being applied**", in its words — and the same
 * rule holds here as everywhere in this module: only a read of the listing says what Amazon is showing. `includedData` is how
 * that read is asked for alongside the report rather than as a separate call per SKU.
 */
final readonly class AmazonListingsFeed
{
    /** `messages` `maxItems` in Amazon's schema. */
    public const MOST_MESSAGES = 25_000;

    /** Amazon's own suggestion: "The suggested minimum feed size is 1,500 items". Below it, per-SKU calls are the right tool. */
    public const FEWEST_WORTH_SENDING = 1_500;

    /** The `header.version` const in the schema. Not a number we chose. */
    private const SCHEMA_VERSION = '2.0';

    /**
     * The `header.report.includedData` enum, verbatim from the schema.
     *
     * **This is how the report is made to say something about successes.** Asked for, "the processing report that generates
     * for the feed submission includes the listings items output from the Selling Partner Listings Items API with the
     * `includedData` elements from the request" — so the report's `items` array carries each listing's own data, read from
     * Amazon's catalogue rather than inferred from a count. It does not turn acceptance into application; it means the
     * listing read that proves application can arrive WITH the report instead of as one call per SKU afterwards.
     */
    public const INCLUDABLE_DATA = ['summaries', 'attributes', 'issues', 'offers', 'fulfillmentAvailability', 'procurement', 'relationships', 'productTypes'];

    /** The only value in the schema's `header.report.apiVersion` enum. */
    private const LISTINGS_API_VERSION = '2021-08-01';

    /**
     * @param array<string, mixed> $document the body, exactly as it will be uploaded
     * @param array<int, string> $skuByMessage the mapping the result cannot be read without
     */
    private function __construct(
        public array $document,
        public array $skuByMessage,
        public ?string $refusal = null,
    ) {
    }

    public static function refused(string $refusal): self
    {
        return new self([], [], $refusal);
    }

    /**
     * Builds a feed of price and quantity changes.
     *
     * `PATCH` is the operation, not `UPDATE`: `UPDATE` replaces a listing's whole attribute set, so sending one to change a
     * price would silently drop every attribute not named. The schema requires `productType` and `patches` for a `PATCH`.
     *
     * @param array<string, array{product_type: string, patches: list<array<string, mixed>>}> $changes keyed by SKU
     */
    public static function ofPatches(string $sellerId, array $changes, string $issueLocale = 'en_US', array $includedData = []): self
    {
        if (trim($sellerId) === '') {
            return self::refused('amazon_feed_has_no_seller_id');
        }
        if ($changes === []) {
            return self::refused('amazon_feed_has_no_changes');
        }
        if (count($changes) > self::MOST_MESSAGES) {
            // The caller chunks; this refuses rather than truncating, because a truncated feed looks like a whole one in its
            // own report and the dropped SKUs would be recorded as neither sent nor failed.
            return self::refused('amazon_feed_has_more_than_amazon_takes:' . count($changes));
        }

        $messages = [];
        $skuByMessage = [];
        $messageId = 0;
        foreach ($changes as $sku => $change) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                return self::refused('amazon_feed_message_has_no_sku');
            }
            $productType = trim((string) ($change['product_type'] ?? ''));
            $patches = array_values((array) ($change['patches'] ?? []));
            if ($productType === '') {
                return self::refused('amazon_feed_patch_has_no_product_type:' . $sku);
            }
            if ($patches === []) {
                return self::refused('amazon_feed_patch_changes_nothing:' . $sku);
            }
            if (in_array($sku, $skuByMessage, true)) {
                // Unreachable through an array key, kept for a caller that builds the list another way: see rule 2.
                return self::refused('amazon_feed_names_one_sku_twice:' . $sku);
            }

            $messageId++;
            $skuByMessage[$messageId] = $sku;
            $messages[] = [
                'messageId' => $messageId,
                'sku' => $sku,
                'operationType' => 'PATCH',
                'productType' => $productType,
                'patches' => $patches,
            ];
        }

        $header = ['sellerId' => $sellerId, 'version' => self::SCHEMA_VERSION, 'issueLocale' => $issueLocale];
        if ($includedData !== []) {
            $unknown = array_values(array_diff($includedData, self::INCLUDABLE_DATA));
            if ($unknown !== []) {
                return self::refused('amazon_feed_asks_for_data_amazon_does_not_publish:' . implode(',', $unknown));
            }
            // BOTH fields are required by the schema's `report` object. Sending `includedData` without `apiVersion` is a
            // document Amazon's own validator rejects, which would lose the whole feed rather than one message.
            $header['report'] = ['includedData' => array_values($includedData), 'apiVersion' => self::LISTINGS_API_VERSION];
        }

        return new self([
            'header' => $header,
            'messages' => $messages,
        ], $skuByMessage);
    }

    public function acceptable(): bool
    {
        return $this->refusal === null;
    }

    public function messageCount(): int
    {
        return count($this->skuByMessage);
    }

    /** @return list<int> */
    public function messageIds(): array
    {
        return array_map('intval', array_keys($this->skuByMessage));
    }

    /**
     * Whether a feed is the right tool for this many changes, by Amazon's own suggested minimum.
     *
     * It is advice and not a rule, so it is reported rather than enforced: a caller with a reason to send 200 in a feed may,
     * and will be told it is spending one of five submissions per five minutes on it.
     */
    public function worthSending(): bool
    {
        return $this->messageCount() >= self::FEWEST_WORTH_SENDING;
    }

    /** The SKU a reported `messageId` is about, or null when the report names one we never sent. */
    public function skuOf(int $messageId): ?string
    {
        return $this->skuByMessage[$messageId] ?? null;
    }

    /** The body as it is uploaded. Amazon's upload is a PUT of these bytes with the content type the document was created for. */
    public function json(): string
    {
        return (string) json_encode($this->document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
