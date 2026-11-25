<?php

namespace App\Services;

use App\Models\ZenderSetting;
use App\Services\Notification\NotificationProfiles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZenderOtpService
{
    public function send(string $phone, string|int $otp): bool
    {
        $settings = $this->accountOfTheMarketInForce() ?? ZenderSetting::first();

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

    /**
     * The Zender account of the market this code belongs to (I1-02), or null when that market has none of its own and the one shared
     * account stands -- which is every market until an operator writes a row, and every market while the switch is off.
     *
     * The account is built as an UNSAVED ZenderSetting rather than as a second shape, so that everything below it -- the two senders, their
     * refusals and the encrypted `secret` cast -- reads one kind of object and nothing had to be retyped. It is never saved: `first()` is
     * still what a platform with one account reads, by the same query.
     *
     * A market whose profile names no Zender account at all is NOT sent through the shared one: its profile answers an account with nothing
     * in it, so the send refuses for want of configuration rather than going out through another market's gateway.
     */
    private function accountOfTheMarketInForce(): ?ZenderSetting
    {
        $profile = app(NotificationProfiles::class)->for(channel: NotificationProfiles::SMS);
        $account = $profile?->setting('zender');

        if (!is_array($account)) {
            return $profile === null || !$profile->namesGateways() ? null : new ZenderSetting(['enabled' => false]);
        }

        $secret = $profile->credential('zender.secret');

        return new ZenderSetting($account + (is_string($secret) ? ['secret' => $secret] : []));
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
        $debugPayload['message'] = '***HIDDEN***';

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