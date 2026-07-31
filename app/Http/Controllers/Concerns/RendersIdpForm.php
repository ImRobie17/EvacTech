<?php

namespace App\Http\Controllers\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Renders the CSWDO IDP Monitoring Form PDF (Phase 3 item 11a).
 *
 * The Barangay side and the City Admin side print the identical document. The
 * figures are shared through App\Support\IdpForm; this trait shares the render
 * itself, so paper size, filename shape and date formatting cannot drift apart
 * between the two roles.
 *
 * No constants live here on purpose -- a trait constant cannot be read as
 * Trait::CONST (gotcha 10). IdpForm::TYPE is the one string both sides need and
 * it lives on that class.
 */
trait RendersIdpForm
{
    /**
     * @param  array  $data  the validated manual header inputs
     * @param  array  $form  the figures from IdpForm::forCenter()/accumulated()
     */
    protected function renderIdpPdf(array $data, array $form)
    {
        $now = now();

        // Separators stay OUTSIDE format() (gotcha 6). ' at ' inside a format
        // string would be read as tokens: 'a' is am/pm and 't' is the number of
        // days in the month, so the header would print garbage.
        $generatedAt = $now->format('F j, Y') . ' at ' . $now->format('g:i A');

        $pdf = Pdf::loadView('reports.pdf.idp-monitoring', [
            'form' => $form,
            // The paper form's banner reads exactly "IDP Monitoring Form". Only
            // the city-wide roll-up, which has no paper equivalent, is labelled
            // differently -- so a printed single-shelter sheet is identical to
            // the one it replaces.
            'bannerTitle' => $form['accumulated']
                ? 'IDP Monitoring Form - Accumulated Shelters'
                : 'IDP Monitoring Form',
            'disasterName' => $data['disaster_name'],
            'disasterDate' => Carbon::parse($data['disaster_date'])->format('F j, Y'),
            // Null echoes as an empty string, leaving the ruled cell blank to be
            // completed by hand rather than printing a misleading zero.
            'affectedFamilies' => $data['affected_families'] ?? null,
            'affectedPersons' => $data['affected_persons'] ?? null,
            'generatedAt' => $generatedAt,
        // PORTRAIT. Two narrow tables. The generic report view is landscape;
        // that setPaper() call must not be copied here.
        ])->setPaper('a4', 'portrait');

        $slug = $form['accumulated'] ? 'accumulated_shelters' : 'shelter';
        $filename = 'idp_monitoring_form_' . $slug . '_' . $now->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
    }
}
