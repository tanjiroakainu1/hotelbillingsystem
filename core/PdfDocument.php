<?php
declare(strict_types=1);

final class PdfDocument
{
    public static function fromLines(array $lines): string
    {
        $pages = array_chunk($lines ?: [''], 50);
        $streams = [];
        foreach ($pages as $pageLines) {
            $content = "BT /F1 11 Tf 40 800 Td 14 TL\n";
            foreach ($pageLines as $index => $line) {
                $safe = self::escapeText(mb_substr((string) $line, 0, 95));
                if ($index > 0) {
                    $content .= "T*\n";
                }
                $content .= "($safe) Tj\n";
            }
            $content .= 'ET';
            $streams[] = $content;
        }
        return self::fromStreams($streams);
    }

    public static function fromStreams(array $streams): string
    {
        $streams = $streams ?: [''];
        $objects = ['', ''];
        $pageIds = [];
        foreach ($streams as $stream) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $pageIds[] = $pageId;
            $objects[] = '';
            $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $regularFontId = count($objects) + 1;
        $boldFontId = $regularFontId + 1;
        foreach ($pageIds as $pageId) {
            $contentId = $pageId + 1;
            $objects[$pageId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 ' . $regularFontId
                . ' 0 R /F2 ' . $boldFontId . ' 0 R >> >> >>';
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $kids = implode(' ', array_map(static fn(int $pageId): string => $pageId . ' 0 R', $pageIds));
        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    public static function text(
        string $text,
        float $x,
        float $y,
        float $size = 10,
        bool $bold = false,
        array $color = [0.12, 0.18, 0.26]
    ): string {
        $safe = self::escapeText($text);
        $font = $bold ? 'F2' : 'F1';
        return sprintf(
            "q %.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET Q\n",
            $color[0],
            $color[1],
            $color[2],
            $font,
            $size,
            $x,
            $y,
            $safe
        );
    }

    public static function rectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        array $color
    ): string {
        return sprintf(
            "q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $width,
            $height
        );
    }

    public static function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        array $color = [0.82, 0.86, 0.89],
        float $width = 0.6
    ): string {
        return sprintf(
            "q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q\n",
            $color[0],
            $color[1],
            $color[2],
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
