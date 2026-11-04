<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Contracts\Repositories\AdminRepositoryInterface;
use Exception;
use App\Enums\SessionKey;
use Illuminate\Http\Request;
use App\Services\ShopService;
use App\Services\VendorService;
use Illuminate\Http\JsonResponse;
use App\Services\RecaptchaService;
use App\Traits\EmailTemplateTrait;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Collection;
use App\Http\Requests\Vendor\VendorAddRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Repositories\VendorRegistrationReasonRepository;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\HelpTopicRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Contracts\Repositories\EmailTemplatesRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;

class RegisterController extends BaseController
{
    use EmailTemplateTrait;

    public function __construct(
        private readonly VendorRepositoryInterface          $vendorRepo,
        private readonly AdminRepositoryInterface           $adminRepo,
        private readonly VendorWalletRepositoryInterface    $vendorWalletRepo,
        private readonly ShopRepositoryInterface            $shopRepo,
        private readonly VendorService                      $vendorService,
        private readonly ShopService                        $shopService,
        private readonly EmailTemplatesRepositoryInterface  $emailTemplatesRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly HelpTopicRepositoryInterface       $helpTopicRepo,
        private readonly VendorRegistrationReasonRepository $vendorRegistrationReasonRepo,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $businessMode = getWebConfig(name: 'business_mode');
        $vendorRegistration = getWebConfig(name: 'seller_registration');
        if ((isset($businessMode) && $businessMode == 'single') || (isset($vendorRegistration) && $vendorRegistration == 0)) {
            ToastMagic::warning(translate('access_denied') . '!!');
            return redirect('/');
        }
        $vendorRegistrationHeader = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_header'])['value']);
        $vendorRegistrationReasons = $this->vendorRegistrationReasonRepo->getListWhere(orderBy: ['priority' => 'desc'], filters: ['status' => 1], dataLimit: 'all');
        $sellWithUs = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_sell_with_us'])['value']);
        $downloadVendorApp = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'download_vendor_app'])['value']);
        $businessProcess = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_main_section'])['value']);
        $businessProcessStep = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_step'])['value']);
        $helpTopics = $this->helpTopicRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['type' => 'vendor_registration', 'status' => '1'],
            dataLimit: 'all');
        return view(VIEW_FILE_NAMES['seller_registration'], compact('vendorRegistrationHeader', 'vendorRegistrationReasons', 'sellWithUs', 'downloadVendorApp', 'helpTopics', 'businessProcess', 'businessProcessStep'));
    }

    public function add(VendorAddRequest $request): JsonResponse
    {
        $result = RecaptchaService::verificationStatus(request: $request, session: SessionKey::VENDOR_RECAPTCHA_KEY, action: "register");
        if ($result && !$result['status']) {
            if ($request->ajax()) {
                return response()->json([
                    'error' => $result['message'],
                ]);
            }
        }
        $adminEmail = $this->adminRepo->getFirstWhere(['admin_role_id' => 1]);
        if ($adminEmail && isset($adminEmail['email']) && $request['email'] === $adminEmail['email']) {
            return response()->json([
                'error' => translate('Email_already_exist_please_try_another_email'),
            ]);
        }
        $vendor = $this->vendorRepo->add(data: $this->vendorService->getAddData($request));
        $this->shopRepo->add($this->shopService->getAddShopDataForRegistration(request: $request, vendorId: $vendor['id']));
        $this->vendorWalletRepo->add($this->vendorService->getInitialWalletData(vendorId: $vendor['id']));

        $data = [
            'vendorName' => $request['f_name'],
            'status' => 'pending',
            'subject' => translate('Vendor_Registration_Successfully_Completed'),
            'title' => translate('Vendor_Registration_Successfully_Completed'),
            'userType' => 'vendor',
            'templateName' => 'registration',
        ];
        try {
            event(new VendorRegistrationEvent(email: $request['email'], data: $data));
        } catch (Exception $e) {
            return response()->json(
                ['status' => 1, 'redirectRoute' => route('vendor.auth.login')]
            );
        }
        return response()->json(
            ['status' => 1, 'redirectRoute' => route('vendor.auth.login')]
        );
    }
}
