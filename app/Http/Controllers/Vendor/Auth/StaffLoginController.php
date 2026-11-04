<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\SellerStaff;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Seller staff sign-in (Phase 3, Stage A — the login the foundation deferred).
 *
 * A staff member authenticates with their own credentials, but is then signed in **as their parent
 * seller** on the existing `seller` guard, with `seller_staff_id` stamped on the session. That single
 * choice is what keeps this safe and small: every vendor controller already scopes data by
 * `auth('seller')`, so a staff member transparently operates their shop with no controller change, and
 * `SellerStaffAccessMiddleware` restricts *what* they may do by their role's permissions. The owner's
 * own login path is untouched and never sets `seller_staff_id`.
 */
class StaffLoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:seller', ['except' => ['logout']]);
    }

    public function getLoginView(): View|RedirectResponse
    {
        return view('vendor-views.auth.staff-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Staff email is unique only per seller (a colleague at another shop may share it), so match on
        // the credential that actually verifies rather than an arbitrary first row.
        $staff = SellerStaff::where('email', $request->input('email'))->get()
            ->first(fn ($candidate) => $candidate->password && Hash::check($request->input('password'), $candidate->password));

        if (!$staff) {
            ToastMagic::error(translate('credentials_doesnt_match') . '!');

            return back();
        }

        if ($staff->status !== SellerStaff::STATUS_ACTIVE) {
            ToastMagic::error(translate('your_staff_account_is_inactive') . '!');

            return back();
        }

        $seller = Seller::find($staff->seller_id);
        if (!$seller || $seller->status !== 'approved') {
            ToastMagic::error(translate('the_shop_this_account_belongs_to_is_not_active') . '!');

            return back();
        }

        // Sign in as the parent seller so all existing shop scoping applies, then mark the staff context.
        Auth::guard('seller')->loginUsingId($seller->id);
        session(['seller_staff_id' => $staff->id]);
        $staff->forceFill(['last_login_at' => now()])->save();

        ToastMagic::success(translate('welcome') . ' ' . $staff->name);

        return redirect()->route('vendor.dashboard.index');
    }

    public function logout(): RedirectResponse
    {
        session()->forget('seller_staff_id');
        Auth::guard('seller')->logout();

        return redirect()->route('vendor.staff-auth.login');
    }
}
