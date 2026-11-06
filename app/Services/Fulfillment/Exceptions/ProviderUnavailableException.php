<?php

namespace App\Services\Fulfillment\Exceptions;

use RuntimeException;

/** An outbound call was refused locally: integrations are switched off, or not configured. Nothing was sent. */
class ProviderUnavailableException extends RuntimeException
{
}
