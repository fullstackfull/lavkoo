<?php

namespace App\Services\Address;

use RuntimeException;

/** Saudi Post's lookup service refused, failed, or answered with something that cannot be read as regions and cities. Its message never holds the API key. */
class SplLookupsException extends RuntimeException
{
}
