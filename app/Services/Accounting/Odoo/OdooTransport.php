<?php

namespace App\Services\Accounting\Odoo;

/**
 * Which of Odoo's two external APIs an instance is spoken to with — DISCOVERED, never assumed.
 *
 * Odoo has changed this twice and is changing it again, so the choice cannot be a setting somebody guesses:
 *
 *  - **JSON-2** (`POST /json/2/<model>/<method>`, `Authorization: bearer <api key>`) is documented as NEW IN 19.0. Asking a 17.0
 *    or 18.0 server for it would 404, and the 17.0 and 18.0 reference pages do not mention it at all.
 *  - **XML-RPC** (`/xmlrpc/2/common` and `/xmlrpc/2/object`, through `execute_kw`) is what the 17.0 and 18.0 pages document, and
 *    it still answers on later ones — but master's own pages call both XML-RPC and JSON-RPC **deprecated**, with the `db` service
 *    removed in Odoo 20 and `common` and `object` removed in Odoo 22.
 *
 * So the version is read first, from `version()` on `/xmlrpc/2/common`, which needs NO authentication — and the transport follows
 * from the answer rather than from anybody's belief about the customer's instance.
 */
enum OdooTransport: string
{
    /** `POST /json/2/<model>/<method>`. Odoo 19.0 and later; the forward path, and the only one not deprecated. */
    case Json2 = 'json2';

    /** `execute_kw` on `/xmlrpc/2/object`. Odoo 18.0 and earlier; deprecated upstream, and the only option on those versions. */
    case XmlRpc = 'xmlrpc';

    /** The version could not be read, so NEITHER is chosen: a transport picked blind would fail in a way nobody could read. */
    case Unknown = 'unknown';

    /**
     * The transport a server of this major version is spoken to with.
     *
     * 19 is the boundary and Odoo's own wording sets it: the JSON-2 page says "New in version 19.0". Below that the
     * page does not merely omit JSON-2 — it documents XML-RPC as the external API — so this is not a guess at a gap.
     */
    public static function forMajorVersion(?int $major): self
    {
        return match (true) {
            $major === null || $major < 1 => self::Unknown,
            $major >= 19 => self::Json2,
            default => self::XmlRpc,
        };
    }

    /** Is this an API Odoo still supports, or one it has announced the removal of? Shown to a person, never acted on silently. */
    public function isDeprecatedUpstream(): bool
    {
        return $this === self::XmlRpc;
    }

    public function labelKey(): string
    {
        return 'odoo_transport_' . $this->value;
    }
}
