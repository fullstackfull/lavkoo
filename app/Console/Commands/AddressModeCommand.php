<?php

namespace App\Console\Commands;

use App\Services\Address\AddressModeGate;
use App\Services\Address\AddressSchema;
use Illuminate\Console\Command;

class AddressModeCommand extends Command
{
    protected $signature = 'address:mode {mode : off, soft or strict}';

    protected $description = 'Set the unified address mode when the admin panel cannot be used (off is always allowed; soft and strict only when the store is ready) and drop the settings cache so it takes effect at once';

    public function handle(AddressModeGate $gate): int
    {
        $mode = (string) $this->argument('mode');
        if (!in_array($mode, AddressSchema::MODES, true)) {
            $this->error(sprintf('Unknown mode "%s": use %s.', $mode, implode(', ', AddressSchema::MODES)));

            return self::INVALID;
        }

        // Business settings are cached for hours: the gate must compare with what the database says, not with what was cached.
        clearWebConfigCacheKeys();
        AddressSchema::flush();

        $problems = $gate->switchTo($mode);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error(translate($problem));
            }

            return self::FAILURE;
        }

        $this->line(sprintf('Address mode: %s configured, %s in force.', AddressSchema::configuredMode(), AddressSchema::mode()));

        return self::SUCCESS;
    }
}
