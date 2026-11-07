<?php

// One process of a ConcurrentRunner run: boots the application, waits for the common start time, runs one operation and prints a single JSON line.

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\Operations;

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
}
$report['finished_at'] = microtime(true);

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
