<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZenderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class ZenderSettingController extends Controller
{
 public function show()
{
    $settings = ZenderSetting::first();

    if (!$settings) {
        $settings = ZenderSetting::create([
            'enabled' => false,
            'base_url' => 'https://otp.lynomia.com',
            'type' => 'sms',
            'message' => 'Your verification code is {{otp}}',
            'expire' => 300,
            'priority' => 2,
        ]);
    }

    return view(
        'admin-views.integrations.otpsettings',
        compact('settings')
    );
}

    public function toggleStatus(Request $request): RedirectResponse
    {
        $settings = ZenderSetting::first();
        $settings?->forceFill(['enabled' => $request->boolean('enabled')])->save();

        return back()->with('success', $settings ? 'Zender status updated.' : 'Zender settings not found.');
    }

    public function update(Request $request)
    {
        $settings = ZenderSetting::first();

        if (!$settings) {
            $settings = new ZenderSetting();
        }

        $validated = $request->validate([
            'enabled' => [
                'nullable',
                'boolean',
            ],

            'base_url' => [
                'required',
                'url',
                'max:500',
            ],

            'secret' => [
                'nullable',
                'string',
            ],

            'type' => [
                'required',
                Rule::in(['sms', 'whatsapp']),
            ],

            'message' => [
                'required',
                'string',
            ],

            'expire' => [
                'required',
                'integer',
                'min:30',
            ],

            'priority' => [
                'nullable',
                'integer',
                Rule::in([1, 2]),
            ],

            'account' => [
                'nullable',
                'string',
                'max:255',
            ],

            'mode' => [
                'nullable',
                Rule::in(['devices', 'credits']),
            ],

            'device' => [
                'nullable',
                'string',
                'max:255',
            ],

            'gateway' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sim' => [
                'nullable',
                'integer',
                Rule::in([1, 2]),
            ],
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        $validated['base_url'] = rtrim(
            $validated['base_url'],
            '/'
        );

        if (empty($validated['secret'])) {
            unset($validated['secret']);
        }

        /*
        |--------------------------------------------------------------------------
        | Clean settings depending on sending type
        |--------------------------------------------------------------------------
        */

        if ($validated['type'] === 'whatsapp') {

            $validated['mode'] = null;
            $validated['device'] = null;
            $validated['gateway'] = null;
            $validated['sim'] = null;

            if (empty($validated['priority'])) {
                $validated['priority'] = 2;
            }
        }

        if ($validated['type'] === 'sms') {

            $validated['account'] = null;
            $validated['priority'] = null;

            if (($validated['mode'] ?? null) === 'devices') {
                $validated['gateway'] = null;
            }

            if (($validated['mode'] ?? null) === 'credits') {
                $validated['device'] = null;
                $validated['sim'] = null;
            }
        }

        $settings->fill($validated);
        $settings->save();

        return redirect()
            ->route('admin.zender.settings')
            ->with(
                'success',
                'Zender settings saved successfully.'
            );
    }

public function test(Request $request)
{
    $request->validate([
        'phone' => ['required', 'string', 'max:30'],
    ]);

    $settings = ZenderSetting::first();

    if (!$settings) {
        return response()->json([
            'success' => false,
            'message' => 'Zender settings not found.',
        ], 404);
    }

    if (empty($settings->secret)) {
        return response()->json([
            'success' => false,
            'message' => 'Zender API secret is missing.',
        ], 422);
    }

    if (empty($settings->base_url)) {
        return response()->json([
            'success' => false,
            'message' => 'Zender Base URL is missing.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    */
    if ($settings->type === 'sms') {

        if (empty($settings->mode)) {
            return response()->json([
                'success' => false,
                'message' => 'SMS mode is missing.',
            ], 422);
        }

        $testOtp = random_int(100000, 999999);

        $message = str_replace(
            '{{otp}}',
            (string) $testOtp,
            $settings->message
        );

        $url = rtrim($settings->base_url, '/') . '/api/send/sms';

        $payload = [
            'secret' => $settings->secret,
            'mode' => $settings->mode,
            'phone' => $request->phone,
         'message' => 'Test SMS from Postman',

            // ثبتنا بالتجربة أن 0 يعمل كـ high priority
            'priority' => 0,
        ];

        /*
        |--------------------------------------------------------------------------
        | Device mode
        |--------------------------------------------------------------------------
        */
        if ($settings->mode === 'devices') {

            if (empty($settings->device)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is missing.',
                ], 422);
            }

            $payload['device'] = $settings->device;

            if (!empty($settings->sim)) {
                $payload['sim'] = (int) $settings->sim;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Credits mode
        |--------------------------------------------------------------------------
        */
        elseif ($settings->mode === 'credits') {

            if (empty($settings->gateway)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway ID is missing.',
                ], 422);
            }

            $payload['gateway'] = $settings->gateway;
        }

        /*
        |--------------------------------------------------------------------------
        | Debug - SMS
        |--------------------------------------------------------------------------
        */
        $debugPayload = $payload;
        $debugPayload['secret'] = '***HIDDEN***';

        \Log::info('========== ZENDER SMS TEST ==========');

        \Log::info('Zender SMS Request', [
            'method' => 'POST',
            'url' => $url,
            'content_type' => 'application/x-www-form-urlencoded',
            'payload' => $debugPayload,
        ]);

        try {

            /*
            |--------------------------------------------------------------------------
            | SMS MUST use asForm()
            |--------------------------------------------------------------------------
            */
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post($url, $payload);

            $responseData = $response->json();

            \Log::info('Zender SMS Response', [
                'http_status' => $response->status(),
                'body' => $responseData ?? $response->body(),
            ]);

            \Log::info('=====================================');

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test SMS submitted successfully.',
                    'data' => $responseData,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Zender rejected the SMS request.',
                'status' => $response->status(),
                'data' => $responseData ?: $response->body(),
            ], $response->status());

        } catch (\Throwable $e) {

            \Log::error('Zender SMS Exception', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not send test SMS.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */
  if ($settings->type === 'whatsapp') {

    if (empty($settings->account)) {
        return response()->json([
            'success' => false,
            'message' => 'WhatsApp account is missing.',
        ], 422);
    }

    $testOtp = random_int(100000, 999999);

    $template = trim((string) $settings->message);

    $message = str_contains($template, '{{otp}}')
        ? str_replace('{{otp}}', (string) $testOtp, $template)
        : $template . ' ' . $testOtp;

    $url = rtrim($settings->base_url, '/') . '/api/send/whatsapp';

    $payload = [
        'secret' => $settings->secret,
        'account' => $settings->account,
        'recipient' => $request->phone,
        'type' => 'text',
        'message' => $message,
        'priority' => (int) ($settings->priority ?? 2),
    ];

    $debugPayload = $payload;
    $debugPayload['secret'] = '***HIDDEN***';

    \Log::info('========== ZENDER WHATSAPP TEST ==========');

    \Log::info('Zender WhatsApp Request', [
        'method' => 'POST',
        'url' => $url,
        'content_type' => 'multipart/form-data',
        'payload' => $debugPayload,
    ]);

    try {

        $http = Http::asMultipart()
            ->acceptJson()
            ->timeout(30);

        foreach ($payload as $key => $value) {
            if ($value !== null && $value !== '') {
                $http = $http->attach(
                    $key,
                    (string) $value
                );
            }
        }

        $response = $http->post($url);

        $responseData = $response->json();

        \Log::info('Zender WhatsApp Response', [
            'http_status' => $response->status(),
            'body' => $responseData ?? $response->body(),
        ]);

        \Log::info('==========================================');

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'message' => 'Test WhatsApp message submitted successfully.',
                'data' => $responseData,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Zender rejected the WhatsApp request.',
            'status' => $response->status(),
            'data' => $responseData ?: $response->body(),
        ], $response->status());

    } catch (\Throwable $e) {

        \Log::error('Zender WhatsApp Exception', [
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Could not send test WhatsApp message.',
            'error' => $e->getMessage(),
        ], 500);
    }
}


    return response()->json([
        'success' => false,
        'message' => 'Invalid Zender sending type.',
    ], 422);
}



}