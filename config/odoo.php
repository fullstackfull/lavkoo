<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Odoo accounting — its own switches, every one of them off
    |--------------------------------------------------------------------------
    |
    | Odoo is NOT a sales channel. It does not appear in `SalesChannel`, it is
    | never a source of sales or orders, and it has its own switches on purpose:
    | turning Amazon or noon on must not, by itself, permit a single line to be
    | written into somebody's accounts.
    |
    | The shared protection engine is still what enforces these -- the operation
    | allow-list, the failure classification, the rehearsal mode, the redacted
    | logs, the recorded approvals and the audit trail all come from
    | `ChannelOperations` and `ChannelHttp`. What is separate is the POLICY: an
    | operation says which switch family governs it, and Odoo's family is this
    | file.
    |
    | They are four rather than one because they guard four different-sized
    | mistakes, and each is off as shipped.
    |
    */

    // Nothing at all. With this off not even a version read leaves: no connection test, no chart of accounts, nothing.
    'enabled' => filter_var(env('ODOO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Reads are allowed, writes are not. A person can connect an instance, run the preflight, read its accounts and taxes and
    // build the whole mapping with this off -- which is the only order in which that work is safe to do.
    'external_writes_enabled' => filter_var(env('ODOO_EXTERNAL_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Creating DRAFT journal entries. Separate from the switch above because a draft is still a row in somebody's ledger: it is
    // visible to their accountant, it carries our reference, and it is not nothing.
    'draft_creation_enabled' => filter_var(env('ODOO_DRAFT_CREATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Moving a draft to POSTED: the irreversible step. Deliberately its own switch, and deliberately not implied by the one
    // above -- creating a draft and committing it to the books are different decisions, and only the second cannot be undone.
    // Nothing in this version acts on it: Odoo documents no posting method, so it stays a declared gap with its switch already
    // in place (see docs/sales-channels/odoo-accounting.md).
    'final_posting_enabled' => filter_var(env('ODOO_FINAL_POSTING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | The connection
    |--------------------------------------------------------------------------
    |
    | Odoo's external API is documented as available only on Custom pricing
    | plans, which is a fact about the customer's subscription that no setting
    | here can change. The host, database and key belong to the accounting
    | connection and are kept apart from any marketing account's credentials.
    |
    */

    'connect_timeout' => (int) env('ODOO_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('ODOO_TIMEOUT', 30),

    // How many times a call that is SAFE to repeat is retried. A write is never retried by the transport whatever this says --
    // `ChannelOperations` marks it unsafe -- because repeating a journal entry is a question only the posting queue can answer,
    // after asking Odoo what it already holds.
    'retries' => (int) env('ODOO_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | The outbox
    |--------------------------------------------------------------------------
    |
    | One row per fact per version, claimed before it is worked. These numbers
    | govern how long a dead run holds a row and how often a refusing instance
    | is asked again. They do NOT govern the one case that matters most: a
    | create whose outcome nobody knows waits for a person, however many
    | attempts are left.
    |
    */
    'outbox' => [
        // A run that claimed a row and never finished (a crash) gives it up after this long.
        'claim_minutes' => 15,
        // How many times a row that failed for a reason worth retrying is tried before it is laid to rest.
        'attempts' => 5,
        // The first wait after a failure; each later try waits a multiple of it.
        'retry_seconds' => 300,
        // How long a row that is waiting on ANOTHER row waits before looking again -- a reversal whose original entry is not in
        // Odoo yet. It spends no attempt, so this is the whole of its pacing.
        'wait_seconds' => 600,
    ],

    // Per-operation ceiling, in the shape the shared limiter reads.
    'rate_limits' => [
        'default' => ['max' => (int) env('ODOO_RATE_LIMIT_MAX', 60), 'per_seconds' => (int) env('ODOO_RATE_LIMIT_SECONDS', 60)],
    ],

];
