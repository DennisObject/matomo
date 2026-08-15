<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

use Dompdf\Dompdf;
use Dompdf\Options;

final class DompdfScheduledReportDocumentRenderer implements ScheduledReportDocumentRenderer
{
    public function render(string $html, string $format): RenderedScheduledReport
    {
        if ($format === 'html') {
            return new RenderedScheduledReport($html, 'text/html; charset=utf-8', 'html');
        }

        if ($format === 'sms') {
            return new RenderedScheduledReport(trim(strip_tags($html)), 'text/plain; charset=utf-8', 'txt');
        }

        if ($format !== 'pdf') {
            throw new ScheduledReportException('The scheduled report document format is invalid.');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return new RenderedScheduledReport($dompdf->output(), 'application/pdf', 'pdf');
    }
}
