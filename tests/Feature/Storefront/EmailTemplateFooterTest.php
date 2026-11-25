<?php

namespace Tests\Feature\Storefront;

use App\Models\EmailTemplate;
use App\Models\Translation;

/**
 * I1-03: a translated e-mail shows its OWN footer and its OWN copyright text.
 *
 * `EmailTemplateTrait::sendingMail` walks the translation rows of the recipient's language and copies each one into the template. Two of the
 * four were copied into each other's field: the `copyright_text` row was written over `footer_text` and the `footer_text` row over
 * `copyright_text`. The e-mail has a slot for each -- `view-footer-text` above the signature and `view-copyright-text` at the foot of the page
 * -- so a recipient in any language but the platform's default was shown the copyright notice where the "contact us for any queries" line
 * belongs and the contact line in place of the copyright. The admin's own preview of the same template
 * (resources/views/admin-views/business-settings/email-template/index.blade.php:12) reads the two the right way round, so the screen an
 * operator approves the wording on did not show what was sent.
 *
 * Driven through the real queued listener and the real mailer, so the view is rendered inside the send and the two slots are read out of the
 * HTML that would have left the server rather than out of the template array.
 */
class EmailTemplateFooterTest extends OrderNotificationTestCase
{
    private const FOOTER_IN_ENGLISH = 'Please contact us for any queries';

    private const FOOTER_IN_ARABIC = 'تواصل معنا لأي استفسار';

    private const COPYRIGHT_IN_ENGLISH = 'Copyright 2026 Lavkoo. All rights reserved.';

    private const COPYRIGHT_IN_ARABIC = 'حقوق النشر 2026 لافكو. جميع الحقوق محفوظة.';

    public function test_a_translated_email_shows_its_own_footer_and_its_own_copyright(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $this->seedOrderPlacedTemplateWithItsFooterAndCopyright();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertSame(self::FOOTER_IN_ARABIC, $this->textInTheSlot(slot: 'view-footer-text', body: $body));
        $this->assertSame(self::COPYRIGHT_IN_ARABIC, $this->textInTheSlot(slot: 'view-copyright-text', body: $body));
    }

    /** The platform's own language has no translation row, so both slots show the template's stored text: the case that never showed the defect. */
    public function test_an_untranslated_email_shows_the_stored_footer_and_the_stored_copyright(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $this->seedOrderPlacedTemplateWithItsFooterAndCopyright();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->makeCustomer(['app_language' => 'en', 'f_name' => 'Noura', 'l_name' => 'Al Sabah'])->id,
        ]);

        $this->placeOrderMailFor($order);

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertSame(self::FOOTER_IN_ENGLISH, $this->textInTheSlot(slot: 'view-footer-text', body: $body));
        $this->assertSame(self::COPYRIGHT_IN_ENGLISH, $this->textInTheSlot(slot: 'view-copyright-text', body: $body));
    }

    /** A language that translates only one of the two shows its translation in that slot and the stored text in the other, never the other's. */
    public function test_a_language_that_translates_only_the_footer_leaves_the_copyright_as_it_is_stored(): void
    {
        $this->captureTheEmailsTheMailerRenders();
        $this->switchStorefrontsOn();
        $template = $this->seedOrderPlacedTemplateWithItsFooterAndCopyright();
        Translation::query()
            ->where(['translationable_type' => EmailTemplate::class, 'translationable_id' => $template->id, 'locale' => 'sa', 'key' => 'copyright_text'])
            ->delete();
        $order = $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
        ]);

        $this->placeOrderMailFor($order);

        $body = $this->bodyOfTheEmailTheMailerSent();
        $this->assertSame(self::FOOTER_IN_ARABIC, $this->textInTheSlot(slot: 'view-footer-text', body: $body));
        $this->assertSame(self::COPYRIGHT_IN_ENGLISH, $this->textInTheSlot(slot: 'view-copyright-text', body: $body));
    }

    /** The order-placed design, with a stored footer and copyright in English and a translation of each in Arabic. */
    private function seedOrderPlacedTemplateWithItsFooterAndCopyright(): EmailTemplate
    {
        $template = EmailTemplate::create([
            'template_name' => 'order-place', 'user_type' => 'customer', 'template_design_name' => 'order-place',
            'title' => 'Your order is placed', 'body' => 'Order {orderId} is on its way', 'status' => 1,
            'footer_text' => self::FOOTER_IN_ENGLISH, 'copyright_text' => self::COPYRIGHT_IN_ENGLISH,
        ]);

        foreach (['title' => 'تم إنشاء طلبك', 'footer_text' => self::FOOTER_IN_ARABIC, 'copyright_text' => self::COPYRIGHT_IN_ARABIC] as $key => $value) {
            Translation::create([
                'translationable_type' => EmailTemplate::class, 'translationable_id' => $template->id,
                'locale' => 'sa', 'key' => $key, 'value' => $value,
            ]);
        }

        return $template;
    }

    /** What the rendered e-mail put in one of its two text slots, read out of the HTML by the class the design gives that slot. */
    private function textInTheSlot(string $slot, string $body): string
    {
        $this->assertSame(1, preg_match('/class="' . $slot . '">\s*(.*?)\s*</s', $body, $found), "the e-mail has one [{$slot}] slot");

        return html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5);
    }
}
