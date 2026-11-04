<?php

namespace App\Services\SellerCenter;

use App\Services\Marketplace\OperationsPolicy;

/**
 * The canonical status and severity vocabulary (handoff 06).
 *
 * Every badge in the Seller Center resolves through here. A screen that invents a colour or a
 * status word is a defect, and the way to make that impossible is to give screens no way to choose
 * one: they pass the server's value, this decides the tone and the glyph.
 *
 * Two rules are enforced rather than documented:
 *   · a status is always an icon **and** a word — colour alone never carries meaning, which matters
 *     for colour-blind readers and for anything printed;
 *   · `unknown` is not `healthy`. A domain with no sample renders grey with "No data", never green.
 */
class Status
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const GOOD = 'good';
    public const NEUTRAL = 'neutral';
    public const INFO = 'info';
    public const UNKNOWN = 'unknown';

    /** Severity ordering, everywhere: tables, filters, legends, grouped lists. */
    public const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];

    /**
     * Severity → tone + glyph (handoff 06 §1).
     *
     * @var array<string, array{tone: string, glyph: string}>
     */
    private const SEVERITY = [
        'critical' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'high' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'medium' => ['tone' => self::MEDIUM, 'glyph' => 'info'],
        'low' => ['tone' => self::NEUTRAL, 'glyph' => 'dot-outline'],
    ];

    /**
     * Status word → tone + glyph (handoff 06 §2–3).
     *
     * Keys are the server's own vocabulary, normalised to snake_case. Domain-specific sets (order
     * fulfilment, shipment, payout, brand, automation, compliance, case, approval) all map into
     * this one table rather than carrying their own colours.
     *
     * @var array<string, array{tone: string, glyph: string}>
     */
    private const STATUSES = [
        // positive
        'active' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'approved' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'healthy' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'delivered' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'paid' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'completed' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'matched' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'resolved' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'success' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],
        'met' => ['tone' => self::GOOD, 'glyph' => 'check'],
        'ready' => ['tone' => self::GOOD, 'glyph' => 'check-circle'],

        // neutral
        'draft' => ['tone' => self::NEUTRAL, 'glyph' => 'note-pencil'],
        'inactive' => ['tone' => self::NEUTRAL, 'glyph' => 'pause'],
        'paused' => ['tone' => self::NEUTRAL, 'glyph' => 'pause'],
        'cancelled' => ['tone' => self::NEUTRAL, 'glyph' => 'x'],
        'canceled' => ['tone' => self::NEUTRAL, 'glyph' => 'x'],
        'closed' => ['tone' => self::NEUTRAL, 'glyph' => 'x'],
        'scheduled' => ['tone' => self::NEUTRAL, 'glyph' => 'calendar-dot'],
        'skipped' => ['tone' => self::NEUTRAL, 'glyph' => 'x'],
        'cod' => ['tone' => self::NEUTRAL, 'glyph' => 'currency-circle-dollar'],

        // medium
        'pending' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'under_review' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'processing' => ['tone' => self::MEDIUM, 'glyph' => 'spinner'],
        'submitted' => ['tone' => self::MEDIUM, 'glyph' => 'paper-plane-tilt'],
        'monitoring' => ['tone' => self::MEDIUM, 'glyph' => 'eye'],
        'watch' => ['tone' => self::MEDIUM, 'glyph' => 'eye'],
        'picking' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'packed' => ['tone' => self::MEDIUM, 'glyph' => 'package'],
        'in_transit' => ['tone' => self::MEDIUM, 'glyph' => 'truck'],
        'out_for_delivery' => ['tone' => self::MEDIUM, 'glyph' => 'truck'],
        'ready_to_ship' => ['tone' => self::MEDIUM, 'glyph' => 'package'],
        'accepted' => ['tone' => self::MEDIUM, 'glyph' => 'check'],
        'label_created' => ['tone' => self::MEDIUM, 'glyph' => 'printer'],
        'awaiting_pickup' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'requested' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'invited' => ['tone' => self::MEDIUM, 'glyph' => 'paper-plane-tilt'],
        'open' => ['tone' => self::MEDIUM, 'glyph' => 'eye'],
        'waiting_for_you' => ['tone' => self::MEDIUM, 'glyph' => 'hourglass'],
        'under_investigation' => ['tone' => self::MEDIUM, 'glyph' => 'magnifying-glass'],
        'auto_retried' => ['tone' => self::MEDIUM, 'glyph' => 'arrow-clockwise'],

        // high
        'needs_attention' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'at_risk' => ['tone' => self::HIGH, 'glyph' => 'clock-countdown'],
        'expiring_soon' => ['tone' => self::HIGH, 'glyph' => 'clock-countdown'],
        'low_stock' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'low' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'discrepancy' => ['tone' => self::HIGH, 'glyph' => 'scales'],
        'partially_completed' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'degraded' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'requires_review' => ['tone' => self::HIGH, 'glyph' => 'magnifying-glass'],
        'capped' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'more_information_required' => ['tone' => self::HIGH, 'glyph' => 'warning'],
        'return_open' => ['tone' => self::HIGH, 'glyph' => 'arrow-clockwise'],

        // critical
        'late' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'breached' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'failed' => ['tone' => self::CRITICAL, 'glyph' => 'x-circle'],
        'rejected' => ['tone' => self::CRITICAL, 'glyph' => 'x-circle'],
        'suspended' => ['tone' => self::CRITICAL, 'glyph' => 'seal-warning'],
        'expired' => ['tone' => self::CRITICAL, 'glyph' => 'calendar-x'],
        'out_of_stock' => ['tone' => self::CRITICAL, 'glyph' => 'prohibit'],
        'unmatched' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'mismatch' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'exception' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'returned_to_origin' => ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon'],
        'stopped_by_marketplace' => ['tone' => self::CRITICAL, 'glyph' => 'seal-warning'],

        // unknown — deliberately not green
        'unknown' => ['tone' => self::UNKNOWN, 'glyph' => 'question'],
        'no_data' => ['tone' => self::UNKNOWN, 'glyph' => 'question'],
        'missing' => ['tone' => self::UNKNOWN, 'glyph' => 'question'],
    ];

    /** @return array{tone: string, glyph: string, key: string} */
    public static function of(?string $status): array
    {
        $key = self::normalise($status);
        $found = self::STATUSES[$key] ?? ['tone' => self::NEUTRAL, 'glyph' => 'dot-outline'];

        return $found + ['key' => $key];
    }

    /** @return array{tone: string, glyph: string, key: string} */
    public static function severity(?string $severity): array
    {
        $key = self::normalise($severity);
        $found = self::SEVERITY[$key] ?? ['tone' => self::NEUTRAL, 'glyph' => 'dot-outline'];

        return $found + ['key' => $key];
    }

    public static function tone(?string $status): string
    {
        return self::of($status)['tone'];
    }

    /** Sort a list of severity words into the canonical order. */
    public static function sortSeverities(array $severities): array
    {
        usort($severities, static function ($a, $b) {
            $ai = array_search(self::normalise($a), self::SEVERITY_ORDER, true);
            $bi = array_search(self::normalise($b), self::SEVERITY_ORDER, true);

            return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
        });

        return $severities;
    }

    /** The worst severity in a set, for a rail dot or a group badge. */
    public static function highest(array $severities): ?string
    {
        foreach (self::SEVERITY_ORDER as $candidate) {
            foreach ($severities as $severity) {
                if (self::normalise($severity) === $candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * How an SLA deadline reads (handoff 06 §5).
     *
     * Returns the tone, the glyph and a translation key with its placeholder value, so the caller
     * formats the sentence rather than this class guessing at the locale.
     *
     * @return array{tone: string, glyph: string, state: string, minutes: ?int}
     */
    public static function sla(?\DateTimeInterface $dueAt, bool $met = false, ?\DateTimeInterface $now = null): array
    {
        if ($met) {
            return ['tone' => self::GOOD, 'glyph' => 'check', 'state' => 'met', 'minutes' => null];
        }

        if ($dueAt === null) {
            return ['tone' => self::NEUTRAL, 'glyph' => 'dot-outline', 'state' => 'not_applicable', 'minutes' => null];
        }

        $now ??= new \DateTimeImmutable();
        $minutes = (int) round(($dueAt->getTimestamp() - $now->getTimestamp()) / 60);

        if ($minutes < 0) {
            return ['tone' => self::CRITICAL, 'glyph' => 'warning-octagon', 'state' => 'breached', 'minutes' => abs($minutes)];
        }
        // The bands the marketplace set, not two numbers frozen here. The detector that decides an
        // order is "at SLA risk" reads the same policy, so the colour on the row and the issue in
        // the Control Tower cannot disagree about the same order.
        $bands = app(OperationsPolicy::class)->slaBands();

        if ($minutes <= $bands['closing']) {
            return ['tone' => self::HIGH, 'glyph' => 'clock-countdown', 'state' => 'closing', 'minutes' => $minutes];
        }
        if ($minutes <= $bands['soon']) {
            return ['tone' => self::HIGH, 'glyph' => 'clock', 'state' => 'soon', 'minutes' => $minutes];
        }

        return ['tone' => self::NEUTRAL, 'glyph' => 'clock', 'state' => 'on_time', 'minutes' => $minutes];
    }

    private static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return str_replace([' ', '-'], '_', $value);
    }
}
