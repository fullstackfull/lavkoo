<?php

namespace App\Enums\Fulfillment;

/** What happened to an inbound provider event. Every event is stored, whether or not it changed anything. */
final class EventOutcome
{
    public const RECEIVED = 'received';
    public const APPLIED = 'applied';
    public const INFORMATIONAL = 'informational';
    public const IGNORED_DUPLICATE = 'ignored_duplicate';
    public const IGNORED_STALE = 'ignored_stale';
    public const IGNORED_TERMINAL = 'ignored_terminal';
    public const IGNORED_UNMATCHED = 'ignored_unmatched';
    public const IGNORED_UNMAPPED = 'ignored_unmapped';
    public const REJECTED_UNVERIFIED = 'rejected_unverified';
    public const FAILED = 'failed';
}
