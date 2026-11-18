<?php

namespace Tests\Support;

/**
 * What a PDF made by dompdf prints, read back: it writes each piece of text as a (string) of a TJ operator inside a Flate-compressed content stream,
 * so a test can tell whose product a sheet is for without a PDF library.
 */
trait ReadsPdfText
{
    protected function textOfPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

        $pieces = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content !== false && preg_match_all('/\[\((.*?)\)\] TJ/', $content, $matches)) {
                array_push($pieces, ...$matches[1]);
            }
        }

        return implode(' ', $pieces);
    }
}
