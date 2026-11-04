<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The application's own storage: the directories Laravel must be able to write into, and what has
 * quietly accumulated in them.
 *
 * DiskCollector answers "how full is the filesystem". This answers the question that actually
 * takes stores down: the filesystem has 200 GB free and the site is still returning 500s, because
 * one directory under storage/ came back from a deploy owned by root. Each of those directories is
 * therefore its own metric — an unwritable framework/views 500s every page, an unwritable
 * framework/sessions logs every customer out, and those are not the same incident.
 *
 * Three things this deliberately does NOT do:
 *
 * 1. It does not trust is_writable(). That inspects permission bits, and it answers true on a
 *    read-only remount and on a filesystem with no space left — the two outages most worth
 *    catching. Every directory here is proven by writing a byte into it and removing it again.
 *
 * 2. It does not treat the presence of public/storage as proof the link works. A deploy that
 *    rsyncs public/ turns that symlink into an ordinary directory, which serves whatever was
 *    copied in once and never shows a new upload again. The link is followed and compared against
 *    the public disk's real root.
 *
 * 3. It does not report the S3 credentials it has to read in order to describe the disk. Bucket,
 *    region and endpoint host are published; key and secret never leave config, and even the SDK's
 *    own error text is withheld, because an S3 error body quotes the access key id back at you.
 */
class StorageCollector implements Collector
{
    private const BYTES_PER_MB = 1048576;

    /** A reachability probe is a network round trip; the dashboard must not pay for one per render. */
    private const CLOUD_PROBE_KEY = 'monitoring:storage:cloud_probe';
    private const CLOUD_PROBE_SECONDS = 60;

    private const PERMISSION_REMEDY = 'Run php artisan file:permission, or chown -R the storage tree to the web user.';
    private const SPACE_REMEDY = 'Free space on the filesystem holding this directory (df -h, and df -i for inodes) or raise the account quota. The permission bits are already correct, so chown will not help.';
    private const S3_REMEDY = 'Set AWS_BUCKET and AWS_DEFAULT_REGION in .env (plus AWS_ENDPOINT for a non-AWS S3 provider), then run php artisan config:clear.';

    private const S3_ADAPTER = \League\Flysystem\AwsS3V3\AwsS3V3Adapter::class;

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    public function key(): string
    {
        return 'storage';
    }

    public function collect(): array
    {
        // Memoised per instance: gauges() asks for four of these numbers again, and walking the
        // compiled view, session and upload trees twice per render is how a monitoring page
        // becomes the thing that needs monitoring.
        return $this->readings ??= array_merge(
            $this->writability(),
            $this->logs(),
            $this->publicDisk(),
            $this->compiled(),
            $this->filesystemDisk(),
            $this->sessions(),
        );
    }

    public function gauges(): array
    {
        $collected = $this->collect();
        $megabytes = fn (Metric $metric) => $metric->map(fn (int $bytes) => round($bytes / self::BYTES_PER_MB, 2), 'MB');

        return array_filter([
            'storage.logs_mb' => $megabytes($collected['logs_size']),
            'storage.compiled_views_count' => $collected['compiled_views'],
            'storage.sessions_count' => $collected['sessions'],
            'storage.public_mb' => $megabytes($collected['public_size']),
        ], fn (Metric $metric) => $metric->isOk() && is_numeric($metric->value));
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The six directories whose loss is a total outage, one metric each.
     *
     * They are reported separately rather than as a single "storage is writable" because the
     * consequence of each is different, and so is the fix — knowing that sessions cannot be
     * written is a diagnosis, knowing that "storage" cannot be written is a shrug.
     *
     * @return array<string, Metric>
     */
    private function writability(): array
    {
        return [
            'storage_writable' => $this->writable(storage_path(), 'nothing can log, cache or accept an upload'),
            'framework_views_writable' => $this->writable($this->configuredPath('view.compiled', storage_path('framework/views')), 'every Blade page returns a 500'),
            'framework_cache_writable' => $this->writable(storage_path('framework/cache'), 'the file cache store fails on every write'),
            'framework_sessions_writable' => $this->writable($this->configuredPath('session.files', storage_path('framework/sessions')), 'no customer can log in or keep a cart'),
            'bootstrap_cache_writable' => $this->writable(base_path('bootstrap/cache'), 'config:cache and route:cache cannot be rebuilt, and the next deploy will not boot'),
            'public_writable' => $this->writable(public_path(), 'storage:link and asset publishing fail'),
        ];
    }

    private function writable(string $path, string $consequence): Metric
    {
        $source = 'PHP write test in ' . $this->relative($path);

        return Metric::probe($source, function () use ($path, $source, $consequence) {
            if (!is_dir($path)) {
                return Metric::of(false, $source, null, sprintf(
                    '%s does not exist, so %s. Recreate the directory and run php artisan file:permission.',
                    $this->relative($path),
                    $consequence,
                ));
            }

            $failure = $this->writeProbe($path);

            // False is a reading, not a failed probe — the store is one request away from an
            // outage — so it is published as a value with the fix beside it, rather than as a
            // state that would file it among the things monitoring merely could not measure.
            return Metric::of($failure === null, $source, null, $failure === null ? null : sprintf(
                '%s cannot be written by the PHP user (%s), so %s. %s',
                $this->relative($path),
                $failure['reason'],
                $consequence,
                $failure['remedy'],
            ));
        });
    }

    /**
     * @return array{reason: string, remedy: string}|null  what stopped the write and the fix for
     *                                                     that particular failure, or null when the
     *                                                     write went through
     */
    private function writeProbe(string $directory): ?array
    {
        // Named per process so two concurrent renders cannot delete each other's probe file.
        $probe = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.monitoring-write-' . getmypid();

        [$opened, $written, $diagnostic] = $this->attemptWrite($probe);

        if (!$opened) {
            return $this->openFailure($diagnostic);
        }
        if ($written) {
            return null;
        }

        return [
            'reason' => 'the file was created but could not be written to, which is what a full filesystem or an exhausted quota looks like',
            'remedy' => self::SPACE_REMEDY,
        ];
    }

    /**
     * @return array{0: bool, 1: bool, 2: string}  whether the file opened, whether the byte landed,
     *                                             and the kernel's own complaint about it
     */
    private function attemptWrite(string $probe): array
    {
        // The handler is taken over rather than the calls being suppressed with @: Laravel installs
        // its own, which swallows a suppressed warning before error_get_last() ever sees it, and
        // the kernel's wording is the only thing separating a read-only remount from a bad chown —
        // two failures whose fixes have nothing to do with each other.
        $diagnostic = '';
        set_error_handler(static function (int $level, string $message) use (&$diagnostic): bool {
            $diagnostic = $message;

            return true;
        });

        try {
            $handle = fopen($probe, 'w');
            if ($handle === false) {
                return [false, false, $diagnostic];
            }

            // Creating the file is not the test: fopen succeeds on a full filesystem and on an
            // over-quota account, and only the write itself comes back short.
            $written = fwrite($handle, '.');
            fclose($handle);
            unlink($probe);

            return [true, $written === 1, $diagnostic];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Why the directory refused the file, and the fix for that reason rather than for the usual one.
     *
     * Sending an operator to chown during a read-only remount or a full disk costs them the outage
     * twice: the command changes nothing, and running it reads as having ruled permissions out.
     *
     * @return array{reason: string, remedy: string}
     */
    private function openFailure(string $message): array
    {
        if (stripos($message, 'read-only file system') !== false) {
            return [
                'reason' => 'the filesystem is mounted read-only',
                'remedy' => 'Remount it read-write (mount -o remount,rw <mountpoint>), but read dmesg first: the kernel drops a filesystem to read-only after an I/O error, so the disk is the suspect, not the mount.',
            ];
        }

        if (stripos($message, 'no space left') !== false) {
            return ['reason' => 'the filesystem is full', 'remedy' => self::SPACE_REMEDY];
        }

        return ['reason' => 'the directory refused a new file', 'remedy' => self::PERMISSION_REMEDY];
    }

    /**
     * @return array<string, Metric>
     */
    private function logs(): array
    {
        $directory = storage_path('logs');
        $source = $this->sourceFor($directory);
        $keys = ['logs_size', 'log_files', 'newest_log', 'newest_log_size', 'newest_log_age_minutes'];

        $usage = $this->measure($directory, $this->visibleFiles());
        if ($usage instanceof Metric) {
            return array_fill_keys($keys, $usage);
        }

        $newest = $usage['newest'];
        $empty = Metric::noData($source, 'No log file has been written yet.');

        return [
            // An unrotated laravel.log is the most common way a perfectly healthy server runs out
            // of disk, which is why the total is reported next to the free space, not inside it.
            'logs_size' => Metric::of($usage['bytes'], $source, 'bytes'),
            'log_files' => Metric::of($usage['files'], $source, 'files'),
            'newest_log' => $newest === null ? $empty : Metric::of($newest['name'], $source),
            'newest_log_size' => $newest === null ? $empty : Metric::of($newest['bytes'], $source, 'bytes'),
            // Age matters as much as size. On a store taking orders, a newest log file untouched
            // for hours does not mean nothing went wrong — it usually means logging is going
            // somewhere else entirely: a stale daily channel, or a path the web user lost.
            'newest_log_age_minutes' => $newest === null ? $empty : Metric::of(
                round(max(0, Clock::now()->getTimestamp() - $newest['modified']) / 60, 1),
                $source,
                'minutes',
                'Since anything was last written to a log file.',
            ),
        ];
    }

    /**
     * @return array<string, Metric>
     */
    private function publicDisk(): array
    {
        $directory = $this->configuredPath('filesystems.disks.public.root', storage_path('app/public'));
        $source = $this->sourceFor($directory);
        $usage = $this->measure($directory);

        $size = $usage instanceof Metric ? $usage : Metric::of($usage['bytes'], $source, 'bytes');
        $files = $usage instanceof Metric ? $usage : Metric::of($usage['files'], $source, 'files');

        return [
            'public_size' => $size,
            'public_files' => $files,
            'public_storage_link' => $this->publicStorageLink($directory),
        ];
    }

    /**
     * Whether public/storage still resolves to the public disk's root.
     */
    private function publicStorageLink(string $target): Metric
    {
        $source = 'PHP is_link()/realpath() on public/storage';
        $link = public_path('storage');

        return Metric::probe($source, function () use ($link, $target, $source) {
            $resolvedTarget = realpath($target);

            if (is_link($link)) {
                $resolved = realpath($link);
                if ($resolved === false) {
                    return Metric::of(false, $source, null, sprintf(
                        'public/storage points at %s, which does not exist, so every uploaded file returns 404. Delete the link and run php artisan storage:link.',
                        readlink($link) ?: 'an unreadable path',
                    ));
                }
                if ($resolvedTarget === false) {
                    // The link lands on a real directory, so it looks healthy — but the disk root
                    // it is supposed to mirror is gone, and the local driver silently recreates
                    // that root on the first upload, somewhere the link does not point.
                    return Metric::of(false, $source, null, sprintf(
                        'public/storage resolves to %s, but the public disk root %s no longer exists, so the next upload creates a root nothing serves. Recreate it and run php artisan storage:link.',
                        $this->relative($resolved),
                        $this->relative($target),
                    ));
                }
                if ($resolved !== $resolvedTarget) {
                    return Metric::of(false, $source, null, sprintf(
                        'public/storage resolves to %s, not to the public disk root %s, so uploads are written where nothing serves them. Delete the link and run php artisan storage:link.',
                        $resolved,
                        $resolvedTarget,
                    ));
                }

                return Metric::of(true, $source, null, 'Resolves to ' . $this->relative($resolved) . '.');
            }

            if (is_dir($link)) {
                return Metric::of(false, $source, null, sprintf(
                    'public/storage is a real directory, not a symlink to the public disk root, so files uploaded after it was created are not served. Move its contents into %s, delete it, and run php artisan storage:link.',
                    $this->relative($target),
                ));
            }

            return Metric::of(false, $source, null, 'public/storage does not exist, so every file on the public disk returns 404. Run php artisan storage:link.');
        });
    }

    /**
     * Compiled Blade and the file cache — the two directories that grow without anyone deciding to.
     *
     * @return array<string, Metric>
     */
    private function compiled(): array
    {
        $viewsDirectory = $this->configuredPath('view.compiled', storage_path('framework/views'));
        $viewsSource = $this->sourceFor($viewsDirectory);
        // Only *.php: the .gitignore Laravel ships here is not a compiled template, and counting
        // it would make an empty cache read as one warm view.
        $views = $this->measure($viewsDirectory, fn (SplFileInfo $file) => $file->getExtension() === 'php');

        $cacheDirectory = storage_path('framework/cache');
        $cacheSource = $this->sourceFor($cacheDirectory);
        $cache = $this->measure($cacheDirectory);
        $cacheNote = $this->staleCacheNote();

        return [
            'compiled_views' => $views instanceof Metric ? $views : Metric::of($views['files'], $viewsSource, 'files'),
            'compiled_views_size' => $views instanceof Metric ? $views : Metric::of($views['bytes'], $viewsSource, 'bytes'),
            'framework_cache_files' => $cache instanceof Metric ? $cache : Metric::of($cache['files'], $cacheSource, 'files', $cacheNote),
            'framework_cache_size' => $cache instanceof Metric ? $cache : Metric::of($cache['bytes'], $cacheSource, 'bytes', $cacheNote),
        ];
    }

    /**
     * Files under framework/cache are only live while the file store is the active one; after a
     * move to Redis they are dead weight nobody thinks to delete.
     */
    private function staleCacheNote(): ?string
    {
        $store = (string) config('cache.default');

        return $store === 'file'
            ? null
            : "The active cache store is '{$store}', so anything here is left over from a previous file-cache configuration.";
    }

    /**
     * @return array<string, Metric>
     */
    private function sessions(): array
    {
        $keys = ['sessions', 'sessions_size', 'sessions_expired'];
        $driver = (string) config('session.driver');

        if ($driver !== 'file') {
            return array_fill_keys($keys, Metric::notSupported(
                'config session.driver',
                "Sessions are held by the '{$driver}' driver, so there are no session files on disk to count.",
            ));
        }

        $directory = $this->configuredPath('session.files', storage_path('framework/sessions'));
        $source = $this->sourceFor($directory);
        $lifetime = max(1, (int) config('session.lifetime'));

        $usage = $this->measure($directory, $this->visibleFiles(), Clock::now()->getTimestamp() - $lifetime * 60);
        if ($usage instanceof Metric) {
            return array_fill_keys($keys, $usage);
        }

        $lottery = (array) config('session.lottery', [2, 100]);

        return [
            'sessions' => Metric::of($usage['files'], $source, 'files'),
            'sessions_size' => Metric::of($usage['bytes'], $source, 'bytes'),
            // Laravel sweeps expired session files on a lottery rather than a schedule, so this
            // directory fills the way a bath with the plug in fills: no error, no alert, and one
            // day no free inodes. The expired count is the number that says the sweep is losing.
            'sessions_expired' => Metric::of($usage['stale'], $source, 'files', sprintf(
                'Older than the %d-minute session lifetime; swept by chance on %d in %d requests.',
                $lifetime,
                (int) ($lottery[0] ?? 2),
                (int) ($lottery[1] ?? 100),
            )),
        ];
    }

    /**
     * @return array<string, Metric>
     */
    private function filesystemDisk(): array
    {
        $source = 'config/filesystems.php';
        $name = (string) config('filesystems.default');
        $disk = config('filesystems.disks.' . $name);

        if (!is_array($disk)) {
            $undefined = Metric::notConfigured(
                $source,
                'Set FILESYSTEM_DRIVER in .env to one of the disks defined in config/filesystems.php, then run php artisan config:clear.',
                "The default disk '{$name}' is not defined, so every upload throws.",
            );

            return array_fill_keys(
                ['default_disk', 'cloud_bucket', 'cloud_region', 'cloud_endpoint', 'cloud_credentials_set', 'cloud_reachable'],
                $undefined,
            );
        }

        $driver = (string) ($disk['driver'] ?? 'unknown');

        return ['default_disk' => Metric::of($name, $source, null, "driver: {$driver}")]
            + $this->cloudDisk($name, $disk, $driver, $source);
    }

    /**
     * Bucket, region and endpoint of an object-storage default disk — never its credentials.
     *
     * @param  array<string, mixed>  $disk
     * @return array<string, Metric>
     */
    private function cloudDisk(string $name, array $disk, string $driver, string $source): array
    {
        $keys = ['cloud_bucket', 'cloud_region', 'cloud_endpoint', 'cloud_credentials_set', 'cloud_reachable'];

        // A bucket key is what makes a disk s3-shaped, whatever the provider calls itself; MinIO,
        // Spaces and R2 are all configured as the s3 driver pointed at another endpoint.
        if ($driver !== 's3' && !isset($disk['bucket'])) {
            return array_fill_keys($keys, Metric::notSupported(
                $source,
                "The default disk '{$name}' uses the {$driver} driver, which has no bucket, region or endpoint.",
            ));
        }

        $bucket = trim((string) ($disk['bucket'] ?? ''));
        $region = trim((string) ($disk['region'] ?? ''));

        if ($bucket === '') {
            return array_fill_keys($keys, Metric::notConfigured(
                $source,
                self::S3_REMEDY,
                "The default disk '{$name}' is an S3 disk with no bucket set, so every upload fails.",
            ));
        }

        return [
            'cloud_bucket' => Metric::of($bucket, $source),
            'cloud_region' => $region === ''
                ? Metric::notConfigured($source, self::S3_REMEDY, 'The S3 disk has no region, and the SDK will not sign a request without one.')
                : Metric::of($region, $source),
            'cloud_endpoint' => $this->cloudEndpoint($disk, $source),
            'cloud_credentials_set' => Metric::of(
                trim((string) ($disk['key'] ?? '')) !== '' && trim((string) ($disk['secret'] ?? '')) !== '',
                $source,
                null,
                'Only whether both keys are present, never their values. False is not automatically wrong: on EC2, ECS and EKS the SDK signs with the instance role instead.',
            ),
            'cloud_reachable' => $this->cloudReachable($name, $disk, $bucket),
        ];
    }

    /**
     * @param  array<string, mixed>  $disk
     */
    private function cloudEndpoint(array $disk, string $source): Metric
    {
        $endpoint = trim((string) ($disk['endpoint'] ?? ''));
        if ($endpoint === '') {
            return Metric::noData($source, 'No custom endpoint; the SDK addresses AWS S3 in the configured region.');
        }

        // Scheme and host only. An endpoint is occasionally written with credentials embedded in
        // it, and a dashboard is the last place they should surface.
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['host'])) {
            // Publishing the raw string would defeat the point: an endpoint that will not parse is
            // exactly the one whose contents nobody has checked.
            return Metric::notConfigured(
                $source,
                'Set AWS_ENDPOINT to a full URL, for example https://minio.internal:9000, then run php artisan config:clear.',
                'The configured endpoint is not a parseable URL, and it is withheld here rather than printed.',
            );
        }

        $host = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return Metric::of($host, $source);
    }

    /**
     * @param  array<string, mixed>  $disk
     */
    private function cloudReachable(string $name, array $disk, string $bucket): Metric
    {
        $source = "S3 HeadObject on disk '{$name}'";

        if (!class_exists(self::S3_ADAPTER)) {
            return Metric::notConfigured(
                $source,
                'Run composer require league/flysystem-aws-s3-v3 "^3.0" so this application can talk to S3 at all.',
                'The S3 adapter is not installed here, so the bucket cannot be reached from this process.',
            );
        }

        try {
            $probe = Cache::remember(
                self::CLOUD_PROBE_KEY . ':' . $name,
                self::CLOUD_PROBE_SECONDS,
                fn () => $this->probeBucket($disk),
            );
        } catch (\Throwable $exception) {
            return Metric::failed($source, $exception);
        }

        // A client the SDK refused to build never put a packet on the wire, so there is no
        // reachability to report. False here would read as "the bucket is down" and send someone
        // hunting a firewall rule when what is missing is a line of .env.
        if ($probe['outcome'] === 'unconfigured') {
            return Metric::notConfigured(
                $source,
                self::S3_REMEDY,
                'The S3 client could not be built from this disk, so no request was attempted: the region or the endpoint is missing or unusable.',
            );
        }

        $reachable = $probe['outcome'] === 'reachable';

        return Metric::of($reachable, $source, null, $reachable
            ? "Bucket {$bucket} answered in {$probe['ms']} ms."
            : sprintf(
                'The bucket did not answer after %s ms (%s). The SDK message is withheld because S3 error bodies quote the access key id back; check the bucket name, region and credentials.',
                $probe['ms'],
                $probe['error'],
            ));
    }

    /**
     * @param  array<string, mixed>  $disk
     * @return array{outcome: string, ms: float, error: string|null}
     */
    private function probeBucket(array $disk): array
    {
        // The disk is rebuilt here rather than resolved by name for one reason: the timeouts. Left
        // to its own defaults the SDK spent 18 seconds retrying a dead endpoint when this was
        // measured, and a bucket that has gone away must cost the dashboard one bounded pause —
        // four seconds at worst, once a minute — not the page. One retry, so a single dropped
        // packet does not get published as an outage.
        $disk['http'] = ['connect_timeout' => 2, 'timeout' => 3] + (array) ($disk['http'] ?? []);
        $disk['retries'] = 1;

        $started = microtime(true);

        try {
            // A HEAD of a key nobody is expected to have: false proves the round trip and the
            // signature exactly as well as true does, and transfers nothing.
            Storage::build($disk)->exists('monitoring/reachability-probe');

            return ['outcome' => 'reachable', 'ms' => $this->elapsedMs($started), 'error' => null];
        } catch (\InvalidArgumentException $exception) {
            // Raised before any I/O by both the filesystem manager and the SDK — an unknown driver,
            // a missing region. Flysystem reports transport failures as RuntimeExceptions instead,
            // so a configuration fault and a dead bucket never arrive as the same class.
            return ['outcome' => 'unconfigured', 'ms' => $this->elapsedMs($started), 'error' => class_basename($exception)];
        } catch (\Throwable $exception) {
            // Only the exception's class survives, for the reason above.
            return ['outcome' => 'unreachable', 'ms' => $this->elapsedMs($started), 'error' => class_basename($exception)];
        }
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 1);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Walk one directory, or say why it could not be walked.
     *
     * @param  callable(SplFileInfo): bool|null  $accept
     * @param  int|null  $staleBefore  unix time before which a file counts as expired
     * @return array{bytes: int, files: int, stale: int, newest: array{name: string, bytes: int, modified: int}|null}|Metric
     */
    private function measure(string $directory, ?callable $accept = null, ?int $staleBefore = null): array|Metric
    {
        $source = $this->sourceFor($directory);

        if (!is_dir($directory)) {
            return Metric::noData($source, $this->relative($directory) . ' does not exist.');
        }
        if (!is_readable($directory)) {
            return Metric::permissionDenied(
                $source,
                'The PHP user cannot read ' . $this->relative($directory) . '.',
                self::PERMISSION_REMEDY,
            );
        }

        try {
            return $this->walk($directory, $accept, $staleBefore);
        } catch (\Throwable $exception) {
            // Skipping an unreadable subdirectory would publish a total that is quietly short of
            // the truth, and a size that only looks safe is worse than no size at all.
            return Metric::failed($source, $exception);
        }
    }

    /**
     * @param  callable(SplFileInfo): bool|null  $accept
     * @return array{bytes: int, files: int, stale: int, newest: array{name: string, bytes: int, modified: int}|null}
     */
    private function walk(string $directory, ?callable $accept, ?int $staleBefore): array
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $bytes = 0;
        $files = 0;
        $stale = 0;
        $newest = null;

        foreach ($entries as $entry) {
            if (!$entry->isFile() || ($accept !== null && !$accept($entry))) {
                continue;
            }

            $size = (int) $entry->getSize();
            $modified = (int) $entry->getMTime();

            $bytes += $size;
            $files++;

            if ($staleBefore !== null && $modified < $staleBefore) {
                $stale++;
            }
            if ($newest === null || $modified > $newest['modified']) {
                $newest = ['name' => $entry->getFilename(), 'bytes' => $size, 'modified' => $modified];
            }
        }

        return ['bytes' => $bytes, 'files' => $files, 'stale' => $stale, 'newest' => $newest];
    }

    /**
     * Laravel ships a .gitignore inside logs/ and sessions/; it is neither a log nor a session.
     *
     * @return callable(SplFileInfo): bool
     */
    private function visibleFiles(): callable
    {
        return fn (SplFileInfo $file) => !str_starts_with($file->getFilename(), '.');
    }

    private function configuredPath(string $key, string $fallback): string
    {
        $path = config($key);

        return is_string($path) && $path !== '' ? $path : $fallback;
    }

    private function sourceFor(string $directory): string
    {
        return 'PHP filesize() over ' . $this->relative($directory);
    }

    /** Paths are shown relative to the application root; the absolute prefix is noise on a dashboard. */
    private function relative(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
