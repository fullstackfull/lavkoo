#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lets backup.sh and verify-backup.sh talk to MySQL without a password ever appearing on a command line, in the shell's
 * variables or in the output. It reads the application's .env, writes a mysql client option file readable by its owner only,
 * and prints the NON-secret facts the scripts need (database name, host, port) as KEY=VALUE lines.
 *
 *   php mysql-defaults.php --app=/path/to/app --out=/path/to/new/defaults.cnf
 */

$options = getopt('', ['app:', 'out:']);
$app = isset($options['app']) ? rtrim((string) $options['app'], '/') : '';
$out = (string) ($options['out'] ?? '');

if (!is_file("{$app}/.env") || $out === '' || file_exists($out)) {
    fwrite(STDERR, "mysql-defaults: need --app=<dir with .env> and --out=<a file that does not exist yet>.\n");
    exit(2);
}

$env = parseEnvironment((string) file_get_contents("{$app}/.env"));
$driver = $env['DB_CONNECTION'] ?? 'mysql';
if (!in_array($driver, ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "mysql-defaults: DB_CONNECTION is '{$driver}', not mysql: this backup script only handles MySQL/MariaDB.\n");
    exit(3);
}
$database = $env['DB_DATABASE'] ?? '';
if (preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $database) !== 1) {
    fwrite(STDERR, "mysql-defaults: DB_DATABASE is missing or has characters this script will not pass to a shell.\n");
    exit(3);
}

$lines = ['[client]', 'user=' . quote($env['DB_USERNAME'] ?? ''), 'password=' . quote($env['DB_PASSWORD'] ?? '')];
if (($env['DB_SOCKET'] ?? '') !== '') {
    $lines[] = 'socket=' . quote($env['DB_SOCKET']);
} else {
    $lines[] = 'host=' . quote($env['DB_HOST'] ?? '127.0.0.1');
    $lines[] = 'port=' . (int) ($env['DB_PORT'] ?? 3306);
}

$handle = fopen($out, 'xb');
if ($handle === false) {
    fwrite(STDERR, "mysql-defaults: could not create {$out}.\n");
    exit(2);
}
chmod($out, 0600);
fwrite($handle, implode("\n", $lines) . "\n");
fclose($handle);

echo "DB_DATABASE={$database}\n";
echo 'DB_HOST=' . (($env['DB_SOCKET'] ?? '') !== '' ? 'socket' : ($env['DB_HOST'] ?? '127.0.0.1')) . "\n";
echo 'DB_PORT=' . (int) ($env['DB_PORT'] ?? 3306) . "\n";

/** @return array<string, string> */
function parseEnvironment(string $contents): array
{
    $values = [];
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
            continue;
        }

        $value = trim($match[2]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strpos($value, $quote, 1);
            while ($quote === '"' && $end !== false && $value[$end - 1] === '\\') {
                $end = strpos($value, $quote, $end + 1);
            }
            $inner = substr($value, 1, ($end === false ? strlen($value) : $end) - 1);
            $value = $quote === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }
        $values[$match[1]] = $value;
    }

    return $values;
}

function quote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}
