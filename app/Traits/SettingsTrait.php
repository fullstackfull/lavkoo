<?php

namespace App\Traits;

trait SettingsTrait
{
    use StorageTrait;

    public function setEnvironmentValue($envKey, $envValue): mixed
    {
        $envFile = app()->environmentFilePath();
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (preg_match('/[^a-zA-Z0-9]/', $envValue)) {
            $formattedValue = "\"{$envValue}\"";
        } else {
            $formattedValue = $envValue;
        }

        $replacement = "{$envKey}={$formattedValue}";
        $keyFound = false;
        $newLines = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match("/^{$envKey}\s*=/i", trim($line))) {
                $newLines[] = $replacement;
                $keyFound = true;
            } else {
                $newLines[] = $line;
            }
        }

        if (!$keyFound) {
            $newLines[] = $replacement;
        }
        file_put_contents($envFile, implode(PHP_EOL, $newLines) . PHP_EOL);
        return $formattedValue;
    }

    public function getSettings($object, $type)
    {
        $config = null;
        foreach ($object as $setting) {
            if ($setting['type'] == $type) {
                $config = $this->storageDataProcessing($type, $setting);
            }
        }
        return $config;
    }

    private function storageDataProcessing($name, $value)
    {
        $arrayOfCompaniesValue = ['company_web_logo', 'company_mobile_logo', 'company_footer_logo', 'company_fav_icon', 'loader_gif', 'blog_feature_download_app_icon', 'blog_feature_download_app_background'];
        if (in_array($name, $arrayOfCompaniesValue)) {
            $imageData = json_decode($value->value, true) ?? ['image_name' => $value['value'], 'storage' => 'public'];
            $value['value'] = $this->storageLink('company', $imageData['image_name'], $imageData['storage']);
        }
        return $value;
    }
}
