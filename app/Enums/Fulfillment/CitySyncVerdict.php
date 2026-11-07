<?php

namespace App\Enums\Fulfillment;

/** How a refresh of a provider's city list ended. Only `Full` says the cached list is the provider's whole list. */
enum CitySyncVerdict: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Failed = 'failed';
}
