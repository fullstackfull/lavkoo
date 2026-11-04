<?php

namespace App\Services;

use App\Traits\FileManagerTrait;
use Carbon\Carbon;

class VendorService
{
    use FileManagerTrait;

    /**
     * @param string $email
     * @param string $password
     * @param string|bool|null $rememberToken
     * @return bool
     */
    public function isLoginSuccessful(string $email, string $password, string|null|bool $rememberToken): bool
    {
        if (auth('seller')->attempt(['email' => $email, 'password' => $password], $rememberToken)) {
            return true;
        }
        return false;
    }

    /**
     * @param int $vendorId
     * @return array
     */
    public function getInitialWalletData(int $vendorId): array
    {
        return [
            'seller_id' => $vendorId,
            'withdrawn' => 0,
            'commission_given' => 0,
            'total_earning' => 0,
            'pending_withdraw' => 0,
            'delivery_charge_earned' => 0,
            'collected_cash' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function logout(): void
    {
        auth()->guard('seller')->user()?->update(['cm_firebase_token_web' => null]);
        auth()->guard('seller')->logout();
        session()->invalidate();
    }

    /**
     * @param object $request
     * @return array
     */
    public function getFreeDeliveryOverAmountData(object $request): array
    {
        return [
            'free_delivery_status' => $request['free_delivery_status'] == 'on' ? 1 : 0,
            'free_delivery_over_amount' => currencyConverter($request['free_delivery_over_amount'], 'usd'),
        ];
    }

    public function getMinimumOrderAmount(object $request): array
    {
        return [
            'minimum_order_amount' => currencyConverter($request['minimum_order_amount'], 'usd')
        ];
    }

    public function getVendorStockLimit(object $request): array
    {
        return [
            'stock_limit' => $request['stock_limit'] ?? 1
        ];
    }

    public function getUpdateBusinessTIN(object $request): array
    {
        $data = [
            'tax_identification_number' => $request['tax_identification_number'],
            'tin_expire_date' => $request['tin_expire_date'] ? Carbon::parse($request['tin_expire_date']) : null,
        ];
        if ($request->file('tin_certificate')) {
            $data['tin_certificate'] = $this->fileUpload(dir: 'shop/documents/', format: $request->file('tin_certificate')->getClientOriginalExtension(), file: $request->file('tin_certificate'));
            $data['tin_certificate_storage_type'] = config('filesystems.disks.default') ?? 'public';
        }
        return $data;
    }

    /**
     * @param object $request
     * @param object $vendor
     * @return array
     */
    public function getVendorDataForUpdate(object $request, object $vendor): array
    {
        $image = $request['image'] ? $this->update(dir: 'seller/', oldImage: $vendor['image'], format: 'webp', image: $request->file('image')) : $vendor['image'];
        return [
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'phone' => $request['phone'],
            'image' => $image,
        ];
    }

    /**
     * @return array[password: string]
     */
    public function getVendorPasswordData(object $request): array
    {
        return [
            'password' => bcrypt($request['password']),
        ];
    }

    /**
     * @param object $request
     * @return array
     */
    public function getVendorBankInfoData(object $request): array
    {
        return [
            'bank_name' => $request['bank_name'],
            'branch' => $request['branch'],
            'holder_name' => $request['holder_name'],
            'account_no' => $request['account_no'],
        ];
    }

    public function getAddData(object $request): array
    {
        return [
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'image' => $request->file('image') ? $this->upload(dir: 'seller/', format: 'webp', image: $request->file('image')) : 'def.png',
            'password' => bcrypt($request['password']),
            'status' => $request['status'] === 'approved' ? 'approved' : 'pending',
        ];
    }
}
