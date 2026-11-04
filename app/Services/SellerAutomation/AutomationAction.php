<?php

namespace App\Services\SellerAutomation;

use App\Services\Marketplace\SellerPrincipal;

/**
 * What a rule does to each record its trigger selected.
 *
 * `apply()` returns what happened rather than throwing, because one product that cannot be touched
 * must not abandon the other forty in the same run — and because the reason belongs in the trail
 * where the seller can read it, not in a log nobody opens.
 *
 * The contract is deliberately the same shape as `BulkOperation`: both are "do this to each of
 * these, and tell me row by row how it went", and a seller reading an automation trail and a bulk
 * job receipt should not have to learn two vocabularies.
 */
interface AutomationAction
{
    /** Stable key stored on the rule. */
    public function key(): string;

    /** The seller permission required to write a rule that uses it. */
    public function permission(): string;

    /** Which subject types this action understands. */
    public function subjectTypes(): array;

    /**
     * Validation for this action's settings, keyed without the `action_settings.` prefix.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The columns this action is allowed to put back when a seller undoes it.
     *
     * Named by the action rather than taken from the recorded `before`, so a trail row edited or
     * written by an older version of the code can only ever restore what this action legitimately
     * touches. Undo is not a general-purpose write.
     *
     * @return array<int, string>
     */
    public function revertibleColumns(): array;

    /**
     * @param  array<string, mixed>  $settings
     * @return array{ok: bool, reason?: string, before?: array, after?: array, label?: string}
     */
    public function apply(object $subject, array $settings, SellerPrincipal $principal): array;

    /**
     * What this action would do, without doing it. Used by the preview and by nothing else.
     *
     * @param  array<string, mixed>  $settings
     * @return array{ok: bool, reason?: string, before?: array, after?: array, label?: string}
     */
    public function preview(object $subject, array $settings): array;
}
