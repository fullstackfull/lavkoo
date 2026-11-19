<?php

namespace Tests\Support;

/**
 * Reads the platform's own code for the Saudi literals the GCC plan has to account for: the riyal's ISO code, the Saudi
 * dialling code, and the country code "SA" or "sa" written into a payload, a setting default or a comparison.
 *
 * It reads TEXT, not behaviour. A literal it finds may be perfectly correct — the riyal is one row of a list of every
 * world currency, and "sa" is also the ISO code of Sanskrit — so finding one is not a defect. The point is that the set
 * of them is CLOSED: every literal in the code is named in the guard's list, with the GCC item that owns it, and a new
 * one that nobody has accounted for makes the guard fail.
 *
 * Only real literals count. Tokens inside a comment or a docblock are skipped, because prose that mentions +966 is not
 * an assumption the code makes; the same discipline PlatformWriteScanner uses. A number is read as written, so 00966 and
 * '+966' are both found, and an interpolated "…966{$local}" is found too.
 */
final class SaudiLiteralScanner
{
    /** The riyal's ISO 4217 code, matched exactly and case-sensitively: a string that merely contains it is not it. */
    public const RIYAL = 'SAR';

    /** Saudi Arabia's dialling code, matched anywhere in a literal so that 00966 and +966 are both found. */
    public const DIALLING_CODE = '966';

    /** Saudi Arabia's ISO 3166 country code, in the two spellings the code uses, matched exactly. */
    public const COUNTRY_CODES = ['SA', 'sa'];

    /**
     * Every Saudi literal in one file's code.
     *
     * @return list<array{file: string, line: int, literal: string, snippet: string}>
     */
    public function findingsIn(string $code, string $file): array
    {
        $lines = preg_split('/\R/', $code) ?: [];
        $findings = [];

        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG], true)) {
                continue;
            }

            foreach ($this->literalsOf(id: $token[0], text: $token[1]) as $literal) {
                $findings[] = [
                    'file' => $file,
                    'line' => $token[2],
                    'literal' => $literal,
                    'snippet' => trim($lines[$token[2] - 1] ?? ''),
                ];
            }
        }

        return $findings;
    }

    /**
     * Which Saudi literals one token carries. A quoted string is read by its value, so '\x27SAR\x27' and "SAR" are the same
     * finding; a number and the text inside an interpolated string are read as written.
     *
     * @return list<string>
     */
    private function literalsOf(int $id, string $text): array
    {
        $quoted = $id === T_CONSTANT_ENCAPSED_STRING;
        $value = $quoted ? $this->valueOf(text: $text) : $text;
        $readable = $quoted || in_array($id, [T_LNUMBER, T_DNUMBER, T_ENCAPSED_AND_WHITESPACE], true);

        if (!$readable) {
            return [];
        }

        $found = [];
        if ($value === self::RIYAL) {
            $found[] = self::RIYAL;
        }
        if (str_contains($value, self::DIALLING_CODE)) {
            $found[] = self::DIALLING_CODE;
        }
        if (in_array($value, self::COUNTRY_CODES, true)) {
            $found[] = $value;
        }

        return $found;
    }

    /** The value a quoted string holds, without its quotes and without the escaping of the quote it was written with. */
    private function valueOf(string $text): string
    {
        $quote = $text[0] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return $text;
        }

        return str_replace('\\' . $quote, $quote, substr($text, 1, -1));
    }
}
