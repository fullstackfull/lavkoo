<?php

namespace App\Services\Fulfillment\Support;

use Devrabiul\ToastMagic\Facades\ToastMagic;

/**
 * Toasts whose text may not be ours.
 *
 * The toast library hands the message to the browser as HTML (it turns line breaks into <br>) inside a script block,
 * so a message carrying markup runs in the administrator's session. Provider error bodies, product names from a
 * supplier and anything an administrator typed reach the admin screens through toasts. `$message` is our own translated
 * text and is shown as is; `$detail` is escaped.
 */
final class SafeToast
{
    public static function success(string $message, ?string $detail = null): void
    {
        ToastMagic::success(self::compose($message, $detail));
    }

    public static function info(string $message, ?string $detail = null): void
    {
        ToastMagic::info(self::compose($message, $detail));
    }

    public static function warning(string $message, ?string $detail = null): void
    {
        ToastMagic::warning(self::compose($message, $detail));
    }

    public static function error(string $message, ?string $detail = null): void
    {
        ToastMagic::error(self::compose($message, $detail));
    }

    /** What is shown: our text, then the untrusted detail escaped. */
    public static function compose(string $message, ?string $detail): string
    {
        if ($detail === null || $detail === '') {
            return $message;
        }

        return $message === '' ? e($detail) : $message . ': ' . e($detail);
    }
}
