<?php

namespace App\Http\Middleware;

use App\Services\Staff\AdminStepUp;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;

/**
 * The door in front of a panel action that changes who may do what (G-14).
 *
 * Declared on the write routes of staff management, of the role form and of the database-settings clean, never on the screens that only
 * show them: a form an employee cannot open is not a form they can confirm.
 *
 * It refuses rather than redirects to a confirmation page, and the refusal is the whole point: a sensitive action that is not confirmed
 * must write NOTHING. The employee proves who they are by sending their own password with the action, which the panel asks for in the
 * screen's own confirmation field; until a screen carries that field the action is refused, which is why the switch above this ships off.
 */
class EnsureAdminStepUp
{
    public function __construct(private readonly AdminStepUp $stepUp)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!$this->stepUp->isRequired()) {
            return $next($request);
        }

        if ($this->stepUp->isFresh(request: $request) || $this->stepUp->confirmFromRequest(request: $request)) {
            return $next($request);
        }

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(data: ['status' => 'error', 'code' => 'step_up_required', 'message' => translate('confirm_your_password_to_continue')], status: 403);
        }

        ToastMagic::warning(translate('confirm_your_password_to_continue'));

        return back();
    }
}
