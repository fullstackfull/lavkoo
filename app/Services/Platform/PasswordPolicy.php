<?php

namespace App\Services\Platform;

/**
 * One password rule for the whole platform.
 *
 * The audit found a dozen validators that did not agree with each other: a seller registered under
 * an eight-character minimum and could then reset their password to six, because the register and
 * the reset were written by different hands in different files and `app/Rules` held nothing but a
 * file-extension check. Password length is the security control a marketplace retunes most often,
 * and it was the one control that could only be changed by editing twelve files.
 *
 * It applies where a password is CHOSEN — registration, reset, an account created for someone else.
 * Sign-in is deliberately left alone: raising the minimum must not lock out the people who already
 * have a shorter password, and a login form gains nothing by refusing early what the hash will
 * refuse anyway.
 */
class PasswordPolicy
{
    /** Longest we will hash. Bcrypt truncates past 72 bytes; 100 is the platform's existing cap. */
    private const MAXIMUM_LENGTH = 100;

    public function __construct(private readonly Policy $policy)
    {
    }

    public function minimumLength(): int
    {
        return $this->policy->int('password_minimum_length');
    }

    /**
     * The rules for a field where a password is being chosen.
     *
     * @return array<int, string>
     */
    public function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'min:' . $this->minimumLength(),
            'max:' . self::MAXIMUM_LENGTH,
        ];
    }

    /** The same rules for the call sites that build a pipe-delimited string. */
    public function ruleString(bool $required = true): string
    {
        return implode('|', $this->rules($required));
    }
}
