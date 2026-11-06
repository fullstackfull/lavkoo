<?php

namespace App\Enums\Fulfillment;

/** A provider mapping only ever routes an order once an administrator (or the provider's own import) approved it. */
enum MappingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Retired = 'retired';
}
