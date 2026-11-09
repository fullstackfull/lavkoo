<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\Amazon\AmazonFeedResult;
use App\Services\SalesChannels\Amazon\AmazonListingsFeed;
use Tests\TestCase;

/**
 * The two halves of a `JSON_LISTINGS_FEED`: the document that goes out, and the report that comes back.
 *
 * ## Why these exist at all, when the feed was recorded as "not built by decision"
 *
 * That decision rested on a claim that is false: that the result document "has no documented field names". Amazon's own
 * `amzn/selling-partner-api-models` repository publishes `listings-feed-processing-report-schema-v2.json`, and the Listings
 * workflow guide links to it **as** the JSON_LISTINGS_FEED schema. Treating "not on the documentation site" as "undocumented"
 * shut a door that was open, and these tests are written against the real field names.
 *
 * ## The asymmetry everything here turns on
 *
 * The report names every FAILURE — `issues` entries with `messageId`, `sku`, `severity`, `message` — and names no SUCCESS.
 * `summary` carries only counts. So an accepted SKU is known by SUBTRACTION, and subtraction is only safe while the
 * arithmetic agrees: these tests spend most of their effort on the cases where it does not.
 *
 * (Amazon's own sources disagree about this: the submit-a-feed tutorial says the report "indicates which records in the feed
 * that you submitted were successful", while the schema defines no per-message success entry. The design here does not depend
 * on which is right — it never reads a success, it derives one and refuses when the counts do not add up.)
 */
class AmazonListingsFeedTest extends TestCase
{
    // ---- the document that goes out ------------------------------------------------------------------------------

    public function test_the_document_is_built_to_amazons_published_schema(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', [
            'SKU-1' => ['product_type' => 'HEALTH_PERSONAL_CARE', 'patches' => [['op' => 'replace', 'path' => '/attributes/purchasable_offer', 'value' => [['currency' => 'SAR']]]]],
        ]);

        $this->assertTrue($feed->acceptable());
        $this->assertSame(['sellerId' => 'A1SELLER', 'version' => '2.0', 'issueLocale' => 'en_US'], $feed->document['header']);
        $message = $feed->document['messages'][0];
        $this->assertSame(['messageId', 'sku', 'operationType', 'productType', 'patches'], array_keys($message));
        // PATCH, not UPDATE: `UPDATE` replaces a listing's whole attribute set, so using it to change a price would drop every
        // attribute the message did not name.
        $this->assertSame('PATCH', $message['operationType']);
    }

    public function test_the_message_id_to_sku_mapping_comes_back_with_the_document(): void
    {
        // The report keys its issues by `messageId` and records no SKU for anything that succeeded, so this mapping is the only
        // way to learn which SKU failed. A document sent without keeping it is a document whose answer cannot be read.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', [
            'SKU-1' => $this->priceAndStockPatch(),
            'SKU-2' => $this->priceAndStockPatch(),
        ]);

        $this->assertSame([1 => 'SKU-1', 2 => 'SKU-2'], $feed->skuByMessage);
        $this->assertSame('SKU-2', $feed->skuOf(2));
        $this->assertNull($feed->skuOf(99), 'a messageId we never sent names no SKU');
    }

    public function test_a_feed_bigger_than_amazon_takes_is_refused_and_never_truncated(): void
    {
        $changes = [];
        for ($index = 0; $index <= AmazonListingsFeed::MOST_MESSAGES; $index++) {
            $changes['SKU-' . $index] = $this->priceAndStockPatch();
        }

        $feed = AmazonListingsFeed::ofPatches('A1SELLER', $changes);

        // Truncating would be worse than refusing: the report of a truncated feed looks exactly like the report of a whole
        // one, so the dropped SKUs would be recorded as neither sent nor failed.
        $this->assertFalse($feed->acceptable());
        $this->assertStringStartsWith('amazon_feed_has_more_than_amazon_takes', (string) $feed->refusal);
    }

    public function test_amazons_own_suggested_minimum_is_reported_rather_than_enforced(): void
    {
        $small = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch()]);

        $this->assertTrue($small->acceptable(), 'a small feed is allowed');
        // "The suggested minimum feed size is 1,500 items" is advice, so the caller is told it is spending one of five
        // submissions per five minutes rather than being refused.
        $this->assertFalse($small->worthSending());
        $this->assertSame(1_500, AmazonListingsFeed::FEWEST_WORTH_SENDING);
    }

    public function test_a_patch_that_names_no_product_type_or_changes_nothing_is_refused(): void
    {
        $this->assertSame(
            'amazon_feed_patch_has_no_product_type:SKU-1',
            AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => ['product_type' => '', 'patches' => [['op' => 'replace']]]])->refusal,
        );
        $this->assertSame(
            'amazon_feed_patch_changes_nothing:SKU-1',
            AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => ['product_type' => 'HEALTH_PERSONAL_CARE', 'patches' => []]])->refusal,
        );
        $this->assertSame('amazon_feed_has_no_changes', AmazonListingsFeed::ofPatches('A1SELLER', [])->refusal);
        $this->assertSame('amazon_feed_has_no_seller_id', AmazonListingsFeed::ofPatches('  ', ['SKU-1' => $this->priceAndStockPatch()])->refusal);
    }

    // ---- the report that comes back ------------------------------------------------------------------------------

    public function test_a_failure_is_read_per_message_and_per_sku(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch(), 'SKU-2' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['messageId' => 2, 'sku' => 'SKU-2', 'code' => '4000001', 'severity' => 'ERROR', 'message' => 'The price is below the minimum.', 'attributeName' => 'purchasable_offer']],
            summary: ['errors' => 1, 'warnings' => 0, 'messagesProcessed' => 2, 'messagesAccepted' => 1, 'messagesInvalid' => 1],
        ));

        $this->assertTrue($result->readable());
        $failures = $result->failures();
        $this->assertSame([2], array_keys($failures));
        $this->assertSame('SKU-2', $feed->skuOf(2), 'and the mapping turns the messageId back into a SKU');
        $this->assertSame('4000001', $failures[2][0]['code']);
        $this->assertSame('purchasable_offer', $failures[2][0]['attribute']);
    }

    public function test_the_accepted_set_is_derived_by_subtraction(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch(), 'SKU-2' => $this->priceAndStockPatch(), 'SKU-3' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['messageId' => 2, 'sku' => 'SKU-2', 'severity' => 'ERROR', 'message' => 'no']],
            summary: ['errors' => 1, 'warnings' => 0, 'messagesProcessed' => 3, 'messagesAccepted' => 2, 'messagesInvalid' => 1],
        ));

        $accepted = $result->accepted($feed->messageIds());

        $this->assertNull($accepted['refusal']);
        $this->assertSame([1, 3], $accepted['accepted']);
    }

    public function test_a_warning_is_not_a_failure(): void
    {
        // A listing accepted with a warning IS applied. Recording it as rejected would have the next run send it again for
        // ever, and the schema separates `WARNING` from `ERROR` precisely so this can be told apart.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['messageId' => 1, 'sku' => 'SKU-1', 'severity' => 'WARNING', 'message' => 'An image is low resolution.']],
            summary: ['errors' => 0, 'warnings' => 1, 'messagesProcessed' => 1, 'messagesAccepted' => 1, 'messagesInvalid' => 0],
        ));

        $this->assertSame([], $result->failures());
        $this->assertSame([1], array_keys($result->warnings()));
        $this->assertSame(['accepted' => [1], 'refusal' => null], $result->accepted($feed->messageIds()));
    }

    public function test_a_report_about_a_different_number_of_messages_accepts_nothing(): void
    {
        // **THE GUARD THAT MAKES SUBTRACTION SAFE.** A report whose `messagesProcessed` does not match what was sent is a
        // report about a different set of messages; subtracting from it would mark SKUs as published that it never mentioned.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch(), 'SKU-2' => $this->priceAndStockPatch(), 'SKU-3' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(issues: [], summary: ['messagesProcessed' => 2, 'messagesAccepted' => 2, 'messagesInvalid' => 0]));

        $accepted = $result->accepted($feed->messageIds());

        $this->assertSame([], $accepted['accepted']);
        $this->assertSame('amazon_feed_report_counts_do_not_match_what_was_sent:2_of_3', $accepted['refusal']);
    }

    public function test_more_invalid_messages_counted_than_named_accepts_nothing(): void
    {
        // The same arithmetic from the other side. Amazon states `messagesInvalid` itself, so if its issues name fewer
        // failures than it counted, a failure went unreported and the remainder cannot be called accepted.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch(), 'SKU-2' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['messageId' => 1, 'sku' => 'SKU-1', 'severity' => 'ERROR', 'message' => 'no']],
            summary: ['errors' => 2, 'warnings' => 0, 'messagesProcessed' => 2, 'messagesAccepted' => 0, 'messagesInvalid' => 2],
        ));

        $this->assertSame('amazon_counted_more_invalid_messages_than_it_named:2_vs_1', $result->accepted($feed->messageIds())['refusal']);
    }

    public function test_an_issue_with_no_message_id_condemns_the_whole_submission(): void
    {
        // Amazon documents this case in the schema itself: `messageId` is "Not provided if the error is not related to a
        // message (e.g. feed formatting issue)". Such an error is about the feed, so nothing in it took effect.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['severity' => 'ERROR', 'message' => 'The feed document could not be parsed.']],
            summary: ['errors' => 1, 'warnings' => 0, 'messagesProcessed' => 1, 'messagesAccepted' => 0, 'messagesInvalid' => 0],
        ));

        $this->assertTrue($result->wholeFeedWasRefused());
        $this->assertSame([], $result->failures(), 'and it is not blamed on any one message');
        $this->assertSame('amazon_refused_the_whole_feed', $result->accepted($feed->messageIds())['refusal']);
    }

    public function test_an_unreadable_report_is_not_an_empty_one(): void
    {
        foreach ([
            ['not json at all', 'amazon_feed_report_is_not_json'],
            ['{"issues":[]}', 'amazon_feed_report_has_no_summary'],
        ] as [$body, $expected]) {
            $result = AmazonFeedResult::parse($body);

            $this->assertFalse($result->readable());
            $this->assertSame($expected, $result->refusal);
            // Nothing is known, so nothing may be concluded -- least of all that every message was accepted.
            $this->assertSame([], $result->accepted([1, 2])['accepted']);
            $this->assertSame($expected, $result->accepted([1, 2])['refusal']);
        }
    }

    public function test_an_info_issue_is_neither_a_failure_nor_a_warning(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', ['SKU-1' => $this->priceAndStockPatch()]);
        $result = AmazonFeedResult::parse($this->report(
            issues: [['messageId' => 1, 'sku' => 'SKU-1', 'severity' => 'INFO', 'message' => 'Nothing changed.']],
            summary: ['errors' => 0, 'warnings' => 0, 'messagesProcessed' => 1, 'messagesAccepted' => 1, 'messagesInvalid' => 0],
        ));

        $this->assertSame([], $result->failures());
        $this->assertSame([], $result->warnings());
        $this->assertSame([1], $result->accepted($feed->messageIds())['accepted']);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** @return array{product_type: string, patches: list<array<string, mixed>>} */
    private function priceAndStockPatch(): array
    {
        return ['product_type' => 'HEALTH_PERSONAL_CARE', 'patches' => [['op' => 'replace', 'path' => '/attributes/fulfillment_availability', 'value' => [['quantity' => 4]]]]];
    }

    /**
     * A processing report in the shape of `listings-feed-processing-report-schema-v2.json`.
     *
     * @param list<array<string, mixed>> $issues
     * @param array<string, int> $summary
     */
    private function report(array $issues, array $summary): string
    {
        return (string) json_encode([
            'header' => ['sellerId' => 'A1SELLER', 'version' => '2.0', 'feedId' => '50064018535'],
            'issues' => $issues,
            'summary' => $summary,
        ]);
    }
}
