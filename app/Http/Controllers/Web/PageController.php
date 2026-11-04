<?php

namespace App\Http\Controllers\Web;

use App\Contracts\Repositories\BusinessPageRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\HelpTopicRepositoryInterface;
use App\Contracts\Repositories\RobotsMetaContentRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Request;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PageController extends Controller
{
    public function __construct(
        private readonly BusinessSettingRepositoryInterface   $businessSettingRepo,
        private readonly HelpTopicRepositoryInterface         $helpTopicRepo,
        private readonly RobotsMetaContentRepositoryInterface $robotsMetaContentRepo,
        private readonly BusinessPageRepositoryInterface      $businessPageRepo,
    )
    {
    }

    public function getPageView(Request $request): View|RedirectResponse
    {
        $filter = ['slug' => $request['slug']];
        if (!($request->has('source') && $request->source == 'admin')) {
            $filter += ['status' => 1];
        }
        $businessPage = $this->businessPageRepo->getFirstWhere(params: $filter, relations: ['banner']);
        if (!$businessPage) {
            Toastr::error(translate('Page_not_found'));
            return redirect()->route('home');
        }
        $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => $request['slug']]);
        if (!$robotsMetaContentData) {
            $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'default']);
        }
        return view(VIEW_FILE_NAMES['business_page'], compact('businessPage', 'robotsMetaContentData'));
    }

    public function getContactView(): View
    {
        $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'contacts']);
        if (!$robotsMetaContentData) {
            $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'default']);
        }
        return view(VIEW_FILE_NAMES['contacts'], compact('robotsMetaContentData'));
    }

    public function getHelpTopicView(): View
    {
        $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'helpTopic']);
        if (!$robotsMetaContentData) {
            $robotsMetaContentData = $this->robotsMetaContentRepo->getFirstWhere(params: ['page_name' => 'default']);
        }
        $helps = $this->helpTopicRepo->getListWhere(orderBy: ['id' => 'desc'], filters: ['status' => 1, 'type' => 'default'], dataLimit: 'all');
        $pageTitleBanner = $this->businessSettingRepo->whereJsonContains(params: ['type' => 'banner_faq_page'], value: ['status' => '1']);
        return view(VIEW_FILE_NAMES['faq'], compact('helps', 'pageTitleBanner', 'robotsMetaContentData'));
    }

}
