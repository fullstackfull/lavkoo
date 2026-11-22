<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Config;

/**
 * Who a consent decision is about, named in a way that may safely travel in an e-mail.
 *
 * A subject is a KEYED HASH of "customer:<id>" or "email:<address>", so:
 *
 *  - nothing in an unsubscribe link is an account id or an address, so nothing in it can be read off the wire, out of a
 *    web-server log or out of a forwarded message;
 *  - the hash cannot be produced without the application key, so a visitor cannot write down the hash of somebody else
 *    even when they know that person's e-mail address.
 *
 * It is a hash of an identity rather than a random token kept in a table on purpose: a reminder is sent before anybody
 * has agreed to anything, so there may be no row yet to hang a token on, and a person who never answers must still be
 * able to say no.
 *
 * THE LIMIT, stated rather than hidden: the hash is keyed with the application key, so rotating APP_KEY orphans the
 * rows of subjects who have no account id on the row (a newsletter address). A customer's own rows carry customer_id
 * and are found without the hash.
 */
final class MarketingSubject
{
    public static function forCustomer(int $customerId): string
    {
        return self::hash('customer:' . $customerId);
    }

    public static function forEmail(string $email): string
    {
        return self::hash('email:' . mb_strtolower(trim($email)));
    }

    private static function hash(string $identity): string
    {
        return hash_hmac('sha256', $identity, (string) Config::get('app.key'));
    }
}
