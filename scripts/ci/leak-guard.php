#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LeakGuard;

/**
 * Refuses a change that ADDS something that must never be committed: a credential, a private key, an environment file, a
 * database dump, or the phone number or e-mail address of what looks like a real person.
 *
 * It reads only the lines a change adds (git diff against a base), so what was already in the repository is not its concern
 * and it stays quiet about it; it never prints the text it matched, only where it found it and which rule, because a log that
 * quotes a leaked secret is a second leak.
 *
 *   php scripts/ci/leak-guard.php --base=<revision> [--root=<repository>] [--allow=<file>]
 *
 * Exit status: 0 nothing found, 1 findings, 2 it could not run.
 */
final class Scanner
{
    /** Where the rule does not apply: fixtures are fake by policy, and the language files hold words, not data. */
    private const FIXTURES = '#^(tests/|resources/lang/)#';

    /** @var array<string, array{pattern: string, skip?: string}> */
    private const LINE_RULES = [
        'private-key' => ['pattern' => '/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/'],
        'api-token' => ['pattern' => '/\b(?:AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_\-]{35}|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}|xox[baprs]-[A-Za-z0-9\-]{10,}|[sr]k_live_[0-9A-Za-z]{16,}|sk-(?:ant-)?[A-Za-z0-9_\-]{32,})\b/'],
        'jwt' => ['pattern' => '/\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/'],
        'credential-literal' => [
            'pattern' => '/(?<![A-Za-z0-9])(?:pass(?:word|phrase)?|secret|api[_-]?key|access[_-]?token|auth[_-]?token|private[_-]?key)\w*[\'"]?\]?\s*(?:=>|=|:)\s*[\'"]([^\'"\s$\{<>]{12,})[\'"]/i',
            'skip' => '#^(tests/|resources/lang/|docs/|\.env\.example$)#',
        ],
        'saudi-mobile-number' => ['pattern' => '/(?<![\d.])(?:\+?966|00966|0)5\d{8}(?!\d)/', 'skip' => self::FIXTURES],
    ];

    /** A value that is plainly a placeholder is not a credential. */
    private const PLACEHOLDER = '/example|change[_-]?me|placeholder|your[_-]|x{4,}|\*{4,}|•|redacted|dummy|sample|\bnull\b/i';

    /** @var list<string> domains whose addresses are not people */
    private const HARMLESS_DOMAINS = ['example.com', 'example.org', 'example.net', 'example.test', 'localhost', 'anthropic.com', 'noreply.github.com'];

    private const HARMLESS_TOP_LEVEL = ['test', 'invalid', 'local', 'example', 'localhost'];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)/';

    /** @var array<string, string> rule => path pattern */
    private const FILE_RULES = [
        'environment-file' => '#(^|/)\.env(\.(?!example$)[^/]+)?$#',
        'key-material' => '#\.(pem|p12|pfx|p8|jks|keystore|key)$#i',
        'database-dump' => '#\.(sql|sql\.gz|dump|bak)$#i',
        'runtime-output' => '#^(storage/app/fulfillment-evidence/|storage/logs/|build/)#',
    ];

    /** @var list<array{rule: string, path: string}> reasoned exceptions */
    private array $allowed = [];

    public function __construct(private readonly string $root, string $allowFile = '')
    {
        if ($allowFile !== '' && is_file($allowFile)) {
            $decoded = json_decode((string) file_get_contents($allowFile), true);
            foreach ((array) ($decoded['allow'] ?? []) as $entry) {
                if (isset($entry['rule'], $entry['path'], $entry['reason']) && trim((string) $entry['reason']) !== '') {
                    $this->allowed[] = ['rule' => (string) $entry['rule'], 'path' => (string) $entry['path']];
                }
            }
        }
    }

    /**
     * @return list<array{path: string, line: int, rule: string}>
     * @throws \RuntimeException when git cannot produce the change
     */
    public function scan(string $base, string $head = 'HEAD'): array
    {
        $findings = [];
        foreach ($this->git(['diff', '--no-color', '--no-renames', '--diff-filter=AM', '--name-only', "{$base}...{$head}"]) as $path) {
            if ($path !== '') {
                $findings = [...$findings, ...$this->scanPath($path)];
            }
        }

        $path = null;
        $line = 0;
        foreach ($this->git(['diff', '--no-color', '--no-renames', '--diff-filter=AM', '--unified=0', "{$base}...{$head}"]) as $row) {
            if (str_starts_with($row, '+++ b/')) {
                $path = substr($row, 6);
            } elseif (preg_match('/^@@ -\S+ \+(\d+)/', $row, $hunk) === 1) {
                $line = (int) $hunk[1] - 1;
            } elseif ($path !== null && str_starts_with($row, '+') && !str_starts_with($row, '+++')) {
                $line++;
                $findings = [...$findings, ...array_map(static fn (string $rule): array => ['path' => (string) $path, 'line' => $line, 'rule' => $rule], $this->rulesBrokenBy($path, substr($row, 1)))];
            }
        }

        return $this->withoutAllowed($findings);
    }

    /** @return list<array{path: string, line: int, rule: string}> */
    public function scanPath(string $path): array
    {
        $findings = [];
        foreach (self::FILE_RULES as $rule => $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $findings[] = ['path' => $path, 'line' => 0, 'rule' => $rule];
            }
        }

        return $findings;
    }

    /** @return list<string> the rules one added line breaks */
    public function rulesBrokenBy(string $path, string $line): array
    {
        $broken = [];
        foreach (self::LINE_RULES as $rule => $definition) {
            if (isset($definition['skip']) && preg_match($definition['skip'], $path) === 1) {
                continue;
            }
            if (preg_match_all($definition['pattern'], $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0 && $this->isRealLooking($rule, $line, $matches)) {
                $broken[] = $rule;
            }
        }

        if (preg_match(self::FIXTURES, $path) !== 1 && $this->containsPersonalAddress($line)) {
            $broken[] = 'email-address';
        }

        return $broken;
    }

    /** @param list<array<int, array{0: string, 1: int}>> $matches */
    private function isRealLooking(string $rule, string $line, array $matches): bool
    {
        if ($rule !== 'credential-literal') {
            return true;
        }

        foreach ($matches as $match) {
            if (preg_match(self::PLACEHOLDER, $match[1][0]) !== 1 && !$this->isTernaryBranch($line, $match[0][1])) {
                return true;
            }
        }

        return false;
    }

    /** `$x ? 'generate_secret' : 'rotate_secret'` is a choice between two words, not a key with a value. */
    private function isTernaryBranch(string $line, int $keywordOffset): bool
    {
        $tokenStart = $keywordOffset;
        while ($tokenStart > 0 && preg_match('/[A-Za-z0-9_.\-]/', $line[$tokenStart - 1]) === 1) {
            $tokenStart--;
        }

        return $tokenStart > 0 && in_array($line[$tokenStart - 1], ['\'', '"'], true) && preg_match('/\?\s*$/', substr($line, 0, $tokenStart - 1)) === 1;
    }

    private function containsPersonalAddress(string $line): bool
    {
        if (preg_match_all(self::EMAIL, $line, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $domain) {
            $domain = strtolower($domain);
            $topLevel = substr($domain, (int) strrpos($domain, '.') + 1);
            $harmless = in_array($topLevel, self::HARMLESS_TOP_LEVEL, true)
                || array_filter(self::HARMLESS_DOMAINS, static fn (string $known): bool => $domain === $known || str_ends_with($domain, '.' . $known)) !== [];
            if (!$harmless) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{path: string, line: int, rule: string}> $findings
     * @return list<array{path: string, line: int, rule: string}>
     */
    private function withoutAllowed(array $findings): array
    {
        return array_values(array_filter($findings, function (array $finding): bool {
            foreach ($this->allowed as $entry) {
                if ($entry['rule'] === $finding['rule'] && fnmatch($entry['path'], $finding['path'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<string> */
    private function git(array $arguments): array
    {
        $command = 'git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException('git ' . $arguments[0] . ' failed: ' . implode(' ', array_slice($output, 0, 3)));
        }

        return $output;
    }
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $options = getopt('', ['base:', 'head:', 'root:', 'allow:'], $rest);
    $root = (string) ($options['root'] ?? getcwd());
    $base = (string) ($options['base'] ?? '');
    if ($base === '' || preg_match('/^0+$/', $base) === 1) {
        $base = 'HEAD~1';
    }

    $allow = (string) ($options['allow'] ?? $root . '/scripts/ci/leak-guard-allow.json');

    try {
        $findings = (new Scanner($root, $allow))->scan($base, (string) ($options['head'] ?? 'HEAD'));
    } catch (\RuntimeException $exception) {
        fwrite(STDERR, 'leak-guard: ' . $exception->getMessage() . "\n");

        return 2;
    }

    foreach ($findings as $finding) {
        echo $finding['path'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '') . '  ' . $finding['rule'] . "\n";
    }

    if ($findings === []) {
        echo "leak-guard: nothing that looks like a secret or personal data was added.\n";

        return 0;
    }

    echo 'leak-guard: ' . count($findings) . " finding(s). The matched text is not printed on purpose.\n"
        . "Remove the data (and rotate anything that was real). A false alarm is allowed with a reasoned entry in scripts/ci/leak-guard-allow.json.\n";

    return 1;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($_SERVER['argv']));
}
