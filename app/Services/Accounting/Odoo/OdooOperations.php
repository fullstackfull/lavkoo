<?php

namespace App\Services\Accounting\Odoo;

use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;

/**
 * Every call the Odoo client may make, on the same allow-list every other integration here uses.
 *
 * Odoo is not a sales channel, and registering it here is a deliberate choice rather than an accident of structure. What it buys
 * is the gating this posting needs more than any channel call does: the host switch, the account switch, the rehearsal mode, the
 * recorded approval for a write, the redacted audit log, and `ChannelResponse`'s classification of what a failure proves --
 * which is what tells "Odoo refused this" from "nobody knows whether Odoo got it". Writing a second, parallel set of switches
 * for the one integration that touches somebody's books would have been the wrong kind of independence.
 *
 * ## Two transports, one allow-list
 *
 * The paths differ by transport and both are registered, because which one is used is DISCOVERED from the server's version and
 * not decided here (see `OdooTransport`):
 *
 *  - XML-RPC (Odoo 18.0 and earlier): `/xmlrpc/2/common` for the version and the sign-in, `/xmlrpc/2/object` for everything
 *    else through `execute_kw`. One path serves every model call, so the operation name says what the call IS while the path
 *    stays the same.
 *  - JSON-2 (Odoo 19.0 and later): `POST /json/2/<model>/<method>`, so the path is built per call from a model and a method that
 *    this file pins.
 *
 * **EVERY ONE OF THESE IS A POST, INCLUDING THE READS.** That is Odoo's design, not a liberty taken here: `execute_kw` is a POST
 * whether it searches or creates, and JSON-2 posts to a method path. The reads are registered as READS all the same, because a
 * `search_read` changes nothing and making it need a write approval would mean a person could not even look at the chart of
 * accounts without permission to post into it. `ChannelHttpTest` holds the list of non-GET reads and names each one.
 */
final class OdooOperations
{
    /** XML-RPC's two endpoints. One for the meta-calls that need no session, one for every model call. */
    public const PATH_COMMON = '/xmlrpc/2/common';

    public const PATH_OBJECT = '/xmlrpc/2/object';

    /** JSON-2's version endpoint, which replaced XML-RPC's `version()` meta-call. */
    public const PATH_WEB_VERSION = '/web/version';

    /** JSON-2 builds its path from the model and method; this is the prefix the documentation gives. */
    public const PATH_JSON2_PREFIX = '/json/2/';

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // Reading the books is reconciliation work; a person with only that capability may look and may not post.
        $read = [Capability::FinancialReconciliation];
        $probe = [Capability::ConnectionTest, Capability::FinancialReconciliation];

        return [
            // ---- is anybody there, and who are we ---------------------------------------------------------------
            // `version()` on XML-RPC needs no authentication at all, which is what lets the transport be chosen before a
            // credential is used. JSON-2's `GET /web/version` replaces it; whether THAT needs authentication is not stated in
            // Odoo's documentation, so the client is written to cope either way.
            'odoo.version' => Ops::read(['GET', 'POST'], capabilities: $probe, switches: Ops::SWITCHES_ODOO),
            'odoo.authenticate' => Ops::auth(['POST'], switches: Ops::SWITCHES_ODOO),

            // ---- reading what the instance holds ----------------------------------------------------------------
            // `fields_get` and `ir.model` are the documented introspection, and the only way the journal-entry field names are
            // known at all: Odoo's reference documents no page for `account.move`.
            'odoo.fields_get' => Ops::read(['POST'], capabilities: $read, switches: Ops::SWITCHES_ODOO),
            'odoo.model_list' => Ops::read(['POST'], capabilities: $read, switches: Ops::SWITCHES_ODOO),
            // The chart of accounts, the taxes, the journals, the companies and the currencies a mapping is built from.
            'odoo.search_read' => Ops::read(['POST'], capabilities: $read, switches: Ops::SWITCHES_ODOO),
            'odoo.search_count' => Ops::read(['POST'], capabilities: $read, switches: Ops::SWITCHES_ODOO),
            // THE call that asks whether a posting already landed. It is a read, and it is the one read this integration cannot
            // do without: it is how a retry after an unreadable answer avoids posting the same cost twice.
            'odoo.search_entry_by_reference' => Ops::read(['POST'], capabilities: $read, switches: Ops::SWITCHES_ODOO),

            // ---- writing ----------------------------------------------------------------------------------------
            // Creating the DRAFT journal entry. Behind `accounting_posting`, which a master admin records, because this is the
            // one operation in this application that writes into somebody else's ledger.
            'odoo.create_draft_entry' => Ops::write(['POST'], $read, [Ops::SCOPE_ACCOUNTING_POSTING], switches: Ops::SWITCHES_ODOO),
        ];
    }

    /**
     * Operations Odoo offers that are deliberately NOT registered, and why.
     *
     * Named rather than left unsaid, so that "not registered" reads as a decision somebody made and never as "Odoo has no such
     * call". The first is the important one.
     *
     * @return array<string, string>
     */
    public static function deliberatelyNotRegistered(): array
    {
        return [
            // The method that moves an entry from draft to posted. Odoo documents NEITHER a method name nor writing `state`
            // directly, and this is the irreversible step in somebody's accounts. It stays out until the owner's policy and a
            // real instance settle it together -- which is also what the architectural constraint asks for.
            'odoo.post_entry' => 'odoo_documents_no_posting_method_and_posting_needs_the_owners_approval',
            // Changing or deleting an entry. A posted figure is corrected by a REVERSAL, never by an edit: an edited final entry
            // leaves no trace that it was ever different.
            'odoo.write_entry' => 'a_posted_entry_is_corrected_by_reversal_not_by_an_edit',
            'odoo.unlink_entry' => 'a_posted_entry_is_never_deleted',
            // Creating accounts, taxes or journals. What is already in the customer's Odoo is matched, never created twice, and
            // their chart of accounts is not this integration's to invent.
            'odoo.create_account' => 'the_customers_chart_of_accounts_is_matched_never_created_here',
            'odoo.create_tax' => 'the_customers_taxes_are_matched_never_created_here',
            'odoo.create_journal' => 'the_customers_journals_are_matched_never_created_here',
        ];
    }

    /** The JSON-2 path for one model and method, built rather than stored because the pair is what identifies the call. */
    public static function json2Path(string $model, string $method): string
    {
        return self::PATH_JSON2_PREFIX . $model . '/' . $method;
    }
}
