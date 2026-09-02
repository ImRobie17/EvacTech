<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * DROP D. The single decision point for "download this PDF" vs "show it in
     * the browser".
     *
     * stream() with Attachment => false hands the file to the browser's own PDF
     * viewer, which already has a download button of its own, and nothing lands
     * in Downloads. The SAME $pdf object is used either way, so a preview and
     * the download of the same report cannot drift apart -- that is the whole
     * point of doing it this way rather than building a separate HTML preview
     * page that would then have to be kept in step with the PDF view.
     *
     * Lives on the base controller rather than in a Concern because there are
     * FOUR call sites and they do not share a trait: Barangay\ReportController
     * and CityAdmin\ReportController use FiltersReports and RendersIdpForm,
     * SuperAdmin\ReportController uses neither, and RendersIdpForm is itself a
     * trait shared by the first two. Every one of them extends this class, so
     * one protected method here is visible to all four with no trait
     * composition to reason about.
     *
     * $filename must arrive COMPLETE, extension included. The three generate()
     * methods build "name" and append '.pdf'; renderIdpPdf() builds a filename
     * that already ends in '.pdf'. Appending it here would double it on one of
     * those paths.
     *
     * XLSX NEVER REACHES THIS METHOD. Maatwebsite\Excel writes a binary
     * spreadsheet a browser cannot render inline, so the xlsx branch returns
     * before this is called and the forms refuse preview+xlsx up front.
     *
     * @param  \Barryvdh\DomPDF\PDF  $pdf
     */
    protected function pdfResponse($pdf, string $filename, bool $preview)
    {
        return $preview
            ? $pdf->stream($filename, ['Attachment' => false])
            : $pdf->download($filename);
    }
}
