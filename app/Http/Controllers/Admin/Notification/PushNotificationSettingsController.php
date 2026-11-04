<?php

namespace App\Http\Controllers\Admin\Notification;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\NotificationMessageRepositoryInterface;
use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Services\PushNotificationService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushNotificationSettingsController extends BaseController
{

    /**
     * @param BusinessSettingRepositoryInterface $businessSettingRepo
     * @param NotificationMessageRepositoryInterface $notificationMessageRepo
     * @param PushNotificationService $pushNotificationService
     * @param TranslationRepositoryInterface $translationRepo
     */
    public function __construct(
        private readonly BusinessSettingRepositoryInterface     $businessSettingRepo,
        private readonly NotificationMessageRepositoryInterface $notificationMessageRepo,
        private readonly PushNotificationService                $pushNotificationService,
        private readonly TranslationRepositoryInterface         $translationRepo,
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
        $customerMessages = $this->getPushNotificationMessageData(userType: 'customer');
        $vendorMessages = $this->getPushNotificationMessageData(userType: 'seller');
        $deliveryManMessages = $this->getPushNotificationMessageData(userType: 'delivery_man');
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'pnc_language']);
        return view('admin-views.push-notification.index', compact('customerMessages', 'vendorMessages', 'deliveryManMessages', 'language'));
    }

    /**
     * @param $userType
     * @return Collection
     */
    protected function getPushNotificationMessageData($userType): Collection
    {
        $pushNotificationMessages = $this->notificationMessageRepo->getListWhere(orderBy: ['key' => 'asc'], filters: ['user_type' => $userType]);
        $pushNotificationMessagesKeyArray = $this->pushNotificationService->getMessageKeyData(userType: $userType);
        foreach ($pushNotificationMessagesKeyArray as $value) {
            $checkKey = $pushNotificationMessages->where('key', $value)->first();
            if ($checkKey === null) {
                $this->notificationMessageRepo->add(
                    data: $this->pushNotificationService->getAddData(userType: $userType, value: $value)
                );
            }
        }
        foreach ($pushNotificationMessages as $value) {
            if (!in_array($value['key'], $pushNotificationMessagesKeyArray)) {
                $this->notificationMessageRepo->delete(params: ['id' => $value['id']]);
            }
        }
        return $this->notificationMessageRepo->getListWhere(orderBy: ['key' => 'asc'], filters: ['user_type' => $userType]);
    }


    public function updatePushNotificationMessage(Request $request): RedirectResponse
    {
        $pushNotificationMessages = $this->notificationMessageRepo->getListWhere(filters: ['user_type' => $request['type']]);
        foreach ($pushNotificationMessages as $pushNotificationMessage) {
            $message = 'message' . $pushNotificationMessage['id'];
            $status = 'status' . $pushNotificationMessage['id'];
            $lang = 'lang' . $pushNotificationMessage['id'];
            $this->notificationMessageRepo->update(
                id: $pushNotificationMessage['id'],
                data: $this->pushNotificationService->getUpdateData(
                    request: $request,
                    message: $message,
                    status: $status,
                    lang: $lang
                )
            );
            $langs = $request->input($lang, []);
            $messages = $request->input($message, []);
            if (is_array($langs) && is_array($messages)) {
                foreach ($langs as $index => $langValue) {
                    $msgValue = $messages[$index] ?? null;
                    if ($msgValue && $langValue != 'en') {
                        $this->translationRepo->updateData(
                            model: 'App\Models\NotificationMessage',
                            id: $pushNotificationMessage['id'],
                            lang: $langValue,
                            key: $pushNotificationMessage['key'],
                            value: $msgValue
                        );
                    }
                }
            }
        }

        updateSetupGuideCacheKey(key: 'notification_configuration', panel: 'admin');
        ToastMagic::success(translate('update_successfully'));
        return redirect()->route('admin.push-notification.index');
    }

    public function getFirebaseConfigurationView(): View
    {
        $firebaseOTPVerification = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'firebase_otp_verification'])?->value ?? '';
        $configStatus = $this->checkFirebaseConfigAbility();
        $pushNotificationKey = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'push_notification_key'])->value ?? '';
        $configData = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'fcm_credentials'])->value ?? '';
        $projectId = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'fcm_project_id'])->value ?? '';
        return view("admin-views.third-party.firebase-configuration.index", [
            'firebaseOTPVerification' => json_decode($firebaseOTPVerification, true),
            'pushNotificationKey' => $pushNotificationKey,
            'projectId' => $projectId,
            'configData' => json_decode($configData),
            'configStatus' => json_decode($configStatus),
        ]);
    }

    private function checkFirebaseConfigAbility(): bool
    {
        $config = getWebConfig('fcm_credentials') ?? [];
        $configStatus = true;

        if (
            empty($config) || empty($config['apiKey']) || empty($config['authDomain']) ||
            empty($config['projectId']) || empty($config['storageBucket']) || empty($config['messagingSenderId']) || empty($config['appId'])
        ) {
            $configStatus = false;
        }

        return $configStatus;
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws Exception
     */
    public function getFirebaseConfigurationUpdate(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'fcm_project_id', value: $request['fcm_project_id']);
        $this->businessSettingRepo->updateOrInsert(type: 'push_notification_key', value: $request['push_notification_key']);

        $configData = $this->pushNotificationService->getFCMCredentialsArray(request: $request);
        $this->pushNotificationService->firebaseConfigFileGenerate(config: $configData);
        $this->businessSettingRepo->updateOrInsert(type: 'fcm_credentials', value: json_encode($configData));
        clearWebConfigCacheKeys();

        ToastMagic::success(translate('settings_updated'));
        return back();
    }

}
