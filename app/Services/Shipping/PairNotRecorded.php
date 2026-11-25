<?php

namespace App\Services\Shipping;

use RuntimeException;

/** A cross-border pair was not opened or closed, and the message says why (H1-01). Nothing was written and nothing was audited. */
class PairNotRecorded extends RuntimeException
{
}
