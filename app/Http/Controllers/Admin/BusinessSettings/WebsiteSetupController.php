<?php

namespace App\Http\Controllers\Admin\BusinessSettings;

use App\Http\Requests\Admin\WebsiteSetupRequest;
use Illuminate\Http\Request;
use App\Traits\SettingsTrait;
use App\Traits\FileManagerTrait;
use Illuminate\Contracts\View\View;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\BaseController;
use App\Services\BusinessSettingService;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;

class WebsiteSetupController extends BaseController
{

    use SettingsTrait;
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly BusinessSettingService             $businessSettingService,
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
        return $this->getView();
    }

    public function getView(): View
    {
        $colors = getWebConfig(name: 'colors');
        $businessSetting = [
            'primary_color' => $colors['primary'] ?? '',
            'secondary_color' => $colors['secondary'] ?? '',
            'panel_sidebar' => $colors['panel-sidebar'] ?? '',
            'primary_color_light' => $colors['primary_light'] ?? '',
            'web_logo' => getWebConfig(name: 'company_web_logo') ?? '',
            'mob_logo' => getWebConfig(name: 'company_mobile_logo') ?? '',
            'fav_icon' => getWebConfig(name: 'company_fav_icon') ?? '',
            'footer_logo' => getWebConfig(name: 'company_footer_logo') ?? '',
            'loader_gif' => getWebConfig(name: 'loader_gif') ?? '',
        ];
        return view('admin-views.business-settings.website-setup', ['businessSetting' => $businessSetting]);
    }

    public function updateWebsiteSetup(WebsiteSetupRequest $request): RedirectResponse
    {
        $colors = json_encode(['primary' => $request['primary'], 'secondary' => $request['secondary'], 'panel-sidebar' => $request['panel-sidebar'], 'primary_light' => $request['primary_light'], 'app-primary' => $request['app-primary'], 'app-secondary' => $request['app-secondary']]);
        $this->businessSettingRepo->updateOrInsert(type: 'colors', value: $colors);

        $appAppleStore = json_encode(['status' => $request['app_store_download_status'] ?? 0, 'link' => $request['app_store_download_url']]);
        $this->businessSettingRepo->updateOrInsert(type: 'download_app_apple_store', value: $appAppleStore);

        $appGoogleStore = json_encode(['status' => $request['play_store_download_status'] ?? 0, 'link' => $request['play_store_download_url']]);
        $this->businessSettingRepo->updateOrInsert(type: 'download_app_google_store', value: $appGoogleStore);

        $webLogo = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_web_logo']);
        $webInvoiceLogo = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_web_logo_png']);

        if ($request->has('company_web_logo')) {
            $webLogoImage = [
                'image_name' => $this->updateFile(dir: 'company/', oldImage: (is_array($webLogo['value']) ? $webLogo['value']['image_name'] : $webLogo['value']), format: 'webp', image: $request->file('company_web_logo')),
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateWhere(params: ['type' => 'company_web_logo'], data: ['value' => json_encode($webLogoImage)]);

            $webInvoiceLogoImagePath = $webInvoiceLogo && isset($webInvoiceLogo['value']) && isset($webInvoiceLogo['value']['image_name']) ? $webInvoiceLogo['value']['image_name'] : '';
            $webInvoiceLogoImage = [
                'image_name' => $this->updateFile(dir: 'company/', oldImage: $webInvoiceLogoImagePath, format: 'png', image: $request->file('company_web_logo')),
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateOrInsert(type: 'company_web_logo_png', value: json_encode($webInvoiceLogoImage));
        }

        $webFooterLogo = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_footer_logo']);

        if ($request->has('company_footer_logo')) {
            $webFooterLogoImage = [
                'image_name' => $this->updateFile(dir: 'company/', oldImage: (is_array($webFooterLogo['value']) ? $webFooterLogo['value']['image_name'] : $webFooterLogo['value']), format: 'webp', image: $request->file('company_footer_logo')),
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateWhere(params: ['type' => 'company_footer_logo'], data: ['value' => json_encode($webFooterLogoImage)]);
        }

        $favIcon = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_fav_icon']);
        if ($request->has('company_fav_icon')) {
            $favIconImage = [
                'image_name' => $this->updateFile(dir: 'company/', oldImage: (is_array($favIcon['value']) ? $favIcon['value']['image_name'] : $favIcon['value']), format: 'png', image: $request->file('company_fav_icon')),
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateWhere(params: ['type' => 'company_fav_icon'], data: ['value' => json_encode($favIconImage)]);
        }

        $loaderGif = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'loader_gif']);
        if ($request->has('loader_gif')) {
            $loaderGifImage = $loaderGif ? $this->updateFile(dir: 'company/', oldImage: (is_array($loaderGif['value']) ? $loaderGif['value']['image_name'] : $loaderGif['value']), format: 'webp', image: $request->file('loader_gif'))
                : $this->upload(dir: 'company/', format: 'webp', image: $request->file('loader_gif'));

            $loaderGifImageArray = [
                'image_name' => $loaderGifImage,
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateOrInsert(type: 'loader_gif', value: json_encode($loaderGifImageArray));
        }

        $mobileLogo = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_mobile_logo']);
        if ($request->has('company_mobile_logo')) {
            $mobileLogoImage = [
                'image_name' => $this->updateFile(dir: 'company/', oldImage: (is_array($mobileLogo['value']) ? $mobileLogo['value']['image_name'] : $mobileLogo['value']), format: 'webp', image: $request->file('company_mobile_logo')),
                'storage' => config('filesystems.disks.default') ?? 'public'
            ];
            $this->businessSettingRepo->updateWhere(params: ['type' => 'company_mobile_logo'], data: ['value' => $mobileLogoImage]);
        }
        ToastMagic::success(translate('Website_setup_updated_successfully'));
        return back();
    }

}
