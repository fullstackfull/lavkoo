<?php

namespace Tests\Support\Concurrency;

use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooInstance;
use App\Services\Accounting\Odoo\OdooPostingRun;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The operations of the Odoo posting concurrency tests (tests/Feature/Concurrency/OdooPostingConcurrencyTest.php). */
final class OdooOperations
{
    /**
     * Drains the posting outbox, in several processes at the same instant, with **Odoo played by a shared table**.
     *
     * The test creates `test_odoo_moves`, and this fake answers `search_read` from it and `create` by inserting into it — EVERY
     * create, with no unique key. So a second journal entry for one cost becomes a second ROW, which a test can simply count. No
     * amount of reasoning about who read what first can hide it.
     *
     * The window is real and it is the one Odoo leaves open: it publishes no external-reference field, no uniqueness constraint
     * and no upsert, so between one worker being told "no such entry" and its create arriving, every other worker is in exactly
     * the same place. What closes it is the claim and the reference search, and this is what proves it.
     *
     * `lose_the_answer` makes the create insert the row and THEN throw, which is the dangerous case: the entry exists and no
     * worker can see that it does. The right outcome is still one row, and a posting row left for a person.
     *
     * @param array{account: int, lose_the_answer?: bool, die_after_send?: bool, read_lag?: bool} $args
     * @return array<string, mixed>
     */
    public static function postSameFact(array $args): array
    {
        $connection = SalesChannelAccount::findOrFail($args['account']);
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true, 'odoo.draft_creation_enabled' => true]);
        $loseTheAnswer = (bool) ($args['lose_the_answer'] ?? false);
        $dieAfterSend = (bool) ($args['die_after_send'] ?? false);
        $readLag = (bool) ($args['read_lag'] ?? false);

        Http::preventStrayRequests();
        // **Every flag the closure reads has to be captured.** It read `$readLag` and `$dieAfterSend` without them, which PHP
        // 8.3 raises as an undefined variable -- and the only place that showed was a real MariaDB, because these tests skip on
        // SQLite (one in-memory database per process is nothing to race). So the local suite could never have caught it.
        Http::fake(['*/xmlrpc/2/object' => static function (Request $request) use ($loseTheAnswer, $dieAfterSend, $readLag) {
            $body = $request->body();
            $reference = preg_match('/<string>(PKSA-COST-[^<]+)<\/string>/', $body, $matches) === 1 ? $matches[1] : '';

            if (str_contains($body, '<string>search_read</string>')) {
                if ($readLag) {
                    // Odoo's read is behind its own write. It documents neither way, so this is a shape the code has to survive
                    // rather than one it may rule out.
                    return Http::response(self::response('<array><data/></array>'));
                }
                $held = DB::table('test_odoo_moves')->where('reference', $reference)->first();

                return Http::response(self::response($held === null
                    ? '<array><data/></array>'
                    : '<array><data><value><struct>'
                        . '<member><name>id</name><value><int>' . (int) $held->move_id . '</int></value></member>'
                        . '<member><name>ref</name><value><string>' . $reference . '</string></value></member>'
                        . '</struct></value></data></array>'));
            }

            // A create. Recorded unconditionally: the fixture must SHOW a duplicate, not prevent it.
            $moveId = DB::table('test_odoo_moves')->insertGetId([
                'reference' => $reference, 'move_id' => random_int(1000, 99999), 'created_by' => getmypid(),
            ]);
            if ($dieAfterSend) {
                // Odoo has the entry and this process ends here, before PHP records anything. Whatever the database already
                // says is all anybody will ever know -- which is why the fence has to be written before this point.
                throw new RuntimeException('the run was killed after the request went out');
            }
            if ($loseTheAnswer) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            }

            return Http::response(self::response('<int>' . $moveId . '</int>'));
        }]);

        try {
            return app(OdooPostingRun::class)->drain($connection, self::preflight());
        } catch (RuntimeException $died) {
            // Reported rather than swallowed: the harness needs a line from every worker, and the test needs to know which of
            // them got as far as sending.
            return ['claimed' => 1, 'posted' => 0, 'found' => 0, 'failed' => 0, 'needs_review' => 0, 'not_postable' => 0, 'lost_the_row' => 0, 'waiting' => 0, 'stopped' => null, 'died_after_send' => true];
        }
    }

    private static function response(string $value): string
    {
        return '<?xml version="1.0"?><methodResponse><params><param><value>' . $value . '</value></param></params></methodResponse>';
    }

    /** What a real instance would have reported, built here because a worker process has no test fixtures of its own. */
    private static function preflight(): OdooPreflight
    {
        $move = [...OdooPreflight::REQUIRED_FIELDS['account.move'], ...OdooPreflight::OPTIONAL_FIELDS['account.move']];
        $line = [...OdooPreflight::REQUIRED_FIELDS['account.move.line'], 'tax_ids'];

        return new OdooPreflight(
            instance: new OdooInstance(true, true, OdooTransport::XmlRpc, '18.0', 18, 7, 'books'),
            models: array_fill_keys(OdooPreflight::MODELS, true),
            fields: [
                'account.move' => array_fill_keys($move, ['type' => 'char', 'label' => null]),
                'account.move.line' => array_fill_keys($line, ['type' => 'char', 'label' => null]),
            ],
            company: ['id' => 1, 'currency_id' => 3],
        );
    }
}
