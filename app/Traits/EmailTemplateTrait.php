<?php

namespace App\Traits;

use App\Mail\SendMail;
use App\Models\SocialMedia;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Mail;
use App\Services\EmailTemplateService;
use App\Services\Notification\MailAccount;
use App\Services\Notification\MessageIdentity;
use App\Services\Notification\NotificationAttemptLog;
use App\Services\Notification\SendsWithinABudget;
use App\Services\Notification\MessageLanguage;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontUrl;

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

    /**
     * The brand, the website and the year as they are when the message is WRITTEN, rather than as they were when the template row was
     * created (I1-03).
     *
     * A template row is written once, the first time its screen is opened, and read by every market and every year afterwards, so the
     * company name, the website address and the copyright year baked into the stored text went stale the moment the platform was renamed,
     * moved to another address, or simply carried on into the next January -- and a Kuwaiti customer was sent to the default market's
     * address. The three are now placeholders in the default text and are resolved here.
     *
     * The address is the market's own ({@see StorefrontUrl::of()}), which answers for the storefront in force: for a message about an order
     * that is the market the order was sold in, bound by OrderNotificationContext, and for every other message the market of the request
     * that raised it, carried to the worker by ServeInItsStorefront. The default market, and a platform with storefronts off, get the bare
     * address they have today.
     *
     * The NAME is the market's own where it has one (F-13) and the platform's otherwise, which is every market today. It is read through
     * `getWebConfig()` deliberately rather than through the brand service directly: that accessor is where a market's override is applied for
     * every one of the hundred-odd places that ask for the company name, so an e-mail cannot be signed with a name the website does not show.
     * And because the market in force here is the ORDER's (bound by OrderNotificationContext), a Kuwaiti order's confirmation is signed with
     * the Kuwaiti brand even though it is written by a worker that booted in another market.
     *
     * Text that holds none of the three is left exactly as it is, which is every template row an installation already has.
     *
     * @return array<string, string> each placeholder and what it stands for in this message
     */
    private function brandOfThisMessage(): array
    {
        return [
            '{companyName}' => (string) getWebConfig(name: 'company_name'),
            '{websiteUrl}' => app(StorefrontUrl::class)->of(url: url('/')),
            '{currentYear}' => date('Y'),
        ];
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
            $brand = $this->brandOfThisMessage();
            foreach (self::TRANSLATED_TEMPLATE_FIELDS as $field) {
                $text = $template[$field] ?? null;
                $template[$field] = is_string($text) && $text !== '' ? strtr($text, $brand) : $text;
            }
            $data['send-mail'] = true;
            if ($template['status'] == 1) {
                // Every attempt is stored and, where the operator has raised the budget, retried (I1-09). The catch block here used to be
                // EMPTY: a market whose mail password was wrong sent nothing and told nobody -- no row, no screen, no counter -- and the
                // platform's one retry mechanism (`$tries` on the mail listeners) could not help, because the exception never left this
                // trait for the queue to see. The exception is still swallowed, so a failed e-mail still does not fail the request that
                // raised it; what changed is that it is now written down.
                app(SendsWithinABudget::class)->send(
                    channel: NotificationAttemptLog::CHANNEL_MAIL,
                    templateKey: (string) $templateName,
                    recipientType: (string) $userType,
                    sentTo: is_string($sendMailTo) ? $sendMailTo : null,
                    send: function () use ($data, $template, $socialMedia, $sendMailTo): bool {
                        // A mail about an order is written by a queue worker, which has no storefront and no session, so every figure in it
                        // would otherwise be the platform's default currency at today's rate. The order's own record says what it was sold
                        // in; a mail that is about no order (a password reset, a registration) carries none and is written exactly as it was.
                        // And through the mail account of the market this message belongs to (I1-01), which is the market in force: the
                        // order's own for a message about an order, the dispatching request's for every other one. A market with no account
                        // of its own, and every market while the switch is off, leaves through the one account the platform has always used.
                        app(MailAccount::class)->forThisMessage(
                            callback: fn (): mixed => app(StorefrontCurrency::class)->withinOrderCurrency(
                                order: $data['order'] ?? [],
                                callback: fn (): mixed => Mail::to($sendMailTo)->send(new SendMail($data, $template, $socialMedia)),
                            ),
                        );

                        return true;
                    },
                    succeeded: static fn (mixed $answer): bool => $answer === true,
                    // Which order this e-mail is about, so a replay of the event that raised it sends one message rather than two
                    // (I1-09). An e-mail about no order -- a password reset, a registration, a one-time code -- names no business event
                    // and is never suppressed: two of those are two real requests from a customer who pressed the button twice.
                    about: MessageIdentity::aboutTheOrder(
                        order: $data['order'] ?? null,
                        orderId: isset($data['orderId']) ? (string) $data['orderId'] : null,
                        templateKey: (string) $templateName,
                    ),
                );
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
