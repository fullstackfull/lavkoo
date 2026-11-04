<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Enums\WebConfigKey;
use App\Http\Controllers\BaseController;
use App\Repositories\ErrorLogsRepository;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ErrorLogsController extends BaseController
{
    public function __construct(
        private readonly ErrorLogsRepository $errorLogsRepo,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $errorLogs = $this->errorLogsRepo->getListWhere(orderBy: ['id' => 'desc'], dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT));
        return view('admin-views.seo-settings.error-logs', compact('errorLogs'));
    }

    public function update(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->back();
        }
        $this->errorLogsRepo->update(id: $request['id'], data: [
            'redirect_url' => $request['redirect_url'],
            'redirect_status' => $request->get('redirect_status', '301')
        ]);
        ToastMagic::success(translate('updated_successfully'));
        return redirect()->back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->errorLogsRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('deleted_successfully'));
        return redirect()->back();
    }

    public function deleteSelectedErrorLogs(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return redirect()->back();
        }
        if ($request->has('selected-ids') && count($request['selected-ids']) > 0) {
            foreach ($request['selected-ids'] as $id) {
                $this->errorLogsRepo->delete(params: ['id' => $id]);
            }
            ToastMagic::success(translate('selected_error_logs_deleted_successfully'));
        } else {
            ToastMagic::warning(translate('please_select_logs_for_delete'));
        }
        return redirect()->back();
    }

}
