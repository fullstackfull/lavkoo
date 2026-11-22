<?php

namespace App\Console\Commands;

use App\Services\Storefront\RefundFromOriginalPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefundOriginalPaymentPreviewCommand extends Command
{
    protected $signature = 'refunds:preview-original-payment {refund : the id of a refund request}';

    protected $description = 'Read-only: what one refund request would give back if it were worked out from the original payment (its amount, its currency and its gateway record) and not by converting the stored amount at today\'s rate, next to what the platform converts it to today. It pays nothing, writes nothing and adopts no refund policy';

    public function handle(RefundFromOriginalPayment $computation): int
    {
        if (!Schema::hasTable('refund_requests') || !Schema::hasTable('orders')) {
            $this->error('The database has no refund requests or no orders: there is nothing to preview.');

            return self::FAILURE;
        }

        $refund = DB::table('refund_requests')->where('id', (int) $this->argument('refund'))->first();
        $order = $refund === null ? null : DB::table('orders')->where('id', $refund->order_id)->first();

        if ($refund === null || $order === null) {
            $this->error($refund === null ? 'There is no refund request with that id.' : 'The order of that refund request does not exist any more.');

            return self::FAILURE;
        }

        $alreadyRefunded = (string) DB::table('refund_requests')->where('order_id', $refund->order_id)->where('status', 'refunded')->where('id', '!=', $refund->id)->sum('amount');
        $worked = $computation->of(order: $order, refundAmount: (string) $refund->amount, alreadyRefunded: $alreadyRefunded);
        $today = usdToDefaultCurrency(amount: (float) $refund->amount);

        $this->line(sprintf('Refund request %d: order %d, %s in the amounts the order is stored in; the earlier refunds of the order gave back %s.', $refund->id, $order->id, $refund->amount, $alreadyRefunded));

        if ($worked === null) {
            $this->line(sprintf('No original payment is recorded for this order (no paid gateway record and no recorded currency), so there is nothing to work from. The conversion the platform makes today stays what decides: %s %s.', $this->number($today), getCurrencyCode()));
        } else {
            $this->line(sprintf('Original payment: %s %s (%s%s).', $worked['paid'], $worked['currency'], str_replace('_', ' ', $worked['basis']), $worked['transaction'] === null ? '' : ', transaction ' . $worked['transaction']));
            $this->line(sprintf('Worked out from the original payment, this refund gives back %s %s%s.', $worked['amount'], $worked['currency'], $worked['capped'] ? ' (capped: the refunds of the order would take more than its amount)' : ''));
            $this->line(sprintf('Converted today at the platform\'s rate it would give back %s %s%s.', $this->number($today), getCurrencyCode(), strcasecmp($worked['currency'], getCurrencyCode()) === 0 ? sprintf(': the difference between the two is %s %s', $this->number($today - (float) $worked['amount']), $worked['currency']) : ', which is not the currency of the payment, so the two are not compared'));
        }

        $this->line('Nothing was written and nothing was paid. Which of the two a refund gives back is the owner\'s decision: this is the computation a refund policy would use, and no refund calls it.');

        return self::SUCCESS;
    }

    private function number(float $amount): string
    {
        return number_format($amount, 3, '.', '');
    }
}
