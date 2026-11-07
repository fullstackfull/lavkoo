<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;

/** Says why a command did nothing: in a line, or as JSON when the command was asked for JSON, so that whatever reads its output can parse it. */
final class Refusal
{
    public static function say(Command $command, string $message, bool $error = false): void
    {
        if ($command->getDefinition()->hasOption('json') && $command->option('json')) {
            $command->line((string) json_encode(['available' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $error ? $command->error($message) : $command->line($message);
    }
}
