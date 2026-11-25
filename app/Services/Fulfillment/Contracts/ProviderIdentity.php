<?php

namespace App\Services\Fulfillment\Contracts;

/**
 * Who a carrier IS, apart from how it is talked to (H1-11).
 *
 * The platform shipped with two carriers written into an enum, which made the enum the gate: a carrier for the UAE or Kuwait could not exist until
 * somebody added a case to it, and nothing in routing, dispatch, webhooks or the order summary would accept one that was not there. The requirement is
 * that a carrier be one adapter class plus one configuration entry, so the gate has to be the configuration and not a closed list in code.
 *
 * `ProviderCode` implements this, so every existing call site -- 76 in the application and 751 in the tests -- keeps working untouched and the two
 * carriers this platform ships with keep their enum case. A carrier declared only in `config/fulfillment.php` is a `ConfiguredProvider`, which implements
 * the same contract, and `ProviderRegistry` is what resolves either from a string. Code that works with ANY carrier types against this; code that is about
 * one of the two shipped carriers in particular may still name the enum.
 *
 * Nothing here talks to a carrier: that is `ProviderAdapter`. This is only the identity -- the stable code, its URL form, the short code that goes into a
 * fulfilment reference, and the name a person reads.
 */
interface ProviderIdentity
{
    /** The stable machine code, as it is stored on a product, an order's fulfilment row and in the configuration ('torod_point'). */
    public function code(): string;

    /** The URL form of the code ('torod-point'), which is what a webhook route carries. */
    public function slug(): string;

    /** The short code that goes into a fulfilment reference (order 100234 becomes '100234-TP1'). */
    public function referenceCode(): string;

    /** The name a person reads in the panel. */
    public function label(): string;
}
