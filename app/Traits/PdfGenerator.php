<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;

trait  PdfGenerator
{
    public static function generatePdf($view, $filePrefix, $filePostfix, $pdfType = null, $requestFrom = 'admin'): string
    {
        $mpdf = new \Mpdf\Mpdf(['default_font' => 'FreeSerif', 'mode' => 'utf-8', 'format' => [190, 250], 'autoLangToFont' => true]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        if ($pdfType = 'invoice') {
            $footerHtml = self::footerHtml($requestFrom);
            $mpdf->SetHTMLFooter($footerHtml);
        }
        $mpdf_view = $view;
        $mpdf_view = $mpdf_view->render();
        $mpdf->WriteHTML($mpdf_view);
        $mpdf->Output($filePrefix . $filePostfix . '.pdf', 'D');
    }

    public static function storePdf($view, $filePrefix, $filePostfix, $pdfType = null, $requestFrom = 'admin'): string
    {
        $mpdf = new \Mpdf\Mpdf(['default_font' => 'FreeSerif', 'mode' => 'utf-8', 'format' => [190, 250], 'autoLangToFont' => true]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        if ($pdfType = 'invoice') {
            $footerHtml = self::footerHtml($requestFrom);
            $mpdf->SetHTMLFooter($footerHtml);
        }
        $mpdf_view = $view;
        $mpdf_view = $mpdf_view->render();
        $mpdf->WriteHTML($mpdf_view);

        $fileName = $filePrefix . $filePostfix . '.pdf';
        $directory = 'invoices';
        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }
        $filePath = Storage::disk('public')->path($directory . '/' . $fileName);
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);
        return $filePath;
    }

    public static function footerHtml(string $requestFrom): string
    {
        $getCompanyPhone = getWebConfig(name: 'company_phone');
        $getCompanyEmail = getWebConfig(name: 'company_email');

        return '
            <div style="width:100%;background-color:#FAFAFA;padding:8px 24px; margin-top: 30px;">
                <table width="100%" style="font-size:10px;color:#303030;table-layout:fixed;">
                    <tr>
                        <td style="width:33.33%;padding:8px;text-align:left;">
                            ' . url('/') . '
                        </td>
                        <td style="width:33.33%;padding:8px;text-align:center;">
                            ' . $getCompanyPhone . '
                        </td>
                        <td style="width:33.33%;padding:8px;text-align:right;">
                            ' . $getCompanyEmail . '
                        </td>
                    </tr>
                </table>
            </div>';
    }
}
