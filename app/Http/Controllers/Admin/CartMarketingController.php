<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use App\Models\NotificationTemplateMapping;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
class CartMarketingController extends Controller
{
    public function index(): View
    {
        $carts = Cart::query()
            ->where('is_guest', 0)
            ->whereNotNull('customer_id')
            ->get()
            ->groupBy('customer_id');

        $customers = [];

        foreach ($carts as $customerId => $customerCarts) {

            $customer = User::find($customerId);

            if (!$customer || empty($customer->phone)) {
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

    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($validated['customer_ids'] as $customerId) {

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

return redirect()
    ->route('admin.integrations.cart-marketing.index')
    ->with(
        'success',
        "Cart marketing sent successfully to {$sent} customer(s)."
    );
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