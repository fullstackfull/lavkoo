<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Reads language files, Blade views and API payloads for the sentences that claim a country, a storefront, a text or the platform is legally compliant, certified or approved by an authority
 * (I2-07). The platform records what a person tells it about a market and reviews and approves nothing, so no string a person can read and no field an app can read may say that a country is
 * compliant: a refusal says the storefront is not open for checkout, or not approved.
 *
 * It reads text, not meaning. The text is normalised first (lower case, every run of anything that is not a letter, a digit or a percent sign becomes one space, so that a translation key such as
 * `legally_compliant` reads as the words it humanises to, and Arabic loses its vowel marks, its stretching and its variants of the alef) and then matched against the phrases below, in English and in
 * Arabic. A phrase that is negated just before it ("not legally compliant", "غير متوافق") is not a claim. A sentence that says something else about compliance ("compliance documents", "approved
 * products", "compatible with S3") matches nothing, and what a person has to decide about is only what matches. The other languages of the language files (Spanish, Hindi, Bengali) are read through
 * the key of each string, which is English, and through any English left in the text.
 *
 * A finding is an array: the source (language, view or payload), the file (or the label of the payload), the line of a view, the key of a string or the path of a field, the text, the names of the
 * claims, and, for a view, the keys passed to translate() on the line.
 */
final class ComplianceClaimScanner
{
    public const LANGUAGE = 'language';

    public const VIEW = 'view';

    public const PAYLOAD = 'payload';

    private const ARABIC_COMPLIANT = '(?:متوافق|ممتثل)[ةهاونيم]*';

    private const ARABIC_CONFORMING = '(?:متوافق|ممتثل|مطابق|ملتزم)[ةهاونيم]*';

    private const ARABIC_AUTHORITY = '(?:ال)?(?:جهات|جهه|جهة|سلطه|سلطة|سلطات|حكومه|حكومة|حكومي|حكوميه|وزاره|وزارة|هيئه|هيئة|دوله|دولة)';

    /** @var array<string, list<string>> the name of a claim and the patterns, over normalised text, that make it */
    private const CLAIMS = [
        'legally compliant' => [
            '\blegal(?:ly)?\s+complian(?:t|ce)\b',
            '\bcomplian(?:t|ce)\s+(?:legally|by\s+law)\b',
        ],
        'fully compliant' => [
            '\b(?:fully|totally|completely|entirely|wholly)\s+complian(?:t|ce)\b',
            '\b(?:in\s+)?(?:full|total|complete)\s+complian(?:t|ce)\b',
        ],
        '100% compliant' => [
            '\b100\s*(?:%|percent|per\s+cent)\s+(?:legal(?:ly)?\s+)?(?:complian(?:t|ce)|legal|lawful)\b',
            '\bcomplian(?:t|ce)\s+(?:at|by)\s+100\s*(?:%|percent)',
        ],
        'compliant with the law' => [
            '\bcompl(?:iant|iance|y|ies|ied|ying)\s+with\s+(?:(?:the|all|any|local|national|applicable|relevant|saudi|gcc|uae|kuwaiti|qatari|bahraini|omani|emirati)\s+)*'
                . '(?:laws?|legal|legislation|regulations?|regulatory|vat|tax|taxes|gdpr|pdpl|zatca|data\s+protection|privacy|consumer\s+protection|sharia)\b',
        ],
        'compliant' => [
            '\bcompliant\b',
        ],
        'certified' => [
            '\bcertified\b',
            '\bcertifies\b',
        ],
        'approved by the authority' => [
            '\bapproved\s+by\s+(?:(?:the|a|an|local|national|relevant|competent|official|regulatory)\s+)*'
                . '(?:authorit(?:y|ies)|government|ministry|ministries|regulators?|regulatory\s+bod(?:y|ies)|state)\b',
        ],
        'government approved' => [
            '\b(?:government|authority|authorities|regulator|ministry|state)\s+(?:approved|certified|endorsed|verified)\b',
        ],
        'guaranteed legal' => [
            '\bguarantee[sd]?\s+(?:(?:full|legal|regulatory)\s+)*(?:legal(?:ly)?|lawful|complian(?:t|ce)|legality)\b',
            '\blegally\s+guaranteed\b',
            '\bguaranteed\s+by\s+law\b',
        ],
        'legally approved' => [
            '\blegally\s+(?:approved|certified|cleared|verified|endorsed|authori[sz]ed)\b',
            '\b(?:fully|completely|totally)\s+(?:legal|lawful)\b',
        ],
        'ممتثل' => [
            '(?<!\p{L})ممتثل[ةهاونيم]*(?!\p{L})',
        ],
        'متوافق مع القانون' => [
            '(?<!\p{L})' . self::ARABIC_CONFORMING . '\s+(?:مع\s+|ل|ب)?(?:ال|ل)?(?:قانون|قوانين|انظمه|انظمة|نظام|لوائح|تشريع|ضريب|ضرائب)',
        ],
        'متوافق قانونيا' => [
            '(?<!\p{L})' . self::ARABIC_COMPLIANT . '\s+قانوني[اةه]?(?!\p{L})',
            '(?<!\p{L})قانوني[اةه]?\s+(?:متوافق|ممتثل|ملتزم)',
        ],
        'متوافق بالكامل' => [
            '(?<!\p{L})' . self::ARABIC_COMPLIANT . '\s+(?:بالكامل|كليا|تماما|بشكل\s+كامل|بنسبه\s+100)',
            '100\s*%\s*(?:متوافق|ممتثل|قانوني)',
        ],
        'معتمد من الجهات' => [
            '(?<!\p{L})(?:معتمد|مصدق|مرخص|موثق)[ةهاونيم]*\s+(?:من|لدي|بواسطه|بواسطة|عن\s+طريق)\s+' . self::ARABIC_AUTHORITY,
            '(?<!\p{L})(?:معتمد|مصدق|مرخص)[ةهاونيم]*\s+(?:حكومي|رسمي)[اةه]?(?!\p{L})',
        ],
        'مضمون قانونيا' => [
            '(?<!\p{L})مضمون[ةه]?ا?\s+قانوني',
            '(?<!\p{L})(?:نضمن|يضمن|تضمن|ضمان)\s+(?:لك\s+|لكم\s+)?(?:ال)?(?:امتثال|توافق|التزام)',
        ],
    ];

    /** @var array<string, list<string>> what a field of a payload is named for, where a field name that holds the words is itself a claim */
    private const FIELD_NAMES = [
        'a field named for compliance' => ['\bcompl(?:iance|iant|ies|y)\b'],
        'a field named for certification' => ['\bcertif(?:ied|ication)\b'],
    ];

    private const NEGATION = '~(?<!\p{L})(?:و|ف)?(?:not|non|never|nor|without|cannot|(?:isn|aren|wasn|weren|can|won)\s+t|غير|لا|ليس|ليست|لم|لن|بدون|دون|عدم)\s+'
        . '(?:(?:yet|been|be|being|is|are|was|were|currently|really|necessarily|always|legally|fully|officially|automatically|يتم|تتم|بعد|كان|كانت|يكون|تكون)\s+){0,3}$~u';

    private ?string $anyClaim = null;

    public function normalise(string $text): string
    {
        $text = strtr(mb_strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', mb_scrub($text))), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٪' => '%',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي',
        ]);

        return trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]+/u', '', $text)));
    }

    /** @return list<string> the names of the claims a text makes, none of them negated just before it */
    public function claimsIn(string $text): array
    {
        $normalised = $this->normalise($text);

        if ($normalised === '' || $this->offsetsOf(pattern: $this->anyClaim(), subject: $normalised) === []) {
            return [];
        }

        $claims = [];
        foreach (self::CLAIMS as $name => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($this->offsetsOf(pattern: '~' . $pattern . '~u', subject: $normalised) as $offset) {
                    if ($this->offsetsOf(pattern: self::NEGATION, subject: substr($normalised, 0, $offset)) === []) {
                        $claims[$name] = true;
                    }
                }
            }
        }

        return array_keys($claims);
    }

    /** @return list<string> the claims of a field name: the ones of any text, and the names that hold compliance or certification */
    public function claimsInFieldName(string $name): array
    {
        $normalised = $this->normalise($name);
        $claims = $this->claimsIn($name);

        foreach (self::FIELD_NAMES as $claim => $patterns) {
            foreach ($patterns as $pattern) {
                if ($this->offsetsOf(pattern: '~' . $pattern . '~u', subject: $normalised) !== []) {
                    $claims[] = $claim;
                }
            }
        }

        return array_values(array_unique($claims));
    }

    /**
     * @param list<string> $languageRoots
     * @return list<string> the language files under the roots: the PHP and JSON files of every language directory, and the JSON files at a root
     */
    public function languageFiles(array $languageRoots): array
    {
        return $this->filesUnder(directories: $languageRoots, suffixes: ['.php', '.json']);
    }

    /**
     * @param list<string> $directories
     * @return list<string> the Blade views under the directories
     */
    public function viewFiles(array $directories): array
    {
        return $this->filesUnder(directories: $directories, suffixes: ['.blade.php']);
    }

    /**
     * Every string of every language file under the roots (one directory for each language, and JSON files at a root), by its key and by its text.
     *
     * @param list<string> $languageRoots
     * @return list<array<string, mixed>>
     */
    public function inLanguageFiles(array $languageRoots, string $repository): array
    {
        $findings = [];

        foreach ($this->languageFiles($languageRoots) as $file) {
            foreach ($this->flatten(messages: $this->messagesOf($file)) as $key => $text) {
                $claims = array_values(array_unique(array_merge($this->claimsIn((string) $key), $this->claimsIn($text))));

                if ($claims !== []) {
                    $findings[] = ['source' => self::LANGUAGE, 'file' => $this->relative(file: $file, repository: $repository), 'line' => null, 'key' => (string) $key, 'text' => $text, 'claims' => $claims, 'keys' => []];
                }
            }
        }

        return $findings;
    }

    /**
     * Every Blade view under the directories, line by line; a sentence broken across lines is reported for its file with no line.
     *
     * @param list<string> $directories
     * @return list<array<string, mixed>>
     */
    public function inViews(array $directories, string $repository): array
    {
        $findings = [];

        foreach ($this->viewFiles($directories) as $file) {
            $source = (string) file_get_contents($file);
            $claimsOfTheFile = $this->claimsIn($source);

            if ($claimsOfTheFile === []) {
                continue;
            }

            $claimsOfTheLines = [];
            foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
                $claims = $this->claimsIn($line);

                if ($claims !== []) {
                    $claimsOfTheLines = array_merge($claimsOfTheLines, $claims);
                    $findings[] = ['source' => self::VIEW, 'file' => $this->relative(file: $file, repository: $repository), 'line' => $index + 1, 'key' => null, 'text' => trim($line), 'claims' => $claims, 'keys' => $this->translationKeysIn($line)];
                }
            }

            $broken = array_values(array_diff($claimsOfTheFile, $claimsOfTheLines));
            if ($broken !== []) {
                $findings[] = ['source' => self::VIEW, 'file' => $this->relative(file: $file, repository: $repository), 'line' => null, 'key' => null, 'text' => '(a sentence broken across lines)', 'claims' => $broken, 'keys' => []];
            }
        }

        return $findings;
    }

    /**
     * Every field name and every string of a payload decoded from JSON.
     *
     * @param array<array-key, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function inPayload(string $label, array $payload): array
    {
        return $this->payloadFindings(label: $label, payload: $payload, path: '');
    }

    /** @param array<string, mixed> $finding */
    public function describe(array $finding): string
    {
        return sprintf(
            '%s%s [%s] %s => %s',
            $finding['file'],
            $finding['line'] === null ? '' : ':' . $finding['line'],
            implode(', ', $finding['claims']),
            $finding['key'] ?? '',
            mb_substr((string) $finding['text'], 0, 140),
        );
    }

    /** @return list<string> the literal keys passed to translate() in a piece of a view */
    public function translationKeysIn(string $line): array
    {
        preg_match_all('/translate\(\s*([\'"])(.*?)\1/', $line, $matches);

        return $matches[2];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function payloadFindings(string $label, array $payload, string $path): array
    {
        $findings = [];

        foreach ($payload as $name => $value) {
            $here = $path === '' ? (string) $name : $path . '.' . $name;
            $claims = is_string($name) ? $this->claimsInFieldName($name) : [];

            if (is_array($value)) {
                $findings = array_merge($findings, $this->payloadFindings(label: $label, payload: $value, path: $here));
            } elseif (is_string($value)) {
                $claims = array_values(array_unique(array_merge($claims, $this->claimsIn($value))));
            }

            if ($claims !== []) {
                $findings[] = ['source' => self::PAYLOAD, 'file' => $label, 'line' => null, 'key' => $here, 'text' => is_string($value) ? $value : '(a field name)', 'claims' => $claims, 'keys' => []];
            }
        }

        return $findings;
    }

    /** @return list<int> where the pattern matches in the subject; a pattern that cannot be run (a backtrack or JIT limit) is an error, because "no match" would hide a claim */
    private function offsetsOf(string $pattern, string $subject): array
    {
        if (preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('The claim patterns could not be run over a text of ' . strlen($subject) . ' bytes: ' . preg_last_error_msg());
        }

        return array_map(static fn (array $match): int => $match[1], $matches[0]);
    }

    private function anyClaim(): string
    {
        return $this->anyClaim ??= '~(?:' . implode('|', array_merge(...array_values(self::CLAIMS))) . ')~u';
    }

    /** @return array<string, mixed> */
    private function messagesOf(string $file): array
    {
        $messages = str_ends_with($file, '.json') ? json_decode((string) file_get_contents($file), true) : (static fn (string $path): mixed => require $path)($file);

        if (!is_array($messages)) {
            throw new UnexpectedValueException("The language file {$file} does not hold an array of messages.");
        }

        return $messages;
    }

    /**
     * @param array<array-key, mixed> $messages
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += $this->flatten(messages: $value, prefix: $path);
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param list<string> $directories
     * @param list<string> $suffixes
     * @return list<string> the files whose name ends in one of the suffixes, once each, sorted
     */
    private function filesUnder(array $directories, array $suffixes): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                foreach ($suffixes as $suffix) {
                    if (str_ends_with($file->getFilename(), $suffix)) {
                        $files[(string) realpath($file->getPathname())] = true;
                    }
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    private function relative(string $file, string $repository): string
    {
        $root = rtrim((string) realpath($repository), '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
