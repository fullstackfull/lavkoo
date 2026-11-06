<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;

/**
 * The environment half of "safe to start": the settings that decide whether this host can mail, SMS, push, log to, store on
 * or license-check against a third party. It reports whether each variable is SET, never what it is set to; the only values
 * printed are the names of drivers and channels, which are not secret.
 */
final class EnvironmentAudit
{
    private const SECTION = 'environment';

    /** Variables whose presence means a live credential of the platform is in this host's environment. */
    private const CREDENTIAL_VARIABLES = [
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'OPENAI_API_KEY', 'NEXMO_KEY', 'NEXMO_SECRET', 'RAZOR_KEY', 'RAZOR_SECRET',
        'PAYSTACK_SECRET_KEY', 'FLW_SECRET_KEY', 'PAYTM_MERCHANT_KEY', 'PUSHER_APP_SECRET', 'LOG_SLACK_WEBHOOK_URL', 'PAPERTRAIL_URL',
    ];

    /** Variables that feed the vendor licence call. */
    private const LICENCE_VARIABLES = ['PURCHASE_CODE', 'BUYER_USERNAME', 'ADMIN_NAME', 'ADMIN_IDENTIFIER'];

    /** @return list<CompatibilityCheck> */
    public function checks(?string $productionHost = null): array
    {
        return [
            $this->configurationCache(),
            $this->applicationEnvironment(),
            $this->debugMode(),
            $this->licenceCall(),
            $this->licenceVariables(),
            $this->mail(),
            $this->queue(),
            $this->broadcasting(),
            $this->credentialVariables(),
            $this->session(),
            $this->applicationUrl($productionHost),
            $this->serviceWorker(),
            $this->privateKeyFiles(),
        ];
    }

    private function configurationCache(): CompatibilityCheck
    {
        return $this->check(
            'configuration cache',
            !app()->configurationIsCached(),
            'config:cache is not in use',
            'config:cache is in effect: env() calls outside the config files return null, which silently disables FORCE_HTTPS, DEVELOPMENT_ENVIRONMENT and the payment overrides on this application. Run `php artisan config:clear` and never cache the configuration.',
        );
    }

    private function applicationEnvironment(): CompatibilityCheck
    {
        $environment = app()->environment();

        return match (true) {
            $environment === 'production' => $this->failed('application environment', 'APP_ENV is production: this is not a staging copy.'),
            $environment === 'local' => new CompatibilityCheck(self::SECTION, 'application environment', CompatibilityCheck::WARN, 'APP_ENV is local: it changes upload and debug behaviour and, with DISABLE_ACTIVATION_CHECK, bypasses the licence check. Use "staging".'),
            default => $this->passed('application environment', "APP_ENV is {$environment}"),
        };
    }

    private function debugMode(): CompatibilityCheck
    {
        return $this->check('debug mode', !config('app.debug'), 'APP_DEBUG is off', 'APP_DEBUG is on: an error page prints stack traces and configuration. Set APP_DEBUG=false.');
    }

    private function licenceCall(): CompatibilityCheck
    {
        return $this->check(
            'vendor licence call',
            filter_var(env('DEVELOPMENT_ENVIRONMENT'), FILTER_VALIDATE_BOOLEAN),
            'DEVELOPMENT_ENVIRONMENT is on: the licence server is not contacted',
            'DEVELOPMENT_ENVIRONMENT is not on: the first admin, vendor or seller request each day sends the purchase key and this domain to the vendor\'s licence server (check.6amtech.com) and may lock the admin panel. Set DEVELOPMENT_ENVIRONMENT=true as a real environment variable, or block that host; never use APP_ENV=local for this.',
        );
    }

    private function licenceVariables(): CompatibilityCheck
    {
        $set = array_values(array_filter(self::LICENCE_VARIABLES, static fn (string $name): bool => (string) env($name) !== ''));

        return $this->check('licence identity variables', $set === [], 'none of ' . implode(', ', self::LICENCE_VARIABLES) . ' is set', 'set, and part of the licence payload: ' . implode(', ', $set) . '. Do not copy production\'s .env.', CompatibilityCheck::WARN);
    }

    private function mail(): CompatibilityCheck
    {
        $driver = (string) config('mail.driver', config('mail.default', ''));

        return $this->check(
            'mail transport',
            in_array($driver, ['log', 'array'], true),
            "MAIL_DRIVER is {$driver}: nothing is sent",
            "MAIL_DRIVER is " . ($driver === '' ? 'not set' : $driver) . '. This application reads MAIL_DRIVER, not MAIL_MAILER, and the stored mail settings replace it while their status is on (see the mail channel below). Set MAIL_DRIVER=log.',
        );
    }

    private function queue(): CompatibilityCheck
    {
        $connection = (string) config('queue.default');

        return $this->check('queue connection', $connection !== 'sync', "QUEUE_CONNECTION is {$connection}", 'QUEUE_CONNECTION is sync: every listener (mail, push) runs inside the web request and sends at once. Use the database queue and start no worker until the channels below are quiet.', CompatibilityCheck::WARN);
    }

    private function broadcasting(): CompatibilityCheck
    {
        $driver = (string) config('broadcasting.default', '');

        return $this->check('broadcasting', in_array($driver, ['', 'null', 'log'], true), 'broadcasting is off', "BROADCAST_DRIVER is {$driver}: leave it null.", CompatibilityCheck::WARN);
    }

    private function credentialVariables(): CompatibilityCheck
    {
        $set = array_values(array_filter(self::CREDENTIAL_VARIABLES, static fn (string $name): bool => (string) env($name) !== ''));

        return $this->check('third-party credentials in the environment', $set === [], 'none of ' . count(self::CREDENTIAL_VARIABLES) . ' known credential variables is set', 'set: ' . implode(', ', $set) . '. Unset them on a staging host.');
    }

    private function session(): CompatibilityCheck
    {
        $problems = array_filter([
            config('session.driver') === 'array' ? 'SESSION_DRIVER is array' : null,
            config('session.secure') ? null : 'SESSION_SECURE_COOKIE is off',
        ]);

        return $this->check('sessions', $problems === [], 'file-backed sessions with secure cookies', implode('; ', $problems) . '.', CompatibilityCheck::WARN);
    }

    private function applicationUrl(?string $productionHost): CompatibilityCheck
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return match (true) {
            $productionHost !== null && $host !== '' && str_contains($host, strtolower($productionHost)) => $this->failed('application URL', 'APP_URL points at the production host: payment return URLs, signed links and the domain sent to the licence server all derive from it.'),
            $host === '' || $host === 'localhost' => new CompatibilityCheck(self::SECTION, 'application URL', CompatibilityCheck::WARN, 'APP_URL is not set to the staging origin: payment return URLs, mail links and signed links derive from it.'),
            default => $this->passed('application URL', "APP_URL host is {$host}"),
        };
    }

    private function serviceWorker(): CompatibilityCheck
    {
        $carries = array_filter([public_path('firebase-messaging-sw.js'), base_path('firebase-messaging-sw.js')], static fn (string $path): bool => is_file($path) && preg_match('/apiKey\s*:\s*[\'"][^\'"]{8,}/', (string) file_get_contents($path)) === 1);

        return $this->check('Firebase web config in the service worker', $carries === [], 'no service worker carries a Firebase API key', 'a firebase-messaging-sw.js file carries a Firebase web config and is served to browsers: replace it with an empty stub on the staging host.', CompatibilityCheck::WARN);
    }

    private function privateKeyFiles(): CompatibilityCheck
    {
        $found = array_filter(glob(public_path('assets/back-end/*.p8')) ?: [], static fn (string $path): bool => str_contains((string) file_get_contents($path), 'PRIVATE KEY'));

        return $this->check('private key files under public/', $found === [], 'no private key file is served from public/assets/back-end', count($found) . ' private key file(s) (*.p8) sit under public/assets/back-end and are served to anyone: remove them from the staging host and have the owner rotate the key.', CompatibilityCheck::WARN);
    }

    private function check(string $name, bool $ok, string $passed, string $failed, string $failStatus = CompatibilityCheck::FAIL): CompatibilityCheck
    {
        return new CompatibilityCheck(self::SECTION, $name, $ok ? CompatibilityCheck::PASS : $failStatus, $ok ? $passed : $failed);
    }

    private function passed(string $name, string $detail): CompatibilityCheck
    {
        return new CompatibilityCheck(self::SECTION, $name, CompatibilityCheck::PASS, $detail);
    }

    private function failed(string $name, string $detail): CompatibilityCheck
    {
        return new CompatibilityCheck(self::SECTION, $name, CompatibilityCheck::FAIL, $detail);
    }
}
