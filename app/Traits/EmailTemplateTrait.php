<?php

namespace App\Traits;

use App\Mail\SendMail;
use App\Models\SocialMedia;
use App\Models\EmailTemplate;
use Exception;
use Illuminate\Support\Facades\Mail;
use App\Services\EmailTemplateService;
use App\Services\Notification\MessageLanguage;
use App\Services\Storefront\StorefrontCurrency;

trait EmailTemplateTrait
{
    use FileManagerTrait;

    /** The template fields an e-mail renders in the recipient's language, each read from the translation row of its own name. */
    private const TRANSLATED_TEMPLATE_FIELDS = ['title', 'body', 'footer_text', 'copyright_text', 'button_name'];

    protected function textVariableFormat(
        $value, $userName = null, $adminName = null, $vendorName = null, $shopName = null, $shopId = null,
        $deliveryManName = null, $orderId = null, $emailId = null)
    {
        $data = $value;
        if ($data) {
            $data = $userName ? str_replace("{userName}", $userName, $data) : $data;
            $data = $vendorName ? str_replace("{vendorName}", $vendorName, $data) : $data;
            $data = $adminName ? str_replace("{adminName}", $adminName, $data) : $data;
            $data = $shopName ? str_replace("{shopName}", $shopName, $data) : $data;
            $data = $shopName ? str_replace("{shopId}", $shopId, $data) : $data;
            $data = $deliveryManName ? str_replace("{deliveryManName}", $deliveryManName, $data) : $data;
            $data = $orderId ? str_replace("{orderId}", $orderId, $data) : $data;
            $data = $emailId ? str_replace("{emailId}", $emailId, $data) : $data;
        }
        return $data;
    }

    /**
     * Sends one of the platform's e-mail templates, written in the language its recipient reads (I1-03).
     *
     * The language is chosen around the whole send, template read included, because the translation rows are filtered at QUERY time by
     * `getDefaultLanguage()`: a language chosen after the template was loaded would arrive too late to be read. A message about an order has
     * already had its language chosen by {@see \App\Services\Storefront\OrderNotificationContext}, from the order's own recipients, and
     * {@see MessageLanguage::asTheRecipientReads()} leaves that answer alone -- so every e-mail of this platform now has its language
     * decided in exactly one place, and a queued listener no longer writes in whatever language its worker happens to be in.
     */
    protected function sendingMail($sendMailTo, $userType, $templateName, $data = null): void
    {
        app(MessageLanguage::class)->asTheRecipientReads(
            userType: $userType,
            sentTo: $sendMailTo,
            callback: fn () => $this->writeAndSendMail(sendMailTo: $sendMailTo, userType: $userType, templateName: $templateName, data: $data),
        );
    }

    private function writeAndSendMail($sendMailTo, $userType, $templateName, $data): void
    {
        $template = EmailTemplate::with('translationCurrentLanguage')->where(['user_type' => $userType, 'template_name' => $templateName])->first();
        if ($template) {
            if (count($template['translationCurrentLanguage'])) {
                // Each field takes its OWN key's translation (I1-03). Two of them used to take each other's -- `footer_text` was given
                // the `copyright_text` row and `copyright_text` the `footer_text` row -- so every recipient whose language had a
                // translation was shown the copyright notice in the footer slot of the e-mail and the "contact us" line at the foot of
                // the page. The admin's own preview of the same template reads the two by their own keys, so the screen the wording is
                // approved on did not show what was sent. Written as a lookup rather than five assignments so that the field a row
                // reaches is the row's own key and cannot be anything else. The list is still a list: a translation row for a key the
                // e-mail does not render writes nothing.
                foreach ($template?->translationCurrentLanguage ?? [] as $translate) {
                    if (in_array($translate->key, self::TRANSLATED_TEMPLATE_FIELDS, true)) {
                        $template[$translate->key] = $translate->value;
                    }
                }
            }
            $socialMedia = SocialMedia::where(['status' => 1])->get();
            $template['body'] = $this->textVariableFormat(
                value: $template['body'],
                userName: $data['userName'] ?? null,
                adminName: $data['adminName'] ?? null,
                vendorName: $data['vendorName'] ?? null,
                shopName: $data['shopName'] ?? null,
                shopId: $data['shopId'] ?? null,
                deliveryManName: $data['deliveryManName'] ?? null,
                orderId: $data['orderId'] ?? null,
                emailId: $data['emailId'] ?? null
            );
            $template['title'] = $this->textVariableFormat(
                value: $template['title'],
                userName: $data['userName'] ?? null,
                adminName: $data['adminName'] ?? null,
                vendorName: $data['vendorName'] ?? null,
                shopName: $data['shopName'] ?? null,
                deliveryManName: $data['deliveryManName'] ?? null,
                orderId: $data['orderId'] ?? null
            );
            $data['send-mail'] = true;
            if ($template['status'] == 1) {
                try {
                    // A mail about an order is written by a queue worker, which has no storefront and no session, so every figure in it would
                    // otherwise be the platform's default currency at today's rate. The order's own record says what it was sold in; a mail that
                    // is about no order (a password reset, a registration) carries none and is written exactly as it was.
                    app(StorefrontCurrency::class)->withinOrderCurrency(
                        order: $data['order'] ?? [],
                        callback: fn (): mixed => Mail::to($sendMailTo)->send(new SendMail($data, $template, $socialMedia)),
                    );
                } catch (Exception $exception) {
                }
            }
            if (isset($data['attachmentPath'])) {
                unlink($data['attachmentPath']);
            }
        }
    }

    public function getEmailTemplateDataForUpdate($userType): void
    {
        $emailTemplates = EmailTemplate::where(['user_type' => $userType])->get();
        $emailTemplateArray = (new EmailTemplateService)->getEmailTemplateData(userType: $userType);
        foreach ($emailTemplateArray as $value) {
            $checkKey = $emailTemplates->where('template_name', $value)->first();
            if ($checkKey === null) {
                $hideField = (new EmailTemplateService)->getHiddenField(userType: $userType, templateName: $value);
                $title = (new EmailTemplateService)->getTitleData(userType: $userType, templateName: $value);
                $body = (new EmailTemplateService)->getBodyData(userType: $userType, templateName: $value);
                $addData = (new EmailTemplateService)->getAddData(userType: $userType, templateName: $value, hideField: $hideField, title: $title, body: $body);
                EmailTemplate::create($addData);
            }
        }
        foreach ($emailTemplates as $value) {
            if (!in_array($value['template_name'], $emailTemplateArray)) {
                EmailTemplate::find($value['id'])->delete();
            }
        }
    }
}
