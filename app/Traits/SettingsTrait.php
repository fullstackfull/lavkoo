<?php

namespace App\Traits;

use App\Utils\EnvironmentFile;

trait SettingsTrait
{
    use StorageTrait;

    public function setEnvironmentValue($envKey, $envValue): mixed
    {
        return EnvironmentFile::set(path: app()->environmentFilePath(), key: $envKey, value: $envValue);
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
