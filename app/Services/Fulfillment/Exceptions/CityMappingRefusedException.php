<?php

namespace App\Services\Fulfillment\Exceptions;

use InvalidArgumentException;

/**
 * A decision about a provider city (approve, reject, retire, alias) was refused. The message is the translation key of what the
 * administrator is told, so the refusal reads in the language of the panel and not in the one the code was written in.
 */
class CityMappingRefusedException extends InvalidArgumentException
{
    public function __construct(public readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }
}
