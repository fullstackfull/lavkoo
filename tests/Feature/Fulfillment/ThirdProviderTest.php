<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\ConfiguredProvider;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentAggregator;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\Fulfillment\FakeGulfAdapter;

/**
 * H1-11, criterion 1: a carrier for another Gulf country is ONE adapter class plus ONE configuration entry.
 *
 * It was not, and the reason was a single closed enum. `ProviderCode` had two cases and every gate in the platform asked it whether a code was real, so a
 * carrier for the UAE or Kuwait could be configured completely -- credentials, served cities, routes, an adapter class -- and still not exist. The
 * configuration is the gate now (`ProviderRegistry`), and this test is written the way a carrier is actually added: a class in one file, an entry in
 * `config/fulfillment.php`, and nothing else. `FakeGulfAdapter` deliberately has no enum case; if one were ever added for it the test would still pass,
 * which is why the first assertion is that there is none.
 */
class ThirdProviderTest extends FulfillmentTestCase
{
    private const CODE = 'fake_gulf';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fulfillment.providers.' . self::CODE => [
                'label' => 'Fake Gulf Express',
                'reference_code' => 'FG',
                'adapter' => FakeGulfAdapter::class,
                'routes' => ['SA:AE'],
                'secret_keys' => ['api_key', 'webhook_secret'],
            ],
        ]);
    }

    public function test_the_carrier_has_no_enum_case_and_is_known_all_the_same(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertNull(ProviderCode::tryFrom(self::CODE), 'the point of this test is a carrier with no case in the enum');
        $this->assertFalse(ProviderCode::isKnown(self::CODE), 'the enum answers for the two shipped carriers only');
        $this->assertTrue($registry->isKnown(self::CODE), 'and the platform knows the carrier its configuration declares');
    }

    public function test_the_registry_resolves_the_configured_carrier_to_its_adapter(): void
    {
        $adapter = app(ProviderRegistry::class)->adapter(self::CODE);

        $this->assertInstanceOf(FakeGulfAdapter::class, $adapter);
        $this->assertSame(self::CODE, $adapter->code()->code(), 'an adapter answers who it is without an enum');
    }

    public function test_the_carrier_is_listed_among_the_identities_after_the_two_with_a_case(): void
    {
        $identities = app(ProviderRegistry::class)->identities();

        $codes = array_map(static fn (ProviderIdentity $identity): string => $identity->code(), $identities);

        $this->assertSame(['m5azn', 'torod_point', self::CODE], $codes);
        $this->assertSame(ProviderCode::values(), array_slice($codes, 0, 2), 'the shipped two keep their place and their order');
    }

    public function test_its_identity_answers_the_four_things_an_identity_is_asked(): void
    {
        $identity = app(ProviderRegistry::class)->identity(self::CODE);

        $this->assertSame(self::CODE, $identity->code());
        $this->assertSame('fake-gulf', $identity->slug(), 'the URL form a webhook route carries');
        $this->assertSame('FG', $identity->referenceCode());
        $this->assertSame('Fake Gulf Express', $identity->label());
    }

    public function test_a_carrier_that_declares_no_short_code_gets_one_from_its_own_code(): void
    {
        config(['fulfillment.providers.' . self::CODE . '.reference_code' => null]);

        $this->assertSame('FA', app(ProviderRegistry::class)->identity(self::CODE)->referenceCode());
    }

    public function test_the_url_form_of_the_code_resolves_to_the_same_carrier(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(self::CODE, $registry->fromSlug('fake-gulf')?->code());
        $this->assertSame('torod_point', $registry->fromSlug('torod-point')?->code(), 'and the shipped carriers resolve as they always did');
    }

    public function test_the_registry_reads_the_configured_carriers_meta_and_default_settings(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(['SA:AE'], $registry->meta(self::CODE)['routes']);
        $this->assertSame(FakeGulfAdapter::defaultSettings(), $registry->defaultSettings(self::CODE));
    }

    /** An entry nobody finished is not a carrier: a parcel routed to one would be held by something that cannot be talked to. */
    public function test_an_entry_with_no_adapter_class_is_not_a_carrier(): void
    {
        config(['fulfillment.providers.half_written' => ['label' => 'Half written']]);
        $registry = app(ProviderRegistry::class);

        $this->assertFalse($registry->isKnown('half_written'));
        $this->assertNull($registry->identity('half_written'));
        $this->assertNotContains('half_written', array_map(static fn (ProviderIdentity $i): string => $i->code(), $registry->identities()));
    }

    public function test_a_code_nobody_declared_is_refused_rather_than_guessed(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertFalse($registry->isKnown('aramex'));
        $this->assertNull($registry->identity('aramex'));

        $this->expectException(InvalidArgumentException::class);
        $registry->adapter('aramex');
    }

    /** H1-11, criterion 3's groundwork: a configured carrier has credentials of its own, under the key names IT declares. */
    public function test_a_configured_carrier_keeps_its_own_credentials(): void
    {
        $connections = app(ProviderConnectionManager::class);
        $identity = app(ProviderRegistry::class)->identity(self::CODE);

        $connections->save(
            provider: $identity,
            enabled: true,
            baseUrl: 'https://fake-gulf.test',
            settings: ['dispatch_enabled' => true],
            secrets: ['api_key' => 'a-key-of-its-own', 'webhook_secret' => 'a-secret', 'lastmile_password' => 'not one of its keys'],
        );

        $config = $connections->config($identity);

        $this->assertTrue($config->enabled);
        $this->assertSame('https://fake-gulf.test', $config->baseUrl);
        $this->assertSame('a-key-of-its-own', $config->secret('api_key'));
        $this->assertTrue($config->hasSecret('webhook_secret'));
        $this->assertFalse($config->hasSecret('lastmile_password'), 'a key this carrier does not name is ignored, as it is for the shipped two');
        $this->assertSame($identity, $config->provider, 'and the configuration names the carrier it belongs to');
    }

    /** The closed map is a DEFAULT now, so the two shipped carriers must still accept exactly the keys they accepted before. */
    public function test_the_shipped_carriers_keys_are_unchanged_by_the_configuration_becoming_the_gate(): void
    {
        $connections = app(ProviderConnectionManager::class);

        $connections->save(
            provider: ProviderCode::TorodPoint,
            enabled: true,
            baseUrl: null,
            settings: [],
            secrets: ['fulfillment_username' => 'a-user', 'api_key' => 'a key Torod does not accept'],
        );

        $config = $connections->config(ProviderCode::TorodPoint);

        $this->assertSame('a-user', $config->secret('fulfillment_username'));
        $this->assertFalse($config->hasSecret('api_key'));
    }

    public function test_a_configured_carrier_may_not_be_called_until_it_is_enabled(): void
    {
        $connections = app(ProviderConnectionManager::class);
        $identity = app(ProviderRegistry::class)->identity(self::CODE);

        $this->assertFalse($connections->canCall($identity), 'nothing is stored for it yet, so its switch is off');

        $connections->save(provider: $identity, enabled: true, baseUrl: null, settings: []);

        $this->assertTrue($connections->canCall($identity));

        config(['fulfillment.enabled' => false]);

        $this->assertFalse($connections->canCall($identity), 'and the global kill switch stops it like any other carrier');
    }

    /**
     * H1-11, criterion 1's last seam: the webhook route reaches a carrier that has no enum case.
     *
     * Nothing is processed here -- `FakeGulfAdapter` does not handle webhooks, so the right answer is 404 "unsupported". What is proved is that the route
     * RESOLVED the carrier from its URL slug and authenticated it: before this, an unknown slug was refused at the door with 404 as well, and the two
     * 404s are told apart by the one that must come first -- a wrong secret on a carrier the platform knows answers 401, and a carrier it does not know
     * never gets that far.
     */
    public function test_the_webhook_route_reaches_a_carrier_with_no_enum_case(): void
    {
        config(['fulfillment.enabled' => true]);
        app(ProviderConnectionManager::class)->save(
            provider: app(ProviderRegistry::class)->identity(self::CODE),
            enabled: true,
            baseUrl: null,
            settings: ['webhooks_enabled' => true],
            secrets: ['webhook_secret' => 'the-secret'],
        );

        $wrongSecret = $this->postJson('api/fulfillment-webhooks/fake-gulf/not-the-secret/fulfillment', ['any' => 'payload']);
        $rightSecret = $this->postJson('api/fulfillment-webhooks/fake-gulf/the-secret/fulfillment', ['any' => 'payload']);
        $noSuchCarrier = $this->postJson('api/fulfillment-webhooks/aramex/the-secret/fulfillment', ['any' => 'payload']);

        $wrongSecret->assertStatus(401);
        $rightSecret->assertStatus(404);
        $noSuchCarrier->assertStatus(404);
    }

    /**
     * Every method of the contract, called.
     *
     * Written because the first version of `FakeGulfAdapter` built three of these results with constructors that do not exist -- it compiled, and the
     * tests above passed, because nothing ever called them. An adapter nobody exercises is not proof that the contract is implementable.
     */
    public function test_every_method_of_the_contract_answers(): void
    {
        $adapter = app(ProviderRegistry::class)->adapter(self::CODE);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderId: 7300);

        $this->assertTrue($adapter->readiness()->configured);
        $this->assertSame([], $adapter->readiness()->missing);
        $this->assertTrue($adapter->testConnection()->ok);
        $this->assertTrue($adapter->preflight($fulfillment)->ok);
        $this->assertSame(SubmissionResult::DRY_RUN, $adapter->submit($fulfillment, dryRun: true)->outcome);
        $this->assertSame(CancellationResult::REQUESTED, $adapter->cancel($fulfillment)->outcome);
        $this->assertNull($adapter->fetchStatus($fulfillment));
        $this->assertSame(FulfillmentStatus::Delivered, $adapter->mapStatus('delivered'));
        $this->assertNull($adapter->mapStatus('anything else'));
    }

    /** And it must refuse to send anything for real, so a test that reaches a network through it fails rather than passing quietly. */
    public function test_the_fake_carrier_refuses_to_send_a_real_shipment(): void
    {
        $adapter = app(ProviderRegistry::class)->adapter(self::CODE);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderId: 7301);

        $this->expectException(RuntimeException::class);
        $adapter->submit($fulfillment, dryRun: false);
    }

    /**
     * The order summary, the last of the four surfaces the requirement names.
     *
     * It needed no change at all, which is the point: it reads the shipment ROWS and never asks an enum what a carrier is. This test is here so that
     * "handle it unchanged" is a thing somebody checked rather than a thing somebody said.
     */
    public function test_the_order_summary_shows_a_shipment_of_a_carrier_with_no_enum_case(): void
    {
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderId: 7310);
        ProviderFulfillment::whereKey($fulfillment->id)->update(['provider' => self::CODE]);

        $summary = app(FulfillmentAggregator::class)->summarize(7310);

        $this->assertTrue($summary->hasProviderFulfillments(), 'the shipment is in the summary');
        $this->assertSame(self::CODE, $summary->fulfillments->first()->provider);
        $this->assertSame(
            self::CODE,
            $summary->fulfillments->first()->providerIdentity()?->code(),
            'and the row resolves to the carrier its configuration declares',
        );
    }

    /** A code is stored, put in a URL and put in a fulfilment reference, so a value that is not spelled like a code is not one. */
    public function test_a_code_that_is_not_spelled_like_a_code_is_refused(): void
    {
        foreach (['Fake_Gulf', 'fake gulf', 'fake/gulf', 'fake.gulf', '', 'fake-gulf'] as $spelling) {
            config(['fulfillment.providers' => ['' . $spelling => ['adapter' => FakeGulfAdapter::class]]]);

            $this->assertNull(ConfiguredProvider::fromConfig($spelling), "'{$spelling}' is not the spelling of a stored code");
        }
    }
}
