{{--
    The field a sensitive action carries its proof of identity in (G-14, I2-03).

    `App\Services\Staff\AdminStepUp` reads the password out of the request the action itself carries -- there is no separate confirmation
    address, because a second address would be a second place to get the authorization of it wrong. So the field belongs INSIDE the form
    whose button it protects, and a form on a step-up route without it is an action that is always refused.

    It renders nothing at all while step-up is not required, which is every installation today: the screen is exactly the screen it is now
    until an operator turns `gcc.staff.step_up.enabled` on.

    `$id` keeps two forms on one page from sharing a label, and autocomplete is off because this is not a sign-in field and a browser
    offering to remember it would store an employee's panel password against a legal-text form.
--}}
@props(['id' => 'step-up'])
@if (app(\App\Services\Staff\AdminStepUp::class)->isRequired())
    <label class="form-label fs-12" for="{{ $id }}-password">{{ translate('confirm_your_password_to_continue') }}</label>
    <input class="form-control form-control-sm mb-1"
           id="{{ $id }}-password"
           type="password"
           name="{{ \App\Services\Staff\AdminStepUp::PASSWORD_FIELD }}"
           autocomplete="off"
           maxlength="100">
@endif
