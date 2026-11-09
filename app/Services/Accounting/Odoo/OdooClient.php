<?php

namespace App\Services\Accounting\Odoo;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelHttp;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;
use RuntimeException;
use Throwable;

/**
 * One authenticated call to a customer's Odoo, over whichever of its two external APIs that instance actually speaks.
 *
 * Everything that protects a channel call protects these: the operation allow-list, Odoo's own four switches, the rehearsal
 * mode, the rate limit, the redacted log, the audit trail, and `ChannelResponse`'s classification of what a failure proves. The
 * one thing this class adds is the classification Odoo itself needs, which no channel does: **a fault is not a transport
 * failure**. See `OdooAnswer`.
 *
 * ## The transport is discovered, never configured
 *
 * `discover()` reads the version first and the version decides. XML-RPC's `version()` on `/xmlrpc/2/common` needs no
 * authentication, which is what makes that order possible; on an instance where `common` is gone — Odoo's own master pages say
 * it is removed in 22 — `GET /web/version` answers instead. If neither answers, NO transport is chosen and nothing is sent: a
 * transport picked blind fails in a way nobody can read.
 *
 * ## What is NOT done here
 *
 * No entry is built and no entry is posted. This class carries a call and reports honestly what came back; which call to make,
 * whether a reference already exists in Odoo, and what to do about an answer nobody could read are the posting queue's
 * decisions, and they are deliberately not a client's to take.
 */
class OdooClient
{
    public function __construct(
        private readonly ChannelHttp $http,
        private readonly ChannelCredentialStore $credentials,
    ) {
    }

    /**
     * What this instance is, read from it: the version, the transport that follows from it, and whether the credentials work.
     *
     * `reachable` and `authenticated` are separate on purpose. An instance that answers its version but refuses the key is a
     * different problem from one nobody can reach, and a person fixes them in different places.
     */
    public function discover(SalesChannelAccount $account): OdooInstance
    {
        $base = $this->baseUrl($account);
        if ($base === '') {
            return OdooInstance::unreachable('odoo_connection_has_no_host');
        }

        $version = $this->readVersion($account, $base);
        if ($version === null) {
            return OdooInstance::unreachable('odoo_version_could_not_be_read');
        }

        $major = OdooInstance::majorFrom($version);
        $transport = OdooTransport::forMajorVersion($major);
        if ($transport === OdooTransport::Unknown) {
            return new OdooInstance(true, false, $transport, versionInfo: $version, problems: ['odoo_version_is_not_a_number_we_can_read']);
        }

        $serverVersion = trim((string) ($version['server_version'] ?? $version['version'] ?? ''));
        $database = $this->setting($account, 'database');
        $problems = [];
        foreach (['database' => $database, 'login' => $this->setting($account, 'login')] as $name => $value) {
            if ($value === '' && $transport === OdooTransport::XmlRpc) {
                // XML-RPC signs in with database, login and key. JSON-2 needs neither a login nor a user id.
                $problems[] = 'odoo_connection_has_no_' . $name;
            }
        }
        if ($this->apiKey($account) === '') {
            $problems[] = 'odoo_connection_has_no_api_key';
        }
        if ($problems !== []) {
            return new OdooInstance(true, false, $transport, $serverVersion, $major, versionInfo: $version, problems: $problems);
        }

        $userId = $transport === OdooTransport::XmlRpc ? $this->signIn($account, $base, $database) : null;
        $authenticated = $transport === OdooTransport::XmlRpc
            ? $userId !== null
            // JSON-2 publishes no sign-in and no identity endpoint, so there is nothing to ask: the key is either accepted by
            // the next call or it is not. Reporting "authenticated" here would be reporting something nobody checked, so a
            // JSON-2 connection is authenticated by a real read -- `connectionTest()` makes one.
            : $this->keyIsAccepted($account, $base);

        if ($authenticated) {
            $this->credentials->putRuntime($account, 'transport', $transport->value);
            $this->credentials->putRuntime($account, 'server_version', $serverVersion === '' ? null : $serverVersion);
            $this->credentials->putRuntime($account, 'uid', $userId === null ? null : (string) $userId);
        }

        return new OdooInstance(
            true,
            $authenticated,
            $transport,
            $serverVersion === '' ? null : $serverVersion,
            $major,
            $userId,
            $database === '' ? null : $database,
            $version,
            $authenticated ? [] : ['odoo_refused_the_credentials'],
        );
    }

    // ---- the calls, named for what they ask ---------------------------------------------------------------------
    //
    // Each one knows BOTH encodings, because the two transports do not take their arguments the same way: `execute_kw` takes a
    // positional list and a keyword map, and JSON-2 takes named fields only -- its own page says there is no way to call a
    // method positionally. A single generic `call(model, method, args, kwargs)` would have pushed that difference onto every
    // caller, and a caller that gets it wrong writes to the wrong field of somebody's ledger.

    /**
     * Records of one model: Odoo's `search_read`.
     *
     * @param list<mixed> $domain Odoo's own domain syntax, e.g. `[['ref', '=', 'PKSA-COST-12-ab']]`
     * @param list<string> $fields
     */
    public function searchRead(SalesChannelAccount $account, string $model, array $domain, array $fields = [], ?int $limit = null): OdooAnswer
    {
        $kwargs = array_filter(['fields' => $fields, 'limit' => $limit], static fn (mixed $value): bool => $value !== [] && $value !== null);

        return $this->execute($account, 'odoo.search_read', $model, 'search_read', [$domain], $kwargs, json2: ['domain' => $domain] + $kwargs);
    }

    /**
     * How many records match, without reading any of them.
     *
     * @param list<mixed> $domain
     */
    public function searchCount(SalesChannelAccount $account, string $model, array $domain): OdooAnswer
    {
        return $this->execute($account, 'odoo.search_count', $model, 'search_count', [$domain], json2: ['domain' => $domain]);
    }

    /**
     * What fields a model has ON THIS INSTANCE: `fields_get`.
     *
     * THE call this integration cannot do without. Odoo's developer reference has no page for `account.move` or
     * `account.move.line` at all, so the journal-entry field names are not in any documentation to be copied from -- they are
     * asked of the customer's own instance, which is also what survives a field being renamed between versions.
     *
     * Only `string`, `help` and `type` are documented attribute names; anything else is asked for but never relied on.
     *
     * @param list<string> $attributes
     */
    public function fieldsGet(SalesChannelAccount $account, string $model, array $attributes = ['string', 'help', 'type']): OdooAnswer
    {
        return $this->execute($account, 'odoo.fields_get', $model, 'fields_get', [[]], ['attributes' => $attributes], json2: ['attributes' => $attributes]);
    }

    /**
     * Whether an entry carrying one of our references is ALREADY in Odoo.
     *
     * The read that stands in for the idempotency Odoo does not offer. `create` creates every time it is called and there is no
     * external-reference field, no unique key and no upsert, so our own reference is written into `ref` and this is how a retry
     * after an unreadable answer finds out whether the entry exists before writing a second one.
     */
    public function entriesByReference(SalesChannelAccount $account, string $reference): OdooAnswer
    {
        return $this->execute(
            $account,
            'odoo.search_entry_by_reference',
            'account.move',
            'search_read',
            [[['ref', '=', $reference]]],
            ['fields' => ['id', 'ref', 'state', 'name']],
            json2: ['domain' => [['ref', '=', $reference]], 'fields' => ['id', 'ref', 'state', 'name']],
        );
    }

    /**
     * Creates ONE record. The only write this client can make, and the only operation behind the posting approval.
     *
     * It does not post anything: what it creates is a draft, because Odoo documents no method that moves an entry to posted and
     * the final posting is behind its own switch and a recorded approval.
     *
     * @param array<string, mixed> $values
     */
    public function createDraft(SalesChannelAccount $account, string $model, array $values): OdooAnswer
    {
        if (!config('odoo.draft_creation_enabled')) {
            // The switch above the write switch: a draft is still a row the customer's accountant sees.
            throw new ChannelUnavailableException(
                ChannelUnavailableException::WRITES_OFF_ENVIRONMENT,
                'Creating Odoo draft entries is switched off (ODOO_DRAFT_CREATION_ENABLED); nothing was sent.',
            );
        }

        // NO JSON-2 ENCODING, DELIBERATELY. `create`'s arguments cannot be sent positionally on that transport and the page's
        // own example of a `create` body was not readable in a form this repository counts as documentation. So a 19.0 instance
        // refuses here by name rather than being sent a body somebody guessed at -- see `json2NotSettled()`.
        return $this->execute($account, 'odoo.create_draft_entry', $model, 'create', [$values], scope: Ops::SCOPE_ACCOUNTING_POSTING, json2: null);
    }

    /**
     * One model call, in whichever encoding this instance takes.
     *
     * @param list<mixed> $args the positional arguments `execute_kw` takes
     * @param array<string, mixed> $kwargs the keyword arguments both transports take
     */
    private function execute(
        SalesChannelAccount $account,
        string $operation,
        string $model,
        string $method,
        array $args = [],
        array $kwargs = [],
        ?IntegrationCapability $capability = IntegrationCapability::FinancialReconciliation,
        ?string $scope = null,
        ?array $json2 = null,
    ): OdooAnswer {
        $base = $this->baseUrl($account);
        if ($base === '') {
            throw new OdooNotConfigured(OdooNotConfigured::NO_HOST, 'This accounting connection has no Odoo host, so nothing was sent.');
        }

        $transport = $this->transportOf($account);

        return match ($transport) {
            OdooTransport::XmlRpc => $this->callXmlRpc($account, $operation, $base, $model, $method, $args, $kwargs, $capability, $scope),
            OdooTransport::Json2 => $this->callJson2($account, $operation, $base, $model, $method, $json2 ?? throw $this->json2NotSettled($model, $method), $capability, $scope),
            OdooTransport::Unknown => throw new OdooNotConfigured(
                OdooNotConfigured::TRANSPORT_NOT_DISCOVERED,
                'Which of Odoo\'s two APIs this instance speaks has not been discovered yet, and one is never guessed: run the connection test first. Nothing was sent.',
            ),
        };
    }

    /** The transport this connection was DISCOVERED to speak, or Unknown when nobody has looked yet. */
    public function transportOf(SalesChannelAccount $account): OdooTransport
    {
        $stored = (string) ($this->credentials->get($account)->secret('transport') ?? '');

        return OdooTransport::tryFrom($stored) ?? OdooTransport::Unknown;
    }

    // ---- XML-RPC ------------------------------------------------------------------------------------------------

    /**
     * @param list<mixed> $args
     * @param array<string, mixed> $kwargs
     */
    private function callXmlRpc(
        SalesChannelAccount $account,
        string $operation,
        string $base,
        string $model,
        string $method,
        array $args,
        array $kwargs,
        ?IntegrationCapability $capability,
        ?string $scope,
    ): OdooAnswer {
        $database = $this->setting($account, 'database');
        $userId = (int) ($this->credentials->get($account)->secret('uid') ?? 0);
        if ($database === '' || $userId < 1) {
            throw new OdooNotConfigured(
                OdooNotConfigured::NOT_SIGNED_IN,
                'This connection has no signed-in Odoo user, so nothing was sent: run the connection test, which signs in and records the user id.',
            );
        }

        // `execute_kw(db, uid, key, model, method, args, kwargs)`, which is the signature Odoo's 17.0 and 18.0 pages document.
        // The key stands where the password would: Odoo's own wording is that an API key replaces it.
        $body = OdooXmlRpc::request('execute_kw', [$database, $userId, $this->apiKey($account), $model, $method, $args, $kwargs]);

        return $this->readXmlRpc($this->send($account, $operation, 'POST', $base . OdooOperations::PATH_OBJECT, $body, $capability, $scope));
    }

    /** The version, with no authentication at all, which is what lets the transport be chosen before any credential is used. */
    private function readVersion(SalesChannelAccount $account, string $base): ?array
    {
        try {
            $answer = $this->readXmlRpc($this->send(
                $account,
                'odoo.version',
                'POST',
                $base . OdooOperations::PATH_COMMON,
                OdooXmlRpc::request('version', []),
                IntegrationCapability::ConnectionTest,
            ));
            if ($answer->ok() && is_array($answer->value) && $answer->value !== []) {
                return $answer->value;
            }
        } catch (OdooFault) {
            // An instance that answers a fault to `version` is one where `common` is gone (Odoo 22 removes it) or fronted by
            // something that is not Odoo. Either way the other endpoint is the one to ask.
        }

        // `GET /web/version`, which Odoo's 19.0 page gives in place of the meta-call. Whether it needs authentication is not
        // stated there, so a refusal here is read as "could not be read" rather than as a credential problem.
        $response = $this->http->send($account, 'odoo.version', 'GET', $base . OdooOperations::PATH_WEB_VERSION, [], null, [], null, IntegrationCapability::ConnectionTest);

        return $response->ok() && is_array($response->json) && $response->json !== [] ? $response->json : null;
    }

    /** XML-RPC's `authenticate(db, login, key, {})` → the user id every later call carries. */
    private function signIn(SalesChannelAccount $account, string $base, string $database): ?int
    {
        // The fourth argument is an empty DICTIONARY, and `(object) []` is the only way to say that: `[]` is a list in PHP and
        // would be encoded as an empty `<array>`, which is a different type.
        $body = OdooXmlRpc::request('authenticate', [$database, $this->setting($account, 'login'), $this->apiKey($account), (object) []]);
        $answer = $this->readXmlRpc($this->send($account, 'odoo.authenticate', 'POST', $base . OdooOperations::PATH_COMMON, $body, IntegrationCapability::ConnectionTest));

        // Odoo answers `false` for a refused sign-in rather than a fault, so a boolean is not a user id and must not be cast to
        // one: `(int) false` is 0, and 0 is a valid-looking argument to every later call.
        return $answer->ok() && is_int($answer->value) && $answer->value > 0 ? $answer->value : null;
    }

    private function readXmlRpc(ChannelResponse $response): OdooAnswer
    {
        if ($response->isDryRun()) {
            return OdooAnswer::rehearsed($response);
        }
        if (!$response->ok()) {
            return OdooAnswer::unreachable($response);
        }

        try {
            return OdooAnswer::succeeded($response, OdooXmlRpc::response($response->body));
        } catch (OdooFault $fault) {
            return OdooAnswer::refused($response, $fault);
        } catch (RuntimeException $broken) {
            // A 2xx nobody could parse. NOT an empty result: `OdooXmlRpc` refuses to invent one, and this keeps that refusal
            // meaningful all the way up -- the call may well have been carried out.
            return OdooAnswer::unreadable($response, 'odoo_answer_could_not_be_read');
        } catch (Throwable $unexpected) {
            return OdooAnswer::unreadable($response, 'odoo_answer_could_not_be_read');
        }
    }

    private function send(
        SalesChannelAccount $account,
        string $operation,
        string $method,
        string $url,
        string $body,
        ?IntegrationCapability $capability = null,
        ?string $scope = null,
    ): ChannelResponse {
        // `text/xml` on both sides: an XML-RPC endpoint asked for JSON answers something nobody can decode.
        return $this->http->send($account, $operation, $method, $url, ['Content-Type' => 'text/xml', 'Accept' => 'text/xml'], null, [], null, $capability, $scope, $body);
    }

    // ---- JSON-2 -------------------------------------------------------------------------------------------------

    /**
     * `POST /json/2/<model>/<method>` with the method's arguments as NAMED fields.
     *
     * What this repository has verified: the URL shape, `Authorization: bearer <api key>`, and that an error comes back as a
     * 4xx or 5xx carrying `name`, `message`, `arguments`, `context` and `debug`. What it has NOT been able to read verbatim is
     * the page's own request and response examples -- two fetches of the 19.0 page returned only its navigation, and the body
     * reached this repository as a summary. So the named-field shape is **UNVERIFIED** here, recorded as such in
     * docs/sales-channels/odoo-accounting.md, and no WRITE uses it: `createDraft()` refuses on this transport rather than send
     * a body somebody reconstructed. The reads below name their fields after the ORM's own parameters, which is the narrowest
     * assumption available, and a wrong field name fails loudly on the first real call rather than writing the wrong thing.
     *
     * @param array<string, mixed> $body
     */
    private function callJson2(
        SalesChannelAccount $account,
        string $operation,
        string $base,
        string $model,
        string $method,
        array $body,
        ?IntegrationCapability $capability,
        ?string $scope,
    ): OdooAnswer {
        $headers = [
            // Lower-case `bearer`, as the documentation writes it; whether the scheme is case-sensitive is not stated, so its
            // own spelling is the one used.
            'Authorization' => 'bearer ' . $this->apiKey($account),
            'Content-Type' => 'application/json; charset=utf-8',
        ];
        $database = $this->setting($account, 'database');
        if ($database !== '') {
            // Needed when one server hosts several databases, optional otherwise -- so it is sent whenever we know it.
            $headers['X-Odoo-Database'] = $database;
        }

        return $this->readJson2($this->http->send(
            $account,
            $operation,
            'POST',
            $base . OdooOperations::json2Path($model, $method),
            $headers,
            $body,
            [],
            null,
            $capability,
            $scope,
        ));
    }

    /**
     * Reads one JSON-2 answer.
     *
     * A 200 carries the method's return value BARE, not wrapped in a result envelope; a failure carries the error object. The
     * error object is turned into an `OdooFault` so that the rest of this integration sees ONE shape for "Odoo arrived at an
     * answer and the answer was no", whichever transport brought it.
     */
    private function readJson2(ChannelResponse $response): OdooAnswer
    {
        if ($response->isDryRun()) {
            return OdooAnswer::rehearsed($response);
        }
        if ($response->ok()) {
            // JSON-2 answers the method's return value BARE -- no result envelope -- so it is not always an object: `search_count`
            // answers a number and `create` an id. `ChannelResponse` keeps only arrays, so a scalar is decoded here rather than
            // read as "no body".
            if ($response->json !== null) {
                return OdooAnswer::succeeded($response, $response->json);
            }
            $decoded = json_decode($response->body, true);
            // A 200 whose body is not JSON at all is UNREADABLE and never empty: an empty list from a search means "Odoo holds
            // no such entry", and that is the answer on which a second journal entry would be created.
            return json_last_error() === JSON_ERROR_NONE
                ? OdooAnswer::succeeded($response, $decoded)
                : OdooAnswer::unreadable($response, 'odoo_answer_was_not_json');
        }

        $name = trim((string) $response->get('name', ''));
        if ($name === '') {
            // A failure with no error object: a gateway, a proxy, a timeout. Nobody knows whether Odoo saw the call.
            return OdooAnswer::unreachable($response);
        }

        // Odoo itself answered and refused. `arguments` carries its own values and `debug` a traceback; neither is parsed for
        // meaning, and the fault code is the HTTP status because JSON-2 publishes no numeric fault code of its own.
        return OdooAnswer::refused($response, new OdooFault((int) ($response->status ?? 0), $name . ': ' . trim((string) $response->get('message', ''))));
    }

    /** Whether the key is accepted at all, for a transport that publishes no sign-in: one read, of the companies this user can see. */
    private function keyIsAccepted(SalesChannelAccount $account, string $base): bool
    {
        return $this->callJson2(
            $account,
            'odoo.search_count',
            $base,
            'res.company',
            'search_count',
            ['domain' => []],
            // No capability named: the operation's own allow-list still governs, and a connection that may not read finances is
            // refused by that rather than by a capability this probe picked for it.
            null,
            null,
        )->ok();
    }

    private function json2NotSettled(string $model, string $method): OdooNotConfigured
    {
        return new OdooNotConfigured(
            OdooNotConfigured::JSON2_REQUEST_NOT_DOCUMENTED,
            "This Odoo speaks JSON-2, and how '{$method}' on '{$model}' is written in that API has not been read from Odoo's documentation in a form this repository counts as"
            . ' documented. Nothing was sent: a request body reconstructed from a summary is a guess, and this one would write into somebody\'s ledger.',
        );
    }

    // ---- settings and secrets -----------------------------------------------------------------------------------

    private function baseUrl(SalesChannelAccount $account): string
    {
        return rtrim($this->setting($account, 'base_url'), '/');
    }

    private function setting(SalesChannelAccount $account, string $name): string
    {
        return trim((string) $account->setting($name, ''));
    }

    private function apiKey(SalesChannelAccount $account): string
    {
        return trim((string) ($this->credentials->get($account)->secret('api_key') ?? ''));
    }
}
