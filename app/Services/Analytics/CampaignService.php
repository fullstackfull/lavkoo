<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Support\CampaignDestination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Campaign links: the UTM builder, the short links it issues, and what each one returned.
 *
 * The reason short links exist here rather than being left to a third-party shortener: a shortener
 * knows how many people clicked and nothing else. It cannot tell a merchant that the WhatsApp
 * broadcast produced fourteen visits, two carts and one order, because it never sees the shop. A
 * link this shop issues does, and that is the difference between a click count and knowing whether
 * the campaign paid for itself.
 *
 * Clicks are counted, but revenue is attributed from SESSIONS, not clicks. A click is a promise; a
 * session is a visit. Crediting clicks is how a bot farm becomes the best campaign of the month.
 */
class CampaignService
{
    private ?bool $hasSurfaceColumn = null;

    /** Short enough to type off a poster, long enough not to be guessable in bulk. */
    private const CODE_LENGTH = 7;

    /** Characters that cannot be confused with one another when read aloud or off a printed QR. */
    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly CampaignDestination $destination)
    {
    }

    public function ready(): bool
    {
        try {
            return Schema::connection(config('analytics.connection'))->hasTable('analytics_campaigns');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error: ?string, campaign: ?object}
     */
    public function create(array $input, ?int $adminId = null): array
    {
        if (!$this->ready()) {
            return ['ok' => false, 'error' => 'analytics_is_not_installed', 'campaign' => null];
        }

        $check = $this->destination->check($input['destination_url'] ?? null);

        if (!$check['allowed']) {
            return ['ok' => false, 'error' => $check['reason'], 'campaign' => null];
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'error' => 'a_campaign_name_is_required', 'campaign' => null];
        }

        $source = $this->tag($input['utm_source'] ?? null);
        $medium = $this->tag($input['utm_medium'] ?? null);
        $campaign = $this->tag($input['utm_campaign'] ?? null);

        if ($source === null || $medium === null || $campaign === null) {
            return ['ok' => false, 'error' => 'source_medium_and_campaign_are_all_required', 'campaign' => null];
        }

        $code = $this->mintCode();

        if ($code === null) {
            return ['ok' => false, 'error' => 'a_unique_short_code_could_not_be_generated', 'campaign' => null];
        }

        $now = Carbon::now();

        $id = $this->connection()->table('analytics_campaigns')->insertGetId([
            'name' => mb_substr($name, 0, 191),
            'code' => $code,
            'destination_url' => $check['url'],
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
            'utm_content' => $this->tag($input['utm_content'] ?? null),
            'utm_term' => $this->tag($input['utm_term'] ?? null),
            'channel_hint' => $this->tag($input['channel_hint'] ?? null, 32),
            'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 2000) : null,
            'is_active' => true,
            'expires_at' => $this->expiry($input['expires_at'] ?? null),
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['ok' => true, 'error' => null, 'campaign' => $this->find((int) $id)];
    }

    public function find(int $id): ?object
    {
        return $this->ready()
            ? $this->connection()->table('analytics_campaigns')->where('id', $id)->first()
            : null;
    }

    public function findByCode(string $code): ?object
    {
        // Codes are minted from a lowercase alphabet, so folding case here can never collide with
        // a different campaign — and it is the difference between a code read off a poster in
        // capitals working and quietly landing on the home page.
        $code = strtolower(trim($code));

        if (!$this->ready() || !preg_match('/^[a-z0-9]{4,24}$/', $code)) {
            return null;
        }

        return $this->connection()->table('analytics_campaigns')->where('code', $code)->first();
    }

    /**
     * Where a click on this code should actually go.
     *
     * Re-validated here as well as at creation, because a row can be edited directly in the
     * database and an allow-list can be narrowed after the link was issued. A campaign whose
     * destination no longer passes is not followed — it is refused.
     *
     * @return array{ok: bool, url: ?string, reason: ?string}
     */
    public function resolve(string $code): array
    {
        $campaign = $this->findByCode($code);

        if ($campaign === null) {
            return ['ok' => false, 'url' => null, 'reason' => 'unknown_code'];
        }

        if (!$campaign->is_active) {
            return ['ok' => false, 'url' => null, 'reason' => 'inactive'];
        }

        if ($campaign->expires_at !== null && Carbon::parse($campaign->expires_at)->isPast()) {
            return ['ok' => false, 'url' => null, 'reason' => 'expired'];
        }

        $check = $this->destination->check($campaign->destination_url);

        if (!$check['allowed']) {
            return ['ok' => false, 'url' => null, 'reason' => $check['reason']];
        }

        return [
            'ok' => true,
            'url' => $this->destination->withUtm($check['url'], [
                'utm_source' => $campaign->utm_source,
                'utm_medium' => $campaign->utm_medium,
                'utm_campaign' => $campaign->utm_campaign,
                'utm_content' => $campaign->utm_content,
                'utm_term' => $campaign->utm_term,
            ]),
            'reason' => null,
            'campaign' => $campaign,
        ];
    }

    /**
     * Record a click.
     *
     * Best-effort and after the redirect has been decided: a customer following a printed QR must
     * not land on an error because the analytics write failed.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordClick(object $campaign, array $context): void
    {
        try {
            $now = Carbon::now();

            $row = [
                'campaign_id' => $campaign->id,
                'visitor_id' => $context['visitor_id'] ?? null,
                'ip_hash' => $context['ip_hash'] ?? null,
                'device' => $context['device'] ?? null,
                'country' => $context['country'] ?? null,
                'referrer_domain' => $context['referrer_domain'] ?? null,
                'is_bot' => (bool) ($context['is_bot'] ?? false),
                'clicked_at' => $now,
            ];

            // Whether the link opened in the app or a browser. Guarded on the column existing
            // because code reaches a server before its migration does, and a campaign link that
            // stops working during a deployment is worse than one that briefly stops saying where
            // it was opened.
            if ($this->hasSurfaceColumn()) {
                $row['surface'] = $context['surface'] ?? 'web';
            }

            $this->connection()->table('analytics_campaign_clicks')->insert($row);

            // Bot clicks are recorded but never inflate the headline count on the campaign row,
            // which is the number a merchant judges the campaign by.
            if (!($context['is_bot'] ?? false)) {
                $this->connection()->table('analytics_campaigns')->where('id', $campaign->id)->update([
                    'clicks' => DB::raw('clicks + 1'),
                    'last_click_at' => $now,
                ]);
            }
        } catch (\Throwable) {
            // A campaign link that cannot be counted still has to work.
        }
    }

    /**
     * Every campaign with what it returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (!$this->ready()) {
            return [];
        }

        // One grouped query rather than one per campaign: the list is rendered in full on every
        // visit to the section, and a per-row count would be an N+1 the moment a shop has a dozen
        // links.
        $bySurface = $this->clicksBySurface();

        return $this->connection()->table('analytics_campaigns')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (object $campaign) use ($bySurface) {
                return [
                    'row' => $campaign,
                    'short_url' => $this->shortUrl($campaign->code),
                    'tagged_url' => $this->resolve($campaign->code)['url'] ?? null,
                    // Where the link was opened. Null — not zero — when the column predates this
                    // shop's deployment, because "we never recorded it" is not "it never happened".
                    'app_clicks' => $bySurface === null ? null : (int) ($bySurface[$campaign->id]['app'] ?? 0),
                    'web_clicks' => $bySurface === null ? null : (int) ($bySurface[$campaign->id]['web'] ?? 0),
                    // Clicks that never became a visit: an ad network's own crawler, a link
                    // checker, or a preview fetch. A large gap here is the sign that a click count
                    // from any shortener would have been badly misleading.
                    'unconverted_clicks' => max(0, (int) $campaign->clicks - (int) $campaign->sessions),
                    'conversion_rate' => $campaign->sessions > 0
                        ? round(100 * $campaign->orders / $campaign->sessions, 2)
                        : null,
                    'revenue_per_click' => $campaign->clicks > 0
                        ? round((float) $campaign->revenue / $campaign->clicks, 2)
                        : null,
                    'expired' => $campaign->expires_at !== null && Carbon::parse($campaign->expires_at)->isPast(),
                ];
            })->all();
    }

    /**
     * Clicks per campaign, split by where the link was opened.
     *
     * Null when the surface has never been recorded, so a screen can say so instead of drawing a
     * bar chart of zeroes that reads as "nobody ever opened this in the app".
     *
     * @return array<int, array<string, int>>|null
     */
    private function clicksBySurface(): ?array
    {
        if (!$this->hasSurfaceColumn()) {
            return null;
        }

        $rows = $this->connection()->table('analytics_campaign_clicks')
            ->selectRaw('campaign_id, surface, COUNT(*) as total')
            ->where('is_bot', false)
            ->groupBy('campaign_id', 'surface')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->campaign_id][$row->surface ?? 'web'] = (int) $row->total;
        }

        return $counts;
    }

    public function setActive(int $id, bool $active): void
    {
        if ($this->ready()) {
            $this->connection()->table('analytics_campaigns')->where('id', $id)
                ->update(['is_active' => $active, 'updated_at' => Carbon::now()]);
        }
    }

    public function shortUrl(string $code): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . trim((string) config('analytics.campaigns.path', 'go'), '/') . '/' . $code;
    }

    /**
     * A QR code for a short link, as an inline SVG.
     *
     * Rendered here rather than by pulling in a QR library: the payload is a short ASCII URL, the
     * encoder below is a few hundred lines of well-understood arithmetic, and a printed poster is
     * not worth a new composer dependency and its transitive tree. It is also why the code
     * alphabet excludes look-alike characters — a QR that fails to scan gets typed by hand.
     */
    public function qrSvg(string $code, int $size = 220): ?string
    {
        return app(Support\QrCode::class)->svg($this->shortUrl($code), $size);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A short code nothing else is using.
     *
     * Collision is checked rather than assumed: with a 31-character alphabet and 7 places the
     * space is large, but "large" is not "unique", and two campaigns sharing a code would silently
     * merge two sets of results.
     */
    private function mintCode(): ?string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = '';

            for ($index = 0; $index < self::CODE_LENGTH; $index++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            if (!$this->connection()->table('analytics_campaigns')->where('code', $code)->exists()) {
                return $code;
            }
        }

        return null;
    }

    /**
     * A UTM value, cleaned.
     *
     * These end up in reports an administrator reads and in URLs a customer sees, so they are
     * treated as untrusted text and reduced to a safe character set rather than escaped later.
     */
    private function tag(mixed $value, int $limit = 96): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s._\-\+]/u', '', $value) ?? ''));
        $value = preg_replace('/\s+/', '_', $value) ?? $value;

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function expiry(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->endOfDay()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }

    /**
     * Memoised for the request: recordClick runs on the redirect path and a schema lookup per click
     * would put an information_schema query in front of every campaign link.
     */
    private function hasSurfaceColumn(): bool
    {
        return $this->hasSurfaceColumn ??= $this->connection()
            ->getSchemaBuilder()
            ->hasColumn('analytics_campaign_clicks', 'surface');
    }
}
