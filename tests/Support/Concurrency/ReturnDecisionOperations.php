<?php

namespace Tests\Support\Concurrency;

use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Returns\ChannelReturnDecisions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * What a worker process does in the return-decision concurrency tests.
 *
 * **Trendyol is played by a table here**, `test_trendyol_decisions`, which the test creates: every request the fake receives
 * INSERTS a row, with no unique key on it. So a second approval becomes a second ROW, and the assertion is a count rather than
 * an argument — a customer refunded twice is visible as two rows, and nothing in the fixture prevents it.
 *
 * That is deliberate: a fixture that could not record a double send would make these tests pass for the wrong reason.
 */
final class ReturnDecisionOperations
{
    /**
     * Decides one item of one return and tries to send it.
     *
     * Every worker runs the same code path the application runs — `decide()` then `send()` — because what is being tested is
     * how those two behave when several processes reach them at the same instant.
     *
     * @param array{account: int, claim: string, item: string, decision: string, reason?: int, description?: string, decide?: bool, hold_microseconds?: int} $args
     * @return array{decided: int, already: int, refusal: string|null, sent: int, done: int, needs_review: int, failed: int, stopped: string|null}
     */
    public static function decideAndSend(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        self::fakeTrendyol();

        $decisions = app(ChannelReturnDecisions::class);
        $decided = ['rows' => [], 'already' => [], 'refusal' => null];
        if ($args['decide'] ?? true) {
            $decided = $decisions->decide(
                $account,
                (string) $args['claim'],
                [(string) $args['item']],
                (string) $args['decision'],
                adminId: 1,
                reasonId: isset($args['reason']) ? (int) $args['reason'] : null,
                description: (string) ($args['description'] ?? ''),
            );
        }

        // A pause between deciding and sending, so a test can put one worker inside the window the other is racing through.
        usleep((int) ($args['hold_microseconds'] ?? 0));

        $stats = $decisions->send($account);

        return [
            'decided' => count($decided['rows']),
            'already' => count($decided['already']),
            'refusal' => $decided['refusal'],
            'sent' => $stats['sent'],
            'done' => $stats['done'],
            'needs_review' => $stats['needs_review'],
            'failed' => $stats['failed'],
            'stopped' => $stats['stopped'],
        ];
    }

    /**
     * A worker that holds a STALE list of ids: it reads what is sendable, waits while another worker finishes the same row,
     * and only then tries to claim and send it.
     *
     * This is the interleaving the review named, and it cannot be produced by two ordinary workers reliably — the window
     * between the SELECT and the UPDATE is microseconds. So the window is made explicit: the ids are read here, the test lets
     * another worker complete, and the claim is attempted afterwards.
     *
     * @param array{account: int, hold_microseconds?: int} $args
     * @return array{saw: int, sent: int, done: int, needs_review: int, failed: int}
     */
    public static function sendWithAStaleIdList(array $args): array
    {
        $account = SalesChannelAccount::findOrFail($args['account']);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        self::fakeTrendyol();

        // The SELECT half of the claim, exactly as the service does it.
        $saw = ChannelReturnDecision::sendable()->where('account_id', $account->id)->orderBy('id')->pluck('id')->all();
        usleep((int) ($args['hold_microseconds'] ?? 400000));

        // ... and now the UPDATE half, through the service, after the world has moved on.
        $stats = app(ChannelReturnDecisions::class)->send($account);

        return ['saw' => count($saw), 'sent' => $stats['sent'], 'done' => $stats['done'], 'needs_review' => $stats['needs_review'], 'failed' => $stats['failed']];
    }

    /** Trendyol, played by a table with no unique key: a second request is a second row. */
    private static function fakeTrendyol(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/order/claim-issue-reasons' => Http::response([['id' => 51, 'name' => 'Ürün kullanılmış']]),
            '*/claims/*/items/approve' => static function (Request $request): \Illuminate\Http\Client\Response {
                DB::table('test_trendyol_decisions')->insert(['kind' => 'approve', 'body' => mb_substr($request->body(), 0, 500), 'created_by' => getmypid()]);

                return Http::response('', 200);
            },
            '*/claims/*/issue' => static function (Request $request): \Illuminate\Http\Client\Response {
                DB::table('test_trendyol_decisions')->insert(['kind' => 'reject', 'body' => mb_substr($request->body(), 0, 500), 'created_by' => getmypid()]);

                return Http::response('', 200);
            },
        ]);
    }
}
