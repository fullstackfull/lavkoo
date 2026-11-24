<?php

namespace App\Http\Resources\Seller;

use App\Models\User;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\Wallet\MarketBalanceReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as the seller app's point of sale reads one (E-04): seven named fields, and the wallet of the market the seller works in.
 *
 * The lookup answered `User::get()->toArray()`, which is every column of a customer the model does not hide -- their address, their city,
 * their postcode, their referral codes, their verification flags, their temporary token, their loyalty points -- to a seller who typed three
 * letters of a name into a till. The app itself reads exactly these seven (`lib/features/pos/domain/models/customer_model.dart`), so naming
 * them takes nothing away from it and stops sending the rest.
 *
 * `wallet_balance` is the money of the market the seller is working in, when a market has been switched over to its own account
 * ({@see MarketBalanceReader}); it is the one balance the customer's row holds in every other case, which is what this field has always
 * answered. The market is the one the seller's grant and their request resolved to, never one named in a header alone
 * ({@see SellerMarketScope::workingMarket()}).
 *
 * **The seven are what the app reads, not a judgement of what a seller should see.** Whether a seller at a till should be told a customer's
 * e-mail address is the owner's to decide (E-04's open decisions); until then this sends what the app has always been sent, and nothing it
 * has not.
 *
 * @mixin User
 */
class PosCustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'f_name' => $this->f_name,
            'l_name' => $this->l_name,
            'phone' => $this->phone,
            'image' => $this->image,
            'email' => $this->email,
            'wallet_balance' => app(MarketBalanceReader::class)->wallet(
                $this->resource,
                app(SellerMarketScope::class)->workingMarket(),
            ),
        ];
    }
}
