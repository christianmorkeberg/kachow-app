<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF bytes (A4) with dompdf. The export pages are plain tables, so no remote content, no PHP and no scripts are ever allowed;
 * DejaVu Sans (bundled with dompdf) covers æ ø å and the dashes. The web page's print button is dropped — it has no place in a file.
 */
final class PdfRenderer
{
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('~<div class="bar">.*?</div>~s', '', $html) ?? $html;
        $html = str_replace('</head>', '<style>@page{size:A4;margin:16mm}body{font-family:"DejaVu Sans",sans-serif;font-size:11px;max-width:none;padding:0}</style></head>', $html);

        $tmp = sys_get_temp_dir() . '/kachow-dompdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $tmp);
        $options->set('fontCache', $tmp);
        $options->set('chroot', $tmp);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
