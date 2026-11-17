<?php

namespace App\Utils;

/**
 * CSS cannot run script in a current browser, but it can import another sheet and load addresses. This reads the text of a style sheet, of a
 * `style` attribute or of any other attribute the way a browser reads CSS, and tells which addresses it loads. A pattern over the raw text
 * cannot: `/*` inside a string or inside an unquoted `url(...)` is no comment, a comment that only looks like one hides nothing, a backslash
 * writes keywords (`\75rl(`, `@\69mport`) and a character written with a backslash is never punctuation. So the text is walked once, by the
 * rules of CSS syntax, and what comes out is plain: comments gone, escapes written out (a written parenthesis, quote or space stays out of
 * the punctuation). Parentheses are not counted anywhere; a `url(` is read where it stands.
 */
final class CssScanner
{
    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    private const WHITESPACE = " \t\n";

    private const REPLACEMENT_CHARACTER = "\u{FFFD}";

    /** What a browser strips from both ends of an address: everything up to the space. */
    private const ADDRESS_PADDING = "\0..\x20";

    /** The quotes, the parentheses and the backslash, which the patterns below read as punctuation (as they do a space or a control character). */
    private const PUNCTUATION_CODE_POINTS = [0x22, 0x27, 0x28, 0x29, 0x5C];

    /**
     * A function that loads an address given as a string or taken from an attribute, which the `url(...)` rule would never see; a vendor prefix
     * changes nothing.
     */
    private const LOADING_FUNCTIONS = '/(?<![A-Za-z0-9_-])(?:-[a-z]+-)?(?:image-set|cross-fade|element|image|src|attr)\(/i';

    /** What means something in a style sheet or a `style` attribute only: an import, and the script hooks of old browsers. */
    private const STYLE_ONLY = '/@import|expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:/i';

    private const URL_CALL = '/(?<![A-Za-z0-9_-])url\(/i';

    private const URL_ARGUMENT = '/\Gurl\([ \t\n]*(?:"([^"]*)"|\'([^\']*)\'|([^"\'()\s]*))[ \t\n]*\)/i';

    private string $plain = '';

    private int $position = 0;

    private readonly int $length;

    private function __construct(private readonly string $css)
    {
        $this->length = strlen($css);
    }

    /** The text with its comments removed (each one leaves a space, so that it still separates what it sat between) and its escapes written out. */
    public static function plainText(string $css): string
    {
        $scanner = new self(css: str_replace(["\r\n", "\r", "\f", "\0"], ["\n", "\n", "\n", self::REPLACEMENT_CHARACTER], $css));
        $scanner->scan();

        return $scanner->plain;
    }

    /**
     * The addresses of the `url(...)` calls of a style sheet or of a `style` attribute, or null when it cannot be told what the text loads: it
     * imports, it hooks script, it has a function that loads an address from a string, or a `url(` is not a closed call of one address.
     *
     * @return list<string>|null
     */
    public static function addressesOfStyle(string $css): ?array
    {
        $plainText = self::plainText(css: $css);

        return preg_match(self::STYLE_ONLY, $plainText) === 0 ? self::addressesIn(plainText: $plainText) : null;
    }

    /**
     * The same for the value of any other attribute (a paint, a filter, a title): only what loads an address counts, `Behavior: normal` is
     * words.
     *
     * @return list<string>|null
     */
    public static function addressesOfValue(string $value): ?array
    {
        return self::addressesIn(plainText: self::plainText(css: $value));
    }

    /** @return list<string>|null */
    private static function addressesIn(string $plainText): ?array
    {
        if (preg_match(self::LOADING_FUNCTIONS, $plainText) !== 0) {
            return null;
        }

        $addresses = [];
        $offset = 0;
        while (($found = preg_match(self::URL_CALL, $plainText, $call, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $offset = $call[0][1];
            if (preg_match(self::URL_ARGUMENT, $plainText, $argument, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                return null;
            }
            $addresses[] = trim($argument[1] ?? $argument[2] ?? $argument[3], self::ADDRESS_PADDING);
            $offset++;
        }

        return $found === 0 ? $addresses : null;
    }

    private function scan(): void
    {
        while ($this->position < $this->length) {
            $character = $this->css[$this->position];

            if ($character === '/' && ($this->css[$this->position + 1] ?? '') === '*') {
                $this->skipComment();
            } elseif ($character === '"' || $character === "'") {
                $this->readString(quote: $character);
            } elseif ($character === '\\') {
                $this->readBackslash();
            } elseif ($character === '(' && $this->endsWithUrlName() && !$this->startsWithString()) {
                $this->readUrlToken();
            } else {
                $this->copyOrdinaryText();
            }
        }
    }

    private function skipComment(): void
    {
        $end = strpos($this->css, '*/', $this->position + 2);
        $this->position = $end === false ? $this->length : $end + 2;
        $this->plain .= ' ';
    }

    /** A string ends at its quote or at a line break, which stays; a comment marker in it is text, a backslash before a line break joins lines. */
    private function readString(string $quote): void
    {
        $this->plain .= $quote;
        $this->position++;

        while ($this->position < $this->length) {
            $this->copy(length: strcspn($this->css, $quote . "\\\n", $this->position));
            if ($this->position >= $this->length) {
                return;
            }

            $character = $this->css[$this->position];
            if ($character === $quote) {
                $this->plain .= $quote;
                $this->position++;

                return;
            }
            if ($character === "\n") {
                return;
            }

            $this->readBackslashInString();
        }
    }

    private function readBackslashInString(): void
    {
        $next = $this->css[$this->position + 1] ?? null;

        if ($next === null) {
            $this->position++;
        } elseif ($next === "\n") {
            $this->position += 2;
        } else {
            $this->plain .= $this->decodeEscape();
        }
    }

    /** A backslash before anything but a line break (or the end) writes that character; before a line break it is a character of its own. */
    private function readBackslash(): void
    {
        if (($this->css[$this->position + 1] ?? "\n") === "\n") {
            $this->plain .= '\\';
            $this->position++;
        } else {
            $this->plain .= $this->decodeEscape();
        }
    }

    /**
     * An unquoted `url(...)` is one token up to the first closing parenthesis that no backslash protects, whether or not it is well formed:
     * inside it a comment marker, a quote and a parenthesis are text.
     */
    private function readUrlToken(): void
    {
        $this->plain .= '(';
        $this->position++;

        while ($this->position < $this->length) {
            $this->copy(length: strcspn($this->css, ")\\", $this->position));
            if ($this->position >= $this->length) {
                return;
            }

            if ($this->css[$this->position] === ')') {
                $this->plain .= ')';
                $this->position++;

                return;
            }

            $this->readBackslash();
        }
    }

    /** What has been read ends with the name `url` and not with the tail of a longer name (`curl`, `my-url`, `9url`). */
    private function endsWithUrlName(): bool
    {
        $length = strlen($this->plain);

        return $length >= 3
            && strcasecmp(substr($this->plain, -3), 'url') === 0
            && ($length === 3 || preg_match('/[A-Za-z0-9_-]/', $this->plain[$length - 4]) === 0);
    }

    /** After the parenthesis, a quote comes first (spaces apart): the function holds a string, whose tokens are read one by one. */
    private function startsWithString(): bool
    {
        $next = $this->position + 1 + strspn($this->css, self::WHITESPACE, $this->position + 1);

        return in_array($this->css[$next] ?? '', ['"', "'"], true);
    }

    private function copyOrdinaryText(): void
    {
        $this->copy(length: max(1, strcspn($this->css, "/\"'\\(", $this->position)));
    }

    private function copy(int $length): void
    {
        $this->plain .= substr($this->css, $this->position, $length);
        $this->position += $length;
    }

    /** At a backslash that is followed by a character: one to six hex digits and the one whitespace that may close them, or the character itself. */
    private function decodeEscape(): string
    {
        $start = $this->position + 1;
        $digits = strspn($this->css, self::HEX_DIGITS, $start, 6);

        if ($digits === 0) {
            $this->position = $start + 1;
            $byte = $this->css[$start];

            return ord($byte) < 0x80 ? self::written(codePoint: ord($byte)) : $byte;
        }

        $this->position = $start + $digits;
        if (strspn($this->css, self::WHITESPACE, $this->position, 1) === 1) {
            $this->position++;
        }

        return self::written(codePoint: (int)hexdec(substr($this->css, $start, $digits)));
    }

    /**
     * What a backslash writes is a character of a name or of an address and never punctuation: `url(\29 ../x)` is an address that starts with a
     * parenthesis and not an empty one, and `url(\22 #a\22 )` is not a quoted fragment. A character that would read as punctuation, a space or
     * a control character, one that does not exist (nul, a surrogate, a value above the last character) is written as the replacement character.
     */
    private static function written(int $codePoint): string
    {
        $exists = $codePoint <= 0x10FFFF && ($codePoint < 0xD800 || $codePoint > 0xDFFF);
        $isPlain = $exists && $codePoint > 0x20 && !in_array($codePoint, self::PUNCTUATION_CODE_POINTS, true);

        return $isPlain ? mb_chr($codePoint, 'UTF-8') : self::REPLACEMENT_CHARACTER;
    }
}
