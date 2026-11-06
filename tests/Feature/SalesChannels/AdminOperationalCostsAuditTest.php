<?php

namespace Tests\Feature\SalesChannels;

use Tests\TestCase;

/** The project's own static audit of Blade templates (RTL utilities, untranslated text, tables that overflow the page, missing labels), run over the operational-cost screens. */
class AdminOperationalCostsAuditTest extends TestCase
{
    public function test_the_static_ui_audit_finds_nothing_in_the_operational_cost_screens(): void
    {
        $this->artisan('audit:ui', ['--path' => 'admin-views/operational-costs'])
            ->expectsOutputToContain('No UI issues found')
            ->assertExitCode(0);
    }
}
