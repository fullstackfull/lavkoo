<?php

namespace Tests\Unit\Storefront;

use App\Services\Notification\NotificationTypeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotificationTypeScanner;
use Tests\TestCase;

/**
 * I1-07: every notification the code sends is listed as necessary or marketing. The scan reads the code for the types it names (NotificationTypeScanner) and fails for one that is not
 * listed and for a listed one that no code names, so a type added to the code is classified when it is added. What the scan cannot read, a type built from a variable, it cannot report.
 */
class NotificationTypeRegistryTest extends TestCase
{
    private function scanner(): NotificationTypeScanner
    {
        return new NotificationTypeScanner();
    }

    // ---- the scan against the code ----------------------------------------------------------------------------------------------

    public function test_every_notification_type_the_code_names_is_classified(): void
    {
        $unclassified = [];

        foreach ($this->scanner()->typesNamedInCode() as $channel => $named) {
            foreach ($named as $type => $place) {
                if (!array_key_exists($type, NotificationTypeRegistry::all()[$channel])) {
                    $unclassified[] = "{$channel} [{$type}], named by {$place}";
                }
            }
        }

        $this->assertSame([], $unclassified, 'These types are sent by the code and are not listed as necessary or marketing in NotificationTypeRegistry.');
    }

    public function test_every_classified_notification_type_is_named_by_the_code(): void
    {
        $unused = [];
        $named = $this->scanner()->typesNamedInCode();

        foreach (NotificationTypeRegistry::all() as $channel => $types) {
            foreach (array_keys($types) as $type) {
                if (!array_key_exists($type, $named[$channel])) {
                    $unused[] = "{$channel} [{$type}]";
                }
            }
        }

        $this->assertSame([], $unused, 'These types are listed in NotificationTypeRegistry and no code names them.');
    }

    public function test_the_scan_sees_the_types_it_claims_to_see(): void
    {
        $named = $this->scanner()->typesNamedInCode();

        foreach (['order_pending_message', 'order_canceled', 'fund_added_by_admin_message', 'auction_won', 'customer_block_message', 'notification', 'theme_version_changed', 'referral_code_used', 'maintenance_mode'] as $type) {
            $this->assertArrayHasKey($type, $named[NotificationTypeRegistry::PUSH]);
        }
        foreach (['order-place', 'forgot-password', 'digital-product-otp', 'AbandonedCartReminderMail', 'AddFundToWallet', 'email-templates.customer-message'] as $type) {
            $this->assertArrayHasKey($type, $named[NotificationTypeRegistry::EMAIL]);
        }
        foreach (['order_created', 'order_status_changed', 'cart_marketing', 'otp'] as $type) {
            $this->assertArrayHasKey($type, $named[NotificationTypeRegistry::WHATSAPP]);
        }
        $this->assertArrayNotHasKey('SendMail', $named[NotificationTypeRegistry::EMAIL], 'the carrier of the templates is not a type of its own');
        $this->assertGreaterThan(60, count($named[NotificationTypeRegistry::PUSH]));
        $this->assertGreaterThan(20, count($named[NotificationTypeRegistry::EMAIL]));
    }

    // ---- what each pattern of the scan reads ------------------------------------------------------------------------------------

    public function test_the_scan_reads_a_new_message_key_in_the_admin_list_and_in_the_message_map(): void
    {
        $adminList = "<?php\nclass S {\n  public function getMessageKeyData(string \$type): array {\n    \$customer = [\n      'order_pending_message',\n      'flash_sale_message',\n    ];\n    return match (\$type) { 'customer' => \$customer };\n  }\n  public function getAddData() {}\n}";
        $messageMap = "<?php\ntrait T {\n  protected function pushNotificationMessage() {\n    \$notificationKey = [\n      'pending' => 'order_pending_message',\n      'promo' => 'flash_sale_message',\n    ];\n    \$data = \$notificationKey[\$key];\n  }\n}";

        $this->assertSame(['order_pending_message', 'flash_sale_message'], $this->scanner()->messageKeysOfTheAdminList($adminList));
        $this->assertSame(['order_pending_message', 'flash_sale_message'], $this->scanner()->messageKeysOfTheMessageMap($messageMap));
    }

    public function test_the_scan_reads_the_type_a_push_payload_carries_however_it_is_written(): void
    {
        $code = <<<'PHP'
            <?php
            $payload = ['title' => translate('x'), 'order_details_id' => $data['order_details_id'] ?? '', 'type' => 'flash_sale'];
            $notification['type'] = 'campaign';
            $audit = ['subject' => ['type' => 'seller', 'id' => 1]];
            public const TYPE = 'beacon';
            PHP;

        $this->assertEqualsCanonicalizing(['flash_sale', 'campaign', 'beacon'], $this->scanner()->payloadTypesIn($code));
    }

    public function test_the_scan_reads_a_mailable_a_mailed_view_and_a_template_event(): void
    {
        $mailer = "Mail::to(\$user)->send(new \\App\\Mail\\NewPromoMail(\$user)); Mail::to(\$a)->send(new SendMail(\$data)); Mail::send('email-templates.promo', \$data, fn () => 1);";
        $events = "<?php\nclass C {\n protected function events(): array {\n return [\n 'order_created' =>\n 'Order Created',\n 'flash_sale' =>\n 'Flash sale',\n ];\n }\n protected function recipientFields() {}\n}";

        $this->assertSame(['NewPromoMail'], $this->scanner()->mailablesSentIn($mailer));
        $this->assertSame(['email-templates.promo'], $this->scanner()->viewsMailedIn($mailer));
        $this->assertSame(['order_created', 'flash_sale'], $this->scanner()->eventsOfTheTemplateAutomation($events));
    }

    // ---- the classification -----------------------------------------------------------------------------------------------------

    public function test_the_marketing_types_are_exactly_these(): void
    {
        $marketing = [];
        foreach (NotificationTypeRegistry::all() as $channel => $types) {
            $marketing[$channel] = array_keys(array_filter($types, static fn (string $classification): bool => $classification === NotificationTypeRegistry::MARKETING));
            sort($marketing[$channel]);
        }

        $this->assertSame([
            NotificationTypeRegistry::PUSH => ['notification', 'referral_code_used'],
            NotificationTypeRegistry::EMAIL => ['AbandonedCartReminderMail'],
            NotificationTypeRegistry::WHATSAPP => ['cart_marketing'],
        ], $marketing);
    }

    public function test_every_type_is_necessary_or_marketing(): void
    {
        foreach (NotificationTypeRegistry::all() as $channel => $types) {
            foreach ($types as $type => $classification) {
                $this->assertContains($classification, [NotificationTypeRegistry::NECESSARY, NotificationTypeRegistry::MARKETING], "{$channel} [{$type}]");
            }
        }
    }

    #[DataProvider('typesOfWhatTheCustomerAlreadyHas')]
    public function test_the_types_of_an_order_a_refund_a_payment_and_a_code_are_necessary(string $channel, string $type): void
    {
        $this->assertSame(NotificationTypeRegistry::NECESSARY, NotificationTypeRegistry::classificationOf(channel: $channel, type: $type));
        $this->assertFalse(NotificationTypeRegistry::isMarketing(channel: $channel, type: $type));
    }

    /** @return array<string, array{string, string}> */
    public static function typesOfWhatTheCustomerAlreadyHas(): array
    {
        $push = ['order_pending_message', 'order_confirmation_message', 'order_processing_message', 'out_for_delivery_message', 'order_delivered_message', 'order_returned_message', 'order_failed_message', 'order_canceled',
            'order_refunded_message', 'refund_request_canceled_message', 'refund_request_status_changed_by_admin', 'order_edit_message', 'order_edit_return_amount_message', 'order_edit_due_payment_message',
            'fund_added_by_admin_message', 'withdraw_request_status_message', 'cash_collect_by_admin_message', 'message_from_seller', 'customer_block_message', 'order', 'refund', 'wallet', 'chatting'];
        $email = ['order-place', 'order-received', 'order-delivered', 'add-fund-to-wallet', 'forgot-password', 'reset-password-verification', 'registration-verification', 'digital-product-otp', 'digital-product-download'];
        $whatsapp = ['order_created', 'order_status_changed', 'otp'];

        $cases = [];
        foreach ([NotificationTypeRegistry::PUSH => $push, NotificationTypeRegistry::EMAIL => $email, NotificationTypeRegistry::WHATSAPP => $whatsapp] as $channel => $types) {
            foreach ($types as $type) {
                $cases["{$channel} {$type}"] = [$channel, $type];
            }
        }

        return $cases;
    }

    public function test_only_the_two_marketing_senders_ask_the_stop(): void
    {
        $this->assertSame([
            'app/Http/Controllers/Admin/CartMarketingController.php',
            'app/Services/Retention/AbandonedCartService.php',
            'app/Services/Storefront/StorefrontMarketingStop.php',
        ], $this->scanner()->filesNaming('StorefrontMarketingStop'), 'A sender that asks the stop is a marketing sender: classify it in NotificationTypeRegistry and list it here. No order, refund, payment or code message asks it.');
    }

    public function test_a_type_nobody_classified_is_refused_rather_than_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flash_sale_message');

        NotificationTypeRegistry::isMarketing(channel: NotificationTypeRegistry::PUSH, type: 'flash_sale_message');
    }

    public function test_a_channel_nobody_listed_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NotificationTypeRegistry::classificationOf(channel: 'sms', type: 'otp');
    }
}
