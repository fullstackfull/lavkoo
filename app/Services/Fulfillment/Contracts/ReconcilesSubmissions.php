<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;

/**
 * For providers where an ambiguous submission (timeout after the request was sent) can be resolved by
 * asking the provider whether it holds the order. Return `ambiguous` when it cannot be established.
 */
interface ReconcilesSubmissions
{
    public function reconcileSubmission(ProviderFulfillment $fulfillment): SubmissionResult;
}
