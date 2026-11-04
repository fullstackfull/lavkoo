<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Seller;
use App\Traits\PdfGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;

/**
 * The seller's invoice for an order, rendered once for whoever asks.
 *
 * The vendor panel prints it and the seller app downloads it, and they must be the same document —
 * a seller who hands a customer a paper invoice from the panel and a PDF from their phone cannot
 * have the two disagree about what was charged.
 *
 * The app used to reach for the customer API's invoice endpoint, which authenticates against the
 * customer guard: a seller bearer could never satisfy it, so tapping the invoice button was a
 * guaranteed 401 — and, because the app reads a 401 as an expired session, a trip back to the login
 * screen.
 */
class SellerInvoiceService
{
    use PdfGenerator;

    /**
     * Find an order, scoped to the seller who is asking.
     *
     * Ownership is a WHERE, not a check after the fact: an invoice carries a customer's name,
     * address and phone, so it must never be reachable by guessing an id.
     */
    public function orderFor(int|string $orderId, int|string $sellerId): ?Order
    {
        return Order::with(['details', 'customer', 'shipping', 'seller'])
            ->where(['id' => $orderId, 'seller_id' => $sellerId, 'seller_is' => 'seller'])
            ->first();
    }

    /** The invoice document itself — the vendor panel's own template. */
    public function view(Order $order, int|string $sellerId): View
    {
        return ViewFactory::make('vendor-views.order.invoice', [
            'order' => $order,
            'vendor' => Seller::where('id', $sellerId)->value('gst'),
            'companyPhone' => getWebConfig(name: 'company_phone'),
            'companyEmail' => getWebConfig(name: 'company_email'),
            'companyName' => getWebConfig(name: 'company_name'),
            'companyWebLogo' => getWebConfig(name: 'company_web_logo'),
            'invoiceSettings' => getWebConfig(name: 'invoice_settings'),
        ]);
    }

    /**
     * The invoice as bytes, for a client that saves the file itself.
     *
     * Returned as an array of byte values rather than a binary body because that is the shape the
     * app already reads for the customer invoice, and the shape its download path expects.
     *
     * @return array<int, int>
     */
    public function bytes(Order $order, int|string $sellerId): array
    {
        $mpdf = new \Mpdf\Mpdf([
            'default_font' => 'FreeSerif',
            'mode' => 'utf-8',
            'format' => [190, 250],
            'autoLangToFont' => true,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetHTMLFooter(self::footerHtml(requestFrom: 'vendor'));
        $mpdf->WriteHTML($this->view($order, $sellerId)->render());

        return array_values(unpack('C*', $mpdf->Output('', 'S')));
    }
}
