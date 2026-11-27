<?php

namespace App\Rules;

use App\Services\Identity\CustomerEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One account per e-mail ADDRESS, not per spelling of it (A2-06, M2-04).
 *
 * `unique:users` compares the string that was typed through the server's own collation, so what counts as one identity changes with the
 * engine: on MariaDB with `utf8mb4_general_ci` the capitals of `Foo@example.test` are the same account and a LEADING SPACE is a second
 * one, and on SQLite the capitals are a second one too. This rule asks {@see CustomerEmail::accountHoldingTheSameAddress()}, which
 * compares the address, so the answer is the same on every engine.
 *
 * It sits BESIDE `unique:users` rather than replacing it, exactly as {@see OneAccountPerPhone} does: the exact-string key is the cheap and
 * indexed half. It also keeps its own message key, which the paths that print `email.unique` already print for an address that is taken,
 * so a refusal reads the same whichever half refused.
 *
 * Off while `gcc.identity.canonical_email.checked` is off, which is how it ships: not one query is made and every path refuses exactly
 * what it refuses today.
 */
final class OneAccountPerEmail implements ValidationRule
{
    public const MESSAGE = 'email_already_has_been_taken';

    /** The account being edited, which holds its own address and is not a duplicate of itself. */
    public function __construct(private readonly int|string|null $exceptAccountId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!CustomerEmail::isChecked() || !is_string($value) || trim($value) === '') {
            return;
        }

        if (app(CustomerEmail::class)->accountHoldingTheSameAddress(raw: $value, exceptId: $this->exceptAccountId) !== null) {
            $fail(translate(self::MESSAGE));
        }
    }
}
