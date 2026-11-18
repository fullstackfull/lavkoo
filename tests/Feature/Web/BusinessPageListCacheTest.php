<?php

namespace Tests\Feature\Web;

use App\Contracts\Repositories\BusinessPageRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBusinessPages;
use Tests\TestCase;

/**
 * Unpublishing or deleting a page must take it out of the public list at once. The list, which the API and the footer read, is cached
 * for three hours. The repository used to clear that cache before writing, so a reader that rebuilt the list during the write kept the
 * old list, with the page still published or still present, for three hours. The cache is now cleared after the write.
 */
class BusinessPageListCacheTest extends TestCase
{
    use BuildsBusinessPages;

    private bool $readerRebuiltDuringWrite = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBusinessPageTables();
        $this->prepareBusinessPageRendering();
    }

    public function test_a_page_unpublished_while_a_reader_rebuilds_the_list_is_not_listed_afterwards(): void
    {
        $this->insertBusinessPage(slug: 'terms-of-use', status: 1, title: 'Terms of Use');
        $this->warmTheListWith(slug: 'terms-of-use');
        $this->rebuildTheListDuringTheNextWrite();

        app(BusinessPageRepositoryInterface::class)->updateWhere(params: ['slug' => 'terms-of-use'], data: ['status' => 0]);

        $this->assertTrue($this->readerRebuiltDuringWrite);
        $this->assertTheListDoesNotInclude(slug: 'terms-of-use');
    }

    public function test_a_page_deleted_while_a_reader_rebuilds_the_list_is_not_listed_afterwards(): void
    {
        $this->insertBusinessPage(slug: 'terms-of-use', status: 1, title: 'Terms of Use');
        $this->warmTheListWith(slug: 'terms-of-use');
        $this->rebuildTheListDuringTheNextWrite();

        app(BusinessPageRepositoryInterface::class)->delete(params: ['slug' => 'terms-of-use']);

        $this->assertTrue($this->readerRebuiltDuringWrite);
        $this->assertTheListDoesNotInclude(slug: 'terms-of-use');
    }

    private function warmTheListWith(string $slug): void
    {
        $this->getJson('/api/v1/business-pages?type=pages')->assertJsonFragment(['slug' => $slug]);
    }

    private function rebuildTheListDuringTheNextWrite(): void
    {
        $listBeforeTheWrite = Cache::get(CACHE_FOR_BUSINESS_PAGES_LIST);

        DB::beforeExecuting(function (string $sql) use ($listBeforeTheWrite) {
            $isBusinessPageWrite = preg_match('/^\s*(update|delete)\b/i', $sql) === 1 && str_contains($sql, 'business_pages');
            if ($isBusinessPageWrite && !$this->readerRebuiltDuringWrite) {
                $this->readerRebuiltDuringWrite = true;
                Cache::put(CACHE_FOR_BUSINESS_PAGES_LIST, $listBeforeTheWrite, now()->addHours(3));
            }
        });
    }

    private function assertTheListDoesNotInclude(string $slug): void
    {
        $this->getJson('/api/v1/business-pages?type=pages')->assertOk()->assertJsonMissing(['slug' => $slug]);
    }
}
