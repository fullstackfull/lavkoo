<?php

namespace App\Listeners\Concerns;

/**
 * Queue behaviour shared by every listener that sends mail.
 *
 * Without these, a listener inherits the worker defaults (one attempt, 60-second
 * timeout). That combination is a poor fit for SMTP: an unreachable host burns a
 * full minute of worker time before Laravel kills the job, and a genuinely
 * transient blip is never retried.
 *
 * The values chosen:
 *   timeout 30  — a mail host that has not answered in 30 seconds is not going to.
 *                 Releases the worker at half the default.
 *   tries 2     — one retry, because SMTP failures are often transient.
 *   backoff 60  — a minute between attempts, so a host that is down is not hammered.
 *
 * A job that exhausts both attempts lands in failed_jobs. That is the point:
 * EmailTemplateTrait::sendingMail() swallows the send exception, so before these
 * listeners were queued a failed email left no trace anywhere.
 */
trait QueuedMailDelivery
{
    public int $timeout = 30;

    public int $tries = 2;

    public int $backoff = 60;
}
