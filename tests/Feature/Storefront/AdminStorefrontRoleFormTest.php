<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontPermissionService;
use Illuminate\Support\Facades\DB;

/**
 * The role form of the admin panel offers the three storefront permissions in a section of their own, keeps what a role holds checked when it is edited, and a role that is granted one of
 * them can then take the step: the permission is read from the role on every request, so granting and withdrawing it take effect at once. No role holds any of them until it is granted.
 */
class AdminStorefrontRoleFormTest extends AdminStorefrontScreenTestCase
{
    private function checkboxOf(string $html, string $permission): string
    {
        $found = preg_match('/<input type="checkbox" name="modules\[\]" value="' . preg_quote($permission, '/') . '"[^>]*>/', $html, $matches);
        $this->assertSame(1, $found, "{$permission} is offered in the role form");

        return $matches[0];
    }

    public function test_the_create_form_offers_the_three_permissions_unchecked(): void
    {
        $html = $this->get(route('admin.custom-role.create'))->assertOk()->getContent();

        foreach (array_keys(StorefrontPermissionService::all()) as $permission) {
            $this->assertStringNotContainsString('checked', $this->checkboxOf($html, $permission), "{$permission} starts unchecked");
        }
    }

    public function test_the_edit_form_shows_which_of_the_three_a_role_holds(): void
    {
        $html = $this->get(route('admin.custom-role.update', 12))->assertOk()->getContent();

        $this->assertStringContainsString('checked', $this->checkboxOf($html, StorefrontPermissionService::LEGAL_WRITE));
        $this->assertStringNotContainsString('checked', $this->checkboxOf($html, StorefrontPermissionService::LIFECYCLE));
        $this->assertStringNotContainsString('checked', $this->checkboxOf($html, StorefrontPermissionService::LEGAL_APPROVE));
    }

    public function test_a_role_that_is_granted_a_permission_can_take_the_step_at_once_and_loses_it_when_it_is_withdrawn(): void
    {
        $this->meetThePrerequisitesOf();
        $this->signInAs(self::CATALOG_ADMIN);
        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => 'Ready'])->assertRedirect(route('admin.dashboard.index'));

        $this->signInAs(self::MASTER);
        $this->post(route('admin.custom-role.update', 14), ['id' => 14, 'name' => 'Catalog only', 'modules' => ['catalog', StorefrontPermissionService::LIFECYCLE]])->assertRedirect();
        $this->assertContains(StorefrontPermissionService::LIFECYCLE, json_decode((string) DB::table('admin_roles')->where('id', 14)->value('module_access'), true));

        $this->signInAs(self::CATALOG_ADMIN);
        $this->post(route('admin.storefronts.ready', 'KW'), ['reason' => 'Ready'])->assertRedirect();
        $this->assertSame('ready', app(\App\Services\Storefront\StorefrontLifecycle::class)->statusOf('KW')['state']);

        $this->signInAs(self::MASTER);
        $this->post(route('admin.custom-role.update', 14), ['id' => 14, 'name' => 'Catalog only', 'modules' => ['catalog']]);

        $this->signInAs(self::CATALOG_ADMIN);
        $this->post(route('admin.storefronts.activate', 'KW'), ['reason' => 'Open', 'reference' => 'Minutes'])->assertRedirect(route('admin.dashboard.index'));
        $this->assertSame('ready', app(\App\Services\Storefront\StorefrontLifecycle::class)->statusOf('KW')['state'], 'the withdrawn permission stops the step');
    }
}
