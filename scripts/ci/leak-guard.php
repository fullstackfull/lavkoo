#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LeakGuard;

/**
 * Refuses a change that ADDS something that must not be committed: a credential, a private key, an environment file, a database dump,
 * a binary it cannot read, or the phone number or e-mail address of what looks like a real person.
 *
 * It is a pattern check, not a promise: it recognises the shapes below, and a person reading what is committed remains the control.
 * What it does read is every line the change adds, commit by commit (a secret that is added and removed again in the same range is
 * still in the pushed history and still needs rotating) and as one net difference (which also covers what a merge resolved). It never
 * prints the text it matched, only where it found it and which rule, because a log that quotes a leaked secret is a second leak.
 *
 *   php scripts/ci/leak-guard.php --base=<revision> [--head=<revision>] [--root=<repository>] [--allow=<file>]
 *
 * A base of all zeros (what a push that creates a branch reports) means "everything reachable from head".
 *
 * A false alarm is accepted with a reasoned entry in the allow file (default: scripts/ci/leak-guard-allow.json):
 *
 *   {"allow": [{"rule": "email-address", "path": "docs/team/*.md", "line": 12, "reason": "the shared support mailbox, not a person"}]}
 *
 * `rule` and `reason` are required (an entry without a reason is ignored), `path` is a shell pattern, `line` is optional and narrows
 * the exception to one line (without it the rule is silenced for every line of the matching files).
 *
 * Exit status: 0 nothing found, 1 findings, 2 it could not run.
 */
final class Scanner
{
    public const EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /** More than this many commits in one range and only the newest are read one by one (the net difference still covers them all). */
    private const MAX_COMMITS = 500;

    /** A file or diff bigger than this is not read: it is reported, because an unread file is not a clean one. */
    private const MAX_BYTES = 8_000_000;

    /** What a binary file may be without being read: media and fonts carry no text worth reading. */
    private const MEDIA = '/\.(png|jpe?g|gif|webp|avif|ico|bmp|woff2?|ttf|otf|eot|pdf|mp3|mp4|webm|ogg|wav)$/i';

    /** Fake by policy: fixtures hold made-up people, and the language files hold words. Real keys and tokens are still refused there. */
    private const FIXTURES = '#^(tests/.*\.php|resources/lang/)#';

    /** Generated or vendored text, where addresses, numbers and key-like strings are somebody else's. */
    private const VENDORED = '#(^|/)(composer\.(lock|json)|package(-lock)?\.json|yarn\.lock|pnpm-lock\.yaml)$|\.min\.(js|css)$|^(public|resources)/(assets|packages|js|css|vendor)/|^public/Modules/#';

    /** Documentation shows examples; but recorded provider evidence is data, and it gets every rule. */
    private const DOCUMENTATION = '#^docs/(?!integrations/recorded-schemas/).*\.md$#';

    /** Files where `KEY=value` and `key: value` carry configuration without quotes. */
    private const CONFIGURATION = '#(^|/)(\.env[^/]*|[^/]*\.env|\.npmrc|\.netrc|Dockerfile|docker-compose[^/]*|[^/]*\.(ini|ya?ml|conf|cnf|sh|properties|toml))$#i';

    private const SECRET_WORDS = 'pass(?:word|phrase|wd)?|secret|api[_\-]?key|access[_\-]?key|access[_\-]?token|auth[_\-]?token|bearer|token|private[_\-]?key|credential';

    /** A value that is plainly a placeholder or a reference is not a credential. */
    private const PLACEHOLDER = '/example|change[_\-]?me|placeholder|your[_\-]|x{4,}|\*{4,}|•|redacted|dummy|sample|\{\{|\$\{|<[^>]+>|%s|\benv\(|\bconfig\(|\bnull\b|^(true|false|none|secret|password|token)$/i';

    /** Laravel validation rules such as `required|string|max:255`. */
    private const RULE_GRAMMAR = '/^[a-z_]+(:[^|]*)?(\|[a-z_]+(:[^|]*)?)*$/';

    /** @var list<string> domains whose addresses are not people */
    private const HARMLESS_DOMAINS = ['example.com', 'example.org', 'example.net', 'example.test', 'localhost', 'anthropic.com', 'noreply.github.com'];

    private const HARMLESS_TOP_LEVEL = ['test', 'invalid', 'local', 'example', 'localhost'];

    /** `logo@2x.png` is an image name, not an address. */
    private const ASSET_SUFFIX = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js', 'map', 'json', 'php', 'html', 'htm', 'ico'];

    /** @var array<string, string> rule => path pattern (case-insensitive) */
    private const FILE_RULES = [
        'environment-file' => '#(^|/)\.env(\.(?!example$|sample$|dist$)[^/]+)?$|(^|/)[^/]+\.env$|(^|/)env\.(prod(uction)?|stag(e|ing)|live|pilot|local|dev(elopment)?|secrets?)$|(^|/)\.envrc$#i',
        'key-material' => '#\.(pem|p12|pfx|p8|jks|keystore|key|ppk|kdbx|ovpn|gpg)$|(^|/)id_(rsa|dsa|ecdsa|ed25519)$#i',
        'database-dump' => '#\.(sql|dump|bak|backup)(\.(gz|zip|bz2|xz|7z|zst))?$|\.(sqlite3?|db|mdb|accdb)$#i',
        'archive' => '#\.(zip|tar|tgz|7z|rar)$|\.tar\.(gz|bz2|xz)$#i',
        'credential-file' => '#(^|/)(\.htpasswd|\.npmrc|\.netrc|\.pgpass|auth\.json|credentials\.json|service-account[^/]*\.json)$#i',
        'runtime-output' => '#^(storage/app/fulfillment-evidence/|storage/logs/|build/)#',
    ];

    /** @var list<array{rule: string, path: string, line: int|null}> reasoned exceptions */
    private array $allowed = [];

    public function __construct(private readonly string $root, string $allowFile = '')
    {
        if ($allowFile !== '' && is_file($allowFile)) {
            $decoded = json_decode((string) file_get_contents($allowFile), true);
            foreach ((array) ($decoded['allow'] ?? []) as $entry) {
                if (isset($entry['rule'], $entry['path'], $entry['reason']) && trim((string) $entry['reason']) !== '') {
                    $this->allowed[] = ['rule' => (string) $entry['rule'], 'path' => (string) $entry['path'], 'line' => isset($entry['line']) ? (int) $entry['line'] : null];
                }
            }
        }
    }

    /**
     * @return list<array{path: string, line: int, rule: string, commit?: string}>
     * @throws \RuntimeException when git cannot produce the change
     */
    public function scan(string $base, string $head = 'HEAD'): array
    {
        $headSha = $this->revision($head);
        $all = preg_match('/^0+$/', $base) === 1;
        $from = $all ? self::EMPTY_TREE : $this->mergeBase($this->revision($base), $headSha);

        $findings = [];
        foreach ($this->scanDiff($from, $headSha) as $finding) {
            $findings[] = $finding;
        }

        $seen = array_flip(array_map(static fn (array $finding): string => $finding['path'] . '|' . $finding['rule'], $findings));
        foreach ($this->commitsToRead($all ? null : $from, $headSha) as $commit) {
            $parent = $this->parentOrEmptyTree($commit);
            foreach ($this->scanDiff($parent, $commit) as $finding) {
                if (!isset($seen[$finding['path'] . '|' . $finding['rule']])) {
                    $findings[] = $finding + ['commit' => substr($commit, 0, 10)];
                }
            }
        }

        return $this->withoutAllowed($findings);
    }

    /** @return list<string> the rules one added line breaks */
    public function rulesBrokenBy(string $path, string $line): array
    {
        $line = $this->withAsciiDigits($line);
        $broken = [];
        if (preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/', $line) === 1) {
            $broken[] = 'private-key';
        }
        if ($this->containsKnownToken($line)) {
            $broken[] = 'api-token';
        }
        if (preg_match('/\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/', $line) === 1) {
            $broken[] = 'jwt';
        }
        if ($this->containsUrlCredentials($line)) {
            $broken[] = 'url-credentials';
        }

        $fixture = preg_match(self::FIXTURES, $path) === 1;
        $vendored = preg_match(self::VENDORED, $path) === 1;
        $documentation = preg_match(self::DOCUMENTATION, $path) === 1;

        if (!$fixture && !$vendored && !$documentation && $this->containsCredential($path, $line)) {
            $broken[] = 'credential-literal';
        }
        if (!$fixture && !$vendored && preg_match('/(?<![\d.])(?:\+?966|00966|0)[\s\-]?5(?:[\s\-]?\d){8}(?!\d)/', $line) === 1) {
            $broken[] = 'saudi-mobile-number';
        }
        if (!$fixture && !$vendored && $this->containsPersonalAddress($line)) {
            $broken[] = 'email-address';
        }

        return $broken;
    }

    /** @return list<string> the file-name rules a path breaks */
    public function rulesBrokenByName(string $path): array
    {
        return array_keys(array_filter(self::FILE_RULES, static fn (string $pattern): bool => preg_match($pattern, $path) === 1));
    }

    /** @return array<string, string> the file-name rules, for the test that keeps .gitignore in step with them */
    public static function fileRules(): array
    {
        return self::FILE_RULES;
    }

    // ── what a change contains ───────────────────────────────────────────────────────────────────────────────

    /** @return \Generator<int, array{path: string, line: int, rule: string}> */
    private function scanDiff(string $from, string $to): \Generator
    {
        foreach ($this->changedFiles($from, $to) as [$status, $path, $oldBlob, $newBlob]) {
            foreach ($this->rulesBrokenByName($path) as $rule) {
                yield ['path' => $path, 'line' => 0, 'rule' => $rule];
            }
            yield from $this->scanContent($status, $path, $oldBlob, $newBlob);
        }
    }

    /** @return \Generator<int, array{path: string, line: int, rule: string}> */
    private function scanContent(string $status, string $path, string $oldBlob, string $newBlob): \Generator
    {
        $isMedia = preg_match(self::MEDIA, $path) === 1;
        $data = $status === 'A'
            ? $this->read(['cat-file', 'blob', $newBlob])
            : $this->read(['diff', '--no-color', '--no-ext-diff', '--no-textconv', '--text', '--unified=0', $oldBlob, $newBlob]);

        if ($data === null) {
            yield ['path' => $path, 'line' => 0, 'rule' => 'oversized-not-scanned'];

            return;
        }
        if (str_starts_with($data, "\xFF\xFE") || str_starts_with($data, "\xFE\xFF")) {
            $data = (string) mb_convert_encoding($data, 'UTF-8', 'UTF-16');
        }
        if (str_contains($data, "\0")) {
            if (!$isMedia) {
                yield ['path' => $path, 'line' => 0, 'rule' => 'binary-not-scanned'];
            }

            return;
        }

        foreach ($status === 'A' ? $this->numberedLines($data) : $this->addedLines($data) as [$number, $line]) {
            foreach ($this->rulesBrokenBy($path, $line) as $rule) {
                yield ['path' => $path, 'line' => $number, 'rule' => $rule];
            }
        }
    }

    /** @return \Generator<int, array{0: int, 1: string}> every line of a file that was added whole */
    private function numberedLines(string $data): \Generator
    {
        foreach (explode("\n", $data) as $index => $line) {
            yield [$index + 1, rtrim($line, "\r")];
        }
    }

    /**
     * The added lines of a zero-context patch of one file. Everything before the first hunk header is the file header and is skipped,
     * so an added line that starts with '++' cannot be taken for one, and the line numbers come from the hunk headers.
     *
     * @return \Generator<int, array{0: int, 1: string}>
     */
    private function addedLines(string $patch): \Generator
    {
        $line = 0;
        $inHunk = false;
        foreach (explode("\n", $patch) as $row) {
            if (preg_match('/^@@ -\S+ \+(\d+)/', $row, $hunk) === 1) {
                $inHunk = true;
                $line = (int) $hunk[1] - 1;
            } elseif ($inHunk && str_starts_with($row, '+')) {
                $line++;
                yield [$line, rtrim(substr($row, 1), "\r")];
            }
        }
    }

    private function containsKnownToken(string $line): bool
    {
        return preg_match('/\b(?:A(?:KIA|SIA)[0-9A-Z]{16}|AIza[0-9A-Za-z_\-]{35}|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}|glpat-[A-Za-z0-9_\-]{20,}|npm_[A-Za-z0-9]{36}|xox[baprs]-[A-Za-z0-9\-]{10,}|[sr]k_live_[0-9A-Za-z]{16,}|whsec_[A-Za-z0-9]{16,}|SG\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}|sk-(?:ant-)?[A-Za-z0-9_\-]{32,}|SK[0-9a-f]{32}|\d{8,10}:AA[A-Za-z0-9_\-]{33})\b/', $line) === 1;
    }

    private function containsUrlCredentials(string $line): bool
    {
        if (preg_match_all('#[a-z][a-z0-9+.\-]*://[^/\s:@]+:([^/\s@]+)@[^\s/]+#i', $line, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $password) {
            if (strlen($password) >= 6 && preg_match(self::PLACEHOLDER, $password) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** `key => 'value'`, `key = value`, `key: value`, `define('KEY', 'value')`, `->setPassword('value')` and `Bearer value`, with a value that looks like a secret. */
    private function containsCredential(string $path, string $line): bool
    {
        $words = self::SECRET_WORDS;
        $quoted = preg_match_all('/(?<![A-Za-z0-9])[A-Za-z0-9_.\-\[\]]*(?:' . $words . ')[A-Za-z0-9_\-]*[\'"\]]*\s*(?:=>|=|:)\s*(?:\'([^\']*)\'|"([^"]*)")/i', $line, $assigned, PREG_SET_ORDER);
        $candidates = [];
        foreach ($assigned as $match) {
            $candidates[] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        }

        if (preg_match_all('/\bdefine\s*\(\s*[\'"][^\'"]*(?:' . $words . ')[^\'"]*[\'"]\s*,\s*([\'"])([^\'"]+)\1/i', $line, $defined, PREG_SET_ORDER) > 0) {
            array_push($candidates, ...array_column($defined, 2));
        }
        if (preg_match_all('/->\s*(?:set)?(?:' . $words . ')\s*\(\s*([\'"])([^\'"]+)\1/i', $line, $called, PREG_SET_ORDER) > 0) {
            array_push($candidates, ...array_column($called, 2));
        }
        if (preg_match_all('/\bBearer\s+([A-Za-z0-9\-._~+\/]{20,}=*)/', $line, $bearer) > 0) {
            array_push($candidates, ...$bearer[1]);
        }
        if (preg_match(self::CONFIGURATION, $path) === 1) {
            if (preg_match_all('/(?<![A-Za-z0-9])[A-Za-z0-9_.\-]*(?:' . $words . ')[A-Za-z0-9_\-]*\s*[=:]\s*([^\s\'"#][^\s#]*)/i', $line, $bare) > 0) {
                array_push($candidates, ...$bare[1]);
            }
            if (preg_match('/\bpassword\s+(\S{6,})/i', $line, $netrc) === 1 && str_ends_with($path, '.netrc')) {
                $candidates[] = $netrc[1];
            }
        }
        unset($quoted);

        foreach ($candidates as $value) {
            if ($this->looksLikeSecret((string) $value)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecret(string $value): bool
    {
        $value = trim($value, " \t'\"");
        if (strlen($value) < 8 || preg_match(self::PLACEHOLDER, $value) === 1 || preg_match(self::RULE_GRAMMAR, $value) === 1) {
            return false;
        }
        if ($value[0] === '$' || str_contains($value, '(') || str_contains($value, '::') || preg_match('#^[a-z][a-z0-9+.\-]*://[^@\s]*$#i', $value) === 1) {
            return false;
        }

        $digits = preg_match('/\d/', $value) === 1;
        $letters = preg_match('/[A-Za-z]/', $value) === 1;
        $mixed = preg_match('/[a-z]/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/[^A-Za-z0-9]/', $value) === 1;

        return ($digits && $letters) || $mixed || (strlen($value) >= 16 && $this->entropy($value) >= 3.5);
    }

    private function entropy(string $value): float
    {
        $entropy = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $share = $count / strlen($value);
            $entropy -= $share * log($share, 2);
        }

        return $entropy;
    }

    private function containsPersonalAddress(string $line): bool
    {
        if (preg_match_all('/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.([A-Za-z]{2,}))(?![A-Za-z0-9])/', $line, $matches, PREG_SET_ORDER) === 0) {
            return false;
        }

        foreach ($matches as [, $local, $domain, $topLevel]) {
            $domain = strtolower($domain);
            $topLevel = strtolower($topLevel);
            $harmless = $local === 'git'
                || in_array($topLevel, self::HARMLESS_TOP_LEVEL, true)
                || in_array($topLevel, self::ASSET_SUFFIX, true)
                || array_filter(self::HARMLESS_DOMAINS, static fn (string $known): bool => $domain === $known || str_ends_with($domain, '.' . $known)) !== [];
            if (!$harmless) {
                return true;
            }
        }

        return false;
    }

    /** Arabic-Indic and Persian digits are read as the digits they are. */
    private function withAsciiDigits(string $line): string
    {
        return strtr($line, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /**
     * @param list<array{path: string, line: int, rule: string, commit?: string}> $findings
     * @return list<array{path: string, line: int, rule: string, commit?: string}>
     */
    private function withoutAllowed(array $findings): array
    {
        return array_values(array_filter($findings, function (array $finding): bool {
            foreach ($this->allowed as $entry) {
                if ($entry['rule'] === $finding['rule'] && fnmatch($entry['path'], $finding['path']) && ($entry['line'] === null || $entry['line'] === $finding['line'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ── git ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> [status, path, old blob, new blob] of every added or modified file */
    private function changedFiles(string $from, string $to): array
    {
        $fields = explode("\0", (string) $this->read(['diff', '--raw', '-z', '--no-renames', '--diff-filter=AM', '--no-ext-diff', $from, $to]));
        $files = [];
        for ($index = 0; $index + 1 < count($fields); $index += 2) {
            if (preg_match('/^:(\d+) (\d+) ([0-9a-f]+) ([0-9a-f]+) ([AM])$/', $fields[$index], $meta) === 1 && $meta[2] !== '160000') {
                $files[] = [$meta[5], $fields[$index + 1], $meta[3], $meta[4]];
            }
        }

        return $files;
    }

    /** @return list<string> the non-merge commits of the range, oldest first */
    private function commitsToRead(?string $from, string $head): array
    {
        $range = $from === null ? [$head] : ["{$from}..{$head}"];
        $commits = array_values(array_filter(explode("\n", trim((string) $this->read(['rev-list', '--no-merges', '--reverse', ...$range])))));

        return count($commits) > self::MAX_COMMITS ? array_slice($commits, -self::MAX_COMMITS) : $commits;
    }

    private function parentOrEmptyTree(string $commit): string
    {
        $parent = trim((string) $this->read(['rev-parse', '--verify', '--quiet', $commit . '^{commit}^'], tolerateFailure: true));

        return preg_match('/^[0-9a-f]{40,64}$/', $parent) === 1 ? $parent : self::EMPTY_TREE;
    }

    private function revision(string $revision): string
    {
        if ($revision === '' || $revision[0] === '-') {
            throw new \RuntimeException("'{$revision}' is not a revision.");
        }

        $sha = trim((string) $this->read(['rev-parse', '--verify', '--quiet', $revision . '^{commit}'], tolerateFailure: true));
        if (preg_match('/^[0-9a-f]{40,64}$/', $sha) !== 1) {
            throw new \RuntimeException("'{$revision}' is not a commit this repository has (a shallow clone may be missing it).");
        }

        return $sha;
    }

    private function mergeBase(string $base, string $head): string
    {
        $mergeBase = trim((string) $this->read(['merge-base', $base, $head], tolerateFailure: true));

        return preg_match('/^[0-9a-f]{40,64}$/', $mergeBase) === 1 ? $mergeBase : throw new \RuntimeException("'{$base}' and the head have no common ancestor in this clone.");
    }

    /**
     * Runs git without a shell and returns what it printed, or null when it printed more than a file may be.
     *
     * @param list<string> $arguments
     */
    private function read(array $arguments, bool $tolerateFailure = false): ?string
    {
        $command = ['git', '-C', $this->root, '-c', 'core.quotepath=false', '-c', 'diff.noprefix=false', '-c', 'diff.renames=false', '-c', 'core.pager=cat', ...$arguments];
        $errors = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('git could not be started.');
        }

        $output = (string) stream_get_contents($pipes[1], self::MAX_BYTES + 1);
        $oversized = strlen($output) > self::MAX_BYTES;
        if ($oversized) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        $status = proc_close($process);

        if ($oversized) {
            return null;
        }
        if ($status !== 0 && !$tolerateFailure) {
            rewind($errors);
            throw new \RuntimeException('git ' . $arguments[0] . ' failed: ' . trim(substr((string) stream_get_contents($errors), 0, 200)));
        }

        return $status === 0 ? $output : '';
    }
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $options = getopt('', ['base:', 'head:', 'root:', 'allow:']);
    $root = (string) ($options['root'] ?? getcwd());
    $base = (string) ($options['base'] ?? '');
    if ($base === '') {
        fwrite(STDERR, "leak-guard: --base=<revision> is required.\n");

        return 2;
    }
    $allow = (string) ($options['allow'] ?? $root . '/scripts/ci/leak-guard-allow.json');

    try {
        $findings = (new Scanner($root, $allow))->scan($base, (string) ($options['head'] ?? 'HEAD'));
    } catch (\RuntimeException $exception) {
        fwrite(STDERR, 'leak-guard: ' . $exception->getMessage() . "\n");

        return 2;
    }

    foreach ($findings as $finding) {
        echo (isset($finding['commit']) ? $finding['commit'] . ' ' : '') . $finding['path'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '') . '  ' . $finding['rule'] . (isset($finding['commit']) ? '  (in an earlier commit of this change)' : '') . "\n";
    }

    if ($findings === []) {
        echo "leak-guard: nothing that looks like a secret or personal data was added.\n";

        return 0;
    }

    echo 'leak-guard: ' . count($findings) . " finding(s). The matched text is not printed on purpose.\n"
        . "Remove the data and rotate anything that was real: a secret in an earlier commit is still in the history. A false alarm is allowed with a reasoned entry in scripts/ci/leak-guard-allow.json.\n";

    return 1;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($_SERVER['argv']));
}
