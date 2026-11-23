<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\EffectiveMarkets;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformEffectiveMarkets;
use App\Services\Staff\StaffCountryScope;
use Tests\TestCase;

/**
 * The two documents say how the platform admin API (GCC-20) and the staff country scopes (GCC-14c) meet, each from its own side: the contract the seller app is built on and the staff scope's
 * own document. This keeps them naming what the code has, and each other.
 */
class StaffScopeDocsTest extends TestCase
{
    private const PLATFORM_DOC = 'docs/gcc/GCC-20-PLATFORM-ADMIN-API.md';

    private const STAFF_DOC = 'docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md';

    public function test_the_contract_says_the_rule_the_seam_what_the_app_sees_and_what_must_be_true_before_both_switches_are_on(): void
    {
        $doc = $this->read(self::PLATFORM_DOC);

        foreach ([
            '## 12. The staff country scopes (GCC-14c)', '### The rule', '### What it does to each part of the principal', '### What the seller app sees', '### The console',
            '### What it costs, and when it is dormant', '### Decisions the owner is left', '### Before both switches are on', '### The tests',
            'PlatformEffectiveMarkets', 'StaffCountryScope::countriesOfAdmin()', 'GCC_STAFF_COUNTRY_SCOPES', 'There is no switch of its own', 'market_not_allowed', 'GCC-14C-STAFF-COUNTRY-SCOPES.md',
            'gcc:platform-admin:list', 'gcc:platform-admin:grant', 'Staff countries', 'Effective markets', 'PlatformAdminPrincipal::isPlatformOwner()',
        ] as $text) {
            $this->assertStringContainsString($text, $doc, $text);
        }
    }

    public function test_the_contract_lists_every_decision_it_leaves_to_the_owner(): void
    {
        $decisions = (string) preg_replace('/^.*### Decisions the owner is left\n(.*?)### Before both switches are on.*$/s', '$1', $this->read(self::PLATFORM_DOC));

        foreach (range(1, 7) as $number) {
            $this->assertMatchesRegularExpression('/^' . $number . '\. \*\*/m', $decisions, "decision {$number}");
        }
        $this->assertDoesNotMatchRegularExpression('/^8\. /m', $decisions);
    }

    public function test_what_the_documents_name_exists_in_the_code(): void
    {
        $this->assertTrue(class_exists(PlatformEffectiveMarkets::class));
        $this->assertTrue(class_exists(EffectiveMarkets::class));
        $this->assertTrue(method_exists(StaffCountryScope::class, 'countriesOfAdmin'));
        $this->assertTrue(method_exists(PlatformAdminPrincipal::class, 'isPlatformOwner'));
    }

    public function test_the_staff_document_points_at_the_contract_and_records_the_decision_as_built(): void
    {
        $doc = $this->read(self::STAFF_DOC);

        $this->assertStringContainsString('## The platform admin API is a consumer of the staff scope (GCC-20)', $doc);
        $this->assertStringContainsString('GCC-20-PLATFORM-ADMIN-API.md', $doc);
        $this->assertStringContainsString('countriesOfAdmin', $doc);
        $this->assertStringContainsString('PlatformEffectiveMarkets', $doc);
        $this->assertStringNotContainsString('once both are merged', $doc, 'the decision is no longer waiting for the merge');
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
