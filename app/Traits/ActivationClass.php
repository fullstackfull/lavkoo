<?php

namespace App\Traits;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait ActivationClass
{
    public function is_local(): bool
    {
        return in_array(request()->ip(), ['127.0.0.1', '::1']);
    }

    public function getDomain(): string
    {
        return str_replace(["http://", "https://", "www."], "", url('/'));
    }

    public function getSystemAddonCacheKey(string|null $app = 'default'): string
    {
        return str_replace('-', '_', Str::slug('cache_system_addons_for_' . $app . '_' . $this->getDomain()));
    }

    public function getAddonsConfig(): array
    {
        if (file_exists(base_path('config/system-addons.php'))) {
            return include(base_path('config/system-addons.php'));
        }

        $apps = ['admin_panel', 'vendor_panel', 'user_app', 'vendor_app', 'deliveryman_app', 'theme_lifestyle'];
        $appConfig = [];
        foreach ($apps as $app) {
            $appConfig[$app] = [
                "active" => "0",
                "name" => "",
                "identifier" => "",
                "username" => "",
                "purchase_key" => "",
                "software_id" => "",
                "domain" => "",
                "software_type" => $app == 'admin_panel' ? "product" : 'addon',
            ];
        }
        return $appConfig;
    }

    public function getCacheTimeoutByDays(int $days = 3): int
    {
        return 60 * 60 * 24 * $days;
    }

    public function getRequestConfig(string|null $username = null, string|null $purchaseKey = null, string|null $softwareId = null, string|null $softwareType = null, string|null $name = null, string|null $identifier = null): array
    {
        $isLocal = false;
        if ($this->is_local() || env('DEVELOPMENT_ENVIRONMENT', false) || config('app.mode') == 'demo') {
            $isLocal = true;
        }

        $errors = [];
        $activeStatus = base64_encode(1);
        if(!$isLocal) {
            try {
                $response = Http::post(base64_decode('aHR0cHM6Ly9jaGVjay42YW10ZWNoLmNvbS9hcGkvdjIvcmVnaXN0ZXItZG9tYWlu'), [
                    base64_decode('dXNlcm5hbWU=') => trim($username),
                    base64_decode('cHVyY2hhc2Vfa2V5') => $purchaseKey,
                    base64_decode('c29mdHdhcmVfaWQ=') => base64_decode($softwareId ?? SOFTWARE_ID),
                    base64_decode('ZG9tYWlu') => $this->getDomain(),
                    base64_decode('c29mdHdhcmVfdHlwZQ==') => $softwareType,
                    base64_decode('bmFtZQ==') => $name ?? env('ADMIN_NAME', ''),
                    base64_decode('ZW1haWw=') => $identifier ?? base64_decode(env('ADMIN_IDENTIFIER', '')),
                ])->json();
                $activeStatus = $response['active'] ?? base64_encode(1);
                if (!base64_decode($activeStatus) && !empty($response['errors'])) {
                    $errors = $response['errors'];
                }
            } catch (Exception $exception) {
                $activeStatus = base64_encode(1);
            }
        }

        return [
            "active" => base64_decode($activeStatus),
            "name" => $name,
            "identifier" => $identifier,
            "username" => trim($username),
            "purchase_key" => $purchaseKey,
            "software_id" => $softwareId ?? SOFTWARE_ID,
            "domain" => $this->getDomain(),
            "software_type" => $softwareType,
            "errors" => $errors,
        ];
    }

    public function checkActivationCache(string|null $app)
    {
        if ($this->is_local() || is_null($app) || env('DEVELOPMENT_ENVIRONMENT', false) || config('app.mode') == 'demo') {
            return true;
        }

        $config = $this->getAddonsConfig();
        $cacheKey = $this->getSystemAddonCacheKey(app: $app);

        if (isset($config[$app]) && (!isset($config[$app]['active']) || $config[$app]['active'] == 0)) {
            Cache::forget($cacheKey);
            return false;
        } else {
            $appConfig = $config[$app];
            return Cache::remember($cacheKey, $this->getCacheTimeoutByDays(days: 1), function () use ($app, $appConfig) {
                $response = $this->getRequestConfig(username: $appConfig['username'], purchaseKey: $appConfig['purchase_key'], softwareId: $appConfig['software_id'], softwareType: $appConfig['software_type'] ?? base64_decode('cHJvZHVjdA=='));
                $this->updateActivationConfig(app: $app, response: $response);
                return (bool)$response['active'];
            });
        }
    }

    public function updateActivationConfig($app, $response): void
    {
        $config = $this->getAddonsConfig();
        $config[$app] = $response;
        $configContents = "<?php return " . var_export($config, true) . ";";
        file_put_contents(base_path('config/system-addons.php'), $configContents);
    }

    public function cleanupEnvFileContext(?string $envPath = null): bool
    {
        $envPath = $envPath ?: base_path('.env');
        if (!file_exists($envPath)) {
            return false;
        }

        $contents = file_get_contents($envPath);
        $lines = explode(PHP_EOL, $contents);
        $cleanedLines = [];
        $seenKeys = [];
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }
            if (strpos($trimmedLine, '#') === 0) {
                $cleanedLines[] = $trimmedLine;
                continue;
            }

            if (strpos($trimmedLine, '=') !== false) {
                list($key, $value) = explode('=', $trimmedLine, 2);
                $key = trim($key);
                if (in_array($key, $seenKeys)) {
                    continue;
                }
                $value = trim($value);
                $cleanedLine = $key . '=' . $value;
                $cleanedLines[] = $cleanedLine;
                $seenKeys[] = $key;
            } else {
                $cleanedLines[] = $trimmedLine;
            }
        }
        $cleanedContent = implode(PHP_EOL, $cleanedLines) . PHP_EOL;
        file_put_contents($envPath, $cleanedContent);
        return true;
    }
}
