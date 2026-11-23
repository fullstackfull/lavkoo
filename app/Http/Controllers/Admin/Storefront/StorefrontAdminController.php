<?php

namespace App\Http\Controllers\Admin\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Fulfillment\Support\SafeToast;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use RuntimeException;

/**
 * What the storefront screens share: a step is taken by the service that owns it, and what comes back to the person is the step done or the reason the service refused it, in
 * the words of the service. The service decides; the screen does not repeat its rules.
 */
abstract class StorefrontAdminController extends Controller
{
    /**
     * Takes one step and answers on the page the person was on: with the step done, or with why it was refused, keeping what they typed.
     *
     * @param Closure(): mixed $step
     */
    protected function attempt(Closure $step, string $done): RedirectResponse
    {
        try {
            $step();
        } catch (QueryException $failure) {
            // A failure of the database is not a refusal, and its message holds the statement: it must not reach the screen.
            throw $failure;
        } catch (InvalidArgumentException|RuntimeException $refusal) {
            SafeToast::error(translate('this_step_was_refused'), $refusal->getMessage());

            return back()->withInput();
        }

        ToastMagic::success(translate($done));

        return back();
    }
}
