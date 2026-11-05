<?php

namespace App\Services\Fulfillment\Readiness\Staging;

/**
 * What identifies a real person in a restored copy of the store, and what a staging copy keeps instead: placeholders that
 * keep the SHAPE the application and the providers validate (a Saudi mobile number is 05 and eight digits, an e-mail address
 * has an @) and stay unique per row, so unique indexes and the order flow keep working, while reaching nobody.
 *
 * Passwords are emptied, so no customer, vendor or admin can sign in with a production password; a staging administrator is
 * created by hand afterwards (see the staging guide). The list is a starting point built from the models: columns the real
 * schema lacks are skipped, and columns it has that are not listed (free text in reviews, tickets, notes) are not touched,
 * which is why the plan prints a check for each table and the guide asks for a review of the real column list.
 */
final class PersonalDataScrub
{
    /** @return list<ScrubTable> */
    public static function tables(): array
    {
        $phone = static fn (SqlDialect $sql): string => $sql->concat("'05'", $sql->zeroPadded('id', 8));
        $email = static fn (string $prefix): \Closure => static fn (SqlDialect $sql): string => $sql->concat("'{$prefix}'", 'id', "'@staging.invalid'");
        $text = static fn (string $value): \Closure => static fn (): string => "'{$value}'";
        $numbered = static fn (string $label): \Closure => static fn (SqlDialect $sql): string => $sql->concat("'{$label} '", 'id');

        $person = static fn (string $label, string $mailPrefix): array => [
            'name' => $numbered($label),
            'f_name' => $text($label),
            'l_name' => static fn (SqlDialect $sql): string => $sql->concat("'#'", 'id'),
            'email' => $email($mailPrefix),
            'phone' => $phone,
            'password' => $text(''),
            'street_address' => $text(''),
            'zip' => $text(''),
            'house_no' => $text(''),
            'apartment_no' => $text(''),
            'temporary_token' => $text(''),
            'auth_token' => $text(''),
        ];

        $address = static fn (): array => [
            'contact_person_name' => $text('Recipient'),
            'phone' => $phone,
            'address' => $text(''),
            'zip' => $text(''),
            'latitude' => $text(''),
            'longitude' => $text(''),
        ];

        $addressJson = static fn (string $column): \Closure => static fn (SqlDialect $sql): string => $sql->jsonReplace($column, [
            '$.contact_person_name' => "'Recipient'",
            '$.phone' => $sql->concat("'05'", $sql->zeroPadded('id', 8)),
            '$.email' => $sql->concat("'recipient'", 'id', "'@staging.invalid'"),
            '$.address' => "''",
            '$.zip' => "''",
        ]);

        return [
            new ScrubTable('users', $person('Customer', 'customer')),
            new ScrubTable('sellers', $person('Vendor', 'vendor')),
            new ScrubTable('admins', $person('Admin', 'admin')),
            new ScrubTable('delivery_men', $person('Courier', 'courier') + ['identity_number' => $text(''), 'address' => $text('')]),
            new ScrubTable('shops', ['contact' => $phone, 'address' => $text('')]),
            new ScrubTable('shipping_addresses', $address()),
            new ScrubTable('billing_addresses', $address()),
            new ScrubTable('orders', ['shipping_address_data' => $addressJson('shipping_address_data'), 'billing_address_data' => $addressJson('billing_address_data'), 'order_note' => $text('')]),
            new ScrubTable('contacts', ['name' => $text('Visitor'), 'email' => $email('visitor'), 'mobile_number' => $phone, 'message' => $text(''), 'reply' => $text('')]),
            new ScrubTable('subscriptions', ['email' => $email('subscriber')]),
            new ScrubTable('abandoned_cart_reminders', ['email' => $email('cart')]),
            new ScrubTable('guest_users', ['ip_address' => $text('')]),
            new ScrubTable('chattings', ['message' => $text('')]),
            new ScrubTable('support_ticket_convs', ['customer_message' => $text(''), 'admin_message' => $text('')]),
        ];
    }
}
