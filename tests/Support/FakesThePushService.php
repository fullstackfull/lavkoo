<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The push service at its HTTP boundary: a service account in the business settings (its private key is the throwaway key of the API test
 * base, which signs the token request) and Google's token endpoint and FCM both answering 200, so a test reads the requests that would have
 * left the server. Needs the business_settings table and useThrowawayPassportKeys().
 */
trait FakesThePushService
{
    protected const FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/lavkoo-test/messages:send';

    protected function configureThePushService(): void
    {
        DB::table('business_settings')->insert(['type' => 'push_notification_key', 'value' => json_encode([
            'project_id' => 'lavkoo-test', 'client_email' => 'push@lavkoo-test.example', 'private_key' => config('passport.private_key'),
        ])]);
        Cache::flush();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'minted-access-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/lavkoo-test/messages/1']),
        ]);
    }

    /** @return list<Request> */
    protected function pushesSent(): array
    {
        return Http::recorded(static fn (Request $request): bool => str_starts_with($request->url(), 'https://fcm.googleapis.com/'))
            ->map(static fn (array $exchange): Request => $exchange[0])
            ->values()
            ->all();
    }
}
