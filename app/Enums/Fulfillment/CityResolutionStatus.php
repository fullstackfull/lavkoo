<?php

namespace App\Enums\Fulfillment;

/** What looking up a provider's city for an order's address found. Only `Resolved` lets the order go on. */
enum CityResolutionStatus: string
{
    case Resolved = 'resolved';
    case Unmapped = 'unmapped';
    case Ambiguous = 'ambiguous';
    case PendingReview = 'pending_review';
}
