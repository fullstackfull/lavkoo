<?php

namespace App\Enums\SalesChannels;

/** What the application does about the fulfilment of one order. */
enum ExecutionPolicy: string
{
    /** The application creates the fulfilment order (once). */
    case Managed = 'managed';
    /** Someone else created, or will run, the fulfilment: the application follows it and counts its costs, and sends nothing. */
    case ExternallyManaged = 'externally_managed';
    /** Nothing happens until a person decides. */
    case ManualReview = 'manual_review';

    public function sendsToProvider(): bool
    {
        return $this === self::Managed;
    }

    public function labelKey(): string
    {
        return 'execution_policy_' . $this->value;
    }
}
