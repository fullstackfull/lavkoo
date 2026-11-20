<?php

namespace App\Services;

use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Closing a customer account without destroying the money history.
 *
 * A wallet or a loyalty movement is a financial record: it is appended and never edited, and the balance it leaves behind has to stay
 * readable once the customer is gone. The customer row IS that balance record -- `wallet_balance` and `loyalty_point` live on it -- and
 * it is also the only parent the two ledgers have, so an account that holds money, or has ever moved any, is closed by ANONYMISING the
 * row instead of removing it: the personal data goes, the money and its history stay, and nothing can sign in as the account again.
 *
 * An account that holds nothing and has moved nothing is deleted outright, exactly as before: there is no history to keep, and a live
 * store must not start keeping rows it never kept.
 */
class CustomerAccountDeletionService
{
    /** What a closed account carries instead of a name, an address or a telephone number. */
    private const CLOSED = 'deleted';

    /** RFC 2606 reserves `.invalid`, so a closed account's placeholder address can never reach anybody. */
    private const CLOSED_EMAIL_DOMAIN = '@deleted.invalid';

    public function closeAccount(User $customer): void
    {
        if ($this->holdsMoneyOrHistory($customer)) {
            $this->anonymise($customer);

            return;
        }

        $customer->delete();
    }

    /** A balance either way round is money, so a negative one counts: it is a debt the books have to keep. */
    private function holdsMoneyOrHistory(User $customer): bool
    {
        return (float) $customer->wallet_balance !== 0.0
            || (float) $customer->loyalty_point !== 0.0
            || WalletTransaction::where('user_id', $customer->id)->exists()
            || LoyaltyPointTransaction::where('user_id', $customer->id)->exists();
    }

    /**
     * The customer's own details are replaced by a marker built from the row's id, so that a unique index on the address or the telephone
     * number is still satisfied and the same person can register again. The balance columns and the ledgers are not touched at all.
     *
     * The password becomes the hash of a secret nobody holds rather than an empty column: the hasher refuses a value that is not a hash
     * of the configured algorithm, and a login path must be able to compare against this row and simply fail.
     */
    private function anonymise(User $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer->tokens()->update(['revoked' => true]);
            $customer->forceFill([
                'name' => null,
                'f_name' => self::CLOSED,
                'l_name' => self::CLOSED,
                'email' => self::CLOSED . '-' . $customer->id . self::CLOSED_EMAIL_DOMAIN,
                'phone' => self::CLOSED . '-' . $customer->id,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'temporary_token' => null,
                'image' => null,
                'street_address' => null,
                'city' => null,
                'zip' => null,
                'country' => null,
                'house_no' => null,
                'apartment_no' => null,
                'cm_firebase_token' => null,
                'cm_firebase_token_web' => null,
                'login_medium' => null,
                'social_id' => null,
                'referral_code' => null,
                'payment_card_last_four' => null,
                'payment_card_brand' => null,
                'payment_card_fawry_token' => null,
                'is_active' => 0,
                'is_phone_verified' => 0,
                'is_email_verified' => 0,
            ])->save();
        });
    }
}
