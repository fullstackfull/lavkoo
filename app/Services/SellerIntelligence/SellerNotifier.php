<?php

namespace App\Services\SellerIntelligence;

use App\Models\Seller;
use App\Models\SellerInsight;
use App\Models\SellerNotificationDelivery;
use App\Services\AuditLogger;
use App\Traits\PushNotificationTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The seam between what the platform knows and what the seller finds out.
 *
 * Everything needed for this existed and was never joined up. The notification stack is complete —
 * FCM HTTP v1, per-device and multi-device sends, mail, in-app records. The detection side is
 * complete — insights, SLA breaches, scorecards. Nothing connected them, so an SLA breach opened a
 * row in a ledger and told nobody, and a seller learned their listing had been rejected by opening
 * the app and looking.
 *
 * Two decisions do most of the work here, and both are about restraint rather than delivery:
 *
 * **One message per fact, not per row.** Fifty orders approaching their deadline is one message that
 * says fifty, with a filtered view behind it. Fifty separate pushes is how an alerting system trains
 * people to swipe it away without reading.
 *
 * **A fact is announced once.** The digest key is (seller, topic, window) and is unique in the
 * database, so a sweep that runs again inside the same window announces nothing. Detection runs on a
 * schedule; without this, every run would repeat itself.
 *
 * Sending never throws into the caller. A detector's job is to be right about the problem; a failed
 * push is not a reason for the problem to go unrecorded.
 */
class SellerNotifier
{
    use PushNotificationTrait;

    /**
     * How long one topic stays announced for a seller.
     *
     * Long enough that a sweep does not repeat itself, short enough that a problem still standing
     * tomorrow is worth saying again.
     */
    private const WINDOW_HOURS = 12;

    /** Below this, nothing is pushed. A low-severity insight belongs in the list, not on a phone. */
    private const PUSH_FROM_SEVERITY = ['critical', 'high'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Announce one seller's open insights, aggregated by type.
     *
     * @return array<int, SellerNotificationDelivery>
     */
    public function announceInsights(Seller $seller, Collection $insights): array
    {
        $sent = [];

        foreach ($insights->groupBy('type') as $type => $group) {
            $delivery = $this->deliver(
                seller: $seller,
                topic: (string) $type,
                severity: $this->worstSeverity($group),
                subjectCount: $group->count(),
                actionKey: 'open_action_center',
                actionParams: ['type' => $type],
            );

            if ($delivery) {
                $sent[] = $delivery;
            }
        }

        return $sent;
    }

    /**
     * Record and send one aggregated notification, unless this fact has already been announced.
     *
     * Returns null when it was suppressed as a duplicate — which is a normal outcome, not a failure.
     */
    public function deliver(
        Seller $seller,
        string $topic,
        string $severity,
        int $subjectCount,
        string $title = '',
        ?string $body = null,
        ?string $actionKey = null,
        ?array $actionParams = null,
    ): ?SellerNotificationDelivery {
        if (!Schema::hasTable('seller_notification_deliveries')) {
            return null;
        }

        $digestKey = $this->digestKey($seller->id, $topic);

        // Already announced inside this window. The unique key is the real guard — two sweeps
        // racing cannot both win — and this read just avoids the exception in the common case.
        if (SellerNotificationDelivery::where('digest_key', $digestKey)->exists()) {
            return null;
        }

        try {
            $delivery = SellerNotificationDelivery::create([
                'seller_id' => $seller->id,
                'topic' => $topic,
                'severity' => $severity,
                'title' => $title ?: translate('notify_' . strtolower($topic)),
                'body' => $body,
                'subject_count' => $subjectCount,
                'action_key' => $actionKey,
                'action_params' => $actionParams,
                'digest_key' => $digestKey,
                'status' => SellerNotificationDelivery::STATUS_QUEUED,
            ]);
        } catch (Throwable) {
            // Lost the race for this window. Somebody else announced it; nothing more to do.
            return null;
        }

        $channels = $this->send($seller, $delivery);

        $delivery->forceFill([
            'status' => $channels === [] ? SellerNotificationDelivery::STATUS_SUPPRESSED : SellerNotificationDelivery::STATUS_SENT,
            'channels' => $channels,
            'sent_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'seller.notified',
            subject: ['type' => 'seller', 'id' => $seller->id],
            context: ['topic' => $topic, 'severity' => $severity, 'count' => $subjectCount, 'channels' => array_keys($channels)],
        );

        return $delivery;
    }

    /**
     * Push it, if it is worth a phone.
     *
     * The in-app record is the delivery row itself and always exists; the push is the part that
     * interrupts somebody, so it is gated on severity. A seller with no device token still has the
     * notification waiting in the app — which is the point of recording it rather than only sending.
     *
     * @return array<string, string> channel => outcome
     */
    private function send(Seller $seller, SellerNotificationDelivery $delivery): array
    {
        $channels = ['in_app' => 'recorded'];

        if (!in_array($delivery->severity, self::PUSH_FROM_SEVERITY, true)) {
            return $channels;
        }

        $tokens = array_values(array_filter([$seller->cm_firebase_token, $seller->cm_firebase_token_web]));

        if ($tokens === []) {
            return $channels;
        }

        try {
            $this->sendPushNotificationToMultipleDevices($tokens, [
                'title' => $delivery->title,
                'description' => $delivery->body ?? '',
                'image' => '',
                'type' => 'seller_operations',
                'message_key' => $delivery->topic,
                'notification_key' => 'seller_operations',
                'recipient_type' => 'seller',
                'recipient_user_id' => (string) $seller->id,
            ]);

            $channels['push'] = 'sent';
        } catch (Throwable $exception) {
            // Recorded on the row rather than thrown: the seller still has it in the app, and a
            // detector must never fail because a push did.
            $channels['push'] = 'failed';
            $delivery->forceFill(['error' => $exception->getMessage()])->save();
        }

        return $channels;
    }

    /**
     * Identity of the fact: this seller, this topic, this window.
     *
     * The window is a floor rather than a rolling interval, so two sweeps an hour apart inside the
     * same half-day produce the same key and the second one is suppressed by the unique index.
     */
    private function digestKey(int|string $sellerId, string $topic): string
    {
        $window = (int) floor(now()->timestamp / (self::WINDOW_HOURS * 3600));

        return $sellerId . '|' . $topic . '|' . $window;
    }

    /** @param Collection<int, SellerInsight> $insights */
    private function worstSeverity(Collection $insights): string
    {
        return $insights
            ->sortBy(fn (SellerInsight $insight) => SellerInsight::SEVERITY_ORDER[$insight->severity] ?? 99)
            ->first()?->severity ?? 'medium';
    }
}
