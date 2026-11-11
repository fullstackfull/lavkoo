<?php

namespace App\Services\Accounting\Odoo;

use RuntimeException;

/**
 * This accounting connection cannot be used yet, and nothing was sent.
 *
 * Its own exception rather than a channel refusal, because none of these is a switch somebody turned off or an approval somebody
 * withheld: they are things not yet known about the customer's Odoo. `reason` is a stable code the posting queue records and a
 * screen shows, never the server's text.
 */
class OdooNotConfigured extends RuntimeException
{
    /** No host on the connection: nobody to ask. */
    public const NO_HOST = 'odoo_connection_has_no_host';

    /** Which of Odoo's two APIs this instance speaks has not been read from it, and one is never guessed. */
    public const TRANSPORT_NOT_DISCOVERED = 'odoo_transport_not_discovered';

    /** XML-RPC needs the database and the user id its sign-in answers; neither is on this connection. */
    public const NOT_SIGNED_IN = 'odoo_connection_is_not_signed_in';

    /** This instance speaks JSON-2, and how this call is written in that API has not been read in a form this repository counts as documented. */
    public const JSON2_REQUEST_NOT_DOCUMENTED = 'odoo_json2_request_shape_not_documented';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
