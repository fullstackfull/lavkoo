<?php

namespace App\Http\Controllers\Admin\HelpAndSupport;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\HelpTopicRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\HelpTopicAddRequest;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class HelpTopicController extends BaseController
{

    public function __construct(
        private readonly HelpTopicRepositoryInterface $helpTopicRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $searchValue = $request->searchValue ?? '';
        $helps = $this->helpTopicRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: $searchValue, filters: ['type' => 'default'],dataLimit: 10);
        return view('admin-views.pages-and-media.help-topics.list', compact('helps'));
    }


    public function add(HelpTopicAddRequest $request): RedirectResponse
    {
        $this->helpTopicRepo->add(data: [
            'type' => $request['type'] ?? 'default',
            'question' => $request['question'],
            'answer' => $request['answer'],
            'status' => $request->get('status', 0),
            'ranking' => $request['ranking'],
        ]);
        ToastMagic::success(translate('FAQ_added_successfully'));
        return back();
    }

    public function updateFeatureStatus(Request $request): JsonResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_registration_faq_status', value: $request['status'] ?? 0);
        return response()->json([
            'status' => true,
            'message' => translate('Status_updated_successfully')
        ]);
    }

    public function updateStatus($id): JsonResponse
    {
        $helpTopic = $this->helpTopicRepo->getFirstWhere(params: ['id'=>$id]);
        $this->helpTopicRepo->update(id: $id, data: [
            'status' => $helpTopic['status'] ? 0:1,
        ]);
        return response()->json([
            'status' => true,
            'message' => translate('Status_updated_successfully')
        ]);
    }

    public function getUpdateResponse($id): JsonResponse
    {
        $helpTopic = $this->helpTopicRepo->getFirstWhere(params: ['id'=>$id]);
        return response()->json($helpTopic);
    }

    public function update(HelpTopicAddRequest $request, $id): RedirectResponse
    {
        $this->helpTopicRepo->update(id: $id, data: [
            'question' => $request['question'],
            'answer' => $request['answer'],
            'ranking' => $request['ranking'],
            'status' => $request->get('status', 0),
        ]);
        ToastMagic::success(translate('FAQ_Update_successfully'));
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->helpTopicRepo->delete(params: ['id'=>$request['id']]);
        return back();
    }

}
