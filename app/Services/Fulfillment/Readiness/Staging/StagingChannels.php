<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use Closure;

/**
 * Every way the platform can reach a real person or a third party, as a restored copy of production would re-arm it.
 *
 * Almost all of it is configured in database rows (settings, gateway credentials, device tokens, queued jobs) and applied when
 * the application boots, so an environment file cannot switch it off: the rows must be neutralised before the first web,
 * queue or cron process starts. This is the one declaration of what those rows are. The audit counts the ones still armed;
 * the plan generator prints the statements that neutralise them and the check that must then count zero.
 *
 * Table and column names come from the models and queries in this repository: most of these tables are created by the
 * installation dump, not by a migration, so both users of this list first look at the real schema and skip what is not there.
 */
final class StagingChannels
{
    /** @return list<OutboundChannel> */
    public static function all(): array
    {
        return [
            self::queue(),
            self::sessionsAndTokens(),
            self::mail(),
            self::abandonedCartMail(),
            self::monitoringAlerts(),
            self::smsGateways(),
            self::zender(),
            self::firebaseOtp(),
            self::push(),
            self::paymentGateways(),
            self::deliverySyria(),
            self::fulfillmentProviders(),
            self::chatwoot(),
            self::sellerWebhooks(),
            self::remoteStorage(),
            self::analytics(),
            self::aiProvider(),
            self::recaptcha(),
            self::maps(),
            self::socialLogin(),
        ];
    }

    private static function queue(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'queue',
            label: 'Queued jobs restored from production',
            risk: OutboundChannel::CRITICAL,
            why: 'A worker replays whatever the jobs table holds: production\'s pending mail, push notifications and seller webhooks, through whichever channel is still armed.',
            targets: [self::emptied('jobs'), self::emptied('failed_jobs')],
        );
    }

    private static function sessionsAndTokens(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'sessions_and_tokens',
            label: 'Sessions, reset codes and API tokens restored from production',
            risk: OutboundChannel::HIGH,
            why: 'They let a person who held one in production act on the copy, and the application issues the same constant verification codes everywhere (config("app.mode") is never set), so the copy must hold none.',
            targets: array_map(self::emptied(...), ['sessions', 'password_resets', 'password_reset_tokens', 'phone_or_email_verifications', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes', 'personal_access_tokens']),
        );
    }

    private static function mail(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'mail',
            label: 'Outbound e-mail (SMTP / SendGrid)',
            risk: OutboundChannel::CRITICAL,
            why: 'MailConfigServiceProvider replaces the whole mail configuration with these rows whenever their status is on, so MAIL_DRIVER in the environment cannot sink the mail.',
            targets: [new SinkTarget(
                table: 'business_settings',
                columns: ['type', 'value'],
                armed: static fn (SqlDialect $sql): string => implode(' OR ', [$sql->jsonFlag('value', '$.status'), $sql->jsonNonBlank('value', '$.username'), $sql->jsonNonBlank('value', '$.password')]),
                set: static fn (SqlDialect $sql): string => 'value = ' . $sql->jsonSet('value', ['$.status' => '0', '$.host' => "''", '$.username' => "''", '$.password' => "''"]),
                scope: self::ofTypes('mail_config', 'mail_config_sendgrid'),
            )],
        );
    }

    private static function abandonedCartMail(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'abandoned_cart_mail',
            label: 'Abandoned-cart reminder e-mails',
            risk: OutboundChannel::CRITICAL,
            why: 'A scheduled command e-mails real customers\' carts every 30 minutes while this setting is on.',
            targets: [new SinkTarget(
                table: 'business_settings',
                columns: ['type', 'value'],
                armed: static fn (SqlDialect $sql): string => "REPLACE(value, '\"', '') IN ('1', 'true')",
                set: static fn (): string => "value = '0'",
                scope: self::ofTypes('abandoned_cart_reminder_status'),
            )],
        );
    }

    private static function monitoringAlerts(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'monitoring_alerts',
            label: 'Monitoring alert e-mails',
            risk: OutboundChannel::MEDIUM,
            why: 'The default alert rules e-mail the sender address every time they fire.',
            targets: [new SinkTarget(table: 'monitoring_alert_rules', columns: ['notify_email'], armed: static fn (): string => 'notify_email = 1', set: static fn (): string => 'notify_email = 0')],
        );
    }

    private static function smsGateways(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'sms_gateways',
            label: 'SMS gateways (Twilio, Nexmo, 2factor, msg91, ...)',
            risk: OutboundChannel::CRITICAL,
            why: 'The SMS code tests the JSON status inside the credentials, not the is_active column, so the credentials themselves must be removed.',
            targets: [self::gatewayRows('sms_config')],
        );
    }

    private static function zender(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'zender',
            label: 'Zender / Lynomia OTP gateway (SMS and WhatsApp)',
            risk: OutboundChannel::CRITICAL,
            why: 'Sends OTP messages to real phone numbers while enabled with a secret.',
            targets: [new SinkTarget(
                table: 'zender_settings',
                columns: ['enabled', 'secret'],
                armed: static fn (SqlDialect $sql): string => 'enabled = 1 OR ' . $sql->nonBlank('secret'),
                set: static fn (): string => 'enabled = 0, secret = NULL',
            )],
        );
    }

    private static function firebaseOtp(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'firebase_otp',
            label: 'Firebase phone-authentication OTP',
            risk: OutboundChannel::CRITICAL,
            why: 'Sends verification SMS through Google\'s identity toolkit while its status is on.',
            targets: [new SinkTarget(
                table: 'business_settings',
                columns: ['type', 'value'],
                armed: static fn (SqlDialect $sql): string => $sql->jsonFlag('value', '$.status') . ' OR ' . $sql->jsonNonBlank('value', '$.web_api_key'),
                set: static fn (SqlDialect $sql): string => 'value = ' . $sql->jsonSet('value', ['$.status' => '0', '$.web_api_key' => "''"]),
                scope: self::ofTypes('firebase_otp_verification'),
            )],
        );
    }

    private static function push(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'push',
            label: 'FCM push notifications (device tokens and the service account)',
            risk: OutboundChannel::CRITICAL,
            why: 'Order, refund and chat events push to real devices, and a theme or maintenance change pushes to every subscriber of the production app. The service-account row must stay (the code dereferences it) but be empty.',
            targets: [
                self::blankedSettings('push_notification_key', 'fcm_credentials'),
                ...array_map(
                    static fn (array $column): SinkTarget => self::cleared($column[0], $column[1]),
                    [['users', 'cm_firebase_token'], ['users', 'cm_firebase_token_web'], ['sellers', 'cm_firebase_token'], ['sellers', 'cm_firebase_token_web'], ['delivery_men', 'fcm_token'], ['guest_users', 'fcm_token']],
                ),
            ],
        );
    }

    private static function paymentGateways(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'payment_gateways',
            label: 'Payment gateways (credentials and legacy settings)',
            risk: OutboundChannel::CRITICAL,
            why: 'Gateway controllers choose live or test credentials by mode and never check is_active, so switching a gateway off is not enough: its credentials must go. For a rehearsal re-enter sandbox keys with mode "test" only.',
            targets: [self::gatewayRows('payment_config'), self::blankedSettings('paypal', 'razor_pay', 'paystack', 'flutterwave', 'paytm')],
        );
    }

    private static function deliverySyria(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'delivery_syria',
            label: 'Delivery Syria courier (a real parcel is created at checkout)',
            risk: OutboundChannel::CRITICAL,
            why: 'Checkout creates a real courier parcel synchronously for orders that choose this shipping method. It is outside the FULFILLMENT_* switches.',
            targets: [
                new SinkTarget(
                    table: 'business_settings',
                    columns: ['type', 'value'],
                    armed: static fn (SqlDialect $sql): string => implode(' OR ', [$sql->jsonFlag('value', '$.status'), $sql->jsonNonBlank('value', '$.secret_token'), $sql->jsonNonBlank('value', '$.webhook_token')]),
                    set: static fn (SqlDialect $sql): string => 'value = ' . $sql->jsonSet('value', ['$.status' => '0', '$.secret_token' => "''", '$.webhook_token' => "''"]),
                    scope: self::ofTypes('delivery_syria'),
                ),
                new SinkTarget(
                    table: 'business_settings',
                    columns: ['type', 'value'],
                    armed: static fn (): string => '1 = 1',
                    action: SinkTarget::DELETE,
                    scope: self::ofTypes('delivery_syria_webhook_token'),
                ),
                new SinkTarget(
                    table: 'shipping_methods',
                    columns: ['name', 'status'],
                    armed: static fn (): string => 'status = 1',
                    set: static fn (): string => 'status = 0',
                    scope: static fn (): string => "name = 'DeliverySyria'",
                ),
            ],
        );
    }

    private static function fulfillmentProviders(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'fulfillment_providers',
            label: 'Fulfillment providers (M5AZN, Torod Point): credentials and switches',
            risk: OutboundChannel::HIGH,
            why: 'A second layer behind the environment switches: no stored credential, nothing enabled, dispatch off and dry run on. The credentials are encrypted with the production APP_KEY, so a new key already makes them unreadable.',
            targets: [
                new SinkTarget(
                    table: 'integration_settings',
                    columns: ['provider', 'enabled', 'credentials'],
                    armed: static fn (SqlDialect $sql): string => 'enabled = 1 OR ' . $sql->nonBlank('credentials'),
                    set: static fn (): string => 'enabled = 0, credentials = NULL',
                    scope: self::ofProviders('m5azn', 'torod_point'),
                ),
                new SinkTarget(
                    table: 'integration_settings',
                    columns: ['provider', 'settings'],
                    armed: static fn (SqlDialect $sql): string => implode(' OR ', [
                        ...array_map(
                            static fn (string $switch): string => $sql->jsonFlag('settings', '$.' . $switch),
                            ['dispatch_enabled', 'auto_dispatch', 'product_sync_enabled', 'inventory_sync_enabled', 'import_enabled', 'push_products_enabled', 'webhooks_enabled'],
                        ),
                        "(CASE WHEN json_valid(settings) THEN " . $sql->jsonText('settings', '$.dry_run') . " END IN ('0', 'false'))",
                    ]),
                    set: static fn (SqlDialect $sql): string => 'settings = ' . $sql->jsonSet('settings', [
                        '$.dispatch_enabled' => $sql->jsonBoolean(false),
                        '$.auto_dispatch' => $sql->jsonBoolean(false),
                        '$.dry_run' => $sql->jsonBoolean(true),
                        '$.product_sync_enabled' => $sql->jsonBoolean(false),
                        '$.inventory_sync_enabled' => $sql->jsonBoolean(false),
                        '$.import_enabled' => $sql->jsonBoolean(false),
                        '$.push_products_enabled' => $sql->jsonBoolean(false),
                        '$.webhooks_enabled' => $sql->jsonBoolean(false),
                    ]),
                    scope: self::ofProviders('m5azn', 'torod_point'),
                ),
            ],
        );
    }

    private static function chatwoot(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'chatwoot',
            label: 'Chatwoot / WhatsApp templates and manual messages',
            risk: OutboundChannel::CRITICAL,
            why: 'Sends WhatsApp templates to real customers while its integration row is enabled with a token.',
            targets: [
                new SinkTarget(
                    table: 'integration_settings',
                    columns: ['provider', 'enabled', 'credentials'],
                    armed: static fn (SqlDialect $sql): string => 'enabled = 1 OR ' . $sql->nonBlank('credentials'),
                    set: static fn (): string => 'enabled = 0, credentials = NULL',
                    scope: self::ofProviders('chatwoot'),
                ),
                new SinkTarget(table: 'notification_template_mappings', columns: ['enabled'], armed: static fn (): string => 'enabled = 1', set: static fn (): string => 'enabled = 0'),
            ],
        );
    }

    private static function sellerWebhooks(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'seller_webhooks',
            label: 'Seller webhooks (outbound POSTs of order, product and payout data)',
            risk: OutboundChannel::HIGH,
            why: 'Order and product events during a validation would POST to sellers\' own servers, and a five-minute sweep replays pending deliveries.',
            targets: [
                new SinkTarget(table: 'seller_webhooks', columns: ['status'], armed: static fn (): string => "status <> 'disabled'", set: static fn (): string => "status = 'disabled'"),
                new SinkTarget(table: 'seller_webhook_deliveries', columns: ['status'], armed: static fn (): string => "status = 'pending'", set: static fn (): string => "status = 'failed'", scope: static fn (): string => "status = 'pending'"),
            ],
        );
    }

    private static function remoteStorage(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'remote_storage',
            label: 'Remote object storage (S3)',
            risk: OutboundChannel::HIGH,
            why: 'The stored S3 credentials are copied into the filesystem configuration on every web request; admin screens then read and write the production bucket.',
            targets: [
                new SinkTarget(
                    table: 'business_settings',
                    columns: ['type', 'value'],
                    armed: static fn (): string => "REPLACE(value, '\"', '') = 's3'",
                    set: static fn (): string => "value = 'public'",
                    scope: self::ofTypes('storage_connection_type'),
                ),
                new SinkTarget(
                    table: 'business_settings',
                    columns: ['type', 'value'],
                    armed: static fn (SqlDialect $sql): string => $sql->nonBlank('value'),
                    action: SinkTarget::DELETE,
                    scope: self::ofTypes('storage_connection_s3_credential'),
                ),
            ],
        );
    }

    private static function analytics(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'analytics',
            label: 'Analytics and advertising pixels (browser-side)',
            risk: OutboundChannel::MEDIUM,
            why: 'Active rows inject production Google, Meta and other tags into every storefront page, so staging traffic would count as production traffic.',
            targets: [
                new SinkTarget(table: 'analytic_scripts', columns: ['is_active'], armed: static fn (): string => 'is_active = 1', set: static fn (): string => 'is_active = 0'),
                self::blankedSettings('pixel_analytics'),
            ],
        );
    }

    private static function aiProvider(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'ai_provider',
            label: 'AI content generation (OpenAI / Claude)',
            risk: OutboundChannel::MEDIUM,
            why: 'Spends the production API key whenever someone presses a generate button.',
            targets: [new SinkTarget(
                table: 'ai_settings',
                columns: ['status', 'api_key'],
                armed: static fn (SqlDialect $sql): string => 'status = 1 OR ' . $sql->nonBlank('api_key'),
                set: static fn (): string => 'status = 0, api_key = NULL',
            )],
        );
    }

    private static function recaptcha(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'recaptcha',
            label: 'Google reCAPTCHA',
            risk: OutboundChannel::MEDIUM,
            why: 'The production keys do not cover the staging domain, so admin login would fail; switching it off falls back to the built-in captcha.',
            targets: [new SinkTarget(
                table: 'business_settings',
                columns: ['type', 'value'],
                armed: static fn (SqlDialect $sql): string => $sql->jsonFlag('value', '$.status') . ' OR ' . $sql->jsonNonBlank('value', '$.secret_key'),
                set: static fn (SqlDialect $sql): string => 'value = ' . $sql->jsonSet('value', ['$.status' => '0', '$.secret_key' => "''"]),
                scope: self::ofTypes('recaptcha'),
            )],
        );
    }

    private static function maps(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'maps',
            label: 'Google Maps API keys',
            risk: OutboundChannel::LOW,
            why: 'Billable server calls (autocomplete, distance, geocode) on the production key.',
            targets: [self::blankedSettings('map_api_key', 'map_api_key_server')],
        );
    }

    private static function socialLogin(): OutboundChannel
    {
        return new OutboundChannel(
            key: 'social_login',
            label: 'Social login (Google, Facebook, Apple)',
            risk: OutboundChannel::MEDIUM,
            why: 'The providers are stored as a JSON list with one status per provider and the client secrets beside it; no portable statement can edit a list element by element.',
            targets: [new SinkTarget(
                table: 'business_settings',
                columns: ['type', 'value'],
                armed: static fn (): string => "value LIKE '%\"status\":1%' OR value LIKE '%\"status\":\"1\"%' OR value LIKE '%\"status\": 1%' OR value LIKE '%\"status\": \"1\"%'",
                scope: self::ofTypes('social_login', 'apple_login'),
            )],
            manual: 'In the admin panel (Social login settings) set every provider\'s status to off and clear its client secret, or do the same from tinker. Delete any Apple *.p8 key from the staging storage.',
        );
    }

    private static function gatewayRows(string $settingsType): SinkTarget
    {
        return new SinkTarget(
            table: 'addon_settings',
            columns: ['settings_type', 'is_active', 'live_values', 'test_values'],
            armed: static fn (SqlDialect $sql): string => implode(' OR ', ['is_active = 1', $sql->nonBlank('live_values'), $sql->nonBlank('test_values')]),
            set: static fn (): string => 'is_active = 0, live_values = NULL, test_values = NULL',
            scope: static fn (): string => "settings_type = '{$settingsType}'",
        );
    }

    /** Settings rows whose whole value must be emptied. */
    private static function blankedSettings(string ...$types): SinkTarget
    {
        return new SinkTarget(
            table: 'business_settings',
            columns: ['type', 'value'],
            armed: static fn (SqlDialect $sql): string => $sql->nonBlank('value'),
            set: static fn (): string => "value = ''",
            scope: self::ofTypes(...$types),
        );
    }

    private static function cleared(string $table, string $column): SinkTarget
    {
        return new SinkTarget(table: $table, columns: [$column], armed: static fn (SqlDialect $sql): string => $sql->nonBlank($column), set: static fn (): string => "{$column} = NULL");
    }

    private static function emptied(string $table): SinkTarget
    {
        return new SinkTarget(table: $table, columns: [], armed: static fn (): string => '1 = 1', action: SinkTarget::EMPTY);
    }

    private static function ofTypes(string ...$types): Closure
    {
        return static fn (): string => 'type IN (' . implode(', ', array_map(static fn (string $type): string => "'{$type}'", $types)) . ')';
    }

    private static function ofProviders(string ...$providers): Closure
    {
        return static fn (): string => 'provider IN (' . implode(', ', array_map(static fn (string $provider): string => "'{$provider}'", $providers)) . ')';
    }
}
