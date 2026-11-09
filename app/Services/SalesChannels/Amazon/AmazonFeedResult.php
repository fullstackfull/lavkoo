<?php

namespace App\Services\SalesChannels\Amazon;

/**
 * What Amazon says became of a listings feed, read from its processing report by field name.
 *
 * ## This class exists because an earlier decision here was wrong
 *
 * The feed was recorded as "not built by decision" on the grounds that the result document "has no documented field names".
 * That was true of the documentation SITE and false of the documentation: Amazon's own
 * `amzn/selling-partner-api-models` repository publishes `listings-feed-processing-report-schema-v2.json`, and the Listings
 * workflow guide links to it **as** the JSON_LISTINGS_FEED schema. A schema Amazon publishes and links to is documentation,
 * and treating "not on the docs site" as "undocumented" shut a door that was open.
 *
 * ## What the report does and does not say
 *
 * The schema's top level is `header`, `issues`, `summary`, and an optional `items`. And the shape of the answer follows from
 * one asymmetry that every caller has to respect:
 *
 *  - **Every failure is named.** Each entry of `issues` carries `severity` (`ERROR`, `WARNING`, `INFO`), `message`, and
 *    usually `messageId` and `sku` — so the SKU that did not take, and why, is readable.
 *  - **No success is named.** There is no per-message success record anywhere in the schema. `summary` counts
 *    `messagesProcessed`, `messagesAccepted`, `messagesInvalid`, `errors` and `warnings`, and that is all.
 *
 * So an accepted SKU is known only by SUBTRACTION: what was sent, less what the report blamed. That is sound — but only while
 * the arithmetic is checked, which is why `accepted()` refuses to answer unless `messagesProcessed` equals what was sent. A
 * report about a different number of messages than were sent is not a report about this feed, and guessing from it would mark
 * a price change as applied on the strength of a count that never mentioned it.
 *
 * `messageId` is documented as absent when the problem is not about a message ("Not provided if the error is not related to a
 * message (e.g. feed formatting issue)"), and such an issue condemns the whole submission rather than one SKU.
 */
final readonly class AmazonFeedResult
{
    public const ERROR = 'ERROR';

    public const WARNING = 'WARNING';

    public const INFO = 'INFO';

    /**
     * @param array<int, list<array{code: string|null, severity: string, message: string, attribute: string|null}>> $problemsByMessage keyed by the feed's own messageId
     * @param list<array{code: string|null, severity: string, message: string, attribute: string|null}> $problemsAboutTheFeed issues Amazon did not attach to any message
     * @param array<string, mixed> $summary the report's own counts, as it gave them
     */
    private function __construct(
        public array $problemsByMessage,
        public array $problemsAboutTheFeed,
        public array $summary,
        public ?string $refusal = null,
    ) {
    }

    /** A report that could not be read at all. Not an empty report: nothing is known, and nothing may be concluded. */
    public static function unreadable(string $refusal): self
    {
        return new self([], [], [], $refusal);
    }

    /**
     * Reads the report.
     *
     * The JSON is Amazon's; the field names are the schema's. A body that is not the documented shape is refused rather than
     * read loosely, because a loose read of this document ends in a price change recorded as applied.
     */
    public static function parse(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return self::unreadable('amazon_feed_report_is_not_json');
        }
        $summary = $decoded['summary'] ?? null;
        if (!is_array($summary)) {
            // `summary` is required by the schema. Without it there are no counts, and without counts nothing can be concluded
            // about the messages the report did not mention.
            return self::unreadable('amazon_feed_report_has_no_summary');
        }

        $byMessage = [];
        $aboutTheFeed = [];
        foreach ((array) ($decoded['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $problem = [
                'code' => isset($issue['code']) ? (string) $issue['code'] : null,
                // `severity` and `message` are the schema's only required fields of an issue.
                'severity' => strtoupper((string) ($issue['severity'] ?? self::ERROR)),
                'message' => (string) ($issue['message'] ?? ''),
                'attribute' => isset($issue['attributeName']) ? (string) $issue['attributeName'] : null,
            ];
            // An issue with no `messageId` is about the submission, not about a SKU -- Amazon documents exactly that case.
            if (isset($issue['messageId']) && is_numeric($issue['messageId'])) {
                $byMessage[(int) $issue['messageId']][] = $problem;

                continue;
            }
            $aboutTheFeed[] = $problem;
        }

        return new self($byMessage, $aboutTheFeed, [
            'errors' => (int) ($summary['errors'] ?? 0),
            'warnings' => (int) ($summary['warnings'] ?? 0),
            'messages_processed' => (int) ($summary['messagesProcessed'] ?? 0),
            'messages_accepted' => (int) ($summary['messagesAccepted'] ?? 0),
            'messages_invalid' => (int) ($summary['messagesInvalid'] ?? 0),
        ]);
    }

    public function readable(): bool
    {
        return $this->refusal === null;
    }

    /** True when Amazon blamed the submission itself rather than any of its messages: nothing in it took effect. */
    public function wholeFeedWasRefused(): bool
    {
        foreach ($this->problemsAboutTheFeed as $problem) {
            if ($problem['severity'] === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * The messages Amazon named as failures, and why. A WARNING is not a failure: the schema separates the two and so does
     * this, because a listing accepted with a warning IS applied and recording it as rejected would have the next run send it
     * again for ever.
     *
     * @return array<int, list<array{code: string|null, severity: string, message: string, attribute: string|null}>>
     */
    public function failures(): array
    {
        $failures = [];
        foreach ($this->problemsByMessage as $messageId => $problems) {
            $errors = array_values(array_filter($problems, static fn (array $problem): bool => $problem['severity'] === self::ERROR));
            if ($errors !== []) {
                $failures[$messageId] = $errors;
            }
        }

        return $failures;
    }

    /**
     * @return array<int, list<array{code: string|null, severity: string, message: string, attribute: string|null}>>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->problemsByMessage as $messageId => $problems) {
            $kept = array_values(array_filter($problems, static fn (array $problem): bool => $problem['severity'] === self::WARNING));
            if ($kept !== []) {
                $warnings[$messageId] = $kept;
            }
        }

        return $warnings;
    }

    /**
     * The messages that took effect — **by subtraction, and only when the arithmetic agrees.**
     *
     * Amazon records no per-message success, so the accepted set is what was sent less what was blamed. That inference is only
     * as good as the report being about THIS submission, so it is refused unless `messagesProcessed` equals the number sent.
     * A mismatch is not a rounding difference to shrug at: it means the report describes a different set of messages, and
     * subtracting from it would mark SKUs as published that it never mentioned.
     *
     * @param list<int> $sentMessageIds every messageId the submission carried
     * @return array{accepted: list<int>, refusal: string|null}
     */
    public function accepted(array $sentMessageIds): array
    {
        if (!$this->readable()) {
            return ['accepted' => [], 'refusal' => $this->refusal];
        }
        if ($this->wholeFeedWasRefused()) {
            return ['accepted' => [], 'refusal' => 'amazon_refused_the_whole_feed'];
        }
        $processed = (int) ($this->summary['messages_processed'] ?? 0);
        if ($processed !== count($sentMessageIds)) {
            return ['accepted' => [], 'refusal' => 'amazon_feed_report_counts_do_not_match_what_was_sent:' . $processed . '_of_' . count($sentMessageIds)];
        }

        $failed = array_keys($this->failures());
        $accepted = array_values(array_diff($sentMessageIds, $failed));

        // The second check on the same arithmetic, from the other side. Amazon states `messagesInvalid` itself, so if the
        // issues name fewer failures than Amazon counted, some failure was not reported per message and the remainder cannot
        // be called accepted.
        $invalid = (int) ($this->summary['messages_invalid'] ?? 0);
        if ($invalid > count($failed)) {
            return ['accepted' => [], 'refusal' => 'amazon_counted_more_invalid_messages_than_it_named:' . $invalid . '_vs_' . count($failed)];
        }

        return ['accepted' => $accepted, 'refusal' => null];
    }
}
