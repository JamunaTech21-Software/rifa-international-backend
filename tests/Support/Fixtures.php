<?php

declare(strict_types=1);

namespace Tests\Support;

/** Small, valid binary files for upload tests. */
final class Fixtures
{
    public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public static function png(): string
    {
        return (string) base64_decode(self::PNG_BASE64);
    }

    /** A valid one-page PDF with a correct cross-reference table. */
    public static function pdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            "<< /Length 44 >>\nstream\nBT /F1 18 Tf 20 100 Td (RIFA test) Tj ET\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /** Write contents to a temp file and return its path. */
    public static function tempFile(string $contents, string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . '/rifa-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $contents);

        return $path;
    }
}
