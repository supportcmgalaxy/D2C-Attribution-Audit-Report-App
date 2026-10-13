<?php
/**
 * SimplePDF — Zero-dependency PDF Generator
 * Produces valid PDF 1.4 files using raw PDF object syntax.
 * Supports: text, rectangles, lines, font sizes, colors, page breaks.
 *
 * CMGalaxy Internal — no external libraries required.
 */
class SimplePDF
{
    private array $pages      = [];
    private int   $objCount   = 0;
    private float $pageWidth  = 595.28;  // A4 pt
    private float $pageHeight = 841.89;  // A4 pt
    private float $margin     = 40;
    private float $y          = 0;
    private array $stream     = [];
    private float $fontSize   = 10;
    private array $fillColor  = [0, 0, 0];
    private array $textColor  = [0, 0, 0];
    private array $drawColor  = [0, 0, 0];

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if (!empty($this->stream)) {
            $this->pages[] = implode("\n", $this->stream);
        }
        $this->stream = [];
        $this->y = $this->margin;
    }

    public function setFont(string $family = 'Helvetica', string $style = '', float $size = 10): void
    {
        $this->fontSize = $size;
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->fillColor = [$r / 255, $g / 255, $b / 255];
    }

    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->textColor = [$r / 255, $g / 255, $b / 255];
    }

    public function setDrawColor(int $r, int $g, int $b): void
    {
        $this->drawColor = [$r / 255, $g / 255, $b / 255];
    }

    public function rect(float $x, float $y, float $w, float $h, string $style = 'F'): void
    {
        $pdfY = $this->pageHeight - $y - $h;
        [$fr, $fg, $fb] = $this->fillColor;
        [$dr, $dg, $db] = $this->drawColor;
        $op = match(strtoupper($style)) { 'D' => 'S', 'FD' => 'B', default => 'f' };
        $this->stream[] = sprintf(
            "q %.4f %.4f %.4f rg %.4f %.4f %.4f RG %.2f %.2f %.2f %.2f re %s Q",
            $fr, $fg, $fb, $dr, $dg, $db, $x, $pdfY, $w, $h, $op
        );
    }

    public function roundedRect(float $x, float $y, float $w, float $h, float $r = 4, string $style = 'FD'): void
    {
        $pdfY = $this->pageHeight - $y - $h;
        [$fr, $fg, $fb] = $this->fillColor;
        [$dr, $dg, $db] = $this->drawColor;
        $op = match(strtoupper($style)) { 'D' => 'S', 'FD' => 'B', default => 'f' };
        $k  = $r * 0.55228475;
        $x0 = $x; $x1 = $x + $r; $x2 = $x + $w - $r; $x3 = $x + $w;
        $y0 = $pdfY; $y1 = $pdfY + $r; $y2 = $pdfY + $h - $r; $y3 = $pdfY + $h;

        $this->stream[] = sprintf(
            "q %.4f %.4f %.4f rg %.4f %.4f %.4f RG 0.5 w " .
            "%.2f %.2f m " .
            "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
            "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
            "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
            "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %s Q",
            $fr, $fg, $fb, $dr, $dg, $db,
            $x1, $y0,
            $x2, $y0, $x2 + $k, $y0, $x3, $y0 + $r - $k, $x3, $y1,
            $x3, $y2, $x3, $y2 + $k, $x2 + $k, $y3, $x2, $y3,
            $x1, $y3, $x1 - $k, $y3, $x0, $y2 + $k, $x0, $y2,
            $x0, $y1, $x0, $y1 - $k, $x1 - $k, $y0, $x1, $y0,
            $op
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        [$dr, $dg, $db] = $this->drawColor;
        $this->stream[] = sprintf(
            "q %.4f %.4f %.4f RG 0.5 w %.2f %.2f m %.2f %.2f l S Q",
            $dr, $dg, $db,
            $x1, $this->pageHeight - $y1,
            $x2, $this->pageHeight - $y2
        );
    }

    public function text(float $x, float $y, string $text, float $size = 0, bool $bold = false): void
    {
        if ($size <= 0) $size = $this->fontSize;
        $pdfY = $this->pageHeight - $y;
        [$tr, $tg, $tb] = $this->textColor;
        $safe  = $this->escape($text);
        $fref  = $bold ? 'F2' : 'F1';
        $this->stream[] = sprintf(
            "q %.4f %.4f %.4f rg BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET Q",
            $tr, $tg, $tb, $fref, $size, $x, $pdfY, $safe
        );
    }

    /**
     * Word-wrapped text block. Returns new Y after the block.
     */
    public function multiText(float $x, float $y, float $maxW, string $text, float $size = 10, bool $bold = false, float $leading = 0): float
    {
        if ($leading <= 0) $leading = $size * 1.55;
        $avgChar = $size * 0.52;
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $cur   = '';
        foreach ($words as $w) {
            $test = $cur === '' ? $w : "$cur $w";
            if (strlen($test) * $avgChar > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $test;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        foreach ($lines as $l) {
            if ($y > $this->pageHeight - $this->margin - 20) {
                $this->addPage();
                $y = $this->margin + 10;
            }
            $this->text($x, $y, $l, $size, $bold);
            $y += $leading;
        }
        return $y;
    }

    public function getY(): float     { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function ln(float $h = 6): void { $this->y += $h; }
    public function getContentWidth(): float { return $this->pageWidth - 2 * $this->margin; }
    public function getPageWidth(): float  { return $this->pageWidth; }
    public function getPageHeight(): float { return $this->pageHeight; }
    public function getMargin(): float     { return $this->margin; }

    public function output(): string
    {
        // Finalize last page stream
        if (!empty($this->stream)) {
            $this->pages[] = implode("\n", $this->stream);
            $this->stream  = [];
        }

        $body   = '';
        $xrefs  = [];

        // Reserve IDs
        $catalogId = ++$this->objCount;  // 1
        $pagesId   = ++$this->objCount;  // 2
        $font1Id   = ++$this->objCount;  // 3  Helvetica
        $font2Id   = ++$this->objCount;  // 4  Helvetica-Bold

        $pageIds = [];
        foreach ($this->pages as $stream) {
            $len = strlen($stream);

            // Content stream
            $cId = ++$this->objCount;
            $xrefs[$cId] = strlen($body);
            $body .= "{$cId} 0 obj\n<< /Length {$len} >>\nstream\n{$stream}\nendstream\nendobj\n";

            // Page object
            $pId = ++$this->objCount;
            $pageIds[] = $pId;
            $xrefs[$pId] = strlen($body);
            $body .= "{$pId} 0 obj\n"
                   . "<< /Type /Page /Parent {$pagesId} 0 R"
                   . " /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}]"
                   . " /Resources << /Font << /F1 {$font1Id} 0 R /F2 {$font2Id} 0 R >> >>"
                   . " /Contents {$cId} 0 R >>\n"
                   . "endobj\n";
        }

        // Font 1: Helvetica
        $xrefs[$font1Id] = strlen($body);
        $body .= "{$font1Id} 0 obj\n"
               . "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n"
               . "endobj\n";

        // Font 2: Helvetica-Bold
        $xrefs[$font2Id] = strlen($body);
        $body .= "{$font2Id} 0 obj\n"
               . "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n"
               . "endobj\n";

        // Pages
        $kids = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageIds));
        $xrefs[$pagesId] = strlen($body);
        $body .= "{$pagesId} 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>\nendobj\n";

        // Catalog
        $xrefs[$catalogId] = strlen($body);
        $body .= "{$catalogId} 0 obj\n<< /Type /Catalog /Pages {$pagesId} 0 R >>\nendobj\n";

        $header  = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $hLen    = strlen($header);
        foreach ($xrefs as &$off) $off += $hLen;
        unset($off);

        $xrefOffset = $hLen + strlen($body);
        $maxId      = max(array_keys($xrefs));

        $xrefTable = "xref\n0 " . ($maxId + 1) . "\n";
        $xrefTable .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $xrefTable .= isset($xrefs[$i])
                ? sprintf("%010d 00000 n \n", $xrefs[$i])
                : "0000000000 65535 f \n";
        }

        $trailer = "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $header . $body . $xrefTable . $trailer;
    }

    private function escape(string $s): string
    {
        $replacements = [
            '✓' => 'OK',
            '✔' => 'OK',
            '◐' => '*',
            '⚡' => '*',
            '→' => '->',
            '←' => '<-',
            '–' => '-',
            '—' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            '…' => '...',
            '•' => '*',
            '·' => '*',
        ];
        $s = str_replace(array_keys($replacements), array_values($replacements), $s);
        $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        if ($conv !== false) {
            $s = $conv;
        } else {
            $s = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }
}
