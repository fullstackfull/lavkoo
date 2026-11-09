<?php

namespace Tests\Feature\Accounting;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\Accounting\Odoo\OdooAnswer;
use App\Services\Accounting\Odoo\OdooClient;
use App\Services\Accounting\Odoo\OdooNotConfigured;
use App\Services\Accounting\Odoo\OdooTransport;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use App\Services\Fulfillment\Support\SecretRedactor;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Speaking to a customer's Odoo: which API it speaks, and what each answer PROVES.
 *
 * Two things are being tested here and they matter for different reasons.
 *
 * **The transport is discovered.** Odoo has changed its external API twice and is removing the older one: XML-RPC is what 17.0
 * and 18.0 document, JSON-2 is "new in 19.0", and master calls `common` and `object` deprecated with removal announced for
 * Odoo 22. So the version is read first — `version()` needs no authentication, which is what makes that order possible — and an
 * unreadable version picks NO transport rather than guessing.
 *
 * **A fault is not a transport failure.** This is the half that protects somebody's books. Odoo answering "no" means the request
 * arrived and will be refused again; a timeout after the request went means an entry MAY EXIST that we cannot see. A client that
 * merges them into one "failed" either retries what will never work, or writes a second journal entry for the same cost. Every
 * state below is asserted through `certainlyDidNotAct()` and `mayHaveActed()`, because those are the two questions a posting
 * queue actually asks — and `ChannelResponse::isTransient()` answers neither.
 *
 * Every answer here is a fixture. Nothing reaches a real instance, and nothing here proves a real Odoo works.
 */
class OdooClientTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;

    private const WEB_VERSION = self::ODOO_HOST . '/web/version';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true, 'odoo.draft_creation_enabled' => true]);
        $this->account = $this->odooConnection(signedIn: false);
    }

    // ---- the credential never reaches the audit log ---------------------------------------------------------------

    public function test_the_api_key_is_never_written_into_the_call_log(): void
    {
        // **THIS WAS A REAL LEAK, and the shape of it is worth keeping written down.** Odoo's XML-RPC is POSITIONAL:
        // `authenticate(db, login, key, {})` and `execute_kw(db, uid, key, model, method, args, kwargs)` carry the API key as
        // an unnamed `<string>`. The transport logged a raw body as itself, and the redactors work by KEY -- `password`,
        // `authorization`, `api_key` -- so there was no key to match and nothing downstream could tell the credential from the
        // database name. Every single Odoo call wrote the key in clear text into `sales_channel_api_logs`.
        // **An object call, not the sign-in.** The sign-in's body happens to carry a login e-mail, and the PII redactor masks a
        // whole string value when it finds personal data inside one -- so `authenticate` was covered by accident. `execute_kw`
        // has no e-mail in it to hide behind, and it is the call every read and every write makes.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml([['id' => 4, 'name' => 'Sales']]))]);

        app(OdooClient::class)->searchRead($this->account, 'account.account', [['code', '=', '4000']], ['id', 'name']);

        $logged = SalesChannelApiLog::all();
        $this->assertNotEmpty($logged, 'the calls really were logged, so the assertion below means something');
        foreach ($logged as $row) {
            $written = (string) json_encode([$row->request_summary, $row->response_summary, $row->url, $row->error_message]);
            $this->assertStringNotContainsString('key-abc', $written, "the API key reached the log of '{$row->operation}'");
        }
    }

    public function test_the_masked_body_is_still_useful_to_a_person_reading_the_log(): void
    {
        // Masking must not turn the log into nothing: an operator reading it has to be able to see WHICH call was made and
        // with what, or the audit trail stops being one. So the method name and the arguments that are not secret stay.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml([['id' => 4, 'name' => 'Sales']]))]);

        app(OdooClient::class)->searchRead($this->account, 'account.account', [['code', '=', '4000']], ['id', 'name']);

        // Found by its CONTENT, not by an operation name: what matters is that the row carrying the object call stays
        // readable, whichever of the read operations made it.
        $body = (string) collect(SalesChannelApiLog::all())
            ->map(static fn (SalesChannelApiLog $row): string => (string) json_encode($row->request_summary))
            ->first(static fn (string $written): bool => str_contains($written, 'execute_kw'));
        $this->assertStringContainsString('execute_kw', $body, 'the method name is still there');
        $this->assertStringContainsString('account.account', $body, 'and so is the model, so the trail is still a trail');
        $this->assertStringContainsString(SecretRedactor::MASK, $body, 'and the key stands masked where it was');
    }

    public function test_a_raw_body_whose_layout_nobody_declared_is_logged_only_as_a_size(): void
    {
        // The safe default, and the reason the fix is not just "mask the Odoo key": a future caller passing some other
        // protocol's raw body gets no body in its log rather than a leak. `version` is that case already -- it carries no
        // credential, so no masked copy is built for it, and its log records the size and nothing else.
        Http::fake([self::ODOO_COMMON => Http::sequence()
            ->push($this->odooXml(['server_version' => '18.0', 'server_version_info' => [18, 0, 0, 'final', 0]]))
            ->push($this->odooXml(7))]);

        app(OdooClient::class)->discover($this->account);

        $version = SalesChannelApiLog::where('operation', 'odoo.version')->firstOrFail();
        $this->assertArrayHasKey('raw_bytes', (array) ($version->request_summary['body'] ?? []), 'a body with no declared layout is a byte count');
        $this->assertArrayNotHasKey('raw', (array) ($version->request_summary['body'] ?? []));
    }

    // ---- the transport is read, never assumed --------------------------------------------------------------------

    public function test_an_eighteen_point_zero_instance_is_spoken_to_over_xml_rpc_and_signed_in(): void
    {
        Http::fake([self::ODOO_COMMON => Http::sequence()
            ->push($this->odooXml((['server_version' => '18.0', 'server_version_info' => [18, 0, 0, 'final', 0]])))
            ->push($this->odooXml(7))]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertSame(OdooTransport::XmlRpc, $instance->transport);
        $this->assertSame(18, $instance->majorVersion);
        $this->assertSame(7, $instance->userId);
        $this->assertTrue($instance->usable());
        $this->assertSame('xmlrpc', $this->secret('transport'), 'what was learned is kept, so the next call does not discover it again');
        $this->assertSame('7', $this->secret('uid'));
    }

    public function test_a_saas_instance_labels_itself_differently_and_is_still_read(): void
    {
        // A hosted instance says `saas~17.4+e` where a self-hosted one says `18.0`. Reading only the structured list would be
        // enough here, but the string form is what a gateway sometimes leaves, and 17 is not a missing version.
        Http::fake([self::ODOO_COMMON => Http::sequence()
            ->push($this->odooXml((['server_version' => 'saas~17.4+e'])))
            ->push($this->odooXml(3))]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertSame(17, $instance->majorVersion);
        $this->assertSame(OdooTransport::XmlRpc, $instance->transport);
    }

    public function test_a_nineteen_point_zero_instance_is_spoken_to_over_json_two(): void
    {
        Http::fake([
            self::ODOO_COMMON => Http::response($this->odooXml((['server_version' => '19.0', 'server_version_info' => [19, 0, 0, 'final', 0]]))),
            self::ODOO_HOST . '/json/2/res.company/search_count' => Http::response(2),
        ]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertSame(OdooTransport::Json2, $instance->transport);
        $this->assertNull($instance->userId, 'JSON-2 uses no user id at all');
        $this->assertTrue($instance->authenticated, 'and nothing but a real read can say the key works');
        $this->assertSame('bearer key-abc', $this->sentTo('/json/2/')[0]->header('Authorization')[0]);
    }

    public function test_an_instance_without_the_old_endpoints_is_read_from_the_web_version(): void
    {
        // Odoo's own pages announce the removal of `common` and `object` in 22. The version then comes from `GET /web/version`,
        // whose keys are spelled WITHOUT the `server_` prefix -- reading only the prefixed ones would make every such instance
        // look like one whose version cannot be read.
        Http::fake([
            self::ODOO_COMMON => Http::response('', 404),
            self::WEB_VERSION => Http::response(['version' => '19.0', 'version_info' => [19, 0, 0, 'final', 0, '']]),
            self::ODOO_HOST . '/json/2/res.company/search_count' => Http::response(1),
        ]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertSame(19, $instance->majorVersion);
        $this->assertSame(OdooTransport::Json2, $instance->transport);
    }

    public function test_a_version_nobody_can_read_picks_no_transport_and_signs_in_to_nothing(): void
    {
        Http::fake([self::ODOO_COMMON => Http::response($this->odooXml((['server_serie' => 'unknown']))), self::WEB_VERSION => Http::response([], 500)]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertSame(OdooTransport::Unknown, $instance->transport);
        $this->assertFalse($instance->usable());
        $this->assertContains('odoo_version_is_not_a_number_we_can_read', $instance->problems);
        $this->assertNull($this->secret('transport'), 'nothing was learned, so nothing is remembered');
    }

    public function test_an_instance_nobody_can_reach_is_not_an_instance_with_a_bad_password(): void
    {
        Http::fake([self::ODOO_COMMON => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: books.example.test'), self::WEB_VERSION => Http::response([], 503)]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertFalse($instance->reachable);
        $this->assertFalse($instance->authenticated);
        $this->assertSame(['odoo_version_could_not_be_read'], $instance->problems);
    }

    public function test_a_connection_with_no_host_asks_nobody_anything(): void
    {
        $this->account->forceFill(['settings' => ['database' => 'books', 'login' => 'bot@example.test']])->save();
        Http::fake();

        $instance = app(OdooClient::class)->discover($this->account->fresh());

        $this->assertSame(['odoo_connection_has_no_host'], $instance->problems);
        Http::assertNothingSent();
    }

    public function test_a_refused_sign_in_is_not_read_as_user_zero(): void
    {
        // THE TRAP. Odoo answers a BOOLEAN FALSE for a refused sign-in rather than a fault, and `(int) false` is 0 -- a
        // perfectly valid-looking argument to hand `execute_kw` for the rest of the run.
        Http::fake([self::ODOO_COMMON => Http::sequence()
            ->push($this->odooXml((['server_version' => '18.0'])))
            ->push($this->odooXml(false))]);

        $instance = app(OdooClient::class)->discover($this->account);

        $this->assertNull($instance->userId);
        $this->assertFalse($instance->authenticated);
        $this->assertFalse($instance->usable());
        $this->assertSame(['odoo_refused_the_credentials'], $instance->problems);
        $this->assertNull($this->secret('uid'), 'nothing is remembered from a refusal');
    }

    public function test_a_connection_missing_its_key_says_so_instead_of_signing_in_with_nothing(): void
    {
        app(ChannelCredentialStore::class)->save($this->account, [], ['api_key']);
        Http::fake([self::ODOO_COMMON => Http::response($this->odooXml((['server_version' => '18.0'])))]);

        $instance = app(OdooClient::class)->discover($this->account->fresh());

        $this->assertContains('odoo_connection_has_no_api_key', $instance->problems);
        $this->assertCount(1, $this->sentTo('/xmlrpc/2/common'), 'the version was read; no sign-in was attempted');
    }

    // ---- what one call looks like on the wire --------------------------------------------------------------------

    public function test_a_read_is_execute_kw_with_the_database_the_user_and_the_key(): void
    {
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml(([['id' => 4, 'name' => 'Sales']])))]);

        $answer = app(OdooClient::class)->searchRead($this->account, 'account.account', [['code', '=', '4000']], ['id', 'name']);

        $this->assertTrue($answer->ok());
        $this->assertSame([['id' => 4, 'name' => 'Sales']], $answer->records());
        $body = $this->sentTo('/xmlrpc/2/object')[0]->body();
        $this->assertStringContainsString('<methodName>execute_kw</methodName>', $body);
        $this->assertStringContainsString('<string>books</string>', $body, 'the database');
        $this->assertStringContainsString('<int>7</int>', $body, 'the signed-in user');
        $this->assertStringContainsString('<string>account.account</string>', $body);
        $this->assertStringContainsString('<string>search_read</string>', $body);
        $this->assertStringContainsString('<name>fields</name>', $body);
    }

    public function test_an_entry_is_looked_for_by_our_own_reference(): void
    {
        // The read that stands in for the idempotency Odoo does not offer: `create` creates every time it is called, there is no
        // external-reference field and no unique key, so our reference goes in `ref` and this is how a retry finds it.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml(([])))]);

        $answer = app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $this->assertTrue($answer->ok());
        $this->assertSame([], $answer->records(), 'an empty answer means Odoo holds no such entry, and it is a real answer');
        $body = $this->sentTo('/xmlrpc/2/object')[0]->body();
        $this->assertStringContainsString('<string>account.move</string>', $body);
        $this->assertStringContainsString('<string>ref</string>', $body);
        $this->assertStringContainsString('<string>PKSA-COST-12-9ab3</string>', $body);
    }

    public function test_the_fields_of_a_model_are_asked_of_the_instance_itself(): void
    {
        // Odoo's developer reference has NO page for `account.move`, so the field names are not in any documentation to copy
        // from: they are asked of the customer's own instance, which is also what survives a rename between versions.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml((['ref' => ['type' => 'char', 'string' => 'Reference']])))]);

        $answer = app(OdooClient::class)->fieldsGet($this->account, 'account.move');

        $this->assertTrue($answer->ok());
        $this->assertSame(['ref' => ['type' => 'char', 'string' => 'Reference']], $answer->value);
        $this->assertStringContainsString('<string>fields_get</string>', $this->sentTo('/xmlrpc/2/object')[0]->body());
    }

    // ---- WHAT EACH ANSWER PROVES --------------------------------------------------------------------------------

    public function test_a_fault_inside_a_two_hundred_is_a_refusal_and_not_an_outage(): void
    {
        // XML-RPC answers a fault with HTTP 200, so a client that looks only at the status reads this as a success.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooFault(2, "Odoo Server Error\nTraceback (most recent call last): ..."))]);

        $answer = app(OdooClient::class)->searchRead($this->account, 'account.move', []);

        $this->assertSame(OdooAnswer::REFUSED, $answer->state);
        $this->assertTrue($answer->isRefusal());
        $this->assertFalse($answer->ok());
        $this->assertSame(2, $answer->fault->faultCode);
        $this->assertStringContainsString('Traceback', $answer->fault->faultString, 'kept for a person to read, never parsed for meaning');
    }

    public function test_a_fault_is_not_treated_as_proof_that_nothing_happened(): void
    {
        // Odoo's documentation does not state that a failed call rolls its transaction back, and this code does not rely on
        // behaviour nobody published when the cost of being wrong is a duplicate entry in somebody's books.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooFault(1, 'ValidationError'))]);

        $answer = app(OdooClient::class)->searchRead($this->account, 'account.move', []);

        $this->assertFalse($answer->certainlyDidNotAct());
        $this->assertTrue($answer->mayHaveActed());
    }

    public function test_an_answer_lost_after_the_request_went_is_the_case_that_must_never_retry_itself(): void
    {
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);

        $answer = app(OdooClient::class)->searchRead($this->account, 'account.move', []);

        $this->assertSame(OdooAnswer::UNREACHABLE, $answer->state);
        $this->assertTrue($answer->mayHaveActed());
        $this->assertFalse($answer->certainlyDidNotAct());
    }

    public function test_a_request_that_never_left_the_host_is_the_one_failure_that_proves_nothing_happened(): void
    {
        // The only failure a posting queue may act on without asking Odoo first, and it is narrow on purpose: the request did
        // not go.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: books.example.test')]);

        $answer = app(OdooClient::class)->searchRead($this->account, 'account.move', []);

        $this->assertTrue($answer->certainlyDidNotAct());
        $this->assertFalse($answer->mayHaveActed());
    }

    public function test_a_two_hundred_nobody_can_parse_is_never_an_empty_result(): void
    {
        // THE most dangerous confusion in this file. An empty list from a search means "Odoo holds no such entry", and that is
        // exactly the answer on which a second journal entry would be created.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response('<html><body>502 Bad Gateway</body></html>')]);

        $answer = app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $this->assertSame(OdooAnswer::UNREADABLE, $answer->state);
        $this->assertFalse($answer->ok());
        $this->assertTrue($answer->mayHaveActed());
        $this->assertSame([], $answer->records(), 'and the records of an unreadable answer are empty WITHOUT that meaning "none exist"');
    }

    public function test_an_empty_search_result_and_an_unreadable_answer_are_different_states(): void
    {
        // The pair, side by side, because the whole protection against a duplicate entry rests on telling them apart.
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::sequence()->push($this->odooXml(([])))->push('not xml at all')]);
        $client = app(OdooClient::class);

        $none = $client->entriesByReference($this->account, 'PKSA-COST-1-aaaa');
        $unreadable = $client->entriesByReference($this->account, 'PKSA-COST-2-bbbb');

        $this->assertTrue($none->ok(), 'Odoo answered: it holds none');
        $this->assertTrue($none->certainlyDidNotAct() === false && $none->mayHaveActed() === false, 'a successful read neither acted nor may have');
        $this->assertFalse($unreadable->ok(), 'nobody answered anything we could read');
        $this->assertTrue($unreadable->mayHaveActed());
    }

    public function test_a_five_hundred_is_not_an_answer_about_what_odoo_holds(): void
    {
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response('', 503)]);

        $answer = app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $this->assertSame(OdooAnswer::UNREACHABLE, $answer->state);
        $this->assertTrue($answer->mayHaveActed());
    }

    public function test_a_json_two_error_object_is_the_same_refusal_as_an_xml_fault(): void
    {
        // Two transports, one shape for "Odoo arrived at an answer and the answer was no".
        $this->signedIn(OdooTransport::Json2);
        Http::fake([self::ODOO_HOST . '/json/2/account.move/search_read' => Http::response([
            'name' => 'werkzeug.exceptions.Unauthorized', 'message' => 'Invalid apikey', 'arguments' => ['Invalid apikey', 401], 'context' => [], 'debug' => 'Traceback ...',
        ], 401)]);

        $answer = app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $this->assertTrue($answer->isRefusal());
        $this->assertStringContainsString('Invalid apikey', $answer->fault->faultString);
        $this->assertSame(401, $answer->fault->faultCode);
    }

    public function test_a_json_two_failure_with_no_error_object_is_an_outage(): void
    {
        $this->signedIn(OdooTransport::Json2);
        Http::fake([self::ODOO_HOST . '/json/2/account.move/search_read' => Http::response('<html>gateway</html>', 502)]);

        $answer = app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $this->assertSame(OdooAnswer::UNREACHABLE, $answer->state);
        $this->assertTrue($answer->mayHaveActed());
    }

    public function test_a_json_two_read_carries_the_database_header_and_named_arguments(): void
    {
        $this->signedIn(OdooTransport::Json2);
        Http::fake([self::ODOO_HOST . '/json/2/account.move/search_read' => Http::response([])]);

        app(OdooClient::class)->entriesByReference($this->account, 'PKSA-COST-12-9ab3');

        $request = $this->sentTo('/json/2/')[0];
        $this->assertSame('books', $request->header('X-Odoo-Database')[0]);
        $this->assertSame([['ref', '=', 'PKSA-COST-12-9ab3']], $request->data()['domain']);
        $this->assertSame(['id', 'ref', 'state', 'name'], $request->data()['fields']);
    }

    // ---- writing ------------------------------------------------------------------------------------------------

    public function test_a_draft_entry_is_created_with_every_switch_and_the_approval_in_place(): void
    {
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml(91))]);

        $answer = app(OdooClient::class)->createDraft($this->account, 'account.move', ['ref' => 'PKSA-COST-12-9ab3', 'move_type' => 'entry']);

        $this->assertTrue($answer->ok());
        $this->assertSame([91], $answer->ids());
        $this->assertStringContainsString('<string>create</string>', $this->sentTo('/xmlrpc/2/object')[0]->body());
    }

    public function test_the_draft_switch_off_stops_the_write_even_with_the_others_on(): void
    {
        // Four switches because they guard four different-sized mistakes, and a draft is still a row the customer's accountant
        // sees.
        config(['odoo.draft_creation_enabled' => false]);
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml(91))]);

        try {
            app(OdooClient::class)->createDraft($this->account, 'account.move', ['ref' => 'X']);
            $this->fail('a draft must not be created with its own switch off');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_rehearsing_connection_writes_nothing_and_claims_nothing(): void
    {
        $this->signedIn();
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml(91))]);

        $answer = app(OdooClient::class)->createDraft($this->account->fresh(), 'account.move', ['ref' => 'X']);

        $this->assertTrue($answer->isRehearsal());
        $this->assertTrue($answer->certainlyDidNotAct(), 'a rehearsal is one of the two things that prove nothing happened');
        Http::assertNothingSent();
    }

    public function test_a_connection_lost_while_creating_an_entry_is_not_reported_as_a_failed_create(): void
    {
        // THE financial case. The request went and no answer came back: an entry may exist in the customer's ledger. The queue
        // that called this must verify by reference, and if that cannot answer either, a person decides -- which is why this
        // never answers "did not act".
        $this->signedIn();
        Http::fake([self::ODOO_OBJECT => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);

        $answer = app(OdooClient::class)->createDraft($this->account, 'account.move', ['ref' => 'PKSA-COST-12-9ab3']);

        $this->assertFalse($answer->ok());
        $this->assertFalse($answer->certainlyDidNotAct());
        $this->assertTrue($answer->mayHaveActed());
        $this->assertSame(1, $answer->response->attempts, 'and a write is never retried by the transport');
    }

    public function test_a_json_two_instance_refuses_to_create_rather_than_send_a_body_nobody_read(): void
    {
        // The repository's own rule, applied to itself: `create`'s JSON-2 body was not readable in Odoo's documentation in a
        // form this repository counts as documented, so a 19.0 instance is refused by name instead of being sent a guess.
        $this->signedIn(OdooTransport::Json2);
        Http::fake();

        try {
            app(OdooClient::class)->createDraft($this->account, 'account.move', ['ref' => 'X']);
            $this->fail('a guessed request body must not be sent into somebody\'s ledger');
        } catch (OdooNotConfigured $exception) {
            $this->assertSame(OdooNotConfigured::JSON2_REQUEST_NOT_DOCUMENTED, $exception->reason);
            $this->assertStringContainsString('JSON-2', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_undiscovered_transport_sends_nothing_at_all(): void
    {
        // Nobody has run the connection test, so which API this instance speaks is unknown -- and one is never guessed.
        Http::fake();

        try {
            app(OdooClient::class)->searchRead($this->account, 'account.account', []);
            $this->fail('a transport must not be guessed');
        } catch (OdooNotConfigured $exception) {
            $this->assertSame(OdooNotConfigured::TRANSPORT_NOT_DISCOVERED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_an_xml_rpc_call_without_a_signed_in_user_is_refused_before_anything_leaves(): void
    {
        app(ChannelCredentialStore::class)->putRuntime($this->account, 'transport', 'xmlrpc');
        Http::fake();

        try {
            app(OdooClient::class)->searchRead($this->account->fresh(), 'account.account', []);
            $this->fail('execute_kw with no user id must not be sent');
        } catch (OdooNotConfigured $exception) {
            $this->assertSame(OdooNotConfigured::NOT_SIGNED_IN, $exception->reason);
        }

        Http::assertNothingSent();
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /** A connection that has already been discovered and signed in, which is the state every call but the first is made in. */
    private function signedIn(OdooTransport $transport = OdooTransport::XmlRpc): void
    {
        $store = app(ChannelCredentialStore::class);
        $store->putRuntime($this->account, 'transport', $transport->value);
        if ($transport === OdooTransport::XmlRpc) {
            $store->putRuntime($this->account, 'uid', '7');
        }
        $this->account = $this->account->fresh();
    }

    private function secret(string $key): ?string
    {
        return app(ChannelCredentialStore::class)->get($this->account->fresh())->secret($key);
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
