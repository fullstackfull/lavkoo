<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplateMapping;
use App\Services\ChatwootService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class IntegrationTemplateController extends Controller
{
    public function __construct(
        protected ChatwootService $chatwoot
    ) {
    }

    public function index(): View
    {
        try {
            $templates = $this->chatwoot->getWhatsAppTemplates();
        } catch (Throwable $e) {
            $templates = [];

            session()->flash(
                'error',
                'Unable to load WhatsApp templates: ' . $e->getMessage()
            );
        }

        /*
         * جميع الإعدادات المحفوظة محليًا.
         * المفتاح = template_name|language
         */
        $savedMappings = NotificationTemplateMapping::where(
            'provider',
            'chatwoot'
        )
            ->get()
            ->keyBy(function ($mapping) {
                return $mapping->template_name . '|' . $mapping->template_language;
            });

        /*
         * تجهيز الـTemplates للواجهة.
         */
        $templates = collect($templates)
            ->map(function (array $template) use ($savedMappings) {

                $templateName = $template['name'] ?? '';
                $language = $template['language'] ?? '';

                $key = $templateName . '|' . $language;

                $mapping = $savedMappings->get($key);

                /*
                 * استخراج variables مثل:
                 * ["1", "2"]
                 */
                $variables = $this->chatwoot->getTemplateVariables(
                    $template
                );

                /*
                 * استخراج BODY للعرض في الصفحة.
                 */
                $body = null;

                foreach ($template['components'] ?? [] as $component) {
                    if (($component['type'] ?? null) === 'BODY') {
                        $body = $component['text'] ?? null;
                        break;
                    }
                }

                /*
                 * Header اختياري.
                 */
                $header = null;

                foreach ($template['components'] ?? [] as $component) {
                    if (($component['type'] ?? null) === 'HEADER') {
                        $header = $component['text'] ?? null;
                        break;
                    }
                }

                return [
                    'id' => $template['id'] ?? null,

                    'name' => $templateName,

                    'language' => $language,

                    'category' => $template['category'] ?? null,

                    'status' => $template['status'] ?? null,

                    'parameter_format' =>
                        $template['parameter_format'] ?? null,

                    'header' => $header,

                    'body' => $body,

                    'variables' => $variables,

                    'variables_count' => count($variables),

                    /*
                     * إعداداتنا المحلية.
                     */
                    'mapping_id' => $mapping?->id,

                    'enabled' => (bool) ($mapping?->enabled ?? false),

                    'event' => $mapping?->event,
 'event_condition' => $mapping?->event_condition,
                    'recipient_field' => $mapping?->recipient_field,

                    'variable_mapping' =>
                        $mapping?->variable_mapping ?? [],
                ];
            })
            ->values();

        return view(
            'admin-views.integrations.templates',
            [
                'templates' => $templates,
                'events' => $this->events(),
                'orderStatuses' => $this->orderStatuses(),
                'recipientFields' => $this->recipientFields(),
                'availableFields' => $this->availableFields(),
            ]
        );
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'template_name' => [
                'required',
                'string',
                'max:150',
            ],
'event_condition' => [
    'nullable',
    'string',
    'max:100',
],
            'template_language' => [
                'required',
                'string',
                'max:20',
            ],

            'template_category' => [
                'nullable',
                'string',
                'max:50',
            ],

            'event' => [
                'nullable',
                'string',
                'max:100',
            ],

            'recipient_field' => [
                'nullable',
                'string',
                'max:150',
            ],

            'variable_mapping' => [
                'nullable',
                'array',
            ],

            'variable_mapping.*' => [
                'nullable',
                'string',
                'max:150',
            ],
        ]);

        NotificationTemplateMapping::updateOrCreate(
            [
                'provider' => 'chatwoot',

                'template_name' =>
                    $validated['template_name'],

                'template_language' =>
                    $validated['template_language'],
            ],
            [
                'template_category' =>
                    $validated['template_category'] ?? null,

                'event' =>
                    $validated['event'] ?? null,
'event_condition' =>
    $validated['event_condition'] ?? null,
                'recipient_field' =>
                    $validated['recipient_field'] ?? null,

                'variable_mapping' =>
                    $validated['variable_mapping'] ?? [],

                'enabled' =>
                    $request->boolean('enabled'),
            ]
        );

        return redirect()
            ->route('admin.integrations.templates.index')
            ->with(
                'success',
                'Template automation settings saved successfully.'
            );
    }
protected function orderStatuses(): array
{
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'out_for_delivery' => 'Out For Delivery',
        'delivered' => 'Delivered',
        'canceled' => 'Canceled',
    ];
}
    protected function events(): array
    {
        return [
            'order_created' =>
                'Order Created',

            'order_status_changed' =>
                'Order Status Changed',

  

            'cart_marketing' =>
                'Abandoned Cart Reminder',

        
        ];
    }

    protected function recipientFields(): array
    {
        return [
            'customer.phone' =>
                'Customer Phone',

            'billing.phone' =>
                'Billing Phone',

            'shipping.phone' =>
                'Shipping Phone',

            'vendor.phone' =>
                'Vendor Phone',
        ];
    }

    protected function availableFields(): array
    {
        return [
            /*
             * Customer
             */
            'customer.f_name' =>
                'Customer First Name',

            'customer.l_name' =>
                'Customer Last Name',

            'customer.full_name' =>
                'Customer Full Name',

            'customer.phone' =>
                'Customer Phone',

            'customer.email' =>
                'Customer Email',

            /*
             * Order
             */
            'order.id' =>
                'Order ID',

            'order.order_amount' =>
                'Order Amount',

            'order.order_status' =>
                'Order Status',

            'order.payment_status' =>
                'Payment Status',

            'order.payment_method' =>
                'Payment Method',

            /*
             * Vendor
             */
            'vendor.f_name' =>
                'Vendor First Name',

            'vendor.l_name' =>
                'Vendor Last Name',

            'vendor.phone' =>
                'Vendor Phone',
        ];
    }
}