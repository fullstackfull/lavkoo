<?php

namespace App\Services;

use App\Models\ZenderSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZenderOtpService
{
    public function send(string $phone, string|int $otp): bool
    {
        $settings = ZenderSetting::first();

        if (!$settings || !$settings->enabled) {
            return false;
        }

        if (empty($settings->secret) || empty($settings->base_url)) {
            Log::error('Zender OTP: Missing configuration.');

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | SMS
        |--------------------------------------------------------------------------
        */

        if ($settings->type === 'sms') {
            return $this->sendSms(
                settings: $settings,
                phone: $phone,
                otp: $otp
            );
        }

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

        if ($settings->type === 'whatsapp') {
            return $this->sendWhatsApp(
                settings: $settings,
                phone: $phone,
                otp: $otp
            );
        }

        return false;
    }


    private function sendSms(
        ZenderSetting $settings,
        string $phone,
        string|int $otp
    ): bool {

        if (empty($settings->mode)) {
            Log::error('Zender SMS: Sending mode is missing.');

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate message
        |--------------------------------------------------------------------------
        */

        $template = trim((string) $settings->message);

        if (str_contains($template, '{{otp}}')) {
            $message = str_replace(
                '{{otp}}',
                (string) $otp,
                $template
            );
        } else {
            $message = $template . ' ' . $otp;
        }

        $url = rtrim(
            $settings->base_url,
            '/'
        ) . '/api/send/sms';

        $payload = [
            'secret' => $settings->secret,
            'mode' => $settings->mode,
            'phone' => $phone,
            'message' => $message,

            // نفس القيمة التي ثبتت معنا بالاختبار
            'priority' => 0,
        ];

        /*
        |--------------------------------------------------------------------------
        | Devices
        |--------------------------------------------------------------------------
        */

        if ($settings->mode === 'devices') {

            if (empty($settings->device)) {
                Log::error('Zender SMS: Device ID is missing.');

                return false;
            }

            $payload['device'] = $settings->device;

            if (!empty($settings->sim)) {
                $payload['sim'] = (int) $settings->sim;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Credits
        |--------------------------------------------------------------------------
        */

        elseif ($settings->mode === 'credits') {

            if (empty($settings->gateway)) {
                Log::error('Zender SMS: Gateway ID is missing.');

                return false;
            }

            $payload['gateway'] = $settings->gateway;
        }

        try {

            Log::info('Zender OTP SMS Request', [
                'url' => $url,
                'phone' => $phone,
                'mode' => $settings->mode,
                'device' => $settings->device,
                'gateway' => $settings->gateway,
                'sim' => $settings->sim,
                'message' => $message,
            ]);

            /*
            |--------------------------------------------------------------------------
            | مهم
            |--------------------------------------------------------------------------
            | هذا هو الشكل الذي ثبت أنه يعمل معنا.
            */

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post($url, $payload);

            $data = $response->json();

            Log::info('Zender OTP SMS Response', [
                'status' => $response->status(),
                'body' => $data ?? $response->body(),
            ]);

            return $response->successful()
                && (($data['status'] ?? null) == 200);

        } catch (\Throwable $e) {

            Log::error('Zender OTP SMS Error', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }


    private function sendWhatsApp(
    ZenderSetting $settings,
    string $phone,
    string|int $otp
): bool {

    if (empty($settings->account)) {
        Log::error('Zender WhatsApp: Account ID is missing.');

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Generate message
    |--------------------------------------------------------------------------
    */

    $template = trim((string) $settings->message);

    if (str_contains($template, '{{otp}}')) {
        $message = str_replace(
            '{{otp}}',
            (string) $otp,
            $template
        );
    } else {
        $message = $template . ' ' . $otp;
    }

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    */

    $url = rtrim(
        $settings->base_url,
        '/'
    ) . '/api/send/whatsapp';

    /*
    |--------------------------------------------------------------------------
    | Payload
    |--------------------------------------------------------------------------
    */

    $payload = [
        'secret' => $settings->secret,
        'account' => $settings->account,
        'recipient' => $phone,
        'type' => 'text',
        'message' => $message,
        'priority' => (int) ($settings->priority ?? 2),
    ];

    try {

        $debugPayload = $payload;
        $debugPayload['secret'] = '***HIDDEN***';

        Log::info('Zender WhatsApp Request', [
            'url' => $url,
            'payload' => $debugPayload,
        ]);

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

        $data = $response->json();

        Log::info('Zender WhatsApp Response', [
            'status' => $response->status(),
            'body' => $data ?? $response->body(),
        ]);

        return $response->successful()
            && (($data['status'] ?? null) == 200);

    } catch (\Throwable $e) {

        Log::error('Zender WhatsApp Error', [
            'message' => $e->getMessage(),
        ]);

        return false;
    }
}
}