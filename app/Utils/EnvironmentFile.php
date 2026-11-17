<?php

namespace App\Utils;

use RuntimeException;

/**
 * One variable of the .env file. The file is read, the lines of that variable are replaced and the rest is written back as it was, and two writes
 * that overlap (two admin tabs, a double click, the licence form's five calls beside a settings save) used to truncate each other's file:
 * APP_KEY and DB_PASSWORD gone. The change is one critical section on a file that is locked and replaced in one step (see AtomicFile), and the
 * value cannot add a line, end its own string or be expanded when the file is read: control characters are dropped, and a quote, a backslash
 * and a dollar sign are escaped inside double quotes.
 *
 * A file that cannot be opened, locked, read or written is an error, never a success: the screens that call this tell the admin whether the
 * setting was saved, and a hardened install keeps the file read-only.
 */
final class EnvironmentFile
{
    /**
     * Sets a variable, adds the line when the file has none, and returns the value as it is written.
     *
     * @throws RuntimeException when the file does not exist or cannot be opened, locked, read or written
     */
    public static function set(string $path, string $key, mixed $value): string
    {
        $formattedValue = self::format(value: $value);
        AtomicFile::rewrite(path: $path, change: fn (string $content): string => self::withVariable(content: $content, key: $key, formattedValue: $formattedValue));

        return $formattedValue;
    }

    /**
     * The file with the variable set and every other line as it was, byte for byte: blank lines, comments, line breaks and the lines of a value
     * that goes on over several lines.
     */
    private static function withVariable(string $content, string $key, string $formattedValue): string
    {
        $lineBreak = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $variable = "{$key}={$formattedValue}{$lineBreak}";
        $namesTheKey = '/^' . preg_quote($key, '/') . '\s*=/i';
        $keyFound = false;
        $updated = '';

        foreach (self::entries(content: $content) as $entry) {
            if (preg_match($namesTheKey, trim($entry)) === 1) {
                $updated .= $variable;
                $keyFound = true;
            } else {
                $updated .= $entry;
            }
        }
        if (!$keyFound) {
            $updated .= ($updated === '' || str_ends_with($updated, "\n") ? '' : $lineBreak) . $variable;
        }

        return $updated;
    }

    /**
     * The lines of the file with their line breaks, where the lines of a value in double quotes that goes on over several lines are one entry, as
     * the parser that reads the file takes them (Dotenv\Parser\Lines): a line inside such a value may be blank, or start with the name of a
     * variable, and is no variable of its own.
     *
     * @return list<string>
     */
    private static function entries(string $content): array
    {
        $entries = [];
        $entry = '';
        $isInsideAValue = false;

        foreach (preg_split('/(?<=\n)/', $content, flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $text = rtrim($line, "\r\n");
            $startsAValue = !$isInsideAValue && self::startsAValueOverSeveralLines(line: $text);
            $isInsideAValue = ($isInsideAValue || $startsAValue) && !self::endsAValueOverSeveralLines(line: $text, startedOnThisLine: $startsAValue);
            $entry .= $line;
            if (!$isInsideAValue) {
                $entries[] = $entry;
                $entry = '';
            }
        }

        return $entry === '' ? $entries : [...$entries, $entry];
    }

    private static function startsAValueOverSeveralLines(string $line): bool
    {
        return str_contains($line, '="') && !self::endsAValueOverSeveralLines(line: $line, startedOnThisLine: true);
    }

    /** A quote that no backslash escapes closes the value; the quote that opened it is not one, so a line that opens a value needs a second. */
    private static function endsAValueOverSeveralLines(string $line, bool $startedOnThisLine): bool
    {
        if ($line === '"') {
            return true;
        }
        $quotes = (int) preg_match_all('/(?=([^\\\\]"))/', str_replace('\\\\', '', $line));

        return $startedOnThisLine ? $quotes > 1 : $quotes >= 1;
    }

    private static function format(mixed $value): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);

        return preg_match('/[^a-zA-Z0-9]/', $text) === 1 ? '"' . addcslashes($text, '"\\$') . '"' : $text;
    }
}
