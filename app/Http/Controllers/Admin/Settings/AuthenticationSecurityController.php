<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Services\AuditLogger;
use App\Services\Platform\Policy;
use App\Services\RecaptchaService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The two authentication settings that had no screen anywhere.
 *
 * reCAPTCHA is the platform's only bot defence on its sign-in and password-reset forms, and it was
 * seeded off at install with no writer in any admin controller or view — so a shop being credential-
 * stuffed had no way to turn it on, and the decision to leave it off lived in a class comment.
 *
 * Which channel a customer's password reset is sent through had the same shape: the vendor and the
 * delivery-man equivalents both have screens, only the customer one did not, so moving customer
 * account recovery to SMS was a hand-edited settings row.
 *
 * Enabling reCAPTCHA without keys would refuse every sign-in on the shop, so the form will not save
 * that combination — it names the missing key instead.
 */
class AuthenticationSecurityController extends BaseController
{
    private const RESET_CHANNELS = ['email', 'phone'];

    public function __construct(
        private readonly Policy $policy,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(?Request $request = null, ?string $type = null): View
    {
        $recaptcha = getWebConfig(name: 'recaptcha');

        return view('admin-views.settings.authentication', [
            'recaptcha' => is_array($recaptcha) ? $recaptcha : ['status' => 0, 'site_key' => '', 'secret_key' => ''],
            'enforced' => RecaptchaService::isEnforced(),
            'minimumScore' => $this->policy->float('recaptcha_minimum_score'),
            'resetChannel' => getWebConfig(name: 'forgot_password_verification') ?: 'email',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|boolean',
            'site_key' => 'nullable|string|max:191',
            'secret_key' => 'nullable|string|max:191',
            'recaptcha_minimum_score' => 'required|numeric|min:0|max:1',
            'forgot_password_verification' => 'required|in:' . implode(',', self::RESET_CHANNELS),
        ]);

        if ((int) $validated['status'] === 1 && (empty($validated['site_key']) || empty($validated['secret_key']))) {
            ToastMagic::error(translate('recaptcha_needs_both_a_site_key_and_a_secret_key_before_it_can_be_switched_on'));

            return back()->withInput();
        }

        $before = getWebConfig(name: 'recaptcha');

        BusinessSetting::updateOrCreate(['type' => 'recaptcha'], ['value' => json_encode([
            'status' => (int) $validated['status'],
            'site_key' => (string) ($validated['site_key'] ?? ''),
            'secret_key' => (string) ($validated['secret_key'] ?? ''),
        ])]);

        BusinessSetting::updateOrCreate(
            ['type' => 'forgot_password_verification'],
            ['value' => $validated['forgot_password_verification']],
        );

        $this->policy->save(['recaptcha_minimum_score' => $validated['recaptcha_minimum_score']]);

        cache()->flush();

        // The keys themselves are never recorded — an audit row is not the place a secret is written
        // down — so the trail says whether it was switched on, and nothing more.
        $this->audit->record(
            action: 'settings.authentication_updated',
            before: ['recaptcha_enabled' => (int) ($before['status'] ?? 0)],
            after: [
                'recaptcha_enabled' => (int) $validated['status'],
                'forgot_password_verification' => $validated['forgot_password_verification'],
            ],
        );

        ToastMagic::success(translate('the_authentication_settings_were_saved'));

        return back();
    }
}
