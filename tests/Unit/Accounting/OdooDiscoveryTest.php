<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\Odoo\OdooInstance;
use App\Services\Accounting\Odoo\OdooTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Choosing how to speak to one customer's Odoo — from what the server says, never from what anybody assumed.
 *
 * Odoo has two external APIs and the boundary is a version number: JSON-2 is documented as "New in version 19.0", while 17.0 and
 * 18.0 document XML-RPC instead and master calls XML-RPC deprecated with `common` and `object` removed in Odoo 22. Asking an 18.0
 * server for JSON-2 would 404; assuming XML-RPC forever would stop working on an upgrade.
 *
 * **And the two endpoints spell their version keys differently**, which is the bug these tests exist for. XML-RPC's `version()`
 * answers `server_version_info`; JSON-2's replacement `GET /web/version` answers `version_info`, with no prefix. A client reading
 * only the prefixed spelling sees every 19.0 instance as one whose version cannot be read — and an unreadable version must choose
 * no transport at all, so the whole integration would refuse to start against exactly the servers it is meant for.
 */
class OdooDiscoveryTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: int|null}> */
    public static function versionAnswers(): array
    {
        return [
            // What XML-RPC's `version()` answers.
            'xmlrpc, structured' => [['server_version_info' => [18, 0, 0, 'final', 0], 'server_version' => '18.0'], 18],
            'xmlrpc, string only' => [['server_version' => '17.0'], 17],
            // What JSON-2's `GET /web/version` answers: the SAME facts under unprefixed names.
            'json-2, structured' => [['version_info' => [19, 0, 0, 'final', 0, ''], 'version' => '19.0'], 19],
            'json-2, string only' => [['version' => '19.0'], 19],
            // A hosted instance labels itself differently, and `saas~` must not be read as a missing version.
            'saas, structured' => [['server_version_info' => [17, 4, 0, 'final', 0], 'server_version' => 'saas~17.4+e'], 17],
            'saas, string only' => [['server_version' => 'saas~17.4+e'], 17],
            'enterprise suffix' => [['server_version' => '18.0+e'], 18],
            // Nothing readable is NOT a version of zero and NOT an old server.
            'no version at all' => [['server_serie' => 'x', 'protocol_version' => 1], null],
            'an empty string' => [['server_version' => ''], null],
            'a string with no digits' => [['server_version' => 'unknown'], null],
            'nothing whatever' => [[], null],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('versionAnswers')]
    public function test_the_major_version_is_read_from_either_spelling(array $answer, ?int $expected): void
    {
        $this->assertSame($expected, OdooInstance::majorFrom($answer));
    }

    /** @return array<string, array{0: int|null, 1: OdooTransport}> */
    public static function versionsAndTransports(): array
    {
        return [
            'Odoo 19 speaks JSON-2' => [19, OdooTransport::Json2],
            'and so does a later one' => [20, OdooTransport::Json2],
            'Odoo 18 speaks XML-RPC' => [18, OdooTransport::XmlRpc],
            'Odoo 17 speaks XML-RPC' => [17, OdooTransport::XmlRpc],
            'an older one speaks XML-RPC' => [16, OdooTransport::XmlRpc],
            'an unreadable version chooses nothing' => [null, OdooTransport::Unknown],
            'and so does a nonsense one' => [0, OdooTransport::Unknown],
        ];
    }

    #[DataProvider('versionsAndTransports')]
    public function test_the_transport_follows_the_version_and_an_unknown_version_chooses_nothing(?int $major, OdooTransport $expected): void
    {
        $this->assertSame($expected, OdooTransport::forMajorVersion($major));
    }

    public function test_a_nineteen_point_oh_instance_is_not_mistaken_for_an_unreadable_one(): void
    {
        // The bug this file is for, stated as one test: JSON-2's own answer, straight through to a transport.
        $answer = ['version_info' => [19, 0, 0, 'final', 0, ''], 'version' => '19.0'];

        $this->assertSame(OdooTransport::Json2, OdooTransport::forMajorVersion(OdooInstance::majorFrom($answer)));
    }

    public function test_the_deprecated_transport_is_named_as_such_without_being_refused(): void
    {
        // Odoo has announced the removal, not performed it. A customer on 18.0 has nothing else to use, so this is something to
        // show a person and never a reason to refuse to work.
        $this->assertTrue(OdooTransport::XmlRpc->isDeprecatedUpstream());
        $this->assertFalse(OdooTransport::Json2->isDeprecatedUpstream());
    }

    public function test_an_unreachable_instance_carries_its_reason_and_is_not_usable(): void
    {
        $instance = OdooInstance::unreachable('odoo_host_refused_the_connection');

        $this->assertFalse($instance->usable());
        $this->assertSame(['odoo_host_refused_the_connection'], $instance->problems);
        $this->assertSame(OdooTransport::Unknown, $instance->transport);
    }

    public function test_reachable_is_not_the_same_as_signed_in(): void
    {
        // A server that answers `version()` but refuses the credentials is a different problem from one nobody can reach, and a
        // person fixes them in different places. `version()` needs no authentication, which is why the two can differ at all.
        $reachableButRefused = new OdooInstance(
            reachable: true,
            authenticated: false,
            transport: OdooTransport::XmlRpc,
            serverVersion: '18.0',
            majorVersion: 18,
            problems: ['odoo_refused_the_api_key'],
        );

        $this->assertFalse($reachableButRefused->usable());
        $this->assertTrue($reachableButRefused->reachable);
    }

    public function test_an_instance_on_an_unknown_transport_is_never_usable_however_well_it_answered(): void
    {
        $signedInButUnreadable = new OdooInstance(reachable: true, authenticated: true, transport: OdooTransport::Unknown);

        $this->assertFalse($signedInButUnreadable->usable(), 'a transport picked blind fails in a way nobody can read');
    }

    public function test_a_usable_instance_is_reachable_signed_in_and_on_a_known_transport(): void
    {
        $usable = new OdooInstance(
            reachable: true,
            authenticated: true,
            transport: OdooTransport::Json2,
            serverVersion: '19.0',
            majorVersion: 19,
            userId: 2,
            database: 'acme',
        );

        $this->assertTrue($usable->usable());
    }
}
