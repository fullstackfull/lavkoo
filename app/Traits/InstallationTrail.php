<?php

namespace App\Traits;

use App\Models\Admin;
use App\Models\BusinessSetting;
use App\Models\EmailTemplate;
use App\Models\NotificationMessage;
use App\Models\ShippingType;
use App\Models\Shop;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

trait InstallationTrail
{
    public static function updateRobotTexFile(): void
    {
        try {
            $path = DOMAIN_POINTED_DIRECTORY == 'public' ? public_path('robots.txt') : base_path('robots.txt');
            if (!File::exists($path)) {
                fopen($path, "w") or die("Unable to open file!");
            }
            $content = "User-agent: *\nDisallow: /login/admin/\nSitemap: " . url('/sitemap.xml');
            if (!File::exists($path)) {
                File::put($path, $content);
            }
            File::put($path, $content);
        } catch (Exception $exception) {
        }
    }

    public function addSystemPrimaryData(object|array $request): void
    {
        DB::table('admins')->insertOrIgnore([
            'name' => $request['admin_name'],
            'email' => $request['admin_email'],
            'admin_role_id' => 1,
            'password' => bcrypt($request['admin_password']),
            'phone' => $request['admin_phone'],
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('admin_wallets')->insert([
            'admin_id' => 1,
            'withdrawn' => 0,
            'commission_earned' => 0,
            'inhouse_earning' => 0,
            'delivery_charge_earned' => 0,
            'pending_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BusinessSetting::where(['type' => 'company_name'])->update(['value' => $request['company_name']]);

        $this->businessSettingGetOrInsert(type: 'product_brand', value: 1);
        $this->businessSettingGetOrInsert(type: 'digital_product', value: 1);
        $this->businessSettingGetOrInsert(type: 'delivery_boy_expected_delivery_date_message', value: json_encode(['status' => 0, 'message' => '']));
        $this->businessSettingGetOrInsert(type: 'order_canceled', value: json_encode(['status' => 0, 'message' => '']));
        $this->businessSettingGetOrInsert(type: 'offline_payment', value: json_encode(['status' => 0]));

        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], [
            'value' => $request['currency_model']
        ]);

        $this->updateOrInsertPolicy(type: 'refund-policy');
        $this->updateOrInsertPolicy(type: 'return-policy');
        $this->updateOrInsertPolicy(type: 'cancellation-policy');

        $this->businessSettingGetOrInsert(type: 'temporary_close', value: json_encode(['status' => 0]));
        $this->businessSettingGetOrInsert(type: 'vacation_add', value: json_encode([
            'status' => 0,
            'vacation_start_date' => null,
            'vacation_end_date' => null,
            'vacation_note' => null
        ]));

        $this->businessSettingGetOrInsert(type: 'cookie_setting', value: json_encode([
            'status' => 0,
            'cookie_text' => null
        ]));

        DB::table('colors')->whereIn('id', [16, 38, 93])->delete();

        $this->businessSettingGetOrInsert(type: 'apple_login', value: json_encode([[
            'login_medium' => 'apple',
            'client_id' => '',
            'client_secret' => '',
            'status' => 0,
            'team_id' => '',
            'key_id' => '',
            'service_file' => '',
            'redirect_url' => '',
        ]]));

        $this->businessSettingGetOrInsert(type: 'ref_earning_status', value: 0);
        $this->businessSettingGetOrInsert(type: 'ref_earning_exchange_rate', value: 0);

        $this->businessSettingGetOrInsert(type: 'guest_checkout', value: 0);
        $this->businessSettingGetOrInsert(type: 'minimum_order_amount', value: 0);
        $this->businessSettingGetOrInsert(type: 'minimum_order_amount_by_seller', value: 0);
        $this->businessSettingGetOrInsert(type: 'minimum_order_amount_status', value: 0);
        $this->businessSettingGetOrInsert(type: 'admin_login_url', value: 'admin');
        $this->businessSettingGetOrInsert(type: 'employee_login_url', value: 'employee');
        $this->businessSettingGetOrInsert(type: 'free_delivery_status', value: 0);
        $this->businessSettingGetOrInsert(type: 'free_delivery_responsibility', value: 'admin');
        $this->businessSettingGetOrInsert(type: 'free_delivery_over_amount', value: 0);
        $this->businessSettingGetOrInsert(type: 'free_delivery_over_amount_seller', value: 0);
        $this->businessSettingGetOrInsert(type: 'add_funds_to_wallet', value: 0);
        $this->businessSettingGetOrInsert(type: 'minimum_add_fund_amount', value: 0);
        $this->businessSettingGetOrInsert(type: 'whatsapp', value: json_encode(['status' => 1, 'phone' => '00000000000']));
        $this->businessSettingGetOrInsert(type: 'currency_symbol_position', value: 'left');

        // Data insert into shipping table
        $new_shipping_type = new ShippingType;
        $new_shipping_type->seller_id = 0;
        $new_shipping_type->shipping_type = 'order_wise';
        $new_shipping_type->save();

        $this->notification_message_import(); // notification message add in the new table
        $this->company_riliability_import(); // company riliability add in the new table

        $this->businessSettingGetOrInsert(type: 'app_activation', value: json_encode(['software_id' => '', 'is_active' => 0]));

        if (!NotificationMessage::where(['key' => 'product_request_approved_message'])->first()) {
            DB::table('notification_messages')->updateOrInsert([
                'key' => 'product_request_approved_message'
            ],
                [
                    'user_type' => 'seller',
                    'key' => 'product_request_approved_message',
                    'message' => 'customize your product request approved message message',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }

        if (!NotificationMessage::where(['key' => 'product_request_rejected_message'])->first()) {
            DB::table('notification_messages')->updateOrInsert([
                'key' => 'product_request_rejected_message'
            ],
                [
                    'user_type' => 'seller',
                    'key' => 'product_request_rejected_message',
                    'message' => 'customize your product request rejected message message',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }

        $this->businessSettingGetOrInsert(type: 'map_api_status', value: 1);

        // Priority setup and vendor registration data process
        $this->getPrioritySetupAndVendorRegistrationData();

        if (Admin::count() > 0 && EmailTemplate::count() < 1) {
            $emailTemplateUserData = [
                'admin',
                'customer',
                'vendor',
                'delivery-man',
            ];
            foreach ($emailTemplateUserData as $value) {
                $this->getEmailTemplateDataForUpdate($value);
            }
        }

        $this->businessSettingGetOrInsert(type: 'storage_connection_type', value: 'public');
        $this->businessSettingGetOrInsert(type: 'google_search_console_code', value: '');
        $this->businessSettingGetOrInsert(type: 'bing_webmaster_code', value: '');
        $this->businessSettingGetOrInsert(type: 'baidu_webmaster_code', value: '');
        $this->businessSettingGetOrInsert(type: 'yandex_webmaster_code', value: '');

        BusinessSetting::updateOrInsert(['type' => 'company_phone'], [
            'value' => $request['admin_phone'] ?? '+8801712000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        cacheRemoveByType(type: 'business_settings');

        $inHouseShop = getInHouseShopConfig();
        Shop::where('author_type', 'admin')->update([
            'name' => $request['company_name'],
            'slug' => Str::slug($request['company_name']) . '-' . rand(1000, 9999),
        ]);
        cacheRemoveByType(type: 'shops');
        cacheRemoveByType(type: 'in_house_shop');
    }

    public static function notification_message_import()
    {
        /** for customer */
        $user_type_customer = NotificationMessage::where('user_type', 'customer')->get();
        $array_for_customer_message_key = [
            'order_pending_message',
            'order_confirmation_message',
            'order_processing_message',
            'out_for_delivery_message',
            'order_delivered_message',
            'order_returned_message',
            'order_failed_message',
            'order_canceled',
            'order_refunded_message',
            'refund_request_canceled_message',
            'message_from_delivery_man',
            'message_from_seller',
            'fund_added_by_admin_message',
        ];
        foreach ($array_for_customer_message_key as $key => $value) {
            $key_check = $user_type_customer->where('key', $value)->first();
            if ($key_check == null) {
                DB::table('notification_messages')->updateOrInsert(['user_type' => 'customer'],
                    [
                        'user_type' => 'customer',
                        'key' => $value,
                        'message' => 'customize your' . ' ' . str_replace('_', ' ', $value) . ' ' . 'message',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
        /**end for customer*/

        $user_type_seller = NotificationMessage::where('user_type', 'seller')->get();
        $array_for_seller_message_key = [
            'new_order_message',
            'refund_request_message',
            'order_edit_message',
            'withdraw_request_status_message',
            'message_from_customer',
            'delivery_man_assign_by_admin_message',
            'order_delivered_message',
            'order_canceled',
            'order_refunded_message',
            'refund_request_canceled_message',
            'refund_request_status_changed_by_admin',

        ];
        foreach ($array_for_seller_message_key as $key => $value) {
            $key_check = $user_type_seller->where('key', $value)->first();
            if ($key_check == null) {
                DB::table('notification_messages')->insert([
                    'user_type' => 'seller',
                    'key' => $value,
                    'message' => 'customize your' . ' ' . str_replace('_', ' ', $value) . ' ' . 'message',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        /**end for seller*/

        /**start delivery man*/
        $user_type_delivery_man = NotificationMessage::where('user_type', 'delivery_man')->get();
        $array_for_delivery_man_message_key = [
            'new_order_assigned_message',
            'expected_delivery_date',
            'delivery_man_charge',
            'order_canceled',
            'order_rescheduled_message',
            'order_edit_message',
            'message_from_seller',
            'message_from_admin',
            'message_from_customer',
            'cash_collect_by_admin_message',
            'cash_collect_by_seller_message',
            'withdraw_request_status_message',

        ];
        foreach ($array_for_delivery_man_message_key as $key => $value) {
            $key_check = $user_type_delivery_man->where('key', $value)->first();
            if ($key_check == null) {
                DB::table('notification_messages')->insert([
                    'user_type' => 'delivery_man',
                    'key' => $value,
                    'message' => 'customize your' . ' ' . str_replace('_', ' ', $value) . ' ' . 'message',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        /**end for delivery man*/
    }


    public static function company_riliability_import()
    {
        $data = [
            [
                'item' => 'delivery_info',
                'title' => 'Fast Delivery all across the country',
                'image' => '',
                'status' => 1,
            ],
            [
                'item' => 'safe_payment',
                'title' => 'Safe Payment',
                'image' => '',
                'status' => 1,
            ],
            [
                'item' => 'return_policy',
                'title' => '7 Days Return Policy',
                'image' => '',
                'status' => 1,
            ],
            [
                'item' => 'authentic_product',
                'title' => '100% Authentic Products',
                'image' => '',
                'status' => 1,
            ],
        ];

        if (BusinessSetting::where(['type' => 'company_reliability'])->first() == false) {
            BusinessSetting::insert(['type' => 'company_reliability'], [
                'value' => json_encode($data),
            ]);
        }
    }

    public static function updateEnvironmentFile(object|array $request): void
    {
        $key = base64_encode(random_bytes(32));
        $dbPassword = str_replace('"', '\"', $request['DB_PASSWORD']);
        if (preg_match('/[^a-zA-Z0-9]/', $dbPassword)) {
            $dbPassword = '"' . $dbPassword . '"';
        }

        $adminName = session('admin_name');
        if (preg_match('/[^a-zA-Z0-9]/', $adminName)) {
            $adminName = '"' . str_replace('"', '\"', $adminName) . '"';
        }

        $adminEmail = session('admin_email');
        if (preg_match('/[^a-zA-Z0-9]/', $adminEmail)) {
            $adminEmail = '"' . str_replace('"', '\"', $adminEmail) . '"';
        }

        $envPath = base_path('.env');
        $existingEnv = file_exists($envPath) ? file_get_contents($envPath) : '';

        $updates = [
            'APP_NAME' => '6valley' . time(),
            'APP_ENV' => 'live',
            'APP_KEY' => 'base64:' . $key,
            'APP_DEBUG' => 'false',
            'APP_INSTALL' => 'true',
            'APP_LOG_LEVEL' => 'debug',
            'APP_MODE' => 'live',
            'APP_URL' => URL::to('/'),

            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $request['DB_HOST'],
            'DB_PORT' => '3306',
            'DB_DATABASE' => $request['DB_DATABASE'],
            'DB_USERNAME' => $request['DB_USERNAME'],
            'DB_PASSWORD' => $dbPassword,

            'PURCHASE_CODE' => session('purchase_key'),
            'BUYER_USERNAME' => session('username'),
            'ADMIN_NAME' => $adminName,
            'ADMIN_IDENTIFIER' => $adminEmail,
            'SOFTWARE_ID' => 'MzE0NDg1OTc=',

            'SOFTWARE_VERSION' => SOFTWARE_VERSION,
        ];

        $lines = explode(PHP_EOL, $existingEnv);
        $newLines = [];
        $updatedKeys = [];

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                $newLines[] = $line;
                continue;
            }

            $found = false;
            foreach (array_keys($updates) as $key) {
                if (preg_match("/^{$key}\s*=/i", trim($line))) {
                    $newLines[] = "{$key}={$updates[$key]}";
                    $updatedKeys[] = $key;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $newLines[] = $line;
            }
        }

        foreach ($updates as $key => $value) {
            if (!in_array($key, $updatedKeys)) {
                $newLines[] = "{$key}={$value}";
            }
        }

        file_put_contents($envPath, implode(PHP_EOL, $newLines) . PHP_EOL);
    }
}
