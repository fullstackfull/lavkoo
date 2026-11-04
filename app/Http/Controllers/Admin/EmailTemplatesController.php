<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\TranslationRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\EmailTemplateRequest;
use App\Repositories\BusinessSettingRepository;
use App\Repositories\EmailTemplatesRepository;
use App\Repositories\SocialMediaRepository;
use App\Services\EmailTemplateService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class   EmailTemplatesController extends BaseController
{
    public function __construct(
        private readonly EmailTemplatesRepository       $emailTemplatesRepo,
        private readonly EmailTemplateService           $emailTemplateService,
        private readonly BusinessSettingRepository      $businessSettingRepo,
        private readonly SocialMediaRepository          $socialMediaRepo,
        private readonly TranslationRepositoryInterface $translationRepo,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $socialMedia = $this->socialMediaRepo->getListWhere(filters: ['status' => 1], dataLimit: 'all');

        return view('email-templates.mail-tester', compact('socialMedia'));
    }

    public function getView($userType, $templateName): View
    {
        $this->getEmailTemplateForData(userType: $userType);
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'pnc_language']);
        $socialMedia = $this->socialMediaRepo->getListWhere(filters: ['status' => 1], dataLimit: 'all');
        $template = $this->emailTemplatesRepo->getFirstWhere(params: ['user_type' => $userType, 'template_name' => $templateName], relations: ['translations', 'translationCurrentLanguage']);
        return view('admin-views.business-settings.email-template.index', compact('template', 'language', 'socialMedia'));
    }

    protected function getEmailTemplateForData($userType): Collection
    {
        $emailTemplates = $this->emailTemplatesRepo->getListWhere(filters: ['user_type' => $userType]);
        $emailTemplateArray = $this->emailTemplateService->getEmailTemplateData(userType: $userType);
        foreach ($emailTemplateArray as $value) {
            $checkKey = $emailTemplates->where('template_name', $value)->first();
            if ($checkKey === null) {
                $hideField = $this->emailTemplateService->getHiddenField(userType: $userType, templateName: $value);
                $title = $this->emailTemplateService->getTitleData(userType: $userType, templateName: $value);
                $body = $this->emailTemplateService->getBodyData(userType: $userType, templateName: $value);
                $this->emailTemplatesRepo->add(
                    data: $this->emailTemplateService->getAddData(userType: $userType, templateName: $value, hideField: $hideField, title: $title, body: $body)
                );
            }
        }
        foreach ($emailTemplates as $value) {
            if (!in_array($value['template_name'], $emailTemplateArray)) {
                $this->emailTemplatesRepo->delete(params: ['id' => $value['id']]);
            }
        }
        return $this->emailTemplatesRepo->getListWhere(filters: ['user_type' => $userType]);
    }

    public function update(EmailTemplateRequest $request, $templateName, $userType): RedirectResponse
    {
        $emailTemplate = $this->emailTemplatesRepo->getFirstWhere(params: ['template_name' => $templateName, 'user_type' => $userType]);
        $data = $this->emailTemplateService->getUpdateData(request: $request, template: $emailTemplate);
        $this->emailTemplatesRepo->update(id: $emailTemplate['id'], data: $data);
        $requestNames = ['title', 'body', 'button_name', 'footer_text', 'copyright_text'];
        $this->addTranslateData(lang: $request['lang'], requestNames: $requestNames, id: $emailTemplate['id'], request: $request);
        ToastMagic::success(translate('update_successfully'));
        return redirect()->back();
    }

    protected function addTranslateData($lang, $requestNames, $id, $request): void
    {
        foreach ($lang as $value) {
            foreach ($requestNames as $name) {
                $values = $request->input($name, []);
                $langValue = is_array($values) ? ($values[$value] ?? null) : null;
                if ($langValue && $value !== 'en') {
                    $this->translationRepo->updateData(
                        model: 'App\Models\EmailTemplate',
                        id: $id,
                        lang: $value,
                        key: $name,
                        value: $langValue
                    );
                }
            }
        }
    }

    public function updateStatus(Request $request, $templateName, $userType): JsonResponse
    {
        $emailTemplate = $this->emailTemplatesRepo->getFirstWhere(params: ['template_name' => $templateName, 'user_type' => $userType]);
        $templateName = str_replace('-', '_', $templateName);
        $this->emailTemplatesRepo->update(id: $emailTemplate['id'], data: ['status' => $request->get('status', 0)]);
        return response()->json([
            'message' => $request->has('status') && $request['status'] ? translate($templateName . '_mail_is_on') : translate($templateName . '_mail_is_off'),
            'success' => 200
        ]);
    }
}
