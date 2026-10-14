<?php

namespace App\Support;

/** Number pages on standard exports, never on the separate official IDP form. */
final class StandardPdf
{
    public static function addPageNumbers($wrapper): void
    {
        $wrapper->render();
        $dompdf = $wrapper->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('helvetica', 'normal');
        $canvas->page_text(
            $canvas->get_width() - 100,
            $canvas->get_height() - 30,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            8,
            [0.29, 0.35, 0.43]
        );
    }
}
