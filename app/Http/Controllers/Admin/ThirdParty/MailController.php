<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\MailUpdateRequest;
use App\Services\MailService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MailController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly MailService $mailService,
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
        return view('admin-views.third-party.mail.index');
    }


    public function update(MailUpdateRequest $request): RedirectResponse
    {
        if ($request['status'] == 1) {
            $mailConfigSendGrid = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'mail_config_sendgrid']);
            $mailData = json_decode($mailConfigSendGrid['value'], true);
            $mailDataArray = $this->mailService->getMailData(mailData: $mailData);
            $this->businessSettingRepo->updateWhere(params: ['type' => 'mail_config_sendgrid'], data: ['value' => json_encode($mailDataArray)]);
        }

        $dataArray = $this->mailService->getData(request: $request);
        $this->businessSettingRepo->updateWhere(params: ['type' => 'mail_config'], data: ['value' => json_encode($dataArray)]);
        ToastMagic::success(translate('Configuration_updated_successfully'));
        return back();
    }

    public function updateSendGrid(MailUpdateRequest $request): RedirectResponse
    {
        if ($request['status'] == 1) {
            $mailConfig = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'mail_config']);
            $dataMailSmtp = json_decode($mailConfig['value'], true);
            $mailDataArray = $this->mailService->getMailData(mailData: $dataMailSmtp);
            $this->businessSettingRepo->updateWhere(params: ['type' => 'mail_config'], data: ['value' => json_encode($mailDataArray)]);
        }
        $dataArray = $this->mailService->getData(request: $request);
        $this->businessSettingRepo->updateWhere(params: ['type' => 'mail_config_sendgrid'], data: ['value' => json_encode($dataArray)]);
        ToastMagic::success(translate('SendGrid_Configuration_updated_successfully'));
        return back();
    }

    public function send(Request $request): JsonResponse
    {
        $response = $this->mailService->sendMail(request: $request);
        return response()->json([
            'status' => $response['status'],
            'message' => $response['message'],
        ]);
    }
}
