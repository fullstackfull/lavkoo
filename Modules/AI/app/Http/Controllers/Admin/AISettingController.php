<?php

namespace Modules\AI\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Modules\AI\app\Http\Requests\AISettingRequest;
use Modules\AI\app\Http\Requests\AICustomerUsagesLimitRequest;
use Modules\AI\app\Http\Requests\AIVendorUsagesLimitRequest;
use Modules\AI\app\Models\AISetting;

class AISettingController extends Controller
{

    public function index()
    {
        $AiSetting = AISetting::first();
        return view('ai::admin-views.ai-setting.index', compact('AiSetting'));
    }

    public function getVendorUsagesLimitView()
    {
        $AiSetting = AISetting::first();
        return view('ai::admin-views.ai-setting.vendors-usage-limits', compact('AiSetting'));
    }

    public function getCustomerUsagesLimitView()
    {
        $AiSetting = AISetting::first();
        return view('ai::admin-views.ai-setting.customers-usage-limits', compact('AiSetting'));
    }


    public function store(AISettingRequest $request): RedirectResponse
    {
        Cache::forget('active_ai_provider');
        self::addFirstAISetting();

        try {
            $AiSetting = AISetting::first();
            $AiSetting->update([
                'api_key' => $request['api_key'],
                'organization_id' => $request['organization_id'],
                'status' => !empty($request['api_key']) && !empty($request['organization_id']) && $request['status'] == 1 ? 1 : 0,
            ]);

            ToastMagic::success(translate('AI_configuration_saved_successfully'));
        } catch (Exception $exception) {
            ToastMagic::error(translate('Failed_to_save_AI_configuration'));
        }
        return redirect()->back();
    }

    public function updateVendorUsagesLimit(AIVendorUsagesLimitRequest $request): RedirectResponse
    {
        Cache::forget('active_ai_provider');
        self::addFirstAISetting();

        try {
            $AiSetting = AISetting::first();
            $AiSetting->update([
                'image_upload_limit' => $request['image_upload_limit'] ?? 0,
                'generate_limit' => $request['generate_limit'] ?? 0
            ]);

            ToastMagic::success(translate('AI_configuration_saved_successfully'));
        } catch (Exception $exception) {
            ToastMagic::error(translate('Failed_to_save_AI_configuration'));
        }
        return redirect()->back();
    }

    public function updateCustomerUsagesLimit(AICustomerUsagesLimitRequest $request): RedirectResponse
    {
        Cache::forget('active_ai_provider');
        self::addFirstAISetting();

        try {
            $AiSetting = AISetting::first();
            $AiSetting->update([
                'customer_generate_limit' => $request['customer_generate_limit'] ?? 0,
                'customer_image_upload_limit' => $request['customer_image_upload_limit'] ?? 0,
            ]);

            ToastMagic::success(translate('AI_configuration_saved_successfully'));
        } catch (Exception $exception) {
            ToastMagic::error(translate('Failed_to_save_AI_configuration'));
        }
        return redirect()->back();
    }


    public function addFirstAISetting(): void
    {
        Cache::forget('active_ai_provider');
        if (!AISetting::first()) {
            AISetting::create([
                'ai_name' => 'OpenAI',
                'api_key' => '',
                'organization_id' => '',
                'image_upload_limit' => 0,
                'generate_limit' => 0,
                'customer_generate_limit' => 0,
                'customer_image_upload_limit' => 0,
                'status' => 0,
            ]);
        }
    }
}
