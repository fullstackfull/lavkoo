<?php

namespace App\Services\Admin;

use App\Utils\Helpers;
use Illuminate\Support\Facades\Route;

/**
 * The catalogue behind the settings index.
 *
 * admin-views/business-settings alone holds 71 templates, spread across four route
 * prefixes, with no single place that lists them — six of those files are even
 * called index.blade.php. Finding a setting meant knowing which controller owned
 * it.
 *
 * Entries are grouped by what a merchant is trying to do, not by the controller
 * that happens to serve them: currency sits with payments, not with "system setup",
 * because that is where someone looks for it.
 *
 * Two guards keep this honest. Every entry is dropped when its route does not exist
 * (an uninstalled add-on, a removed feature), and every group is dropped when the
 * signed-in employee's role does not grant its module — the same check the sidebar
 * uses, so the index can never advertise a page the sidebar hides.
 */
class SettingsIndexService
{
    /**
     * @return array<int, array{title:string, module:string, icon:string, items:array}>
     */
    public function groups(): array
    {
        $groups = [
            [
                'title'  => translate('Store_&_Brand'),
                'module' => 'business_settings',
                'icon'   => 'store',
                'items'  => [
                    ['admin.business-settings.web-config.index', translate('Business_Setup'), translate('store_name_logo_contact_details_and_default_country'), 'business setup company logo name address'],
                    ['admin.business-settings.website-setup', translate('Website_Setup'), translate('how_the_storefront_behaves_for_visitors'), 'website storefront setup'],
                    ['admin.business-settings.inhouse-shop', translate('Inhouse_Shop'), translate('your_own_shop_profile_shown_beside_vendor_shops'), 'inhouse shop own store'],
                    ['admin.business-settings.announcement', translate('Announcement'), translate('the_message_bar_shown_across_the_storefront'), 'announcement banner bar message'],
                    ['admin.pages-and-media.social-media', translate('Social_Media_Links'), translate('the_profile_links_shown_in_the_footer'), 'social media facebook instagram links'],
                ],
            ],
            [
                'title'  => translate('Selling'),
                'module' => 'business_settings',
                'icon'   => 'orders',
                'items'  => [
                    ['admin.business-settings.order-settings.index', translate('Order_Settings'), translate('rules_that_apply_when_an_order_is_placed'), 'order settings checkout rules'],
                    ['admin.business-settings.product-settings.index', translate('Product_Settings'), translate('defaults_and_rules_for_products_in_the_catalogue'), 'product settings catalogue defaults'],
                    ['admin.business-settings.refund-setup', translate('Refund_Setup'), translate('how_refund_requests_are_handled'), 'refund return money back'],
                    ['admin.business-settings.invoice-settings.index', translate('Invoice_Settings'), translate('what_appears_on_a_customer_invoice'), 'invoice receipt billing'],
                    ['admin.business-settings.abandoned-cart.index', translate('Abandoned_Cart'), translate('reminders_sent_when_a_cart_is_left_behind'), 'abandoned cart reminder recovery'],
                    ['admin.business-settings.priority-setup.index', translate('Priority_Setup'), translate('the_order_products_and_sections_appear_in'), 'priority sorting order sequence'],
                ],
            ],
            [
                'title'  => translate('Payments_&_Tax'),
                'module' => '3rd_party_setup',
                'icon'   => 'reports',
                'items'  => [
                    ['admin.third-party.payment-method.index', translate('Payment_Methods'), translate('the_gateways_customers_can_pay_with'), 'payment gateway stripe paypal cash'],
                    ['admin.system-setup.currency.view', translate('Currency'), translate('currencies_symbols_and_exchange_rates'), 'currency exchange rate symbol money'],
                ],
            ],
            [
                'title'  => translate('Shipping_&_Delivery'),
                'module' => 'business_settings',
                'icon'   => 'catalog',
                'items'  => [
                    ['admin.business-settings.shipping-method.index', translate('Shipping_Method'), translate('how_shipping_cost_is_calculated'), 'shipping method cost delivery charge'],
                    ['admin.business-settings.delivery-zone.index', translate('Delivery_Zone'), translate('the_areas_you_deliver_to'), 'delivery zone area region governorate'],
                    ['admin.business-settings.delivery-man-settings.index', translate('Deliveryman_Settings'), translate('rules_for_your_delivery_staff'), 'deliveryman delivery staff rider'],
                ],
            ],
            [
                'title'  => translate('Customers_&_Vendors'),
                'module' => 'business_settings',
                'icon'   => 'customers',
                'items'  => [
                    ['admin.business-settings.customer-settings', translate('Customer_Settings'), translate('what_customers_can_do_on_the_storefront'), 'customer settings account wallet loyalty'],
                    ['admin.business-settings.vendor-settings.index', translate('Vendor_Settings'), translate('rules_that_apply_to_every_vendor'), 'vendor seller settings commission'],
                    ['admin.pages-and-media.vendor-registration-settings.index', translate('Vendor_Registration'), translate('what_a_vendor_sees_when_they_apply'), 'vendor registration signup apply'],
                ],
            ],
            [
                'title'  => translate('Content_&_SEO'),
                'module' => 'business_settings',
                'icon'   => 'marketing',
                'items'  => [
                    ['admin.pages-and-media.list', translate('Business_Pages'), translate('about_terms_privacy_and_other_static_pages'), 'pages about terms privacy policy'],
                    ['admin.pages-and-media.features-section', translate('Features_Section'), translate('the_highlights_shown_on_the_home_page'), 'features section highlights home'],
                    ['admin.pages-and-media.company-reliability', translate('Company_Reliability'), translate('the_trust_badges_shown_to_shoppers'), 'reliability trust badges'],
                    ['admin.seo-settings.web-master-tool', translate('SEO_Settings'), translate('search_engine_verification_and_meta_defaults'), 'seo meta search google webmaster'],
                    ['admin.seo-settings.sitemap', translate('Sitemap'), translate('the_sitemap_search_engines_read'), 'sitemap xml crawl'],
                    ['admin.seo-settings.robot-txt', translate('Robots.txt'), translate('what_crawlers_are_allowed_to_read'), 'robots crawler indexing'],
                    ['admin.seo-settings.redirects.index', translate('Redirects'), translate('send_old_urls_to_new_ones'), 'redirect 301 url moved'],
                    ['admin.seo-settings.templates.index', translate('SEO_Templates'), translate('reusable_title_and_description_patterns'), 'seo template title description'],
                    ['admin.seo-settings.health', translate('SEO_Health'), translate('problems_found_across_your_pages'), 'seo health audit issues'],
                ],
            ],
            [
                'title'  => translate('Appearance'),
                'module' => 'themes_and_addons',
                'icon'   => 'sparkles',
                'items'  => [
                    ['admin.theme.index', translate('Theme_Management'), translate('design_the_pages_of_the_installed_storefront'), 'theme design pages sections builder'],
                    ['admin.theme.builder.index', translate('Theme_Builder'), translate('arrange_the_sections_of_your_home_page'), 'builder sections drag banner'],
                    ['admin.theme.settings.index', translate('Theme_Settings'), translate('colours_typography_and_layout_tokens'), 'colours colors typography layout'],
                ],
            ],
            [
                'title'  => translate('System'),
                'module' => 'system_settings',
                'icon'   => 'settings',
                'items'  => [
                    ['admin.settings.authentication.index', translate('Authentication_Security'), translate('the_bot_defence_on_your_sign_in_forms_and_how_a_customer_recovers_their_account'), 'recaptcha captcha bot login password reset otp security'],
                    ['admin.settings.policies.index', translate('Platform_Policies'), translate('the_rules_the_platform_applies_to_itself_thresholds_limits_and_windows'), 'policy threshold limit rule window stock password webhook payout'],
                    ['admin.system-setup.environment-setup', translate('System_Setup'), translate('environment_and_application_configuration'), 'environment system config env'],
                    ['admin.system-setup.language.index', translate('Languages'), translate('the_languages_your_panel_and_store_offer'), 'language translation locale arabic english'],
                    ['admin.system-setup.login-settings.customer-login-setup', translate('Login_Settings'), translate('how_customers_and_staff_sign_in'), 'login otp password signin'],
                    ['admin.system-setup.email-templates.index', translate('Email_Templates'), translate('the_wording_of_every_automated_email'), 'email template mail wording'],
                    ['admin.system-setup.file-manager.index', translate('Gallery'), translate('every_file_uploaded_to_the_system'), 'gallery files media uploads'],
                    ['admin.system-setup.app-settings', translate('App_Settings'), translate('settings_for_the_mobile_apps'), 'app mobile android ios'],
                    ['admin.system-setup.software-update', translate('Software_Update'), translate('apply_a_new_version_of_the_software'), 'update version upgrade'],
                    ['admin.system-setup.db-index', translate('Database_Index'), translate('database_indexes_and_maintenance'), 'database index maintenance'],
                    ['admin.seo-settings.error-logs.index', translate('Error_Logs'), translate('errors_the_system_has_recorded'), 'errors logs exceptions debug'],
                ],
            ],
            [
                'title'  => translate('Integrations_&_Add-ons'),
                'module' => '3rd_party_setup',
                'icon'   => 'external',
                'items'  => [
                    ['admin.third-party.firebase-configuration.setup', translate('Firebase'), translate('push_notifications_and_phone_verification'), 'firebase push notification otp'],
                    ['admin.third-party.analytics-index', translate('Marketing_Tools'), translate('analytics_and_tracking_scripts'), 'analytics google tag pixel tracking'],
                    ['admin.third-party.ai-setting.index', translate('AI_Setup'), translate('the_ai_provider_used_for_generated_content'), 'ai openai claude provider'],
                    ['admin.third-party.social-login.view', translate('Other_Configuration'), translate('social_login_and_remaining_integrations'), 'social login google facebook apple'],
                    ['admin.system-setup.addon.index', translate('System_Addons'), translate('add_ons_installed_on_this_system'), 'addon module extension'],
                    ['admin.system-setup.addon-activation.index', translate('Addon_Activation'), translate('turn_installed_add_ons_on_or_off'), 'addon activation enable disable'],
                ],
            ],
        ];

        return $this->prepare($groups);
    }

    /** Drop unreachable routes and groups this role cannot see, then resolve URLs. */
    private function prepare(array $groups): array
    {
        $prepared = [];

        foreach ($groups as $group) {
            if (!Helpers::module_permission_check($group['module'])) {
                continue;
            }

            $items = [];
            foreach ($group['items'] as [$routeName, $title, $description, $keywords]) {
                if (!Route::has($routeName)) {
                    continue;           // uninstalled add-on or a route that no longer exists
                }
                $items[] = [
                    'title'       => $title,
                    'description' => $description,
                    'url'         => route($routeName),
                    // Searched against, never displayed: lets "colors" find Theme Settings
                    // and "vat" find Payments without those words being on screen.
                    'keywords'    => mb_strtolower($title . ' ' . $description . ' ' . $keywords),
                ];
            }

            if ($items !== []) {
                $prepared[] = ['title' => $group['title'], 'icon' => $group['icon'], 'items' => $items];
            }
        }

        return $prepared;
    }

    /** Total reachable settings pages, for the page header. */
    public function count(array $groups): int
    {
        return array_sum(array_map(fn (array $group) => count($group['items']), $groups));
    }
}
