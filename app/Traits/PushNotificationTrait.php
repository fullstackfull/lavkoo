<?php

namespace App\Traits;

use App\Services\Notification\NotificationAttemptLog;
use App\Services\Notification\NotificationProfiles;
use App\Services\Notification\SendsWithinABudget;
use App\Services\Storefront\OrderNotificationContext;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\ReferralCustomer;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

trait PushNotificationTrait
{
    use CommonTrait;

    /**
     * @param string $key
     * @param string $type
     * @param object|array $order
     * @param object|array $data
     * @return void
     * push notification order related
     */
    protected function sendOrderNotification(string $key, string $type, object|array $order, object|array $data = []): void
    {
        try {
            $lang = getDefaultLanguage();
            /** for customer  */
            if ($type == 'customer') {
                $fcmToken = $order->customer?->cm_firebase_token;
                $lang = $order->customer?->app_language ?? $lang;
                $value = $this->pushNotificationMessage($key, 'customer', $lang);
                if ($fcmToken && $value) {
                    $value = $this->textVariableDataFormat(value: $value, key: $key, userName: "{$order->customer?->f_name} {$order->customer?->l_name}", shopName: $order->seller?->shop?->name, deliveryManName: "{$order->deliveryMan?->f_name} {$order->deliveryMan?->l_name}", time: now()->diffForHumans(), orderId: $order->id);
                    $postData = [
                        'title' => translate('order'),
                        'description' => $value,
                        'order_id' => $order['id'],
                        'order_details_id' => $data['order_details_id'] ?? '',
                        'image' => '',
                        'type' => 'order',
                        'message_key' => $key,
                    ] + OrderNotificationContext::marketOf(order: $order);
                    $this->sendPushNotificationToDevice($fcmToken, $postData);
                }
            }

            if ($type == 'promoter') {
                $getCustomer = ReferralCustomer::where('user_id', $order->customer->id)->first();
                $getPromoter = User::where('id', $getCustomer->refer_by)->first();
                $fcmToken = $getPromoter?->cm_firebase_token;
                $lang = $getPromoter?->app_language ?? $lang;
                $value = $this->pushNotificationMessage(key: $key, userType: 'customer', lang: $lang);
                if ($fcmToken && $value) {
                    $value = $this->textVariableDataFormat(value: $value, key: $key, userName: "{$getPromoter?->f_name} {$getPromoter?->l_name}", shopName: $order->seller?->shop?->name, deliveryManName: "{$order->deliveryMan?->f_name} {$order->deliveryMan?->l_name}", time: now()->diffForHumans(), orderId: $order->id);
                    $postData = [
                        'title' => translate('Referral_Reward_Updates'),
                        'description' => $value,
                        'order_id' => $order['id'],
                        'order_details_id' => $data['order_details_id'] ?? '',
                        'image' => '',
                        'type' => 'referral_code_used',
                        'message_key' => $key,
                    ];
                    $this->sendPushNotificationToDevice($fcmToken, $postData);
                }
            }
            /** end for customer  */

            /**for seller */
            if ($type == 'seller') {
                $sellerFcmToken = $order->seller?->cm_firebase_token;
                if ($sellerFcmToken) {
                    $lang = $order->seller?->app_language ?? $lang;
                    $value_seller = $this->pushNotificationMessage($key, 'seller', $lang);

                    if ($value_seller) {
                        $value_seller = $this->textVariableDataFormat(value: $value_seller, key: $key, userName: "{$order->customer?->f_name} {$order->customer?->l_name}", shopName: $order->seller?->shop?->name, deliveryManName: "{$order->deliveryMan?->f_name} {$order->deliveryMan?->l_name}", time: now()->diffForHumans(), orderId: $order->id);
                        $postData = [
                            'title' => translate('order'),
                            'description' => $value_seller,
                            'order_id' => $order['id'],
                            'order_details_id' => $data['order_details_id'] ?? '',
                            'image' => '',
                            'type' => 'order',
                            'message_key' => $key,
                        ] + OrderNotificationContext::marketOf(order: $order);
                        if (isset($data['refund'])) {
                            $postData['type'] = 'refund';
                            $postData['refund_id'] = $data['refund']['id'];
                        }

                        $this->sendPushNotificationToDevice($sellerFcmToken, $postData);
                    }
                }
            }
            /**end for seller */

            /** for delivery man*/
            if ($type == 'delivery_man') {
                $fcmTokenDeliveryMan = $order->deliveryMan?->fcm_token;
                $lang = $order->deliveryMan?->app_language ?? $lang;
                $value_delivery_man = $this->pushNotificationMessage($key, 'delivery_man', $lang);

                if ($value_delivery_man) {
                    $value_delivery_man = $this->textVariableDataFormat(value: $value_delivery_man, key: $key, userName: "{$order->customer?->f_name} {$order->customer?->l_name}", shopName: $order->seller?->shop?->name, deliveryManName: "{$order->deliveryMan?->f_name} {$order->deliveryMan?->l_name}", time: now()->diffForHumans(), orderId: $order->id);
                    $postData = [
                        'title' => translate('order'),
                        'description' => $value_delivery_man,
                        'order_id' => $order['id'],
                        'deliveryman_charge' => usdToDefaultCurrency(amount: $order['deliveryman_charge']) ?? 0,
                        'expected_delivery_date' => $order['expected_delivery_date'] ?? '',
                        'image' => '',
                        'type' => 'order'
                    ] + OrderNotificationContext::marketOf(order: $order);
                    if ($order->delivery_man_id) {
                        self::add_deliveryman_push_notification($postData, $order->delivery_man_id);
                    }
                    if ($fcmTokenDeliveryMan) {
                        $this->sendPushNotificationToDevice($fcmTokenDeliveryMan, $postData);
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

    /**
     * chatting related push notification
     * @param string $key
     * @param string $type
     * @param object $userData
     * @param object $messageForm
     * @return void
     */
    protected function chattingNotification(string $key, string $type, object $userData, object $messageForm): void
    {
        try {
            $fcm_token = $type == 'delivery_man' ? $userData?->fcm_token : $userData?->cm_firebase_token;
            if ($fcm_token) {
                $lang = $userData?->app_language ?? getDefaultLanguage();
                $value = $this->pushNotificationMessage($key, $type, $lang);
                if ($value) {
                    $value = $this->textVariableDataFormat(
                        value: $value,
                        key: $key,
                        userName: "{$messageForm?->f_name} ",
                        shopName: "{$messageForm?->shop?->name}",
                        deliveryManName: "{$messageForm?->f_name}",
                        time: now()->diffForHumans()
                    );
                    if ($key == 'message_from_admin') {
                        $messageFromType = 'admin';
                    } elseif ($key == 'message_from_customer') {
                        $messageFromType = 'customer';
                    } elseif ($key == 'message_from_seller') {
                        $messageFromType = 'seller';
                    } elseif ($key == 'message_from_delivery_man') {
                        $messageFromType = 'delivery_man';
                    } else {
                        $messageFromType = '';
                    }
                    $data = [
                        'title' => translate('message'),
                        'description' => $value,
                        'order_id' => '',
                        'image' => '',
                        'type' => 'chatting',
                        'message_key' => $key,
                        'notification_key' => $key,
                        'notification_from' => $messageFromType,
                    ];
                    $this->sendChattingPushNotificationToDevice($fcm_token, $data);
                }
            }
        } catch (Exception $exception) {

        }

    }

    protected function withdrawStatusUpdateNotification(string $key, string $type, string $lang, int $status, string $fcmToken): void
    {
        $value = $this->pushNotificationMessage($key, $type, $lang);
        if ($value) {
            $data = [
                'title' => translate('withdraw_request_' . ($status == 1 ? 'approved' : 'denied')),
                'description' => $value,
                'image' => '',
                'type' => 'wallet_withdraw',
                'message_key' => $key,
            ];
            $this->sendPushNotificationToDevice($fcmToken, $data);
        }
    }

    protected function customerStatusUpdateNotification(string $key, string $type, string $lang, string $status, string $fcmToken): void
    {
        $value = $this->pushNotificationMessage($key, $type, $lang);
        if ($value) {
            $data = [
                'title' => translate('your_account_has_been' . '_' . $status),
                'description' => $value,
                'image' => '',
                'type' => 'block',
                'message_key' => $key,
            ];
            $this->sendPushNotificationToDevice($fcmToken, $data);
        }
    }

    protected function productRequestStatusUpdateNotification(string $key, string $type, string $lang, string $fcmToken): void
    {
        $value = $this->pushNotificationMessage($key, $type, $lang);
        if ($value) {
            $data = [
                'title' => translate($key),
                'description' => $value,
                'image' => '',
                'type' => 'product_request_approved_message',
                'message_key' => $key,
            ];
            $this->sendPushNotificationToDevice($fcmToken, $data);
        }
    }

    protected function cashCollectNotification(string $key, string $type, string $lang, float $amount, string $fcmToken): void
    {
        $value = $this->pushNotificationMessage($key, $type, $lang);
        if ($value) {
            $formattedAmount = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $amount), currencyCode: getCurrencyCode());
            $data = [
                'title' => $formattedAmount . ' ' . translate('_cash_deposit'),
                'description' => $value,
                'image' => '',
                'type' => 'wallet',
                'message_key' => $key,
            ];
            $this->sendPushNotificationToDevice($fcmToken, $data);
        }
    }

    /**
     * push notification variable message format
     */
    protected function textVariableDataFormat($value, $key = null, $userName = null, $shopName = null, $deliveryManName = null, $time = null, $orderId = null)
    {
        $data = $value;
        if ($data) {
            $order = $orderId ? Order::find($orderId) : null;
            $data = $userName ? str_replace("{userName}", $userName, $data) : $data;
            $data = $shopName ? str_replace("{shopName}", $shopName, $data) : $data;
            $data = $deliveryManName ? str_replace("{deliveryManName}", $deliveryManName, $data) : $data;
            $data = $key == 'expected_delivery_date' ? ($order ? str_replace("{time}", $order->expected_delivery_date, $data) : $data) : ($time ? str_replace("{time}", $time, $data) : $data);
            $data = $orderId ? str_replace("{orderId}", $orderId, $data) : $data;
        }
        return $data;
    }

    /**
     * push notification variable message
     * @param string $key
     * @param string $userType
     * @param string $lang
     * @return false|int|mixed|void
     */
    protected function pushNotificationMessage(string $key, string $userType, string $lang)
    {
        try {
            $notificationKey = [
                'pending' => 'order_pending_message',
                'confirmed' => 'order_confirmation_message',
                'processing' => 'order_processing_message',
                'out_for_delivery' => 'out_for_delivery_message',
                'delivered' => 'order_delivered_message',
                'returned' => 'order_returned_message',
                'failed' => 'order_failed_message',
                'canceled' => 'order_canceled',
                'order_refunded_message' => 'order_refunded_message',
                'refund_request_canceled_message' => 'refund_request_canceled_message',
                'new_order_message' => 'new_order_message',
                'order_edit_message' => 'order_edit_message',
                'order_edit_return_amount_message' => 'order_edit_return_amount_message',
                'new_order_assigned_message' => 'new_order_assigned_message',
                'delivery_man_assign_by_admin_message' => 'delivery_man_assign_by_admin_message',
                'order_rescheduled_message' => 'order_rescheduled_message',
                'expected_delivery_date' => 'expected_delivery_date',
                'message_from_admin' => 'message_from_admin',
                'message_from_seller' => 'message_from_seller',
                'message_from_delivery_man' => 'message_from_delivery_man',
                'message_from_customer' => 'message_from_customer',
                'refund_request_status_changed_by_admin' => 'refund_request_status_changed_by_admin',
                'withdraw_request_status_message' => 'withdraw_request_status_message',
                'cash_collect_by_seller_message' => 'cash_collect_by_seller_message',
                'cash_collect_by_admin_message' => 'cash_collect_by_admin_message',
                'fund_added_by_admin_message' => 'fund_added_by_admin_message',
                'delivery_man_charge' => 'delivery_man_charge',
                'product_request_approved_message' => 'product_request_approved_message',
                'product_request_rejected_message' => 'product_request_rejected_message',
                'customer_block_message' => 'customer_block_message',
                'customer_unblock_message' => 'customer_unblock_message',
                'your_referred_customer_has_been_place_order' => 'your_referred_customer_has_been_place_order',
                'your_referred_customer_order_has_been_delivered' => 'your_referred_customer_order_has_been_delivered',
            ];
            $data = NotificationMessage::with(['translations' => function ($query) use ($lang) {
                $query->where('locale', $lang);
            }])->where(['key' => $notificationKey[$key], 'user_type' => $userType])->first() ?? ["status" => 0, "message" => "", "translations" => []];
            if ($data) {
                if ($data['status'] == 0) {
                    return false;
                }
                return count($data->translations) > 0 ? $data->translations[0]->value : $data['message'];
            } else {
                return false;
            }
        } catch (Exception $exception) {

        }
    }


    protected function demoResetNotification(): void
    {
        try {
            $data = [
                'title' => translate('demo_reset_alert'),
                'description' => translate('demo_data_is_being_reset_to_default') . '.',
                'image' => '',
                'order_id' => '',
                'type' => 'demo_reset',
            ];
            $this->sendPushNotificationToTopic(data: $data, topic: $data['type']);
        } catch (Throwable $th) {
            info('Failed_to_sent_demo_reset_notification');
        }
    }


    protected function sendPushNotificationToMultipleDevices(array $tokens, array $data): void
    {
        foreach (array_unique(array_filter($tokens)) as $token) {
            $this->sendPushNotificationToDevice($token, $data);
        }
    }

    /**
     * Device wise notification send
     * @param string $fcmToken
     * @param array $data
     * @return bool|string
     */

    /**
     * The data a device receives, which is a FIXED set of keys: anything a caller adds that is not named here is dropped.
     *
     * That is why the market is added HERE rather than only at the senders -- the payload each sender builds is filtered through this list,
     * so a key put in a payload and not in this list never leaves the server. It cost the first version of K-11 a test run to find out.
     *
     * The market key is present only when the sender HAS one (K-11). A platform with storefronts off sends the data it always sent, with no
     * extra key: an app that reads no key cannot tell "no market" from "a server too old to say", and omitting it keeps those two answers
     * apart instead of sending an empty string for both.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function deviceData(array $data): array
    {
        $deviceData = [
            'title' => (string)$data['title'],
            'body' => (string)$data['description'],
            'image' => $data['image'],
            'order_id' => (string)($data['order_id'] ?? ''),
            'order_details_id' => (string)($data['order_details_id'] ?? ''),
            'refund_id' => (string)($data['refund_id'] ?? ''),
            'deliveryman_charge' => (string)($data['deliveryman_charge'] ?? ''),
            'expected_delivery_date' => (string)($data['expected_delivery_date'] ?? ''),
            'type' => (string)$data['type'],
            'is_read' => '0',
            'message_key' => (string)($data['message_key'] ?? ''),
            'notification_key' => (string)($data['notification_key'] ?? ''),
            'notification_from' => (string)($data['notification_from'] ?? ''),
            'auction_id' => (string)($data['auction_id'] ?? ''),
            'auction_slug' => (string)($data['auction_slug'] ?? ''),
            'auction_product_name' => (string)($data['auction_product_name'] ?? ''),
            'auction_product_thumbnail_full_url' => (string)($data['auction_product_thumbnail_full_url'] ?? ''),
            'excluded_user_id' => (string)($data['excluded_user_id'] ?? ''),
            'recipient_user_id' => (string)($data['recipient_user_id'] ?? ''),
            'recipient_type' => (string)($data['recipient_type'] ?? ''),
        ];

        if (isset($data['storefront']) && (string) $data['storefront'] !== '') {
            $deviceData['storefront'] = (string) $data['storefront'];
        }

        return $deviceData;
    }

    protected function sendPushNotificationToDevice(string $fcmToken, array $data): bool|string
    {
        $postData = [
            'message' => [
                'token' => $fcmToken,
                'data' => $this->deviceData($data),
                'notification' => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ]
                    ]
                ]
            ]
        ];
        return $this->sendNotificationToHttp($postData);
    }

    /**
     * Device wise notification send
     * @param string $fcmToken
     * @param array $data
     * @return bool|string
     */

    protected function sendChattingPushNotificationToDevice(string $fcmToken, array $data): bool|string
    {
        $postData = [
            'message' => [
                'token' => $fcmToken,
                'data' => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
                    'image' => $data['image'],
                    'order_id' => (string)($data['order_id'] ?? ''),
                    'refund_id' => (string)($data['refund_id'] ?? ''),
                    'deliveryman_charge' => (string)($data['deliveryman_charge'] ?? ''),
                    'expected_delivery_date' => (string)($data['expected_delivery_date'] ?? ''),
                    'is_read' => '0',
                    'type' => (string)$data['type'],
                    'message_key' => (string)($data['message_key'] ?? ''),
                    'notification_key' => (string)($data['notification_key'] ?? ''),
                    'notification_from' => (string)($data['notification_from'] ?? ''),
                ],
                'notification' => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
//                    'type' => (string)$data['type'],
//                    'message_key' => (string)($data['message_key'] ?? ''),
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ]
                    ]
                ]
            ]
        ];
        return $this->sendNotificationToHttp($postData);
    }


    /**
     * Device wise notification send
     * @param array|object $data
     * @param string $topic
     * @return bool|string
     */
    protected function sendPushNotificationToTopic(array|object $data, string $topic = 'sixvalley'): bool|string
    {
        $postData = [
            'message' => [
                'topic' => $topic,
                'data' => [
                    'title'            => (string)($data['title'] ?? ''),
                    'body'             => (string)($data['description'] ?? ''),
                    'image'            => $data['image'] ?? '',
                    'order_id'         => (string)($data['order_id'] ?? ''),
                    'type'             => (string)($data['type'] ?? ''),
                    'message_key'      => (string)($data['message_key'] ?? ''),
                    'excluded_user_id' => (string)($data['excluded_user_id'] ?? ''),
                    'auction_id' => (string)($data['auction_id'] ?? ''),
                    'auction_slug' => (string)($data['auction_slug'] ?? ''),
                    'auction_product_name' => (string)($data['auction_product_name'] ?? ''),
                    'auction_product_thumbnail_full_url' => (string)($data['auction_product_thumbnail_full_url'] ?? ''),
                    'is_read'          => '0',
                ],
                'notification' => [
                    'title' => (string)($data['title'] ?? ''),
                    'body' => (string)($data['description'] ?? ''),
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ]
                    ]
                ]
            ]
        ];
        return $this->sendNotificationToHttp($postData);
    }

    /**
     * Sends one push through the Firebase credentials of the market this message belongs to (I1-02), and otherwise through the one shared
     * `push_notification_key`, exactly as before.
     *
     * The market is the one in force, so a push about an order goes out with the credentials of the market the order was sold in. The access
     * token is already cached per `client_email`, so a second credential gets a token of its own without anything else changing.
     *
     * **What a second credential does NOT buy, and it has to be said here:** an FCM device token belongs to the Firebase PROJECT of the app
     * build that registered it, so a market's own credentials reach that market's devices only where that market's app build uses that
     * market's project. Until the owner decides that (A1-17, Q-18 and Q-32), a per-market credential for the same project is the same
     * project with a second service account -- useful for separating quota and audit, not for separating devices.
     *
     * **That is also why push falls back where the SMS and WhatsApp accounts deliberately do not.** A market whose profile names no service
     * account is sent on the platform's project, because a device token only works with the project it was registered in: refusing would
     * silence that market's pushes and gain nothing, since the safer account does not exist. A shared SMS gateway or WhatsApp inbox is the
     * opposite case -- it really would send, from another company's sender number -- so those refuse instead of falling back.
     */
    protected function sendNotificationToHttp(array|null $data): bool|string|null
    {
        // Every attempt is stored and, where the operator has raised the budget, retried (I1-09). This method used to catch `Exception`
        // and return false, so a market whose Firebase service account had been revoked sent nothing and told nobody: there was no row,
        // no screen and no counter, and the only way to find out was for a customer to say the push never arrived. What FAILED is this
        // channel's own judgement rather than an exception -- FCM answers an HTTP 401 for a revoked account without throwing anything --
        // so the predicate below is what decides, and whatever it calls a failure is logged and retried as one.
        return app(SendsWithinABudget::class)->send(
            channel: NotificationAttemptLog::CHANNEL_PUSH,
            templateKey: is_array($data) ? (string) ($data['message']['data']['type'] ?? 'push') : 'push',
            recipientType: is_array($data) ? (isset($data['message']['topic']) ? 'topic' : 'device') : null,
            sentTo: is_array($data) ? (string) ($data['message']['topic'] ?? $data['message']['token'] ?? '') : null,
            send: fn (): mixed => $this->postOnePush($data),
            succeeded: static fn (mixed $answer): bool => is_object($answer) && method_exists($answer, 'successful')
                ? (bool) $answer->successful()
                : $answer !== false && $answer !== null,
        );
    }

    /**
     * One attempt at the FCM call, exactly as it was before the budget was put in front of it -- except that it hands back the RESPONSE.
     *
     * `sendNotificationToHttp()` is declared `bool|string|null`, and PHP honours that by coercing the `Response` through `__toString()`
     * into its body. So the status code of an FCM refusal has never left this trait: every caller has been handed a body string, and a 401
     * for a revoked service account arrived looking exactly like a successful send. That is why the predicate above could not judge
     * anything until this method's own return type stopped throwing the answer away. The public method keeps its declared type, so every
     * existing caller is handed the same string it has always been handed.
     */
    private function postOnePush(array|null $data): mixed
    {
        try {
            $market = app(NotificationProfiles::class)->for(channel: NotificationProfiles::PUSH)?->credential('service_account');
            $key = is_array($market) ? $market : (array) getWebConfig('push_notification_key');
            if (isset($key['project_id'])) {
                $url = 'https://fcm.googleapis.com/v1/projects/' . $key['project_id'] . '/messages:send';
                $headers = [
                    'Authorization' => 'Bearer ' . $this->getAccessToken($key),
                    'Content-Type' => 'application/json',
                ];
            }
            return Http::withHeaders($headers)->post($url, $data);
        } catch (Exception $exception) {
            return false;
        }
    }

    protected function getAccessToken($key): string|null
    {
        // The FCM OAuth token is valid for 3600s. Minting one is a blocking HTTP
        // round-trip to Google, and previously ran on *every* push (so each device
        // send cost 2 calls: token + FCM). Cache it just under its lifetime so a
        // burst of notifications reuses a single token instead of re-minting per send.
        $cacheKey = 'fcm_access_token_' . md5((string) ($key['client_email'] ?? ''));

        return Cache::remember($cacheKey, 3300, function () use ($key) {
            $jwtToken = [
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => time() + 3600,
                'iat' => time(),
            ];
            $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $jwtPayload = base64_encode(json_encode($jwtToken));
            $unsignedJwt = $jwtHeader . '.' . $jwtPayload;
            openssl_sign($unsignedJwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = $unsignedJwt . '.' . base64_encode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->json('access_token') ?? null;
        });
    }
}
