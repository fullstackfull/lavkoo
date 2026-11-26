<?php

namespace App\Rules;

use App\Services\Identity\CustomerPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One account per phone NUMBER, not per spelling of it (A2-06).
 *
 * `unique:users` compares the string that was typed, so `05XX XXX XXX`, `+9665XX XXX XXX` and `00966 5X XXX XXX` are three customers with
 * one phone -- and whichever row a later sign-in finds first decides which account the person gets. This rule asks
 * {@see CustomerPhone::accountHoldingTheSameNumber()}, which compares the number.
 *
 * It sits BESIDE `unique:users` rather than replacing it: the exact-string key is the cheap and indexed half, and a rule that replaced it
 * would turn one query into two for the commonest case. It also keeps its own message key, which the paths that print
 * `phone.unique` already print for a number that is taken, so a refusal reads the same whichever half refused.
 *
 * Off while `gcc.identity.canonical_phone.checked` is off, which is how it ships: not one query is made and every path refuses exactly
 * what it refuses today.
 */
final class OneAccountPerPhone implements ValidationRule
{
    public const MESSAGE = 'phone_number_already_has_been_taken';

    /** The account being edited, which holds its own number and is not a duplicate of itself. */
    public function __construct(private readonly int|string|null $exceptAccountId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!CustomerPhone::isChecked() || !is_string($value) || trim($value) === '') {
            return;
        }

        if (app(CustomerPhone::class)->accountHoldingTheSameNumber(raw: $value, exceptId: $this->exceptAccountId) !== null) {
            $fail(translate(self::MESSAGE));
        }
    }
}
