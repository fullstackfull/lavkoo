<?php

namespace App\Services;

use App\Models\NotificationTemplateMapping;
use App\Models\Order;
use Illuminate\Support\Facades\Log;



class NotificationAutomationService
{
    public function __construct(
        protected ChatwootService $chatwoot
    ) {
    }

   public function trigger(string $event, array $orderIds): array
{
    $result = [
        'event' => $event,
        'orders' => 0,
        'sent' => 0,
        'failed' => 0,
        'errors' => [],
    ];

    if (empty($orderIds)) {
        $result['errors'][] = 'No order IDs provided.';
        return $result;
    }

    $mappings = \App\Models\NotificationTemplateMapping::where('provider', 'chatwoot')
        ->where('event', $event)
        ->where('enabled', true)
        ->get();

    if ($mappings->isEmpty()) {
        $result['errors'][] = 'No enabled notification mappings found for this event.';
        return $result;
    }

    foreach ($orderIds as $orderId) {

        $order = \App\Models\Order::with([
            'customer',
            'seller',
        ])->find($orderId);

        if (!$order) {
            $result['failed']++;

            $result['errors'][] = [
                'order_id' => $orderId,
                'message' => 'Order not found.',
            ];

            continue;
        }

        $result['orders']++;

        foreach ($mappings as $mapping) {

            try {

                $this->processMapping(
                    mapping: $mapping,
                    order: $order
                );

                $result['sent']++;

            } catch (\Throwable $e) {

                $result['failed']++;

                $result['errors'][] = [
                    'order_id' => $order->id,
                    'template' => $mapping->template_name,
                    'message' => $e->getMessage(),
                ];

                \Illuminate\Support\Facades\Log::error(
                    'Notification automation mapping failed',
                    [
                        'order_id' => $order->id,
                        'event' => $event,
                        'template' => $mapping->template_name,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    return $result;
}

    protected function processMapping(
        NotificationTemplateMapping $mapping,
        Order $order
    ): void {
        $template = $this->chatwoot->getWhatsAppTemplate(
            $mapping->template_name,
            $mapping->template_language
        );

        if (!$template) {
            throw new \RuntimeException(
                "WhatsApp template {$mapping->template_name} not found."
            );
        }

        $phone = $this->resolveField(
            $mapping->recipient_field,
            $order
        );

        if (!$phone) {
            throw new \RuntimeException(
                "Recipient phone could not be resolved."
            );
        }

        $bodyParams = [];

        foreach ($mapping->variable_mapping ?? [] as $variable => $field) {

            if (!$field) {
                continue;
            }

            $value = $this->resolveField(
                field: $field,
                order: $order
            );

            $bodyParams[(string) $variable] = (string) ($value ?? '');
        }

        $content = $this->buildTemplateContent(
            template: $template,
            bodyParams: $bodyParams
        );

        $customerName = $order->customer
            ? trim(
                ($order->customer->f_name ?? '') . ' ' .
                ($order->customer->l_name ?? '')
            )
            : null;

        $this->chatwoot->sendWhatsAppTemplate(
            phone: $phone,
            templateName: $mapping->template_name,
            content: $content,
            bodyParams: $bodyParams,
            language: $mapping->template_language,
            category: $mapping->template_category ?? 'UTILITY',
            name: $customerName ?: null,
        );
    }

    protected function resolveField(
        ?string $field,
        Order $order
    ): mixed {
        if (!$field) {
            return null;
        }

        return match ($field) {

            /*
             * Customer
             */
            'customer.f_name' =>
                $order->customer?->f_name,

            'customer.l_name' =>
                $order->customer?->l_name,

            'customer.full_name' =>
                trim(
                    ($order->customer?->f_name ?? '') . ' ' .
                    ($order->customer?->l_name ?? '')
                ),

            'customer.phone' =>
                $order->customer?->phone,

            'customer.email' =>
                $order->customer?->email,

            /*
             * Order
             */
            'order.id' =>
                $order->id,

            'order.order_amount' =>
                $order->order_amount,

            'order.order_status' =>
                $order->order_status,

            'order.payment_status' =>
                $order->payment_status,

            'order.payment_method' =>
                $order->payment_method,

            /*
             * Vendor
             */
            'vendor.f_name' =>
                $order->seller?->f_name,

            'vendor.l_name' =>
                $order->seller?->l_name,

            'vendor.phone' =>
                $order->seller?->phone,

            /*
             * Shipping
             */
            'shipping.phone' =>
                data_get(
                    $order->shipping_address_data,
                    'phone'
                ),

            /*
             * Billing
             */
            'billing.phone' =>
                data_get(
                    $order->billing_address_data,
                    'phone'
                ),

            default => null,
        };
    }

    protected function buildTemplateContent(
        array $template,
        array $bodyParams
    ): string {
        $body = '';

        foreach ($template['components'] ?? [] as $component) {

            if (($component['type'] ?? null) === 'BODY') {
                $body = $component['text'] ?? '';
                break;
            }
        }

        foreach ($bodyParams as $variable => $value) {
            $body = str_replace(
                '{{' . $variable . '}}',
                (string) $value,
                $body
            );
        }

        return $body;
    }



    public function triggerOrderStatusChanged(
    int $orderId,
    string $newStatus
): array {
    $result = [
        'event' => 'order_status_changed',
        'order_id' => $orderId,
        'status' => $newStatus,
        'sent' => 0,
        'failed' => 0,
        'errors' => [],
    ];

    $order = Order::with([
        'customer',
        'seller',
    ])->find($orderId);

    if (!$order) {
        $result['failed']++;
        $result['errors'][] = 'Order not found.';
        return $result;
    }

    $mappings = NotificationTemplateMapping::where('provider', 'chatwoot')
        ->where('event', 'order_status_changed')
        ->where('enabled', true)
        ->get();

    foreach ($mappings as $mapping) {

        /*
         * إذا الـtemplate مربوط بحالة معينة
         * وما تطابق الحالة الجديدة، نتجاهله
         */
        if (
            !empty($mapping->event_condition) &&
            $mapping->event_condition !== $newStatus
        ) {
            continue;
        }

        try {

            $this->processMapping(
                mapping: $mapping,
                order: $order
            );

            $result['sent']++;

        } catch (\Throwable $e) {

            $result['failed']++;

            $result['errors'][] = [
                'template' => $mapping->template_name,
                'message' => $e->getMessage(),
            ];

            Log::error('Order status automation failed', [
                'order_id' => $orderId,
                'status' => $newStatus,
                'template' => $mapping->template_name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return $result;
}
}