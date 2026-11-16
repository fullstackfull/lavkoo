<?php

namespace Tests\Support;

/**
 * digital_payment_success() appends a line to a debug file under storage/logs every time it builds an order. A run that drives it must
 * not leave that file behind or change one that was already there.
 */
trait KeepsOrderDebugLogClean
{
    private ?string $orderDebugLog = null;

    protected function rememberOrderDebugLog(): void
    {
        $path = $this->orderDebugLogPath();
        $this->orderDebugLog = is_file($path) ? (string) file_get_contents($path) : null;
    }

    protected function restoreOrderDebugLog(): void
    {
        $path = $this->orderDebugLogPath();
        $this->orderDebugLog === null ? @unlink($path) : file_put_contents($path, $this->orderDebugLog);
    }

    private function orderDebugLogPath(): string
    {
        return storage_path('logs/order_generate_test.txt');
    }
}
