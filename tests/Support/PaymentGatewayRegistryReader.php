<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Reads the hand-kept payment-gateway registries out of the files that declare them.
 *
 * The platform keeps the same gateway list in six places at once: the start-route map, which used to
 * be a local variable inside App\Traits\Payment::generate_link and now lives in
 * App\Services\Payments\PaymentGatewayCatalog::startRoutes(), Helpers::getDefaultPaymentGateways(),
 * GlobalConstant::DEFAULT_PAYMENT_GATEWAYS, the currency map in
 * PaymentGatewayTrait::getPaymentGatewaySupportedCurrencies(), the routes the application registers,
 * and app/Traits/Payment.txt — the copy AddonService::deleteAddon writes over Payment.php when the
 * Gateways add-on is deleted. Nothing keeps them in step.
 *
 * The lists are PARSED rather than called, for two reasons. Payment.txt declares the same trait as
 * Payment.php, so it can never be loaded into a process that has already loaded the live one. And a key
 * written twice in an array literal is invisible once PHP has evaluated it — the later entry simply
 * wins — which is one of the mistakes a registry test exists to catch.
 */
final class PaymentGatewayRegistryReader
{
    /** The one place the start routes are declared, since the map was lifted out of Payment::generate_link into a class a test can read. */
    public const LIVE_ROUTE_MAP_FILE = 'app/Services/Payments/PaymentGatewayCatalog.php';

    /** The trait Payment.txt would replace. It no longer declares the map itself -- it reads LIVE_ROUTE_MAP_FILE -- but Payment.txt still does. */
    public const LIVE_TRAIT_FILE = 'app/Traits/Payment.php';
    public const DISCARDED_TRAIT_FILE = 'app/Traits/Payment.txt';
    public const CURRENCY_MAP_FILE = 'app/Traits/PaymentGatewayTrait.php';
    public const HELPERS_FILE = 'app/Utils/Helpers.php';
    public const GLOBAL_CONSTANT_FILE = 'app/Enums/GlobalConstant.php';

    /** @return array<string, string> gateway key => the path Payment::generate_link sends the customer to */
    public static function liveRouteMap(): array
    {
        return self::declaredPairs(file: self::LIVE_ROUTE_MAP_FILE, declaration: 'startRoutes');
    }

    /** @return list<string> in declaration order, a key written twice kept twice */
    public static function liveRouteMapKeys(): array
    {
        return self::declaredKeys(file: self::LIVE_ROUTE_MAP_FILE, declaration: 'startRoutes');
    }

    /** @return array<string, string> the same map inside the copy that would replace the live trait */
    public static function discardedTraitRouteMap(): array
    {
        return self::declaredPairs(file: self::DISCARDED_TRAIT_FILE, declaration: '$routes');
    }

    /** @return list<string> */
    public static function discardedTraitRouteMapKeys(): array
    {
        return self::declaredKeys(file: self::DISCARDED_TRAIT_FILE, declaration: '$routes');
    }

    /** @return list<string> the gateway keys the currency map prices */
    public static function currencyMapKeys(): array
    {
        return self::declaredKeys(file: self::CURRENCY_MAP_FILE, declaration: '$paymentGateway');
    }

    /** @return list<string> */
    public static function helpersDefaultGatewayKeys(): array
    {
        // getDefaultPaymentGateways() no longer keeps a list of its own: it returns the constant, so the two
        // cannot drift. Parsing it anyway walks past its body to the NEXT array literal in the file, which is
        // getDefaultSMSGateways() -- and then every test of "the default gateways" reads twilio, nexmo,
        // 2factor, msg91 and releans and compares SMS gateways against payment gateways.
        if (!self::helpersDeclaresItsOwnGatewayList()) {
            return self::globalConstantDefaultGatewayKeys();
        }

        return self::declaredValues(file: self::HELPERS_FILE, declaration: 'getDefaultPaymentGateways');
    }

    /** Whether Helpers::getDefaultPaymentGateways() keeps a second copy of the list instead of returning GlobalConstant::DEFAULT_PAYMENT_GATEWAYS. */
    public static function helpersDeclaresItsOwnGatewayList(): bool
    {
        foreach (self::bodyTokensOf(file: self::HELPERS_FILE, declaration: 'getDefaultPaymentGateways') as $token) {
            if ((is_array($token) ? $token[1] : $token) === '[') {
                return true;
            }
        }

        return false;
    }

    /**
     * The tokens between the opening and closing brace of a declaration's body, comments and all.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function bodyTokensOf(string $file, string $declaration): array
    {
        $tokens = token_get_all(self::read(file: $file));

        $seenDeclaration = false;
        $depth = 0;
        $body = [];

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (!$seenDeclaration) {
                $seenDeclaration = $text === $declaration;
                continue;
            }

            if ($text === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }

            if ($text === '}' && --$depth === 0) {
                return $body;
            }

            if ($depth >= 1) {
                $body[] = $token;
            }
        }

        throw new RuntimeException("No body follows {$declaration} in {$file}; the registry reader needs updating.");
    }

    /** @return list<string> */
    public static function globalConstantDefaultGatewayKeys(): array
    {
        return self::declaredValues(file: self::GLOBAL_CONSTANT_FILE, declaration: 'DEFAULT_PAYMENT_GATEWAYS');
    }

    /** @return array<string, string> */
    private static function declaredPairs(string $file, string $declaration): array
    {
        $pairs = [];
        foreach (self::entries(file: $file, declaration: $declaration) as $entry) {
            if ($entry['key'] !== null && $entry['value'] !== null) {
                $pairs[$entry['key']] = $entry['value'];
            }
        }

        return $pairs;
    }

    /** @return list<string> */
    private static function declaredKeys(string $file, string $declaration): array
    {
        $keys = [];
        foreach (self::entries(file: $file, declaration: $declaration) as $entry) {
            if ($entry['key'] !== null) {
                $keys[] = $entry['key'];
            }
        }

        return $keys;
    }

    /** @return list<string> the entries of a plain list literal, which have no key of their own */
    private static function declaredValues(string $file, string $declaration): array
    {
        $values = [];
        foreach (self::entries(file: $file, declaration: $declaration) as $entry) {
            if ($entry['key'] === null && $entry['value'] !== null) {
                $values[] = $entry['value'];
            }
        }

        return $values;
    }

    /**
     * Every entry at the top level of the array literal that follows $declaration, in source order and
     * with duplicates kept. `value` is null where the entry's value is itself an array.
     *
     * An entry this reader cannot read is an ERROR, never a silently empty result: a gateway written as
     * a class constant or a concatenation would otherwise drop out of every consistency check and the
     * drift the checks exist to catch would pass unnoticed. Only a plain quoted string, `=>` and `,` are
     * accepted at the top level, so anything else stops the read and names itself.
     *
     * @return list<array{key: ?string, value: ?string}>
     */
    private static function entries(string $file, string $declaration): array
    {
        $tokens = token_get_all(self::read(file: $file));
        $start = self::openingBracketAfter(tokens: $tokens, declaration: $declaration, file: $file);

        $entries = [];
        $pendingKey = null;
        $depth = 0;
        $line = 0;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : $line;

            if ($text === '[') {
                $depth++;
                if ($depth > 1 && $pendingKey !== null) {
                    $entries[] = ['key' => $pendingKey, 'value' => null];
                    $pendingKey = null;
                }
                continue;
            }

            if ($text === ']') {
                if (--$depth === 0) {
                    break;
                }
                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if (!is_array($token)) {
                if ($text !== ',') {
                    throw self::unreadable(file: $file, declaration: $declaration, text: $text, line: $line);
                }

                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_DOUBLE_ARROW], true)) {
                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                throw self::unreadable(file: $file, declaration: $declaration, text: $text, line: $line);
            }

            $literal = substr($text, 1, -1);

            if (self::nextSignificantToken(tokens: $tokens, index: $index) === '=>') {
                $pendingKey = $literal;
                continue;
            }

            $entries[] = ['key' => $pendingKey, 'value' => $literal];
            $pendingKey = null;
        }

        if ($depth !== 0) {
            throw new RuntimeException("The array literal after {$declaration} in {$file} does not close; the registry reader needs updating.");
        }

        return $entries;
    }

    private static function unreadable(string $file, string $declaration, string $text, int $line): RuntimeException
    {
        return new RuntimeException("The array literal after {$declaration} in {$file} holds `{$text}` at line {$line}, which is not a plain quoted string. Every gateway registry is READ from its source, so an entry this reader cannot see would drop out of all the consistency checks instead of failing one: write the entry as a quoted string, or teach " . self::class . " to read it.");
    }

    private static function openingBracketAfter(array $tokens, string $declaration, string $file): int
    {
        $seenDeclaration = false;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (!$seenDeclaration) {
                $seenDeclaration = $text === $declaration;
                continue;
            }

            if ($text === '[') {
                return $index;
            }
        }

        throw new RuntimeException("No array literal follows {$declaration} in {$file}; the registry reader needs updating.");
    }

    private static function nextSignificantToken(array $tokens, int $index): string
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return '';
    }

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $file;
        $source = is_file($path) ? file_get_contents($path) : false;

        if ($source === false) {
            throw new RuntimeException("{$file} is one of the gateway registries and could not be read.");
        }

        return $source;
    }
}
