<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The push channel of the shop with no way out to Firebase: the project key an operator saves in the settings, the text an operator switches on for each kind of message, the columns that hold the device
 * token of a customer and of a vendor, and the two hosts the app calls (Google for an access token, Firebase for the message) answered by a stub that records what was sent. For a test case that
 * extends ApiAddressTestCase, so that the project key can be signed with the throwaway key pair of useThrowawayPassportKeys().
 *
 * What was sent is read with takeThePushes(), which hands back the messages sent since the last call, so a step of a test asserts exactly what that step sent and nothing the steps before it did.
 */
trait FakesPushNotifications
{
    private const FIREBASE_PROJECT = 'test-project';

    private const FIREBASE_HOST = 'https://fcm.googleapis.com/';

    private int $pushesTaken = 0;

    /** @param array<string, array<string, string>> $texts the text of each message an operator has switched on, by the kind of person it is for (customer, seller) and its key */
    protected function buildThePushWorld(array $texts): void
    {
        foreach (['users', 'sellers'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('cm_firebase_token')->nullable();
                $blueprint->string('app_language')->default('en');
            });
        }

        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->string('key')->nullable();
            $table->text('message')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        foreach ($texts as $userType => $messages) {
            foreach ($messages as $key => $message) {
                DB::table('notification_messages')->insert(['user_type' => $userType, 'key' => $key, 'message' => $message, 'status' => 1]);
            }
        }

        DB::table('business_settings')->insert(['type' => 'push_notification_key', 'value' => json_encode([
            'project_id' => self::FIREBASE_PROJECT, 'client_email' => 'push@' . self::FIREBASE_PROJECT . '.example.test', 'private_key' => config('passport.private_key'),
        ])]);
        clearWebConfigCacheKeys();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-token-of-the-test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/' . self::FIREBASE_PROJECT . '/messages/1']),
        ]);
        $this->pushesTaken = 0;
    }

    /** @return list<array{token: string, data: array<string, string>, notification: array{title: string, body: string}}> the messages sent to Firebase since the last call */
    protected function takeThePushes(): array
    {
        $sent = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::FIREBASE_HOST))
            ->map(fn (array $exchange) => $exchange[0]->data()['message'])
            ->values();

        $new = $sent->slice($this->pushesTaken)->values()->all();
        $this->pushesTaken = $sent->count();

        return $new;
    }
}
