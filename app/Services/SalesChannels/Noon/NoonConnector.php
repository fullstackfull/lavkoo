<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;

/**
 * noon's side of the integration, as far as noon's own documentation goes.
 *
 * This connector signs in and asks noon who the session belongs to. That is the whole of it, and it is deliberate: of everything noon documents, the sign-in is the only call
 * whose REQUEST BODY is published. Every reference page for stock, price, the catalogue, orders and shipments gives its HTTP method and its exact path — those are recorded in
 * `NoonOperations` — but renders its Request and Responses sections in the browser, so the field names come back empty to anything that fetches the page.
 *
 * So a path being on the allow-list here does not mean a call exists for it. Writing `{"sku": ...}` where noon wants `{"partner_sku": ...}` is a call that fails for a reason
 * no log would explain, or worse, one that succeeds against the wrong field; and the one thing worse than an unbuilt capability is a capability that claims to work. What
 * `capabilityMatrix()` says below is therefore the truth about noon's documentation, not a roadmap.
 *
 * WHAT IS KNOWN AND ALREADY DECIDED, so that it is not re-litigated when the schemas arrive:
 *
 *  - **Stock is addressed by a (warehouse, partner_sku) PAIR**, per the read call's own description. So the warehouses must be listed before a stock figure means anything,
 *    and a noon stock figure is per warehouse — not one number per product.
 *  - **FBN stock is noon's, not ours.** noon's own table puts FBN inventory in a "noon fulfillment center", fulfilled and returned by noon. It must never enter the shared
 *    pool, exactly as Amazon FBA does not. FBPI is the model where the units are on OUR shelf and the shared pool is the right source.
 *  - **FBP does not exist in noon's documentation.** FBN, FBPI and FBPO do. Nothing here will pretend otherwise.
 *  - **FBPI does not take products through the API at all**: its own page says products must be listed in Seller Lab. The Content API's `UpsertProduct` is documented
 *    separately and names no fulfilment model, so whether an FBPI seller may use it is NOT stated either way, and this module will not assume it may.
 */
class NoonConnector implements ChannelConnector
{
    public function __construct(private readonly NoonClient $client)
    {
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Noon;
    }

    /**
     * Signs in and asks noon who the session belongs to. It reads, creates nothing, and changes nothing.
     *
     * A successful answer proves four things at once: the key file is one noon accepts, the project code is the key's own, this server's address is allowed to use it, and
     * the session cookie works on an ordinary call. That is as much as can be proved without a documented second call.
     *
     * An account set to REHEARSE still runs this for real, which is deliberate and not an oversight: a rehearsal withholds writes, and signing in and asking who you are
     * change nothing at noon. A test that refused to run would leave a person unable to check their credentials until they had already turned the writes on.
     */
    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        try {
            $response = $this->client->call($account, 'noon.whoami', 'GET', NoonOperations::paths()['noon.whoami'], capability: IntegrationCapability::ConnectionTest);
        } catch (ChannelUnavailableException $exception) {
            return ConnectionTestResult::failed(
                $exception->getMessage(),
                $exception->reason === ChannelUnavailableException::AUTH_NEEDED ? ConnectionStatus::AuthExpired : ConnectionStatus::Failed,
            );
        } catch (ChannelPermissionDenied $exception) {
            return ConnectionTestResult::failed($exception->getMessage(), ConnectionStatus::Failed);
        }

        if (!$response->ok()) {
            return ConnectionTestResult::failed('noon answered HTTP ' . $response->status . ' to the identity call (' . $response->message() . ').', ConnectionStatus::Failed);
        }

        // The response's own shape is not documented, so nothing is read out of it by name: that it answered at all is the result. Only the project code we sent is shown
        // back, because that is ours and not a guess at noon's.
        return new ConnectionTestResult(
            status: ConnectionStatus::Ok,
            message: 'noon accepted the key file and the session works.',
            granted: ['identity'],
            missing: $this->whatIsNotBuiltYetFor($account),
            details: ['host' => $this->client->baseUrl($account), 'signed_in_at' => $this->client->loginUrl()],
        );
    }

    /**
     * The capabilities an account has switched on that this version cannot actually serve, so the account page says so instead of looking ready.
     *
     * It is not a list of missing permissions at noon: it is a list of promises this module would otherwise be making.
     *
     * @return list<string>
     */
    private function whatIsNotBuiltYetFor(SalesChannelAccount $account): array
    {
        $unservable = [
            IntegrationCapability::ProductSync->value => 'product_sync_not_built_for_noon',
            IntegrationCapability::InventoryPublishing->value => 'inventory_publishing_not_built_for_noon',
            IntegrationCapability::PricePublishing->value => 'price_publishing_not_built_for_noon',
            IntegrationCapability::OrderImport->value => 'order_import_not_built_for_noon',
            IntegrationCapability::FulfillmentDispatch->value => 'fulfillment_dispatch_not_built_for_noon',
            IntegrationCapability::FinancialReconciliation->value => 'financial_reconciliation_not_built_for_noon',
        ];

        $missing = [];
        foreach ((array) ($account->capabilities ?? []) as $held) {
            if (isset($unservable[(string) $held])) {
                $missing[] = $unservable[(string) $held];
            }
        }

        return $missing;
    }

    /** @return array<string, array{documented: bool, built: bool, note: string}> */
    public function capabilityMatrix(): array
    {
        return [
            'service_account_login' => ['documented' => true, 'built' => true, 'note' => 'A JWT signed RS256 with the key file\'s private key (claims sub, iat, jti) is POSTed with the project code to /identity/public/v1/api/login, and noon answers with a session cookie good for 30 days. The ONE call whose request body noon publishes. The sign-in always goes to the production host, even for a sandbox account, because the sandbox would hand out a session production does not know. The cookie is not named in the documentation, so the whole cookie string is kept and replayed.'],
            'identity_whoami' => ['documented' => true, 'built' => true, 'note' => 'GET /identity/v1/whoami. The connection test: it proves the key file, the project code, the address allow-list and the session cookie in one read. Its response shape is not published, so nothing is read out of it by name.'],
            'static_sandbox' => ['documented' => true, 'built' => true, 'note' => 'noon-sandbox-api-gateway.noon.partners is a drop-in replacement for the production host for every call EXCEPT the sign-in. It is a mock: it persists nothing, emits no events, calls nothing downstream, never answers 5xx, and builds its answers out of the request -- so it cannot be used to test stock or pricing logic. Its traffic still counts against the project\'s quota.'],
            'oauth_for_integrators' => ['documented' => true, 'built' => false, 'note' => 'A consent URL at oauth.noon.partners then /identity/oauth/v1/token/create and /identity/oauth/v1/token/exchange, which hand an INTEGRATOR a service-account credential scoped to a seller\'s project. Not built because this application holds one seller\'s own key file; it is for software that onboards many sellers. The operations are registered so the transport would not refuse them.'],
            'warehouses' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/warehouses/list. Needed before any stock call, because noon addresses stock by a (warehouse, partner_sku) pair. Request and response shapes are not published.'],
            'stock' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/stock-list reads and POST /v1/stock-update writes, addressed by (warehouse, partner_sku). Paths and methods verified; the BODIES are not published, so neither is built. FBN stock stays noon\'s own and must never join the shared pool.'],
            'pricing' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/pricing/get and POST /v1/pricing/upsert, by partner SKU. A batch upsert does exist. Bodies not published.'],
            'catalog_product_upsert' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/product/upsert creates a catalogue item or amends one, noon deciding which by our own partner_sku, and an item cannot enter quality review until it is 100% complete. Body not published. NOTE: the FBPI page says an item must be listed in Seller Lab and cannot be created through the API; the Content API names no fulfilment model, so whether an FBPI seller may call this is not stated either way.'],
            'barcode_map' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/barcode/map ties one of our partner_sku values to a barcode, creating the barcode if noon does not hold it. This is how our products would be matched. Body not published.'],
            'fbpi_orders' => ['documented' => true, 'built' => false, 'note' => 'GET /v1/fbpi-order/:fbpi_order_nr/get for one order and POST /v1/fbpi-orders/list for a page of at most 50, continued with the next_token the previous answer gave and filtered by created_after / created_before in UTC. noon sends a webhook when an order is made and the order is then fetched by its number. The buyer\'s details are a separate call. Bodies not published.'],
            'shipments' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/shipment/create, with a unique integration_shipment_nr and an AWB that may be noon\'s or our own courier\'s. Body not published.'],
            'event_destinations' => ['documented' => true, 'built' => false, 'note' => 'POST /v1/destination/https-destination/create registers where noon sends events. Body not published.'],
            'returns' => ['documented' => true, 'built' => false, 'note' => 'A Returns service exists with one documented operation (listing return references). Neither its method nor its path was readable, so nothing is registered for it.'],
            'finance' => ['documented' => false, 'built' => false, 'note' => 'No settlement or fee endpoint was found in noon\'s reference. Until one is, noon fees and commissions cannot be read automatically, and OperationalCosts has nothing to ingest for this channel.'],
            'fbp' => ['documented' => false, 'built' => false, 'note' => 'FBP appears nowhere in noon\'s documentation. FBN (noon\'s warehouse, noon fulfils and handles returns), FBPI (our warehouse, we fulfil) and FBPO (purchase-order driven) are the models it describes. Nothing here assumes an FBP exists.'],
        ];
    }
}
