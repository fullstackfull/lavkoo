<?php

namespace App\Services\Accounting\Odoo;

use RuntimeException;

/**
 * Odoo refused a call and said why.
 *
 * It is its own exception because a fault is NOT the same as a transport failure, and the posting queue has to tell them apart:
 * a fault means the request arrived and Odoo decided against it, so retrying it unchanged will be refused again; a transport
 * failure means nobody knows whether it arrived, which is the case that must never retry itself.
 *
 * `faultString` is Odoo's own text and can carry a Python traceback. It is kept for a person to read and is never parsed for
 * meaning: matching on another system's error prose is how a client starts behaving differently after somebody translates it.
 */
class OdooFault extends RuntimeException
{
    public function __construct(public readonly int $faultCode, public readonly string $faultString)
    {
        parent::__construct('Odoo refused the call (fault ' . $faultCode . ').');
    }
}
