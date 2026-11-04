<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Logs: the lines the application wrote, read from the file it wrote them to.
 *
 * This build has no log table. The 'single' channel appends to storage/logs/laravel.log, so the
 * only honest source is the file, and reading a file is the part of this system most able to take
 * the site down with it: an unrotated laravel.log on a busy store is routinely hundreds of
 * megabytes, and file_get_contents() on one is an out-of-memory error on the monitoring page.
 *
 * So the read is bounded three ways and each bound is reported rather than hidden. Only the tail
 * is read, backwards in chunks from the end, stopping at 2 MB or once enough entry headers have
 * gone past; at most 200 entries come back. When the tail did not reach the start of the file the
 * payload says so, because "these are the last 200 lines" and "this is the whole log" are
 * different claims and only one of them is usually true.
 *
 * Two things here are about honesty rather than convenience.
 *
 * The log file's timestamps are NOT UTC. Monolog writes them in the process timezone, which
 * Laravel sets from app.timezone — Asia/Dhaka on this deployment — while everything monitoring
 * stores is UTC. Parsing a log line as if it were UTC would shift every entry by six hours and
 * line it up against the wrong deploy, which is the same class of bug Clock exists to prevent. So
 * each line is parsed in the log's own timezone and converted once, through Clock::display().
 *
 * And LOG_LEVEL decides what is in the file at all. At `warning` there is no info or debug line to
 * find, so a quiet log is not evidence that nothing happened — it is evidence about the threshold.
 * The panel reports the threshold next to the entries for exactly that reason.
 */
class LogsPanel implements Panel
{
    /**
     * The furthest back a single render will read.
     *
     * Two megabytes is several thousand ordinary lines, and a handful of entries when each carries
     * a full stack trace — which is the honest trade: the operator gets what is actually there.
     */
    private const TAIL_BYTES = 2097152;

    /** Read granularity walking backwards from the end of the file. */
    private const CHUNK_BYTES = 262144;

    /** Entries returned. A log viewer is a way in; nobody reads the two hundred and first row. */
    private const MAX_ENTRIES = 200;

    /**
     * Entries examined while filtering.
     *
     * With a filter set, most of the tail is skipped over, and this is what stops "level=emergency
     * on a debug-level log" from turning into a scan of every line in the buffer.
     */
    private const SCAN_ENTRIES = 2000;

    /** How much of an entry's first line is kept. A dumped payload can be a single 90 KB line. */
    private const MESSAGE_CHARS = 500;

    /** How much of the context and stack trace is kept for the inline expansion. */
    private const DETAIL_CHARS = 6000;

    /** Log files listed in the picker, newest first. A daily channel leaves one per day. */
    private const FILE_LIST_LIMIT = 50;

    /** Search terms are bounded before they reach a case-insensitive scan of the buffer. */
    private const QUERY_CHARS = 120;

    /**
     * The standard Laravel entry header: `[2026-08-22 04:36:57] local.ERROR: `.
     *
     * The datetime part accepts the optional microseconds and offset Monolog can be configured to
     * write, and the channel part is whatever sits before the last dot — Laravel puts the
     * environment name there, so `local.ERROR` and `production.WARNING` both parse.
     */
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([^\s\[\]]+)\.([A-Z]+)(?: \(\d+\))?: /m';

    /** Monolog's levels, in severity order, with the numbers LOG_LEVEL is compared against. */
    private const LEVELS = [
        'emergency' => 600,
        'alert' => 550,
        'critical' => 500,
        'error' => 400,
        'warning' => 300,
        'notice' => 250,
        'info' => 200,
        'debug' => 100,
    ];

    private const PERMISSION_REMEDY = 'chmod 640 storage/logs/laravel.log && chown www-data:www-data storage/logs/laravel.log, or run php artisan file:permission to reset the whole storage tree.';

    private const SOURCE = 'storage/logs, read from disk — this build stores no log table';

    private ?Redactor $redactor = null;

    public function data(string $range, Request $request): array
    {
        $channel = $this->channel();
        $files = $this->files($channel);
        $filters = $this->filters($request, $files);
        $file = $this->file($channel, $files, $filters);
        $read = $this->read($file);
        $parsed = $this->parse($read, $filters);

        return [
            'window' => [
                'range' => $range,
                // Said plainly because the range selector is in the page header on every section:
                // a log file is not bucketed, so the window does not narrow it. The date filter is
                // what narrows it, and the operator should not be left guessing why 15m and 30d
                // show the same lines.
                'applies' => false,
                'timezone' => Clock::displayTimezone(),
                'log_timezone' => $this->logTimezone(),
            ],
            'channel' => $channel,
            'files' => $files,
            'file' => $file,
            'scan' => $read['scan'],
            'filters' => $filters,
            'levels' => array_keys(self::LEVELS),
            'counts' => $parsed['counts'],
            'entries' => $parsed['entries'],
            'source' => self::SOURCE,
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The logging channel as configured, and whether it writes something this page can read.
     *
     * @return array<string, mixed>
     */
    private function channel(): array
    {
        try {
            $default = (string) config('logging.default', 'stack');
            $resolved = $this->fileChannel($default);

            if ($resolved === null) {
                return [
                    'state' => 'not_supported',
                    'default' => $default,
                    'driver' => (string) config('logging.channels.' . $default . '.driver', 'unknown'),
                    'note' => 'The active log channel does not write a file on this server, so there is nothing here to read.',
                    'remedy' => 'Set LOG_CHANNEL=single (or daily) in .env and run php artisan config:clear, or read the logs where this channel sends them.',
                    'source' => 'config/logging.php',
                ];
            }

            [$name, $config] = $resolved;
            $level = strtolower((string) ($config['level'] ?? 'debug'));
            $threshold = self::LEVELS[$level] ?? 100;

            return [
                'state' => 'ok',
                'default' => $default,
                'name' => $name,
                'driver' => (string) ($config['driver'] ?? ''),
                'level' => $level,
                // The single most misread thing on this page. At LOG_LEVEL=warning an empty log is
                // a statement about the threshold, not about the application.
                'never_written' => array_keys(array_filter(
                    self::LEVELS,
                    static fn (int $severity) => $severity < $threshold,
                )),
                'path' => $this->relative((string) ($config['path'] ?? '')),
                'directory' => $this->directoryOf((string) ($config['path'] ?? '')),
                'retention_days' => isset($config['days']) ? (int) $config['days'] : null,
                'source' => 'config/logging.php',
            ];
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => Metric::describeFailure($exception), 'source' => 'config/logging.php'];
        }
    }

    /**
     * Walk the configured channel down to the one that actually writes a file.
     *
     * A stack is the Laravel default and holds other channels, so the file is one level in; a
     * driver that ships lines somewhere else entirely has no file and returns null rather than a
     * guessed path.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function fileChannel(string $name, int $depth = 0): ?array
    {
        $config = (array) config('logging.channels.' . $name, []);
        $driver = (string) ($config['driver'] ?? '');

        if (in_array($driver, ['single', 'daily'], true) && ($config['path'] ?? '') !== '') {
            return [$name, $config];
        }

        if ($driver === 'stack' && $depth === 0) {
            foreach ((array) ($config['channels'] ?? []) as $child) {
                $resolved = $this->fileChannel((string) $child, $depth + 1);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Every log file on disk, so the operator can see what is being read and what is not.
     *
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    private function files(array $channel): array
    {
        $directory = (string) ($channel['directory'] ?? storage_path('logs'));

        try {
            if (!is_dir($directory)) {
                return [
                    'state' => 'no_data',
                    'directory' => $this->relative($directory),
                    'items' => [],
                    'total' => 0,
                    'note' => 'The log directory does not exist, so nothing has been written yet.',
                    'remedy' => 'mkdir -p ' . $this->relative($directory) . ' && php artisan file:permission',
                ];
            }

            if (!is_readable($directory)) {
                return [
                    'state' => 'permission_denied',
                    'directory' => $this->relative($directory),
                    'items' => [],
                    'total' => 0,
                    'note' => 'The log directory exists but this process may not list it.',
                    'remedy' => self::PERMISSION_REMEDY,
                ];
            }

            $paths = glob(rtrim($directory, '/') . '/*.log') ?: [];
            $items = [];
            foreach ($paths as $path) {
                clearstatcache(true, $path);
                $modified = @filemtime($path);
                $items[] = [
                    'name' => basename($path),
                    'bytes' => (int) (@filesize($path) ?: 0),
                    'modified_at' => $modified === false ? null : Clock::display($modified)->toDateTimeString(),
                    'modified_ts' => $modified === false ? 0 : (int) $modified,
                    'readable' => is_readable($path),
                ];
            }

            usort($items, static fn (array $a, array $b) => $b['modified_ts'] <=> $a['modified_ts']);

            return [
                'state' => $items === [] ? 'no_data' : 'ok',
                'directory' => $this->relative($directory),
                // The sort key does not travel: it is a unix timestamp, and a raw one on the page
                // beside a converted one is how somebody ends up comparing the two.
                'items' => array_map(
                    static fn (array $item) => array_diff_key($item, ['modified_ts' => null]),
                    array_slice($items, 0, self::FILE_LIST_LIMIT),
                ),
                'total' => count($items),
                'note' => $items === [] ? 'No .log file has been written in this directory.' : null,
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'directory' => $this->relative($directory),
                'items' => [],
                'total' => 0,
                'note' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * The filter row, normalised.
     *
     * The file name is the one that matters for safety: it is chosen in the URL and ends up at
     * fopen(), so it is matched against the names this panel itself listed rather than sanitised
     * and trusted. A path that was not listed cannot be opened, whatever it is spelled like.
     *
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    /** One query value, trimmed — or an empty string when it is not a single string. */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? trim($value) : '';
    }

    private function filters(Request $request, array $files): array
    {
        // `?level[]=x` hands the request an ARRAY, and casting one to string is a PHP warning the
        // error handler turns into a throw — which takes the whole section down with an "Array to
        // string conversion" card. A filter that cannot be spelled is simply not applied.
        $level = strtolower($this->queryString($request, 'level'));
        $query = $this->queryString($request, 'q');
        $date = $this->queryString($request, 'date');
        $requestedFile = basename($this->queryString($request, 'file'));

        $names = array_column((array) ($files['items'] ?? []), 'name');

        return [
            'level' => array_key_exists($level, self::LEVELS) ? $level : null,
            'q' => $query === '' ? null : Str::limit($query, self::QUERY_CHARS, ''),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            'file' => in_array($requestedFile, $names, true) ? $requestedFile : null,
            'file_rejected' => $requestedFile !== '' && !in_array($requestedFile, $names, true),
        ];
    }

    /**
     * The file this render reads, with everything needed to judge what it is.
     *
     * For a daily channel the newest file is found by modification time rather than by composing
     * today's date into the name: the name was written in whatever timezone the process had, and
     * guessing it would open yesterday's file for three hours every night.
     *
     * @param  array<string, mixed>  $channel
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function file(array $channel, array $files, array $filters): array
    {
        if (($channel['state'] ?? '') === 'not_supported') {
            return [
                'state' => 'not_supported',
                'note' => $channel['note'] ?? null,
                'remedy' => $channel['remedy'] ?? null,
            ];
        }

        try {
            $directory = (string) ($channel['directory'] ?? storage_path('logs'));
            $path = null;

            if ($filters['file'] !== null) {
                $path = rtrim($directory, '/') . '/' . $filters['file'];
            } elseif (($channel['driver'] ?? '') === 'single') {
                $path = (string) config('logging.channels.' . ($channel['name'] ?? '') . '.path', '');
            }

            if (($path === null || $path === '' || !is_file($path)) && !empty($files['items'])) {
                $path = rtrim($directory, '/') . '/' . $files['items'][0]['name'];
            }

            if ($path === null || $path === '' || !is_file($path)) {
                return [
                    'state' => 'no_data',
                    'name' => $path === null || $path === '' ? null : basename($path),
                    'path' => $path === null ? null : $this->relative($path),
                    'note' => 'The log file has not been created yet. Nothing at or above the configured level has been written since this server was set up.',
                    'requested_missing' => $filters['file_rejected'],
                ];
            }

            if (!is_readable($path)) {
                return [
                    'state' => 'permission_denied',
                    'name' => basename($path),
                    'path' => $this->relative($path),
                    'note' => 'The log file exists but this process may not read it. That is usually a deploy or an artisan command that ran as root.',
                    'remedy' => self::PERMISSION_REMEDY,
                ];
            }

            clearstatcache(true, $path);
            $bytes = (int) (@filesize($path) ?: 0);
            $modified = @filemtime($path);

            return [
                'state' => 'ok',
                'name' => basename($path),
                'path' => $this->relative($path),
                'absolute_path' => $path,
                'bytes' => $bytes,
                'modified_at' => $modified === false ? null : Clock::display($modified)->toDateTimeString(),
                // Age is the reading that catches a store logging into a file nobody reads: a
                // laravel.log untouched for two days on a shop taking orders usually means the
                // channel moved, not that nothing went wrong.
                'age_minutes' => $modified === false
                    ? null
                    : round(max(0, Clock::now()->getTimestamp() - (int) $modified) / 60, 1),
                'requested_missing' => $filters['file_rejected'],
            ];
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => Metric::describeFailure($exception)];
        }
    }

    /**
     * Read the tail of the file, backwards, and never more than the cap.
     *
     * Chunks are taken from the end towards the start and prepended, so a 900 MB log costs one
     * seek and a few reads instead of a full pass. The buffer stays out of the payload — only the
     * facts about the read are reported.
     *
     * @param  array<string, mixed>  $file
     * @return array{state: string, buffer: string, scan: array<string, mixed>}
     */
    private function read(array $file): array
    {
        $empty = static fn (string $state, array $scan = []) => [
            'state' => $state,
            'buffer' => '',
            'scan' => array_merge([
                'state' => $state,
                'bytes_read' => 0,
                'bytes_total' => null,
                'reached_start' => false,
                'cap_bytes' => self::TAIL_BYTES,
                'cap_entries' => self::MAX_ENTRIES,
            ], $scan),
        ];

        if (($file['state'] ?? '') !== 'ok') {
            return $empty((string) ($file['state'] ?? 'no_data'));
        }

        $path = (string) $file['absolute_path'];
        $total = (int) ($file['bytes'] ?? 0);

        if ($total === 0) {
            return $empty('no_data', ['bytes_total' => 0]);
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $empty('permission_denied', ['bytes_total' => $total]);
        }

        try {
            $start = max(0, $total - self::TAIL_BYTES);
            $position = $total;
            $buffer = '';
            $headers = 0;

            while ($position > $start && $headers <= self::SCAN_ENTRIES) {
                $length = (int) min(self::CHUNK_BYTES, $position - $start);
                $position -= $length;
                if (fseek($handle, $position) !== 0) {
                    break;
                }
                $chunk = (string) fread($handle, $length);
                if ($chunk === '') {
                    break;
                }
                $buffer = $chunk . $buffer;
                // A header split across a chunk boundary is undercounted here, which only ever
                // means one more chunk is read. The authoritative parse runs on the joined buffer.
                $headers += (int) preg_match_all(self::HEADER, $chunk);
            }

            return [
                'state' => 'ok',
                'buffer' => $buffer,
                'scan' => [
                    'state' => 'ok',
                    'bytes_read' => strlen($buffer),
                    'bytes_total' => $total,
                    'reached_start' => $position <= 0,
                    'cap_bytes' => self::TAIL_BYTES,
                    'cap_entries' => self::MAX_ENTRIES,
                ],
            ];
        } catch (\Throwable $exception) {
            return $empty('failed', ['bytes_total' => $total, 'note' => Metric::describeFailure($exception)]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Split the buffer on entry headers and build the newest entries that match the filters.
     *
     * @param  array{state: string, buffer: string, scan: array<string, mixed>}  $read
     * @param  array<string, mixed>  $filters
     * @return array{entries: array<string, mixed>, counts: array<string, int>}
     */
    private function parse(array $read, array $filters): array
    {
        $noEntries = static fn (string $state, ?string $reason = null) => [
            'entries' => ['state' => $state, 'reason' => $reason, 'items' => [], 'returned' => 0, 'examined' => 0, 'found' => 0],
            'counts' => [],
        ];

        if ($read['state'] !== 'ok' || $read['buffer'] === '') {
            return $noEntries($read['state'] === 'ok' ? 'no_data' : $read['state'], 'nothing_read');
        }

        try {
            $buffer = $read['buffer'];
            $found = preg_match_all(self::HEADER, $buffer, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            if (!$found) {
                // Bytes that hold no Laravel entry header at all: a foreign format, or a single
                // entry longer than the whole tail. Either way it is not "no logs".
                return $noEntries('unrecognised', 'no_entry_header_in_the_portion_read');
            }

            $counts = [];
            foreach ($matches as $match) {
                $level = strtolower($match[3][0]);
                $counts[$level] = ($counts[$level] ?? 0) + 1;
            }

            $items = [];
            $examined = 0;
            $length = strlen($buffer);

            for ($index = count($matches) - 1; $index >= 0; $index--) {
                if (count($items) >= self::MAX_ENTRIES || $examined >= self::SCAN_ENTRIES) {
                    break;
                }
                $examined++;

                $entry = $this->entry($buffer, $matches, $index, $length, $filters);
                if ($entry !== null) {
                    $items[] = $entry;
                }
            }

            return [
                'entries' => [
                    'state' => $items === [] ? 'empty' : 'ok',
                    'reason' => $items === [] ? ($this->hasFilters($filters) ? 'filtered_out' : 'nothing_matched') : null,
                    'items' => $items,
                    'returned' => count($items),
                    'examined' => $examined,
                    'found' => (int) $found,
                    // The list is bounded twice, and the operator needs to know which bound they
                    // hit: more entries in the tail, or the filter pass stopping early.
                    'capped' => count($items) >= self::MAX_ENTRIES,
                    'scan_capped' => $examined >= self::SCAN_ENTRIES,
                ],
                'counts' => $counts,
            ];
        } catch (\Throwable $exception) {
            return $noEntries('failed', Metric::describeFailure($exception));
        }
    }

    /**
     * One entry, filtered and redacted, or null when it does not belong in the list.
     *
     * @param  array<int, array<int, array{0: string, 1: int}>>  $matches
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function entry(string $buffer, array $matches, int $index, int $length, array $filters): ?array
    {
        $level = strtolower($matches[$index][3][0]);
        if ($filters['level'] !== null && $level !== $filters['level']) {
            return null;
        }

        $moment = $this->moment($matches[$index][1][0]);
        if ($filters['date'] !== null && ($moment === null || Clock::display($moment)->toDateString() !== $filters['date'])) {
            return null;
        }

        $start = $matches[$index][0][1];
        $bodyStart = $start + strlen($matches[$index][0][0]);
        $end = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : $length;
        $body = rtrim(substr($buffer, $bodyStart, max(0, $end - $bodyStart)));

        // Cheap rejection before the redaction pass. A term absent from the raw entry cannot be
        // present after redaction either, and this is what keeps a search over a full 2 MB tail
        // from running the redaction regexes thousands of times.
        if ($filters['q'] !== null && stripos($body, $filters['q']) === false) {
            return null;
        }

        $newline = strpos($body, "\n");
        $head = $newline === false ? $body : substr($body, 0, $newline);
        $rest = $newline === false ? '' : substr($body, $newline + 1);

        // Monolog appends the context as JSON on the message line. Splitting there keeps the list
        // readable — the message is the sentence, the context is the evidence behind the toggle.
        $contextAt = strpos($head, ' {"');
        $message = $contextAt === false ? $head : substr($head, 0, $contextAt);
        $context = $contextAt === false ? '' : substr($head, $contextAt + 1);

        $detail = trim($context . ($rest === '' ? '' : "\n" . $rest));

        $redactor = $this->redactor();
        $message = $redactor->text(trim(Str::limit($message, self::MESSAGE_CHARS)));
        $detail = $detail === '' ? null : $redactor->text(Str::limit($detail, self::DETAIL_CHARS));

        // Confirmed against what is actually shown: a term that redaction removed must not pull up
        // the entry it was removed from.
        if ($filters['q'] !== null && stripos($message . ' ' . (string) $detail, $filters['q']) === false) {
            return null;
        }

        return [
            'at' => $moment === null ? null : Clock::display($moment)->toDateTimeString(),
            'date' => $moment === null ? null : Clock::display($moment)->toDateString(),
            'time' => $moment === null ? null : Clock::display($moment)->format('H:i:s'),
            'raw_at' => $matches[$index][1][0],
            'level' => $level,
            'tone' => $this->tone($level),
            'channel' => $matches[$index][2][0],
            'message' => $message === '' ? null : $message,
            'detail' => $detail,
            // A stack trace, specifically — not just "there is more text". The frame lines are what
            // decide whether this entry is a report or an incident.
            'has_trace' => $detail !== null && (str_contains($detail, '[stacktrace]') || preg_match('/^#\d+\s/m', $detail) === 1),
            'correlation_id' => $this->contextValue($body, 'correlation_id'),
            'request_id' => $this->contextValue($body, 'request_id'),
            'bytes' => max(0, $end - $start),
        ];
    }

    /**
     * A value MonitoringServiceProvider shares into Log context, pulled straight out of the entry.
     *
     * Read with a regex rather than json_decode: the context of a logged exception embeds a
     * multi-line stack trace, so the line is regularly not valid JSON on its own and decoding it
     * would drop the ids on exactly the entries worth pivoting on.
     */
    private function contextValue(string $body, string $key): ?string
    {
        if (preg_match('/"' . $key . '"\s*:\s*"([^"]{1,64})"/', $body, $found) !== 1) {
            return null;
        }

        return $this->redactor()->text($found[1]);
    }

    /**
     * A log timestamp read in the timezone it was written in.
     *
     * Never Clock::parse() on this string: that would read it as UTC, and these lines are not UTC.
     * The Carbon produced here carries its real offset, so Clock::display() converts it correctly.
     */
    private function moment(string $raw): ?Carbon
    {
        try {
            return Carbon::parse($raw, $this->logTimezone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The timezone Monolog stamped the file in: PHP's default, which Laravel sets from app.timezone.
     */
    private function logTimezone(): string
    {
        $configured = (string) config('app.timezone', 'UTC');

        return in_array($configured, timezone_identifiers_list(), true) ? $configured : 'UTC';
    }

    private function tone(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'critical',
            'warning' => 'warning',
            'notice', 'info' => 'info',
            default => 'minor',
        };
    }

    /** @param array<string, mixed> $filters */
    private function hasFilters(array $filters): bool
    {
        return $filters['level'] !== null || $filters['q'] !== null || $filters['date'] !== null;
    }

    private function directoryOf(string $path): string
    {
        return $path === '' ? storage_path('logs') : dirname($path);
    }

    /** A path an operator can paste into a shell, rather than one that names the deploy directory. */
    private function relative(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return Str::startsWith($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function redactor(): Redactor
    {
        return $this->redactor ??= Redactor::make();
    }
}
