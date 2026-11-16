<?php

namespace App\Utils;

use RuntimeException;

/**
 * One variable of the .env file. The file is read, changed line by line and written back, and two writes that overlap (two admin tabs, a double
 * click, the licence form's five calls beside a settings save) used to truncate each other's file: APP_KEY and DB_PASSWORD gone. The change is
 * one critical section on a file that is locked and replaced in one step (see AtomicFile), and the value cannot add a line, end its own string
 * or be expanded when the file is read: control characters are dropped, and a quote, a backslash and a dollar sign are escaped inside double
 * quotes.
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

    private static function withVariable(string $content, string $key, string $formattedValue): string
    {
        $replacement = "{$key}={$formattedValue}";
        $keyFound = false;
        $newLines = [];

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=/i', trim($line)) === 1) {
                $newLines[] = $replacement;
                $keyFound = true;
            } else {
                $newLines[] = $line;
            }
        }
        if (!$keyFound) {
            $newLines[] = $replacement;
        }

        return implode(PHP_EOL, $newLines) . PHP_EOL;
    }

    private static function format(mixed $value): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);

        return preg_match('/[^a-zA-Z0-9]/', $text) === 1 ? '"' . addcslashes($text, '"\\$') . '"' : $text;
    }
}
