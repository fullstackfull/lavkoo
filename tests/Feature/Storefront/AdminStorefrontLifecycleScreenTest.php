<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontPermissionService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * I2-11: the screen where a storefront is marked ready, activated, paused and resumed. The routes, the role check, the requests and the templates are the real ones. What is pinned: who
 * may open it and who may change a storefront (the permission of the role, and no other), that the person of every change is the admin who is signed in and never a name typed in,
 * that every step is the service's step (the same refusals in the service's words, the same audit line), and what the page shows about each storefront.
 */
class AdminStorefrontLifecycleScreenTest extends AdminStorefrontScreenTestCase
{
    private const REASON = 'Decision of the launch meeting';

    private const REFERENCE = 'Minutes of the launch meeting, 2026-11-17';

    private function lifecycle(): StorefrontLifecycle
    {
        return app(StorefrontLifecycle::class);
    }

    private function stateOf(string $country = 'KW'): string
    {
        return $this->lifecycle()->statusOf($country)['state'];
    }

    private function anActiveStorefront(string $country = 'KW'): void
    {
        $this->meetThePrerequisitesOf($country);
        $this->lifecycle()->markReady(country: $country, by: 'The head of the market', reason: self::REASON);
        $this->lifecycle()->activate(country: $country, by: 'The head of the market', reference: self::REFERENCE, reason: self::REASON);
    }

    /** @return array<string, array{int}> */
    public static function adminsWhoHoldNoStorefrontPermission(): array
    {
        return ['a role that holds another module' => [self::CATALOG_ADMIN], 'a role that holds all three but is switched off' => [self::ADMIN_OF_A_SWITCHED_OFF_ROLE]];
    }

    // ---- who may open the page and who may change a storefront ------------------------------------------------------------------------------

    #[DataProvider('adminsWhoHoldNoStorefrontPermission')]
    public function test_an_admin_without_a_storefront_permission_cannot_open_the_page_or_change_a_storefront(int $admin): void
    {
        $this->meetThePrerequisitesOf();
        $linesBefore = DB::table('audit_logs')->count();
        $this->signInAs($admin);

        $this->get(route('admin.storefronts.index'))->assertRedirect(route('admin.dashboard.index'));
        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON])->assertRedirect(route('admin.dashboard.index'));

        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());
    }

    public function test_the_master_admin_and_a_role_that_holds_the_lifecycle_permission_open_the_page_and_every_configured_storefront_is_on_it(): void
    {
        foreach ([self::MASTER, self::LIFECYCLE_ADMIN] as $admin) {
            $this->signInAs($admin);
            $html = $this->get(route('admin.storefronts.index'))->assertOk()->getContent();

            foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
                $this->assertStringContainsString('data-storefront="' . $country . '"', $html, "{$country} is on the page of admin {$admin}");
            }
        }
    }

    public function test_a_role_that_only_writes_legal_texts_can_read_the_page_but_is_offered_no_step_and_cannot_take_one(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::LEGAL_WRITER);

        $html = $this->get(route('admin.storefronts.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('storefronts/KW/ready', $html);
        $this->assertStringContainsString(route('admin.storefronts.legal-texts.index', 'KW'), $html, 'the way to the legal texts is there');

        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON])->assertRedirect(route('admin.dashboard.index'));
        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
    }

    public function test_a_role_that_holds_only_the_lifecycle_permission_is_offered_the_step_and_not_the_legal_texts(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $html = $this->get(route('admin.storefronts.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.storefronts.ready', 'KW'), $html);
        $this->assertStringNotContainsString(route('admin.storefronts.legal-texts.index', 'KW'), $html);
        $this->get(route('admin.storefronts.legal-texts.index', 'KW'))->assertRedirect(route('admin.dashboard.index'));
    }

    // ---- what the page shows ----------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_that_is_not_ready_shows_which_prerequisite_is_unmet_and_offers_no_ready_step(): void
    {
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $html = $this->get(route('admin.storefronts.index'))->assertOk()->getContent();

        $kuwait = $this->cardOf($html, 'KW');
        $this->assertStringContainsString('data-prerequisite="legal_texts" data-met="no"', $kuwait);
        $this->assertStringContainsString('data-prerequisite="tax" data-met="no"', $kuwait);
        $this->assertStringNotContainsString(route('admin.storefronts.ready', 'KW'), $kuwait, 'a draft with something unmet is not offered the step');
    }

    public function test_a_storefront_with_everything_it_needs_is_offered_the_ready_step(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $kuwait = $this->cardOf($this->get(route('admin.storefronts.index'))->getContent(), 'KW');

        $this->assertStringContainsString('data-prerequisite="legal_texts" data-met="yes"', $kuwait);
        $this->assertStringContainsString(route('admin.storefronts.ready', 'KW'), $kuwait);
    }

    public function test_the_page_says_when_storefronts_are_switched_off_and_still_works(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->meetThePrerequisitesOf();

        $this->get(route('admin.storefronts.index'))->assertOk()->assertSee('GCC_STOREFRONTS_ENABLED');
        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON]);

        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf(), 'a storefront is prepared before it is switched on');
    }

    public function test_the_default_storefront_is_active_and_can_only_be_paused_browse_only(): void
    {
        $sa = $this->cardOf($this->get(route('admin.storefronts.index'))->getContent(), 'SA');

        $this->assertStringContainsString('data-state="active"', $sa);
        $this->assertStringContainsString('value="browse_only"', $sa);
        $this->assertStringNotContainsString('value="maintenance"', $sa);
        $this->assertStringNotContainsString('value="redirect"', $sa);

        $this->post(route('admin.storefronts.pause', 'SA'), ['mode' => 'maintenance', 'reason' => self::REASON]);

        $this->assertToastContains('would strand its own visitors');
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf('SA'));
    }

    // ---- the steps --------------------------------------------------------------------------------------------------------------------------

    public function test_every_step_of_the_lifecycle_is_taken_through_the_screen_and_audited_with_the_admin_who_is_signed_in(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON])->assertRedirect();
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());

        $this->post(route('admin.storefronts.activate', 'KW'), ['reason' => self::REASON, 'reference' => self::REFERENCE])->assertRedirect();
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());

        $this->post(route('admin.storefronts.pause', 'KW'), ['mode' => 'maintenance', 'reason' => 'The stock count', 'message' => 'Back after the count.', 'until' => now('UTC')->addDay()->format('Y-m-d H:i')])->assertRedirect();
        $status = $this->lifecycle()->statusOf('KW');
        $this->assertSame([StorefrontLifecycle::PAUSED, StorefrontLifecycle::MAINTENANCE, 'Back after the count.'], [$status['state'], $status['mode'], $status['message']]);

        $page = $this->get(route('admin.storefronts.index'))->getContent();
        $this->assertStringContainsString('Back after the count.', $page);
        $this->assertStringContainsString(route('admin.storefronts.resume', 'KW'), $page);

        $this->post(route('admin.storefronts.resume', 'KW'), ['reason' => 'The count is done'])->assertRedirect();
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());

        foreach (['ready', 'activated', 'paused', 'resumed'] as $step) {
            $lines = $this->auditLines('storefront.lifecycle.' . $step);
            $this->assertCount(1, $lines, "one audit line for {$step}");
            $this->assertSame([self::LIFECYCLE_ADMIN, 'Lifecycle admin', 'admin'], [(int) $lines[0]->actor_id, $lines[0]->actor_name, $lines[0]->actor_type], "{$step} names the admin who took it");
        }
    }

    public function test_the_person_of_a_change_is_the_signed_in_admin_and_a_name_typed_in_is_ignored(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON, 'by' => 'The owner of the company', 'person' => 'The owner of the company', 'changed_by' => 'The owner of the company']);

        $this->assertSame('Lifecycle admin (admin ' . self::LIFECYCLE_ADMIN . ')', $this->lifecycle()->statusOf('KW')['changed_by']);
    }

    public function test_a_refusal_is_shown_in_the_words_of_the_service_and_nothing_is_changed_or_audited(): void
    {
        $this->signInAs(self::LIFECYCLE_ADMIN);

        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON])->assertRedirect();

        $this->assertToastContains('KW does not meet what it needs to sell');
        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_storefront_that_is_not_configured_is_refused_in_the_words_of_the_service(): void
    {
        $this->post(route('admin.storefronts.ready', 'ZZ'), ['reason' => self::REASON]);

        $this->assertToastContains('ZZ is not a configured storefront.');
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_step_that_is_missing_its_words_is_refused_before_it_reaches_the_service(): void
    {
        $this->anActiveStorefront();
        $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: 'The head of the market', reason: self::REASON);
        $before = DB::table('audit_logs')->count();

        $this->post(route('admin.storefronts.resume', 'KW'), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.storefronts.pause', 'KW'), ['reason' => self::REASON])->assertSessionHasErrors('mode');
        $this->post(route('admin.storefronts.pause', 'KW'), ['reason' => self::REASON, 'mode' => 'close_everything'])->assertSessionHasErrors('mode');

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertSame(StorefrontLifecycle::PAUSED, $this->stateOf());
    }

    public function test_activating_asks_for_the_reference_and_a_ready_storefront_whose_prerequisite_was_withdrawn_is_refused(): void
    {
        $this->meetThePrerequisitesOf();
        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => self::REASON]);

        $this->post(route('admin.storefronts.activate', 'KW'), ['reason' => self::REASON])->assertSessionHasErrors('reference');
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());

        DB::table('storefront_legal_documents')->where('country_code', 'KW')->delete();
        $this->post(route('admin.storefronts.activate', 'KW'), ['reason' => self::REASON, 'reference' => self::REFERENCE]);

        $this->assertToastContains('does not meet what it needs to sell');
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
    }

    public function test_the_words_a_person_typed_are_shown_escaped(): void
    {
        $this->anActiveStorefront();
        $this->post(route('admin.storefronts.pause', 'KW'), ['mode' => 'browse_only', 'reason' => '<script>alert(1)</script>', 'message' => '<img src=x onerror=alert(2)>']);

        $html = $this->get(route('admin.storefronts.index'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_permission_keys_are_the_three_the_role_form_offers(): void
    {
        $this->assertSame(
            [StorefrontPermissionService::LIFECYCLE, StorefrontPermissionService::LEGAL_WRITE, StorefrontPermissionService::LEGAL_APPROVE],
            array_keys(StorefrontPermissionService::all()),
        );

        $this->signInAs(self::CATALOG_ADMIN);
        $this->assertFalse(StorefrontPermissionService::canOpenTheScreens());
        $this->signInAs(self::LEGAL_APPROVER);
        $this->assertTrue(StorefrontPermissionService::canOpenTheScreens());
    }

    /** The markup of the card of one storefront. */
    private function cardOf(string $html, string $country): string
    {
        $start = strpos($html, 'data-storefront="' . $country . '"');
        $this->assertNotFalse($start, "{$country} is on the page");
        $next = strpos($html, 'data-storefront="', $start + 10);

        return substr($html, $start, $next === false ? null : $next - $start);
    }
}
