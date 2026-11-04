<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Request;

trait DemoMaskingTrait
{
    /**
     * Check if masking should be applied
     */
    protected function shouldMask(): bool
    {
        $segment = request()->segment(1);
        if (config('app.mode') == 'demo' && in_array($segment, ['admin', 'vendor', 'seller'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Mask email field
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: function ($value) {

                // Read directly from env
                if (!$this->shouldMask()) {
                    return $value;
                }

                if (!$value) {
                    return null;
                }

                return $this->maskEmail($value);
            }
        );
    }

    /**
     * Mask phone field
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            get: function ($value) {

                // Read directly from env
                if (!$this->shouldMask()) {
                    return $value;
                }

                if (!$value) {
                    return null;
                }

                return substr($value, 0, 2) . '****';
            }
        );
    }

    /**
     * Mask contact_person_number field
     */
    protected function contactPersonNumber(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$this->shouldMask()) {
                    return $value;
                }

                if (!$value) {
                    return null;
                }

                return substr($value, 0, 2) . '****';
            }
        );
    }


    protected function shippingAddressData(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $data = is_array($value) ? $value : json_decode($value, true);

                if (isset($data['email'])) {
                    $data['email'] = $this->maskEmail($data['email']);
                }
                if (isset($data['phone'])) {
                    $data['phone'] = $this->maskPhone($data['phone']);
                }
                if (isset($data['contact_person_number'])) {
                    $data['contact_person_number'] = $this->maskPhone($data['contact_person_number']);
                }
                return (object)$data;
            }
        );
    }

    protected function billingAddressData(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $data = is_array($value) ? $value : json_decode($value, true);

                if (isset($data['email'])) {
                    $data['email'] = $this->maskEmail($data['email']);
                }
                if (isset($data['phone'])) {
                    $data['phone'] = $this->maskPhone($data['phone']);
                }
                if (isset($data['contact_person_number'])) {
                    $data['contact_person_number'] = $this->maskPhone($data['contact_person_number']);
                }

                return (object)$data;
            }
        );
    }

    public function maskPhone(?string $phone): ?string
    {
        if (!$this->shouldMask()) {
            return $phone;
        }

        if (!$phone) {
            return null;
        }

        return substr($phone, 0, 2) . '****';
    }

    public function maskEmail(?string $email): ?string
    {
        if (!$this->shouldMask()) {
            return $email;
        }

        if (!$email) {
            return null;
        }

        if (!str_contains($email, '@')) {
            return preg_replace(
                '/^(.)(.*)(@.*)$/',
                '$1****$3',
                $email
            );
        }

        [$username, $domain] = explode('@', $email);
        $maskedUsername = substr($username, 0, 1) . '****';
        [$domainName, $extension] = explode('.', $domain, 2);
        $maskedDomain = '**' . substr($domainName, -3) . '.' . $extension;
        return $maskedUsername . '@' . $maskedDomain;
    }
}
