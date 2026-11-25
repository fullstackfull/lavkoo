<?php

namespace App\Http\Controllers\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\MailAccountRequest;
use App\Http\Requests\Admin\Notification\MailAccountTestSendRequest;
use App\Services\Notification\MailAccountTestSend;
use App\Services\Notification\NotificationAccountBoard;
use App\Services\Notification\NotificationAccountWriter;
use App\Services\Notification\NotificationProfiles;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

/**
 * I1-01: the screen where an operator gives a market its own mail account and sends a test e-mail through it.
 *
 * The engine has existed since the accounts did; what did not exist was a way for anybody but a database client to use it. Three things about
 * this screen are not decoration:
 *
 *  - it is shown {@see NotificationAccountBoard}, which carries {@see \App\Services\Notification\NotificationProfile::visible()} and so
 *    cannot carry a credential into the HTML even if the template asked for one;
 *  - the markets it offers are the ones the employee's country scope allows, and the writer asks the same question again before it writes;
 *  - the test send hands back the transport's own words ({@see MailAccountTestSend}), so that a wrong password reads as a wrong password
 *    rather than as silence.
 */
class NotificationAccountController extends Controller
{
    public function __construct(
        private readonly NotificationAccountBoard $board,
        private readonly NotificationAccountWriter $writer,
        private readonly MailAccountTestSend $testSend,
    ) {
    }

    public function index(): View
    {
        $markets = $this->board->markets();

        return view('admin-views.notification-accounts.index', [
            'accountsPerMarketAreOn' => $this->board->accountsPerMarketAreOn(),
            'rows' => array_map(fn (string $country): array => $this->board->row(country: $country), $markets),
            'channels' => NotificationProfiles::CHANNELS,
            'mailChannel' => NotificationProfiles::MAIL,
        ]);
    }

    public function saveMailAccount(MailAccountRequest $request): RedirectResponse
    {
        try {
            $this->writer->saveMailAccount(
                country: $request->country(),
                settings: $request->settings(),
                secrets: $request->secrets(),
                forget: $request->forget(),
                active: $request->isActive(),
                label: $request->label(),
            );
        } catch (RuntimeException $refusal) {
            ToastMagic::error($refusal->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate('the_mail_account_is_saved'));

        return back();
    }

    /**
     * Sends one test e-mail and shows what happened. The result goes back in the session rather than in a toast because an SMTP refusal is a
     * sentence of its own and a toast truncates it, and because the host the message left through is part of the answer.
     */
    public function sendTestMail(MailAccountTestSendRequest $request): RedirectResponse
    {
        if (!$this->board->mayConfigure(country: $request->country())) {
            ToastMagic::error(translate('you_may_not_configure_this_market'));

            return back();
        }

        $result = $this->testSend->to(address: $request->address(), country: $request->country());

        $result['sent'] ? ToastMagic::success(translate('the_test_email_was_accepted_by_the_mail_server')) : ToastMagic::error(translate('the_test_email_was_not_sent'));

        return back()->with('mail_account_test_result', $result);
    }
}
