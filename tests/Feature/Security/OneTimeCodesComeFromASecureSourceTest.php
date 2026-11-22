<?php

namespace Tests\Feature\Security;

use App\Services\Web\CustomerAuthService;
use Tests\TestCase;

/**
 * A one-time code, a generated password and a checksum salt come from the secure generator (A2-05).
 *
 * `rand()` and `mt_rand()` are the same general-purpose generator: fast, fine for a sample rate or a
 * cart label, and NOT unpredictable. `random_int()` is its cryptographically secure counterpart, with
 * the same signature. A one-time code is a credential for the few minutes it lives, so which of the
 * two produced it is the difference between a secret and a number somebody else can work out.
 *
 * The project already knew the right call: random_int() was in use in five places, including one
 * verification code. Twenty-eight sites used the other one.
 *
 * TWO HALVES, as elsewhere in this suite: the behaviour of the one generator that is reachable as a
 * method, and a structural guard over the whole tree so a site added tomorrow is covered without
 * anyone remembering to come back here.
 */
class OneTimeCodesComeFromASecureSourceTest extends TestCase
{
    /**
     * The files that may still use the general-purpose generator, and what for.
     *
     * None of these is a credential: nobody authenticates with a slug suffix, a walk-in cart label, a
     * sampling decision, an image file name or an administrator's own test SMS. Listed by name so a
     * NEW use has to be looked at rather than slipping in behind them.
     */
    private const NOT_CREDENTIALS = [
        'app/Utils/Helpers.php' => 'a shop slug suffix',
        'app/Traits/InstallationTrail.php' => 'a shop slug suffix at install time',
        'app/Services/CartService.php' => 'the label of a walk-in customer cart',
        'app/Http/Controllers/Admin/POS/POSController.php' => 'the label of a walk-in customer cart',
        'app/Http/Controllers/Vendor/POS/POSController.php' => 'the label of a walk-in customer cart',
        'app/Services/Monitoring/Ingest/RequestRecorder.php' => 'a telemetry sampling decision',
        'app/Services/Monitoring/Ingest/TraceRecorder.php' => 'a telemetry sampling decision',
        'app/Http/Controllers/Admin/ThirdParty/SMSModuleController.php' => "an administrator's own test SMS, which grants nothing",
        'app/Http/Requests/ProductUpdateRequest.php' => 'an image file name',

        /*
         * These three mix the general-purpose generator with Str::random, which Laravel draws from
         * random_bytes. The unpredictable part is already unpredictable, so the rand() digits add
         * length and not strength -- and removing them would change the SHAPE of an identifier that
         * older rows already carry. Left as they are, deliberately, and listed so the next reader does
         * not have to work that out again.
         */
        'app/Utils/order.php' => "an order identifier, alongside Str::random which carries the entropy",
        'app/Utils/OrderManager.php' => "the order group identifier, alongside Str::random which carries the entropy",
        'app/Http/Controllers/Payment_Methods/LiqPayController.php' => "a transaction label, alongside Str::random which carries the entropy",
    ];

    // ---------------------------------------------------------------- behaviour

    public function test_in_live_mode_a_verification_code_is_a_six_digit_number_that_varies(): void
    {
        config(['app.mode' => 'live']);
        $service = app(CustomerAuthService::class);

        $codes = [];
        for ($draw = 0; $draw < 200; $draw++) {
            $code = $service->getCustomerVerificationToken();
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{5}$/', (string) $code, 'a live code is not a six-digit number');
            $codes[(string) $code] = true;
        }

        // 200 draws from 900,000 values: a constant, or a code derived from the second, would collapse
        // this to a handful. The bound is deliberately loose -- the claim is "it varies", not a
        // distribution test.
        $this->assertGreaterThan(150, count($codes), 'a live code repeats far too often to be unpredictable');
    }

    public function test_in_any_other_mode_a_verification_code_is_the_constant(): void
    {
        $service = app(CustomerAuthService::class);

        foreach ([null, 'dev', 'demo', 'DEV', 'Live', 'anything'] as $mode) {
            config(['app.mode' => $mode]);

            $this->assertSame(
                '123456',
                (string) $service->getCustomerVerificationToken(),
                'the code is not the constant in mode ' . var_export($mode, true) . ' -- note that only the exact string "live" is live'
            );
        }
    }

    // ---------------------------------------------------------------- structure

    public function test_no_one_time_code_password_or_salt_is_drawn_from_the_general_purpose_generator(): void
    {
        $offenders = [];

        foreach ($this->bareRandomCalls() as $call) {
            if (array_key_exists($call['file'], self::NOT_CREDENTIALS)) {
                continue;
            }

            $offenders[] = "{$call['file']}:{$call['line']}  {$call['text']}";
        }

        $this->assertSame([], $offenders, "a credential is being drawn from rand() or mt_rand():\n  " . implode("\n  ", $offenders));
    }

    public function test_every_file_allowed_to_use_the_general_purpose_generator_still_does_so_for_the_stated_reason(): void
    {
        // The allow-list is not a place to park things. A file that no longer uses the general-purpose
        // generator is removed from it, so the list cannot grow stale and hide a later credential use.
        $files = array_unique(array_column($this->bareRandomCalls(), 'file'));

        foreach (array_keys(self::NOT_CREDENTIALS) as $allowed) {
            $this->assertContains($allowed, $files, "{$allowed} no longer uses the general-purpose generator and should be taken off the list");
        }
    }

    public function test_every_mode_dependent_code_is_random_when_live_and_a_constant_otherwise(): void
    {
        $sites = [];

        foreach (array_keys($this->sourceFiles()) as $relative) {
            foreach (explode("\n", $this->codeOf($relative)) as $number => $line) {
                if (!str_contains($line, "app.mode")) {
                    continue;
                }
                if (!preg_match('/\?\s*random_int\(|:\s*random_int\(/', $line)) {
                    continue;
                }

                $sites[] = ['file' => $relative, 'line' => $number + 1, 'text' => trim($line)];

                // The live branch draws from the secure generator, and the other branch is a literal.
                $this->assertStringContainsString('random_int(', $line, "{$relative}:" . ($number + 1));
                $this->assertMatchesRegularExpression(
                    '/(\?\s*random_int\([^)]*\)\s*:\s*\d+)|(\?\s*\d+\s*:\s*random_int\()/',
                    $line,
                    "{$relative}:" . ($number + 1) . " is mode-dependent but is not 'secure when live, a constant otherwise':\n    " . trim($line)
                );
            }
        }

        // Measured on this head. A thirteenth site is not a failure in itself -- it is a line that
        // wants reading, which is why the number is asserted rather than left implicit.
        $this->assertCount(12, $sites, 'the number of mode-dependent one-time-code sites moved');
    }

    public function test_the_paytm_checksum_salt_keeps_its_shape(): void
    {
        // The salt generator was seeded from the clock, so a request's salt was a function of the
        // moment it arrived. The fix changed only where the characters come from -- this pins the
        // alphabet and the length so the checksum protocol cannot have moved with it.
        $source = $this->codeOf('app/Http/Controllers/Payment_Methods/PaytmController.php');

        $this->assertStringNotContainsString('srand(', $source, 'the salt is seeded again, which makes it a function of the clock');
        $this->assertStringContainsString('random_int(0, strlen($data) - 1)', $source, 'the salt no longer draws the index securely, or reintroduces the modulo');
        $this->assertStringContainsString('"AbcDE123IJKLMN67QRSTUVWXYZ"', $source, 'the salt alphabet changed');
        $this->assertStringContainsString('$this->generateSalt_e(4)', $source, 'the salt length changed');
    }

    // ---------------------------------------------------------------- the scan

    /**
     * Every bare rand()/mt_rand() CALL in the application tree.
     *
     * Read through PHP's own tokenizer with comments and doc blocks dropped, not with a regular
     * expression over the text. The first version of this scan was a regex, and it flagged a COMMENT
     * of mine that described the old behaviour -- "take characters with rand() % strlen()" -- as a
     * credential drawn from rand(). A guard that cannot tell code from prose reports faults that are
     * not there, and would be silenced by someone deleting the comment rather than the call.
     *
     * Deliberately NOT matching random_int, array_rand, Str::random, srand, ->rand or a `brand`
     * method: the question is the general-purpose number generator, and the tokenizer answers it
     * exactly -- a function name token immediately followed by an opening parenthesis, with no
     * object or class operator before it.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    private function bareRandomCalls(): array
    {
        $calls = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            $tokens = token_get_all($source);

            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                if (!in_array($token[1], ['rand', 'mt_rand'], true)) {
                    continue;
                }

                // A method or a class constant, not the global function.
                $previous = $this->previousMeaningfulToken($tokens, $index);
                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true)) {
                    continue;
                }

                // A call, not a mention: the next thing is an opening parenthesis.
                $next = $this->nextMeaningfulToken($tokens, $index);
                if ($next !== '(') {
                    continue;
                }

                $calls[] = ['file' => $relative, 'line' => $token[2], 'text' => $token[1] . '()'];
            }
        }

        return $calls;
    }

    /** @param array<int, mixed> $tokens */
    private function previousMeaningfulToken(array $tokens, int $index): mixed
    {
        for ($at = $index - 1; $at >= 0; $at--) {
            if (is_array($tokens[$at]) && in_array($tokens[$at][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$at];
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextMeaningfulToken(array $tokens, int $index): mixed
    {
        $count = count($tokens);
        for ($at = $index + 1; $at < $count; $at++) {
            if (is_array($tokens[$at]) && in_array($tokens[$at][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$at];
        }

        return null;
    }

    /** The source of every PHP file in app/ and Modules/, keyed by its path from the project root. */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $sources = [];

        foreach (['app', 'Modules'] as $tree) {
            if (!is_dir($root . '/' . $tree)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $tree));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[str_replace($root . '/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    /** The source of one file with every comment and doc block removed, so prose cannot be read as code. */
    private function codeOf(string $relativePath): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
