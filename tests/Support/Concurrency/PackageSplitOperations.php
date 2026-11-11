<?php

namespace Tests\Support\Concurrency;

use App\Models\ChannelPackageSplit;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use App\Services\SalesChannels\Shipments\PackageSplitPlan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * What a worker process does in the package-split concurrency tests.
 *
 * **Trendyol is played by a table here**, `test_trendyol_splits`, with no unique key: every split request INSERTS a row. So a
 * package cut twice is visible as two rows, and the assertion is a count rather than an argument. A fixture that could not
 * record a second cut would make these tests pass for the wrong reason.
 *
 * The order read is served from the same table: as soon as a split has been recorded the fake starts describing the package as
 * `UnPacked` with two successors, which is what Trendyol really does — asynchronously, which is why the workers are also able
 * to run against a package that has not caught up yet.
 */
final class PackageSplitOperations
{
    /**
     * Records a split and tries to send it, exactly as the application does.
     *
     * @param array{account: int, order: string, package: string, lines?: list<int>, request?: bool, hold_microseconds?: int, pretend_trendyol_is_slow?: bool} $args
     * @return array{requested: int, refusal: string|null, sent: int, confirmed: int, checked: int, needs_review: int, failed: int, stopped: string|null}
     */
    public static function requestAndSend(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        self::fakeTrendyol((bool) ($args['pretend_trendyol_is_slow'] ?? false));

        $splits = app(ChannelPackageSplits::class);
        $requested = ['row' => null, 'refusal' => null];
        if ($args['request'] ?? true) {
            $requested = $splits->request(
                $account,
                (string) $args['order'],
                (string) $args['package'],
                PackageSplitPlan::ofLines($args['lines'] ?? [11]),
                adminId: 1,
            );
        }

        // A pause between recording and sending, so a test can put one worker inside the window another races through.
        usleep((int) ($args['hold_microseconds'] ?? 0));

        $stats = $splits->run($account);

        return [
            'requested' => $requested['row'] === null ? 0 : 1,
            'refusal' => $requested['refusal'],
            'sent' => $stats['sent'],
            'confirmed' => $stats['confirmed'],
            'checked' => $stats['checked'],
            'needs_review' => $stats['needs_review'],
            'failed' => $stats['failed'],
            'stopped' => $stats['stopped'],
        ];
    }

    /**
     * A worker holding a STALE list of ids: it reads what is sendable, waits while another worker finishes the same row, and
     * only then tries to claim and send it.
     *
     * The window between the SELECT and the UPDATE is microseconds, so it is made explicit here rather than hoped for.
     *
     * @param array{account: int, hold_microseconds?: int} $args
     * @return array{saw: int, sent: int, confirmed: int, needs_review: int, failed: int}
     */
    public static function sendWithAStaleIdList(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        self::fakeTrendyol(false);

        // The SELECT half of the claim, exactly as the service does it.
        $saw = ChannelPackageSplit::sendable()->where('account_id', $account->id)->orderBy('id')->pluck('id')->all();
        usleep((int) ($args['hold_microseconds'] ?? 400000));

        // ... and the UPDATE half, through the service, after the world has moved on.
        $stats = app(ChannelPackageSplits::class)->run($account);

        return ['saw' => count($saw), 'sent' => $stats['sent'], 'confirmed' => $stats['confirmed'], 'needs_review' => $stats['needs_review'], 'failed' => $stats['failed']];
    }

    /**
     * Trendyol, played by a table with no unique key.
     *
     * **No return type on these closures.** `Http::fake()` hands a stub's answer to Guzzle's promise machinery, which may give
     * back a `FulfilledPromise` rather than a `Response`, and declaring one makes every worker die with a TypeError before a
     * single request is recorded.
     *
     * `$slow` is the asynchrony Trendyol documents: with it the order keeps describing the package as `Created` however many
     * splits were recorded, which is the state a worker must NOT read as "nothing happened".
     */
    private static function fakeTrendyol(bool $slow): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/shipment-packages/*' => static function (Request $request) {
                DB::table('test_trendyol_splits')->insert(['package' => self::packageOf($request->url()), 'body' => mb_substr($request->body(), 0, 500), 'created_by' => getmypid()]);

                // The documented success for every international split endpoint: 200, no body at all.
                return Http::response('', 200);
            },
            '*/v2/orders*' => static function () use ($slow) {
                $cut = !$slow && DB::table('test_trendyol_splits')->exists();

                return Http::response(['content' => $cut
                    ? [self::package('PKG-1', 'UnPacked'), self::successor('PKG-2'), self::successor('PKG-3')]
                    : [self::package('PKG-1', 'Created')]]);
            },
        ]);
    }

    private static function packageOf(string $url): string
    {
        return (string) (preg_match('#/shipment-packages/([^/]+)/#', $url, $found) === 1 ? $found[1] : '');
    }

    /** @return array<string, mixed> */
    private static function successor(string $packageId): array
    {
        return ['createdBy' => 'split', 'originPackageIds' => ['PKG-1']] + self::package($packageId, 'Created');
    }

    /** @return array<string, mixed> */
    private static function package(string $packageId, string $status): array
    {
        return [
            'shipmentPackageId' => $packageId,
            'orderNumber' => 'ORDER-1',
            'shipmentPackageStatus' => $status,
            'status' => $status,
            'orderDate' => 1_763_000_000_000,
            'lastModifiedDate' => 1_763_000_000_000,
            'grossAmount' => 100.0,
            'totalDiscount' => 0.0,
            'totalPrice' => 100.0,
            'currencyCode' => 'SAR',
            'lines' => [
                ['lineId' => 11, 'id' => 11, 'barcode' => 'BAR-11', 'quantity' => 3, 'price' => 50.0, 'amount' => 50.0, 'discount' => 0.0],
                ['lineId' => 12, 'id' => 12, 'barcode' => 'BAR-12', 'quantity' => 1, 'price' => 50.0, 'amount' => 50.0, 'discount' => 0.0],
            ],
        ];
    }
}
