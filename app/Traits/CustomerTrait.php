<?php

namespace App\Traits;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use App\Utils\Helpers;
use App\Utils\Convert;
use App\Services\Customer\CustomerBalanceLock;
use App\Services\Loyalty\WhatAPointIsWorth;
use App\Services\Wallet\WalletOverdraft;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;
use App\Models\BusinessSetting;
use App\Models\WalletTransaction;
use App\Models\User;

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

    protected function createWalletTransaction($user_id, float $amount, $transaction_type, $reference, $payment_data = [], ?LedgerOrigin $origin = null): bool|WalletTransaction
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) return false;

        return CustomerBalanceLock::apply($user_id, function (User $user) use ($amount, $transaction_type, $reference, $payment_data, $origin) {
            $currentBalance = $user->wallet_balance;

            $walletTransaction = new WalletTransaction();
            $walletTransaction->user_id = $user->id;
            $walletTransaction->transaction_id = \Str::uuid();
            $walletTransaction->reference = $reference;
            $walletTransaction->transaction_type = $transaction_type;
            $walletTransaction->payment_method = $payment_data['payment_method'] ?? null;

            $debit = 0.0;
            $credit = 0.0;
            $addFundToWalletBonus = 0;

            if (in_array($transaction_type, ['add_fund_by_admin', 'add_fund', 'order_refund', 'loyalty_point'])) {
                $credit = $amount;
                if ($transaction_type == 'add_fund') {
                    $walletTransaction->admin_bonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                    $addFundToWalletBonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                } else if ($transaction_type == 'loyalty_point') {
                    // The one place that answers what a point is worth (E-14, LF-1), which this writer did not ask: the
                    // seam was reachable only from `WalletPosting`, and nothing calls that, so the setting moved no
                    // money. This is the class's WEB reading, which is this expression to the digit.
                    $credit = WhatAPointIsWorth::creditFor((float) $amount, WhatAPointIsWorth::WEB);
                }
            } else if ($transaction_type == 'order_place') {
                $debit = $amount;
            }

            $creditAmount = currencyConverter($credit);
            $debitAmount = currencyConverter($debit);
            if (WalletOverdraft::refuses($currentBalance, $debitAmount)) {
                return false;
            }
            // The row carries the balance the account ENDS with, bonus included (E-16). All four copies of this write set the
            // account to `previous + bonus + credit - debit` and wrote `previous + credit - debit` on the row, so an `add_fund`
            // row that earned a bonus understated the account by exactly the bonus it was printing in the next column -- on the
            // admin wallet report, which prints both on one line, and in the add-fund email, which prints the row's balance as
            // the customer's new balance. One figure, used twice, is why the two cannot disagree again
            // (App\Services\Customer\CustomerWalletWriter already writes it this way). Rows written before this are NOT
            // rewritten and keep the understated figure; they stay reconcilable, because `admin_bonus` is on the row in both
            // eras.
            $balanceAfter = $currentBalance + $addFundToWalletBonus + $creditAmount - $debitAmount;
            $walletTransaction->credit = $creditAmount;
            $walletTransaction->debit = $debitAmount;
            $walletTransaction->balance = $balanceAfter;
            $walletTransaction->created_at = now();
            $walletTransaction->updated_at = now();
            $walletTransaction->fill(app(LedgerAttribution::class)->of(table: $walletTransaction->getTable(), origin: $origin));
            $user->wallet_balance = $balanceAfter;

            $user->save();
            $walletTransaction->save();

            if (in_array($transaction_type, ['loyalty_point', 'order_place', 'add_fund_by_admin'])) return $walletTransaction;
            return true;
        });
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
