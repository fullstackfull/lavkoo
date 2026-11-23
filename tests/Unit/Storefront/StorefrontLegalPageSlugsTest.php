<?php

namespace Tests\Unit\Storefront;

use Tests\TestCase;

/**
 * The one place that says which business page stands for which legal document type (I2-04). A document type nobody can read, a page two types
 * share, or a slug the installation never creates would leave a storefront with a text it can never show, or with another text under a page.
 */
class StorefrontLegalPageSlugsTest extends TestCase
{
    /** @return array<string, string> document type => slug */
    private function pageSlugs(): array
    {
        return config('gcc.storefronts.legal.page_slugs');
    }

    public function test_every_required_document_type_has_a_page(): void
    {
        $this->assertSame([], array_diff(config('gcc.storefronts.legal.required_documents'), array_keys($this->pageSlugs())));
    }

    public function test_no_page_stands_for_two_document_types(): void
    {
        $this->assertSame(array_values($this->pageSlugs()), array_values(array_unique($this->pageSlugs())));
    }

    public function test_every_page_is_one_the_installation_creates_and_the_admin_keeps(): void
    {
        $adminPages = (string) file_get_contents(app_path('Http/Controllers/Admin/Settings/PagesController.php'));
        $installedPages = (string) file_get_contents(app_path('Traits/UpdateClass.php'));

        foreach ($this->pageSlugs() as $slug) {
            $this->assertStringContainsString("'{$slug}'", $adminPages, $slug);
            $this->assertStringContainsString("'{$slug}'", $installedPages, $slug);
        }
    }

    public function test_the_about_page_stands_for_no_legal_document(): void
    {
        $this->assertNotContains('about-us', $this->pageSlugs());
    }
}
