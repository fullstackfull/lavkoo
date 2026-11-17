<?php

namespace Tests\Feature\Web;

use App\Models\Admin;
use Tests\Support\BuildsBusinessPages;
use Tests\TestCase;

/**
 * A business page is public only while it is published. The public route used to drop that condition for any request that carried
 * source=admin, so anyone could read an unpublished page by adding the parameter. What a visitor sees must not depend on the request:
 * a guest and a signed-in admin get the same answer on the public route.
 */
class BusinessPageVisibilityTest extends TestCase
{
    use BuildsBusinessPages;

    private const PUBLISHED = 1;
    private const UNPUBLISHED = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBusinessPageTables();
        $this->prepareBusinessPageRendering();
    }

    public function test_a_published_page_is_shown_to_a_guest(): void
    {
        $this->insertBusinessPage(slug: 'terms-of-use', status: self::PUBLISHED, title: 'Terms of Use');

        $this->get('/business-page/terms-of-use')
            ->assertOk()
            ->assertViewIs(VIEW_FILE_NAMES['business_page'])
            ->assertSee('Body of Terms of Use', escape: false);
    }

    public function test_an_unpublished_page_is_not_shown_to_a_guest(): void
    {
        $this->insertBusinessPage(slug: 'draft-terms', status: self::UNPUBLISHED, title: 'Draft Terms');

        $this->get('/business-page/draft-terms')
            ->assertRedirect(route('home'))
            ->assertDontSee('Body of Draft Terms', escape: false);
    }

    public function test_the_source_admin_parameter_does_not_reveal_an_unpublished_page(): void
    {
        $this->insertBusinessPage(slug: 'draft-terms', status: self::UNPUBLISHED, title: 'Draft Terms');

        $this->get('/business-page/draft-terms?source=admin')
            ->assertRedirect(route('home'))
            ->assertDontSee('Body of Draft Terms', escape: false);
    }

    public function test_the_source_admin_parameter_does_not_change_what_a_published_page_shows(): void
    {
        $this->insertBusinessPage(slug: 'terms-of-use', status: self::PUBLISHED, title: 'Terms of Use');

        $this->get('/business-page/terms-of-use?source=admin')
            ->assertOk()
            ->assertViewIs(VIEW_FILE_NAMES['business_page'])
            ->assertSee('Body of Terms of Use', escape: false);
    }

    public function test_a_signed_in_admin_gets_no_more_than_a_guest_on_the_public_route(): void
    {
        $this->insertBusinessPage(slug: 'draft-terms', status: self::UNPUBLISHED, title: 'Draft Terms');

        $this->actingAs(new Admin(['id' => 1]), 'admin')
            ->get('/business-page/draft-terms?source=admin')
            ->assertRedirect(route('home'))
            ->assertDontSee('Body of Draft Terms', escape: false);
    }

    public function test_the_public_controller_reads_no_request_parameter_to_widen_visibility(): void
    {
        $controllerSource = (string) file_get_contents(app_path('Http/Controllers/Web/PageController.php'));

        $this->assertStringNotContainsString("'source'", $controllerSource);
        $this->assertStringNotContainsString('->source', $controllerSource);
    }

    public function test_the_admin_view_link_to_a_page_carries_no_source_parameter(): void
    {
        $adminEditView = (string) file_get_contents(resource_path('views/admin-views/pages-and-media/update.blade.php'));

        $this->assertStringNotContainsString("'source' => 'admin'", $adminEditView);
    }
}
