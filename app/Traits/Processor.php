<?php

namespace App\Traits;

use App\Models\Order;
use App\Services\Payments\PaymentReturnsToItsStorefront;
use App\Models\Setting;
use App\Utils\PlainFileName;
use App\Utils\StoredFileExtension;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;

trait  Processor
{
    public function response_formatter($constant, $content = null, $errors = []): array
    {
        $constant = (array)$constant;
        $constant['content'] = $content;
        $constant['errors'] = $errors;
        return $constant;
    }

    public function error_processor($validator): array
    {
        $errors = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            $errors[] = ['error_code' => $index, 'message' => self::translate($error[0])];
        }
        return $errors;
    }

    public function translate($key)
    {
        try {
            App::setLocale('en');
            $lang_array = include(base_path('resources/lang/' . 'en' . '/lang.php'));
            $processed_key = ucfirst(str_replace('_', ' ', str_ireplace(['\'', '"', ',', ';', '<', '>', '?'], ' ', $key)));
            if (!array_key_exists($key, $lang_array)) {
                $lang_array[$key] = $processed_key;
                $str = "<?php return " . var_export($lang_array, true) . ";";
                file_put_contents(base_path('resources/lang/' . 'en' . '/lang.php'), $str);
                $result = $processed_key;
            } else {
                $result = __('lang.' . $key);
            }
            return $result;
        } catch (Exception $exception) {
            return $key;
        }
    }

    public function payment_config($key, $settings_type): object|null
    {
        try {
            $config = DB::table('addon_settings')->where('key_name', $key)
                ->where('settings_type', $settings_type)->first();
        } catch (Exception $exception) {
            return new Setting();
        }

        return (isset($config)) ? $config : null;
    }

    /** Stores an uploaded raster image; a value that is not an uploaded image (a path or an address the request names) is no image, and the old one stays. */
    public function file_uploader(string $dir, string $format, $image = null, $old_image = null)
    {
        if (!$image instanceof UploadedFile || !StoredFileExtension::isRasterImage(image: $image)) return $old_image ?? 'def.png';

        if (PlainFileName::isPlain($old_image)) Storage::disk('public')->delete($dir . $old_image);

        $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
        if (!Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->makeDirectory($dir);
        }
        Storage::disk('public')->put($dir . $imageName, $image->get());

        return $imageName;
    }

    public function payment_response($payment_info, $payment_flag): Application|JsonResponse|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $getNewUser = 0;
        $additionalData = json_decode($payment_info->additional_data, true);

        if (isset($additionalData['new_customer_id']) && isset($additionalData['is_guest_in_order'])) {
            $getNewUser = ($additionalData['new_customer_id'] != 0) ? 1 : 0;
        }

        $orderIds = Order::where(['transaction_ref' => $payment_info['transaction_id']])->get()->pluck('id')->toArray();
        $encodedOrderIds = base64_encode(json_encode($orderIds));

        $token_string = 'payment_method=' . $payment_info->payment_method . '&&transaction_reference=' . $payment_info->transaction_id;
        if (in_array($payment_info->payment_platform, ['web', 'app']) && $payment_info['external_redirect_link'] != null) {
            return redirect($payment_info['external_redirect_link'] . '?flag=' . $payment_flag . '&&token=' . base64_encode($token_string) . '&&new_user=' . $getNewUser . '&&order_ids=' . $encodedOrderIds);
        }
        /*
         * The customer lands in the storefront they PAID in (D2-14). The storefront comes from the payment's own
         * record and never from this request: the gateway's return carries nothing about the shop, and the browser
         * following it may be a new session. Empty while the form is `unchanged`, which is how it ships, so this is
         * the URL it has always been. `App\Services\Payments\PaymentReturnsToItsStorefront` says why only one of
         * the two URL forms is built.
         */
        $storefront = app(PaymentReturnsToItsStorefront::class)->parametersFor(payment: $payment_info);

        return redirect()->route('payment-' . $payment_flag, $storefront + ['token' => base64_encode($token_string), 'new_user' => $getNewUser, 'order_ids' => $encodedOrderIds]);
    }
}
