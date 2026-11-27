<?php

// One process of a ConcurrentRunner run: boots the application, waits for the common start time, runs one operation and prints a single JSON line.

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\Operations;
use Tests\Support\Concurrency\Rendezvous;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$spec = json_decode($argv[1] ?? '', true);
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing']);
if (getenv('DB_STRICT_TESTS') === '1') {
    config(['database.connections.mysql.strict' => true]);
    $app['db']->purge('mysql');
}

// Operations that a test file registers (Operations::register) exist in this process only if a registration file says so.
foreach (glob(__DIR__ . '/registrations/*.php') ?: [] as $registration) {
    require $registration;
}

// The directory of this run's meeting points, for an operation that holds its process at one of them.
Rendezvous::in($spec['rendezvous'] ?? null);

while (microtime(true) < (float) $spec['start_at']) {
    usleep(200);
}

$report = ['pid' => getmypid(), 'started_at' => microtime(true)];
try {
    $report['result'] = Operations::run((string) $spec['op'], (array) ($spec['args'] ?? []), (int) ($spec['index'] ?? 0));
    $report['ok'] = true;
} catch (Throwable $exception) {
    $report['ok'] = false;
    $report['error'] = $exception::class;
    $report['message'] = mb_substr($exception->getMessage(), 0, 400);
    // WHERE it threw, not only what it said. A worker that dies of `Undefined array key 0` is undiagnosable from the message alone, and
    // these failures are intermittent -- `WalletCheckoutConcurrencyTest` lost a worker that way once in 125 concurrency tests on
    // 2026-11-27 and three solo re-runs were green, so the next occurrence may be the only chance to read it. Only frames of this
    // project are kept, because the vendor frames of a framework call are the same every time and would crowd out the line that matters.
    $report['where'] = array_values(array_slice(array_filter(array_map(
        static fn (array $frame): ?string => isset($frame['file'], $frame['line']) && !str_contains($frame['file'], '/vendor/')
            ? str_replace(base_path() . '/', '', $frame['file']) . ':' . $frame['line']
            : null,
        $exception->getTrace(),
    )), 0, 6));
}
$report['finished_at'] = microtime(true);

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
