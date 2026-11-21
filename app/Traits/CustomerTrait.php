<?php

namespace App\Traits;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;

trait CustomerTrait
{
    public function __construct(
        private readonly OrderDetailRepositoryInterface              $orderDetailRepo,
        private readonly CustomerRepositoryInterface                 $customerRepo,
        private readonly LoyaltyPointTransactionRepositoryInterface  $loyaltyPointTransactionRepo,
        private readonly PhoneOrEmailVerificationRepositoryInterface $phoneOrEmailVerificationRepo,
    )
    {
    }

    protected function convertAmountToLoyaltyPoint(object $orderDetails): int
    {
        $loyaltyPointStatus = getWebConfig('loyalty_point_status');
        $loyaltyPoint = 0;
        if ($loyaltyPointStatus == 1) {
            $getLoyaltyPointOnPurchase = getWebConfig('loyalty_point_item_purchase_point');
            $subtotal = ($orderDetails['price'] * $orderDetails['qty']) - $orderDetails['discount'] + $orderDetails['tax'];
            $loyaltyPoint = (int)(usdToDefaultCurrency(amount: $subtotal) * $getLoyaltyPointOnPurchase / 100);
        }
        return $loyaltyPoint;
    }

    public function checkCustomerOTPBlockTimeOrInvalid(object|array|null $verificationData, string|null $identity): array
    {
        $maxOTPHit = getWebConfig(name: 'maximum_otp_hit') ?? 5;
        $maxOTPHitTime = getWebConfig(name: 'otp_resend_time') ?? 60; // seconds
        $tempBlockTime = getWebConfig(name: 'temporary_block_time') ?? 600; // seconds

        $status = 0;
        if ($verificationData) {
            $code = 'valid_otp';
            $message = translate('OTP_is_not_matched');
            if (isset($verificationData->temp_block_time) && Carbon::parse($verificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime) {
                $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();
                $status = 1;
                $code = 'otp_block_time';
                $message = translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            } else if ($verificationData['is_temp_blocked'] == 1 && Carbon::parse($verificationData['created_at'])->DiffInSeconds() >= $tempBlockTime) {
                $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                    'otp_hit_count' => 0,
                    'is_temp_blocked' => 0,
                    'temp_block_time' => null,
                    'updated_at' => now(),
                ]);
                $status = 1;
                $code = 'otp_block_time';
                $message = translate('OTP_is_not_matched');
            } else if ($verificationData['otp_hit_count'] >= $maxOTPHit && Carbon::parse($verificationData['updated_at'])->DiffInSeconds() < $maxOTPHitTime && $verificationData['is_temp_blocked'] == 0) {
                $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                    'is_temp_blocked' => 1,
                    'temp_block_time' => now(),
                ]);
                $time = $tempBlockTime - Carbon::parse($verificationData['temp_block_time'])->DiffInSeconds();
                $status = 1;
                $code = 'otp_temp_blocked';
                $message = translate('Too_many_attempts.') . ' ' . translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            }

            $verificationNewData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $identity]);
            $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                'otp_hit_count' => ($verificationNewData['otp_hit_count'] + 1),
                'updated_at' => now(),
            ]);
        } else {
            $status = 1;
            $code = 'invalid_otp';
            $message = translate('OTP_is_not_matched');
        }

        return [
            'status' => $status,
            'code' => $code,
            'message' => $message
        ];
    }

    public function checkPasswordResetOTPBlockTimeOrInvalid(object|array|null $verificationData, string|null $identity): array
    {
        $maxOTPHit = getWebConfig(name: 'maximum_otp_hit') ?? 5;
        $maxOTPHitTime = getWebConfig(name: 'otp_resend_time') ?? 60; // seconds
        $tempBlockTime = getWebConfig(name: 'temporary_block_time') ?? 600; // seconds

        $status = 0;
        if ($verificationData) {
            $code = 'valid_otp';
            $message = translate('OTP_is_not_matched');
            if (isset($verificationData->temp_block_time) && Carbon::parse($verificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime) {
                $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();
                $status = 1;
                $code = 'otp_block_time';
                $message = translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            } else if ($verificationData['is_temp_blocked'] == 1 && Carbon::parse($verificationData['created_at'])->DiffInSeconds() >= $tempBlockTime) {
                $this->passwordResetRepo->updateOrCreate(params: ['identity' => $identity], value: [
                    'otp_hit_count' => 0,
                    'is_temp_blocked' => 0,
                    'temp_block_time' => null,
                ]);
                $status = 1;
                $code = 'otp_block_time';
            } else if ($verificationData['otp_hit_count'] >= $maxOTPHit && Carbon::parse($verificationData['updated_at'])->DiffInSeconds() < $maxOTPHitTime && $verificationData['is_temp_blocked'] == 0) {
                $this->passwordResetRepo->updateOrCreate(params: ['identity' => $identity], value: [
                    'is_temp_blocked' => 1,
                    'temp_block_time' => now(),
                ]);
                $time = $tempBlockTime - Carbon::parse($verificationData['temp_block_time'])->DiffInSeconds();
                $status = 1;
                $code = 'otp_temp_blocked';
                $message = translate('Too_many_attempts.') . ' ' . translate(' please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            }
            $verificationNewData = $this->passwordResetRepo->getFirstWhere(params: ['identity' => $identity]);
            $this->passwordResetRepo->updateOrCreate(params: ['identity' => $identity], value: [
                'otp_hit_count' => ($verificationNewData['otp_hit_count'] + 1),
                'updated_at' => now(),
            ]);
        } else {
            $status = 1;
            $code = 'invalid_otp';
            $message = translate('OTP_is_not_matched');
        }

        return [
            'status' => $status,
            'code' => $code,
            'message' => $message
        ];
    }


}
