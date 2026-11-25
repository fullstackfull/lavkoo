<?php

namespace Tests\Feature\Storefront;

use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Support\Facades\DB;

/**
 * I1-03: a default e-mail template carries no market-specific company name or URL.
 *
 * `EmailTemplateService` baked three facts into the row at the moment it was created -- `getWebConfig('company_name')`, `url('/')` and
 * `date('Y')` -- and the row is created once, the first time its screen is opened, then read by every market and every year afterwards. So
 * a platform that was renamed went on signing e-mails with its old name, one that moved went on linking to its old address, the copyright
 * line stayed on the year the row happened to be created, and every market's e-mail sent its reader to the DEFAULT market's website. A
 * Kuwaiti customer's registration e-mail invited them to the Saudi shop.
 *
 * The three are placeholders in the default text now, resolved when the message is written: the name from the platform's marketing name as
 * it is at that moment, the address from the market the message belongs to, and the year from the clock. The market is the one the sender is
 * already running in -- the order's own for a message about an order, the dispatching request's for every other -- so nothing new has to be
 * threaded through to get it.
 *
 * What is NOT here: a brand a market can override is F-13 and has no record to read yet, so `{companyName}` resolves to the platform's one
 * marketing name in every market. That is the single place that changes when F-13 lands.
 */
class EmailTemplateBrandTest extends OrderNotificationTestCase
{
    private const PLATFORM_NAME = 'Lavkoo';

    private const RENAMED = 'Lavkoo Gulf';

    public function test_a_default_template_stores_no_company_name_and_no_website_address(): void
    {
        $this->nameThePlatform(self::PLATFORM_NAME);

        $stored = $this->defaultsOf(templateName: EmailTemplateKey::REGISTRATION, userType: 'customer');

        foreach (['body', 'copyright_text'] as $field) {
            $this->assertStringNotContainsString(self::PLATFORM_NAME, $stored[$field], "the stored {$field} names no company");
            $this->assertStringNotContainsString(rtrim(url('/'), '/'), $stored[$field], "the stored {$field} names no address");
        }
        $this->assertStringContainsString('{companyName}', $stored['body']);
        $this->assertStringContainsString('{websiteUrl}', $stored['body']);
        $this->assertStringContainsString('{currentYear}', $stored['copyright_text']);
    }

    /** Every default template of every recipient: not one of them may carry either fact, so a new template cannot reintroduce the defect. */
    public function test_no_default_template_of_any_recipient_stores_either_fact(): void
    {
        $this->nameThePlatform(self::PLATFORM_NAME);
        $service = app(EmailTemplateService::class);
        $checked = 0;

        foreach (['admin', 'customer', 'vendor', 'delivery-man'] as $userType) {
            foreach ($service->getEmailTemplateData(userType: $userType) as $templateName) {
                $stored = $this->defaultsOf(templateName: $templateName, userType: $userType);
                foreach (['title', 'body', 'copyright_text', 'footer_text'] as $field) {
                    $this->assertStringNotContainsString(self::PLATFORM_NAME, (string) $stored[$field], "{$userType}/{$templateName}/{$field}");
                    $this->assertStringNotContainsString(rtrim(url('/'), '/'), (string) $stored[$field], "{$userType}/{$templateName}/{$field}");
                }
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(17, $checked, "every default template of every recipient was read");
    }

    public function test_the_sent_email_shows_the_name_the_platform_has_when_it_is_sent(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $this->seedTheRegistrationTemplateFromTheDefaults();
        $this->nameThePlatform(self::RENAMED);
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id,
        ]);

        $this->placeOrderMailFor($order, templateName: 'registration');

        // The sentence the placeholder sits in, not the name on its own: the design also prints the company name in its signature line, so
        // a looser assertion would pass on the signature alone and say nothing about the stored text.
        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString('visit the ' . self::RENAMED . ' Shop Website', $body, 'the name as it is at send time, not as it was when the row was written');
        $this->assertStringNotContainsString('{companyName}', $body, 'and no placeholder is left in the e-mail');
    }

    public function test_the_sent_email_links_to_the_website_of_the_market_the_order_belongs_to(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.paths.enabled' => true]);
        $this->seedTheRegistrationTemplateFromTheDefaults();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id,
        ]);

        $this->placeOrderMailFor($order, templateName: 'registration');

        $this->assertStringContainsString(rtrim(url('/'), '/') . '/kw', $this->bodyOfTheEmailTheMailerSent(), "the Kuwaiti market's own address");
    }

    /** The default market keeps the bare address it has today, and so does every market while storefronts are off. */
    public function test_an_order_of_the_default_market_links_to_the_bare_address(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $this->seedTheRegistrationTemplateFromTheDefaults();
        $order = $this->sellOrderIn(country: 'SA', orderId: self::SA_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Sara', 'l_name' => 'Alharbi'])->id,
        ]);

        $this->placeOrderMailFor($order, templateName: 'registration');

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString('href="' . rtrim(url('/'), '/') . '"', $body, 'the address the platform sends today');
    }

    public function test_while_storefronts_are_off_an_order_of_another_market_links_to_the_bare_address(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->seedTheRegistrationTemplateFromTheDefaults();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id,
        ]);

        $this->placeOrderMailFor($order, templateName: 'registration');

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString('href="' . rtrim(url('/'), '/') . '"', $body);
        $this->assertStringNotContainsString(rtrim(url('/'), '/') . '/kw', $body);
    }

    /** A template row an installation already has holds text with none of the three placeholders in it, and is sent exactly as it is stored. */
    public function test_a_template_that_names_no_placeholder_is_sent_as_it_is_stored(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        EmailTemplate::create([
            'template_name' => 'registration', 'user_type' => 'customer', 'template_design_name' => 'registration',
            'title' => 'Welcome', 'body' => '<p>Welcome to the shop, with nothing to resolve.</p>', 'status' => 1,
            'footer_text' => 'Contact us', 'copyright_text' => 'Copyright 1999 Somebody Else.',
        ]);
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id,
        ]);

        $this->placeOrderMailFor($order, templateName: 'registration');

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertStringContainsString('Welcome to the shop, with nothing to resolve.', $body);
        $this->assertStringContainsString('Copyright 1999 Somebody Else.', $body);
    }

    /** The row the platform writes for a template it has not got yet, through the platform's own writer. */
    private function seedTheRegistrationTemplateFromTheDefaults(): void
    {
        $this->nameThePlatform(self::PLATFORM_NAME);
        EmailTemplate::create($this->defaultsOf(templateName: EmailTemplateKey::REGISTRATION, userType: 'customer') + ['status' => 1]);
    }

    /** @return array<string, mixed> the row the platform's own writer would create for this template */
    private function defaultsOf(string $templateName, string $userType): array
    {
        $service = app(EmailTemplateService::class);

        return $service->getAddData(
            userType: $userType,
            templateName: $templateName,
            hideField: $service->getHiddenField(userType: $userType, templateName: $templateName),
            title: $service->getTitleData(userType: $userType, templateName: $templateName),
            body: $service->getBodyData(userType: $userType, templateName: $templateName),
        );
    }

    private function nameThePlatform(string $name): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'company_name'], ['value' => $name]);
        \Illuminate\Support\Facades\Cache::flush();
    }
}
