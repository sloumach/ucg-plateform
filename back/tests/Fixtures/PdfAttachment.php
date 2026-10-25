<?php

namespace Tests\Fixtures;

final class PdfAttachment
{
    public static function create(string $contents): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(probe.txt) 4 0 R] >> >> >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>',
            '<< /Type /Filespec /F (probe.txt) /EF << /F 5 0 R >> >>',
            '<< /Type /EmbeddedFile /Length '.strlen($contents).' >>'."\nstream\n".$contents."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
