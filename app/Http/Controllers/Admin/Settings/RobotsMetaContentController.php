<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessPageRepositoryInterface;
use App\Contracts\Repositories\RobotsMetaContentRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\RobotsMetaContentAddRequest;
use App\Services\SEOSettingsService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RobotsMetaContentController extends BaseController
{
    public function __construct(
        private readonly RobotsMetaContentRepositoryInterface $robotsMetaContentRepo,
        private readonly BusinessPageRepositoryInterface      $businessPageRepo,
        private readonly SEOSettingsService                   $SEOSettingsService,
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
        $businessPages = $this->businessPageRepo->getListWhere(dataLimit: 'all');
        $pageListArray = $this->SEOSettingsService->getRobotsMetaContentPages(businessPages: $businessPages);
        ksort($pageListArray);
        $allPageList = $this->robotsMetaContentRepo->getListWhere(dataLimit: 'all')->pluck('page_name')->toArray();
        $skipPages = array_merge(['default'], array_diff($allPageList, array_keys($pageListArray)));
        $pageListData = $this->robotsMetaContentRepo->getListWhereNotIn(whereNotIn: ['page_name' => $skipPages], dataLimit: 20);

        $defaultPageData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'default']);
        return view('admin-views.seo-settings.robots-meta-content', [
            'pageList' => $pageListArray,
            'pageListData' => $pageListData,
            'defaultPageData' => $defaultPageData,
        ]);
    }

    public function addPage(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->route('admin.seo-settings.robots-meta-content.index');
        }
        $businessPages = $this->businessPageRepo->getListWhere(dataLimit: 'all');
        $getPageInfo = $this->SEOSettingsService->getRobotsMetaContentPageName(name: $request['page_name'], businessPages: $businessPages);
        if (!empty($getPageInfo)) {
            $robotsMetaContent = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => $request['page_name']]);
            if ($robotsMetaContent) {
                $this->robotsMetaContentRepo->updateByParams(params: ['page_name' => $request['page_name']], data: [
                    'page_title' => $getPageInfo['title'],
                    'page_name' => $request['page_name'],
                    'page_url' => $getPageInfo['route'],
                    "created_at" => now(),
                ]);
            } else {
                $this->robotsMetaContentRepo->add(data: [
                    'page_title' => $getPageInfo['title'],
                    'page_name' => $request['page_name'],
                    'page_url' => $getPageInfo['route'],
                    'canonicals_url' => $getPageInfo['route'],
                    "created_at" => now(),
                ]);
            }
            ToastMagic::success(translate('successfully_add'));
        }
        return redirect()->route('admin.seo-settings.robots-meta-content.index');
    }

    public function getPageDelete(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->back();
        }
        $this->robotsMetaContentRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('successfully_delete'));
        return redirect()->route('admin.seo-settings.robots-meta-content.index');
    }

    public function getPageAddContentView(Request $request): View
    {
        $pageData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => $request['page_name']]);
        $pageName = $request['page_name'];
        return view('admin-views.seo-settings.robots-meta-content-view', [
            'pageData' => $pageData,
            'pageName' => $pageName,
        ]);
    }

    public function getPageContentUpdate(RobotsMetaContentAddRequest $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->route('admin.seo-settings.robots-meta-content.index');
        }
        $businessPages = $this->businessPageRepo->getListWhere(dataLimit: 'all');
        $getOldData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => $request['page_name']]);
        $getContentData = $this->SEOSettingsService->getRobotsMetaContentData(request: $request, oldData: $getOldData ?? null, businessPages: $businessPages);
        $this->robotsMetaContentRepo->updateOrInsert(params: ['page_name' => $request['page_name']], data: $getContentData);
        ToastMagic::success(translate('successfully_update'));
        return redirect()->route('admin.seo-settings.robots-meta-content.index');
    }

}
