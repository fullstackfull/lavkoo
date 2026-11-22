<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Models\NotificationTemplateMapping;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingMarket;
use App\Services\Notification\NotificationTypeRegistry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontMarketingStop;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
class CartMarketingController extends Controller
{
    public function __construct(
        private readonly StorefrontMarketingStop $marketingStop,
        private readonly StorefrontCurrency $storefrontCurrency,
    ) {
    }

    public function index(): View
    {
        $carts = $this->withoutStoppedStorefronts(
            Cart::query()
                ->where('is_guest', 0)
                ->whereNotNull('customer_id')
                ->get()
        )->groupBy('customer_id');

        $customers = [];

        foreach ($carts as $customerId => $customerCarts) {

            $customer = User::find($customerId);

            if (!$customer || empty($customer->phone)) {
                continue;
            }

            if (!$this->holdsTheMoneyOfOneStorefront(carts: $customerCarts)) {
                continue;
            }

            $itemsCount = $customerCarts->sum('quantity');

            $cartTotal = $customerCarts->sum(function ($cart) {
                return (float) $cart->price * (int) $cart->quantity;
            });

            $lastActivity = $customerCarts->max('updated_at');

            $customers[] = [
                'customer_id' => $customer->id,
                'name' => trim(
                    ($customer->f_name ?? '') . ' ' .
                    ($customer->l_name ?? '')
                ),
                'phone' => $customer->phone,
                'items_count' => $itemsCount,
                'cart_total' => $cartTotal,
                'last_activity' => $lastActivity,
            ];
        }

        $templates = NotificationTemplateMapping::query()
            ->where('provider', 'chatwoot')
            ->where('event', 'cart_marketing')
            ->where('enabled', true)
            ->get();

        return view(
            'admin-views.integrations.cart-marketing',
            compact('customers', 'templates')
        );
    }




    public function send(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'mapping_id' => [
            'required',
            'integer',
            'exists:notification_template_mappings,id',
        ],

        'customer_ids' => [
            'required',
            'array',
            'min:1',
        ],

        'customer_ids.*' => [
            'integer',
            'exists:users,id',
        ],
    ]);

    $mapping = NotificationTemplateMapping::query()
        ->where('id', $validated['mapping_id'])
        ->where('provider', 'chatwoot')
        ->where('event', 'cart_marketing')
        ->where('enabled', true)
        ->first();

if (!$mapping) {
    return redirect()
        ->route('admin.integrations.cart-marketing.index')
        ->with(
            'error',
            'Selected Cart Marketing template is not available or disabled.'
        );
}

    $chatwoot = app(\App\Services\ChatwootService::class);
    $consentGate = app(MarketingConsentGate::class);
    // Asked once: with the gate off no consent row is read and this send is what it has always been.
    $consentRequired = $consentGate->enabled();

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($validated['customer_ids'] as $customerId) {

        if ($consentRequired && !$consentGate->allowsPerson(
            customerId: (int) $customerId,
            email: User::where('id', $customerId)->value('email'),
            market: MarketingMarket::forCustomer(customerId: (int) $customerId),
            channel: MarketingConsent::CHANNEL_WHATSAPP,
            purpose: MarketingConsent::PURPOSE_CART_MARKETING,
        )) {
            // Not an error: an administrator picking a customer who has not agreed is told, and nothing is sent.
            $skipped++;
            continue;
        }

        try {

            $customer = User::find($customerId);

            if (!$customer || empty($customer->phone)) {
                throw new \RuntimeException(
                    'Customer or phone number not found.'
                );
            }

            $carts = Cart::query()
                ->where('customer_id', $customerId)
                ->where('is_guest', 0)
                ->get();

            if ($carts->isEmpty()) {
                throw new \RuntimeException(
                    'Customer cart is empty.'
                );
            }

            $carts = $this->withoutStoppedStorefronts($carts);

            if ($carts->isEmpty()) {
                throw new \RuntimeException(
                    'Customer cart is in a storefront that may not sell.'
                );
            }

            if (!$this->holdsTheMoneyOfOneStorefront(carts: $carts)) {
                throw new \RuntimeException(
                    'Customer carts are in two storefronts, each with its own money.'
                );
            }

            $itemsCount = $carts->sum('quantity');

            $cartTotal = $carts->sum(function ($cart) {
                return (float) $cart->price
                    * (int) $cart->quantity;
            });

            /*
             * Get real WhatsApp template
             */
            $template = $chatwoot->getWhatsAppTemplate(
                $mapping->template_name,
                $mapping->template_language
            );

            if (!$template) {
                throw new \RuntimeException(
                    'WhatsApp template not found in Chatwoot.'
                );
            }

            /*
             * Resolve template variables
             */
            $bodyParams = [];

            foreach ($mapping->variable_mapping ?? [] as $variable => $field) {

              $value = $this->resolveMarketingField(
    field: $field,
    customer: $customer,
    carts: $carts
);
                $bodyParams[(string) $variable] =
                    (string) ($value ?? '');
            }

            /*
             * Build readable content
             */
            $content = '';

            foreach ($template['components'] ?? [] as $component) {

                if (($component['type'] ?? null) === 'BODY') {
                    $content = $component['text'] ?? '';
                    break;
                }
            }

            foreach ($bodyParams as $variable => $value) {

                $content = str_replace(
                    '{{' . $variable . '}}',
                    $value,
                    $content
                );
            }

            /*
             * Send
             */
            $chatwoot->sendWhatsAppTemplate(
                phone: $customer->phone,
                templateName: $mapping->template_name,
                content: $content,
                bodyParams: $bodyParams,
                language: $mapping->template_language,
                category: $mapping->template_category ?? 'MARKETING',
                name: trim(
                    ($customer->f_name ?? '') . ' ' .
                    ($customer->l_name ?? '')
                ) ?: null,
            );

            $sent++;

        } catch (\Throwable $e) {

            $failed++;

            $errors[] =
                'Customer #' . $customerId .
                ': ' . $e->getMessage();

            \Illuminate\Support\Facades\Log::error(
                'Cart marketing send failed',
                [
                    'customer_id' => $customerId,
                    'template' => $mapping->template_name,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

  if ($failed > 0) {
    return redirect()
        ->route('admin.integrations.cart-marketing.index')
        ->with(
            'error',
            "Sent: {$sent}, Failed: {$failed}. " .
            implode(' | ', $errors)
        );
}

// Nothing is appended while the consent gate is off, where $skipped can only be 0: the message an administrator
// reads today stays the message they read.
$skippedNote = $skipped > 0
    ? ' ' . translate('skipped_for_lack_of_whatsapp_marketing_consent') . ": {$skipped}."
    : '';

return redirect()
    ->route('admin.integrations.cart-marketing.index')
    ->with(
        'success',
        "Cart marketing sent successfully to {$sent} customer(s)." . $skippedNote
    );
}



/**
 * The carts a template may be sent about: a message that invites a purchase does not go about a storefront that may not sell.
 */
private function withoutStoppedStorefronts(Collection $carts): Collection
{
    $stopped = $this->marketingStop->stoppedCartGroups(
        channel: NotificationTypeRegistry::WHATSAPP,
        type: NotificationTypeRegistry::CART_MARKETING,
        cartGroupIds: $carts->pluck('cart_group_id')->unique()->values()->all(),
    );

    return $carts->reject(fn (Cart $cart): bool => in_array((string) $cart->cart_group_id, $stopped, true))->values();
}

/**
 * Whether one figure can be written for these carts. The message and the list carry the money of the whole cart, and while each storefront
 * trades in its own currency the amounts of two of them are in two currencies that nothing converts between (gcc.storefronts.currency), so
 * there is no such figure: a customer whose carts span two storefronts is left out rather than sent a number in no currency. Nothing is
 * asked while that switch is off, where every storefront reads the platform's own currency settings and the amounts can be added.
 */
private function holdsTheMoneyOfOneStorefront(Collection $carts): bool
{
    if (!$this->storefrontCurrency->isEnabled()) {
        return true;
    }

    $countries = StorefrontCatalog::countriesOfCartGroups(
        cartGroupIds: $carts->pluck('cart_group_id')->unique()->values()->all(),
    );

    $named = array_filter(array_merge(...array_values($countries)), static fn (string $country): bool => $country !== '');

    return count(array_unique($named)) <= 1;
}

protected function resolveMarketingField(
    string $field,
    $customer,
    $carts
): mixed {
    if (str_starts_with($field, 'customer.')) {

        $key = substr(
            $field,
            strlen('customer.')
        );

        if ($key === 'full_name') {
            return trim(
                ($customer->f_name ?? '') . ' ' .
                ($customer->l_name ?? '')
            );
        }

        return data_get($customer, $key);
    }

    if (str_starts_with($field, 'cart.')) {

        $key = substr(
            $field,
            strlen('cart.')
        );

        if ($key === 'items_count') {
            return $carts->sum('quantity');
        }

        if ($key === 'total') {
            return $carts->sum(function ($cart) {
                return (float) $cart->price
                    * (int) $cart->quantity;
            });
        }

        return data_get(
            $carts->first(),
            $key
        );
    }

    return null;
}
}