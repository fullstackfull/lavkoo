<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Enums\WebConfigKey;
use App\Http\Controllers\BaseController;
use App\Models\BusinessPage;
use App\Traits\FileManagerTrait;
use App\Utils\FileManagerLogic;
use Carbon\Carbon;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Blog\app\Models\Blog;
use Spatie\Sitemap\SitemapGenerator;
use Spatie\Sitemap\Tags\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteMapController extends BaseController
{
    use FileManagerTrait;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepo,
        private readonly ShopRepositoryInterface    $shopRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        if (!File::isDirectory(storage_path('app/public/sitemap'))) {
            File::makeDirectory(storage_path('app/public/sitemap'));
        }
        $siteMapList = self::getAllSitemapFiles(directory: storage_path('app/public/sitemap'));
        $collection = new Collection($siteMapList);
        $dataLimit = !empty(getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)) ? getWebConfig(name: WebConfigKey::PAGINATION_LIMIT) : 25;
        $currentPage = $offset ?? Paginator::resolveCurrentPage('page');
        $totalSize = $collection->count();
        $results = $collection->forPage($currentPage, $dataLimit);
        $paginatedData = new LengthAwarePaginator(items: $results, total: $totalSize, perPage: $dataLimit, currentPage: $currentPage, options: [
            'path' => Paginator::resolveCurrentPath(),
        ]);

        return view('admin-views.seo-settings.sitemap', [
            'siteMapList' => $paginatedData,
        ]);
    }

    public function getGenerateAndDownload(Request $request): JsonResponse
    {
        $filePath = self::processGenerateAndDownload(action: 'generate');
        return response()->json([
            'status' => 1,
            'filePath' => $filePath,
            'fileName' => $filePath,
        ]);
    }

    public function getGenerateAndUpload(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->back();
        }
        self::processGenerateAndDownload(action: 'upload');
        return redirect()->route('admin.seo-settings.sitemap');
    }

    public function getAllSitemapFiles($directory): array
    {
        $files = scandir($directory);
        $fileDetails = [];

        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $filePath = $directory . '/' . $file;
                $createdTime = Carbon::createFromTimestamp(filectime($filePath));
                $modifiedTime = Carbon::createFromTimestamp(filemtime($filePath));
                $fileDetails[] = [
                    'name' => $file,
                    'size' => FileManagerLogic::formatBytes(filesize($filePath)),
                    'path' => asset('storage/app/public/sitemap/' . $file),
                    'created_at' => $createdTime,
                    'modified_at' => $modifiedTime,
                ];
            }
        }
        usort($fileDetails, function($a, $b) {
            return $b['modified_at'] <=> $a['modified_at'];
        });
        return $fileDetails;
    }

    public function processGenerateAndDownload(string $action)
    {
        Artisan::call('file:permission');

        $currentTime = Carbon::now();
        $generator = SitemapGenerator::create(url('/'));
        $generator->shouldCrawl(function ($url) {
            return !str_contains($url, '/products')
                && !str_contains($url, '/product')
                && !str_contains($url, '/vendor-shop');
        });

        // Installed languages drive hreflang alternates: with more than one language, each product URL
        // advertises its per-language variants so AR/EN pages stop competing in search.
        $languages = collect(getWebConfig(name: 'language') ?? [])->pluck('code')->filter()->values();

        $productsUrl = $this->productRepo->getWebListWithScope(scope: 'active', dataLimit: 'all')->pluck('slug');
        foreach ($productsUrl as $productSingleUrl) {
            $productUrl = route('product', ['slug' => $productSingleUrl]);
            $urlObject = Url::create($productUrl)
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly')
                ->setPriority(0.8);
            if ($languages->count() > 1) {
                foreach ($languages as $languageCode) {
                    $urlObject->addAlternate($productUrl . '?language=' . $languageCode, $languageCode);
                }
            }
            $generator->getSitemap()->add($urlObject);
        }

        $blogsUrl = Blog::active()->get();
        foreach ($blogsUrl as $blogSingleUrl) {
            $urlObject = Url::create(route('frontend.blog.details', ['slug' => $blogSingleUrl]))
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly')
                ->setPriority(0.8);
            $generator->getSitemap()->add($urlObject);
        }

        $urlObject = Url::create(route('vendors'))
            ->setLastModificationDate($currentTime)
            ->setChangeFrequency('weekly')
            ->setPriority(0.8);
        $generator->getSitemap()->add($urlObject);

        $urlObject = Url::create(route('vendor-shop', ['slug' => getInHouseShopConfig(key:'slug')]))
            ->setLastModificationDate($currentTime)
            ->setChangeFrequency('weekly')
            ->setPriority(0.8);
        $generator->getSitemap()->add($urlObject);

        $shopsUrl = $this->shopRepo->getListWithScope(scope: 'active', dataLimit: 'all')->pluck('slug');
        foreach ($shopsUrl as $shopSingleUrl) {
            $urlObject = Url::create(route('vendor-shop', ['slug' => $shopSingleUrl]))
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly')
                ->setPriority(0.8);
            $generator->getSitemap()->add($urlObject);
        }

        $policyPagesUrl = [
            route('brands'),
            route('vendors'),
            route('home'),
            route('categories'),
            route('contacts'),
            route('helpTopic'),
            route('products'),
            route('discounted-products'),
            route('track-order.index'),
        ];

        foreach ($policyPagesUrl as $policyPage) {
            $urlObject = Url::create($policyPage)
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly')
                ->setPriority(0.8);
            $generator->getSitemap()->add($urlObject);
        }

        $businessPages = BusinessPage::where('status', 1)->get();
        foreach ($businessPages as $businessPage) {
            $urlObject = Url::create(route('business-page.view', ['slug' => $businessPage['slug']]))
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly')
                ->setPriority(0.8);
            $generator->getSitemap()->add($urlObject);
        }

        $generator->hasCrawled(function (Url $url) use ($currentTime) {
            $url->setPriority(0.8)
                ->setLastModificationDate($currentTime)
                ->setChangeFrequency('weekly');
            return $url;
        });

        $directory = storage_path('app/public/sitemap');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0777, true, true);
        }

        $fileName = 'sitemap-' . Str::slug(Carbon::now()) . '.xml';
        $generator->writeToFile(storage_path('app/public/sitemap/' . $fileName));

        if ($action == 'upload') {
            $generator->writeToFile(public_path('sitemap.xml'));
            $generator->writeToFile(base_path('sitemap.xml'));
        } else{
            return dynamicStorage('storage/app/public/sitemap/' . $fileName);
        }
    }

    public function getDownload(Request $request): BinaryFileResponse|RedirectResponse
    {
        if ($request['path']) {
            $fileName = base64_decode($request['path']);
            if (File::exists(storage_path('app/public/sitemap/' . $fileName))) {
                return response()->download(storage_path('app/public/sitemap/' . $fileName));
            }
        }
        return redirect()->route('admin.seo-settings.sitemap');
    }

    public function getUpload(Request $request): RedirectResponse
    {
        if ($request->file('xml_file')->getClientOriginalExtension() != 'xml') {
            ToastMagic::error(translate('Please_upload_a_xml_file'));
        } elseif ($request->file('xml_file')) {
            $fileName = 'sitemap-' . Str::slug(Carbon::now()) . '.xml';
            $request->file('xml_file')->storeAs('public/sitemap', $fileName);
            if (File::exists(public_path('sitemap.xml'))) {
                File::delete(public_path('sitemap.xml'));
            }
            if (File::exists(base_path('sitemap.xml'))) {
                File::delete(base_path('sitemap.xml'));
            }
            File::put(public_path('sitemap.xml'), $request->file('xml_file'));
            File::put(base_path('sitemap.xml'), $request->file('xml_file'));
            ToastMagic::success(translate('successfully_upload'));
        }
        return redirect()->route('admin.seo-settings.sitemap');
    }

    public function getDelete(Request $request): RedirectResponse
    {
        if ($request['path']) {
            $fileName = base64_decode($request['path']);
            if (File::exists(storage_path('app/public/sitemap/' . $fileName))) {
                File::delete(storage_path('app/public/sitemap/' . $fileName));
                ToastMagic::success(translate('successfully_delete'));
            }
        }
        return redirect()->route('admin.seo-settings.sitemap');
    }
}
