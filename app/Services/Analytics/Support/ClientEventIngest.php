<?php

namespace App\Services\Analytics\Support;

use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsEvent;
use Illuminate\Http\Request;

/**
 * What a client is allowed to say, and how much of it is believed.
 *
 * Two clients report the things a server cannot see for itself — the storefront's beacon and the
 * mobile app — and they arrive through different doors: the beacon is a same-origin browser POST,
 * the app an authenticated-or-not API call with no Origin to check. Only the door differs. The
 * rules about the PAYLOAD are the same on both sides and live here, so a field the beacon refuses
 * cannot be smuggled in through the app.
 *
 * The rules, in one place:
 *
 *  - Only allow-listed event names, and none of them involve money. A client that could post an
 *    order with a value of its choosing would make revenue analytics a suggestion box.
 *  - Nothing the client sends is trusted for anything but a page identity. Ids are coerced to
 *    digits, strings are cut to length, and every other key is dropped rather than sanitised.
 *  - A hard cap on how many events one request may carry.
 */
class ClientEventIngest
{
    public function __construct(private readonly Analytics $analytics)
    {
    }

    /**
     * Record the acceptable events in a payload. Returns how many were kept.
     *
     * @param  mixed  $events  whatever the client sent under `events`
     */
    public function ingest(mixed $events, Request $request): int
    {
        if (!is_array($events)) {
            return 0;
        }

        $limit = (int) config('analytics.beacon.max_events_per_request', 20);
        $kept = 0;

        foreach (array_slice($events, 0, $limit) as $event) {
            if (!is_array($event) || !isset($event['name']) || !is_string($event['name'])) {
                continue;
            }

            if (!AnalyticsEvent::isClientAllowed($event['name'])) {
                continue;
            }

            $kept += $this->analytics->fromClient($event['name'], $this->clean($event), $request) ? 1 : 0;
        }

        return $kept;
    }

    /**
     * Reduce a client payload to the few fields it is allowed to influence.
     *
     * Everything else is dropped rather than sanitised: a client that can set arbitrary properties
     * is a client that can fill the events table with whatever a bored visitor pastes into a
     * console.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function clean(array $event): array
    {
        $properties = [];

        foreach (['position', 'variant', 'list', 'depth', 'section', 'experiment'] as $key) {
            if (isset($event['properties'][$key]) && is_scalar($event['properties'][$key])) {
                $properties[$key] = mb_substr((string) $event['properties'][$key], 0, 64);
            }
        }

        return array_filter([
            'entity_type' => isset($event['entity_type']) && is_string($event['entity_type'])
                ? mb_substr($event['entity_type'], 0, 24)
                : null,
            // An entity id is a database key — digits — with one named exception: campaign
            // overlay sections report as campaign-{id}, the only non-numeric identity the theme
            // deliberately emits. Anything else in that column is somebody probing.
            'entity_id' => isset($event['entity_id'])
                && preg_match('/^(?:\d{1,18}|campaign-\d{1,18})$/', (string) $event['entity_id'])
                ? (string) $event['entity_id']
                : null,
            'path' => isset($event['path']) && is_string($event['path']) ? mb_substr($event['path'], 0, 300) : null,
            'dedupe_key' => isset($event['dedupe_key']) && is_string($event['dedupe_key'])
                ? mb_substr($event['dedupe_key'], 0, 64)
                : null,
            'properties' => $properties,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
