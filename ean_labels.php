<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';

function eanLabelsCanGenerate(): bool
{
    return userCan('inventory_entries');
}

function eanLabelsCollections(): array
{
    return [
        'ksiazki-artystyczne' => [
            'label' => 'Książki Artystyczne',
            'main' => 'karta_ewidencyjna',
        ],
        'kolekcja-maszyn' => [
            'label' => 'Maszyny',
            'main' => 'karta_ewidencyjna_maszyny',
        ],
        'kolekcja-matryc' => [
            'label' => 'Matryce',
            'main' => 'karta_ewidencyjna_matryce',
        ],
        'biblioteka' => [
            'label' => 'Biblioteka',
            'main' => 'karta_ewidencyjna_bib',
        ],
        'kolekcja-klisz' => [
            'label' => 'Klisze drukarskie',
            'main' => 'karta_ewidencyjna_klisze',
        ],
    ];
}

function eanLabelsParseInt($value, int $default): int
{
    if (!is_scalar($value)) {
        return $default;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return $default;
    }

    if (!preg_match('/^-?[0-9]+$/', $raw)) {
        return $default;
    }

    return (int)$raw;
}

function eanLabelsParseFloat($value, float $default): float
{
    if (!is_scalar($value)) {
        return $default;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return $default;
    }

    $normalized = str_replace([' ', ','], ['', '.'], $raw);
    if (!is_numeric($normalized)) {
        return $default;
    }

    return (float)$normalized;
}

function eanLabelsFormatFloat(float $value, int $decimals = 1): string
{
    $formatted = number_format($value, $decimals, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function eanLabelsPdfNum(float $value): string
{
    $formatted = number_format($value, 3, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function eanLabelsPdfEscape(string $value): string
{
    $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
    return strtr($value, [
        '\\' => '\\\\',
        '(' => '\\(',
        ')' => '\\)',
        "\r" => ' ',
        "\n" => ' ',
    ]);
}

function eanLabelsApproxTextWidthPt(string $text, float $fontSizePt): float
{
    return strlen($text) * $fontSizePt * 0.53;
}

function eanLabelsEan13Checksum(string $digits12): int
{
    if (preg_match('/^[0-9]{12}$/', $digits12) !== 1) {
        throw new InvalidArgumentException('Podstawa EAN-13 musi mieć dokładnie 12 cyfr.');
    }

    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$digits12[$i];
        $position = $i + 1;
        $sum += ($position % 2 === 0) ? ($digit * 3) : $digit;
    }

    return (10 - ($sum % 10)) % 10;
}

function eanLabelsBuildCodeForInventory(int $inventoryNumber, string $prefix): string
{
    if ($inventoryNumber < 0) {
        throw new InvalidArgumentException('Numer ewidencyjny nie może być ujemny.');
    }

    if (preg_match('/^[0-9]{3}$/', $prefix) !== 1) {
        throw new InvalidArgumentException('Prefiks EAN musi mieć dokładnie 3 cyfry.');
    }

    if ($inventoryNumber > 999999999) {
        throw new InvalidArgumentException('Numer ewidencyjny jest zbyt duży dla EAN-13 (maks. 9 cyfr przy prefiksie 3-cyfrowym).');
    }

    $base = $prefix . str_pad((string)$inventoryNumber, 9, '0', STR_PAD_LEFT);
    $checksum = eanLabelsEan13Checksum($base);

    return $base . (string)$checksum;
}

function eanLabelsEan13Pattern(string $ean13): string
{
    if (preg_match('/^[0-9]{13}$/', $ean13) !== 1) {
        throw new InvalidArgumentException('Kod EAN-13 musi mieć 13 cyfr.');
    }

    $parityMap = [
        '0' => 'LLLLLL',
        '1' => 'LLGLGG',
        '2' => 'LLGGLG',
        '3' => 'LLGGGL',
        '4' => 'LGLLGG',
        '5' => 'LGGLLG',
        '6' => 'LGGGLL',
        '7' => 'LGLGLG',
        '8' => 'LGLGGL',
        '9' => 'LGGLGL',
    ];
    $encL = [
        '0' => '0001101',
        '1' => '0011001',
        '2' => '0010011',
        '3' => '0111101',
        '4' => '0100011',
        '5' => '0110001',
        '6' => '0101111',
        '7' => '0111011',
        '8' => '0110111',
        '9' => '0001011',
    ];
    $encG = [
        '0' => '0100111',
        '1' => '0110011',
        '2' => '0011011',
        '3' => '0100001',
        '4' => '0011101',
        '5' => '0111001',
        '6' => '0000101',
        '7' => '0010001',
        '8' => '0001001',
        '9' => '0010111',
    ];
    $encR = [
        '0' => '1110010',
        '1' => '1100110',
        '2' => '1101100',
        '3' => '1000010',
        '4' => '1011100',
        '5' => '1001110',
        '6' => '1010000',
        '7' => '1000100',
        '8' => '1001000',
        '9' => '1110100',
    ];

    $first = $ean13[0];
    $leftDigits = substr($ean13, 1, 6);
    $rightDigits = substr($ean13, 7, 6);
    $parity = $parityMap[$first];

    $pattern = '101';
    for ($i = 0; $i < 6; $i++) {
        $digit = $leftDigits[$i];
        $pattern .= ($parity[$i] === 'L' ? $encL[$digit] : $encG[$digit]);
    }
    $pattern .= '01010';
    for ($i = 0; $i < 6; $i++) {
        $digit = $rightDigits[$i];
        $pattern .= $encR[$digit];
    }
    $pattern .= '101';

    if (strlen($pattern) !== 95) {
        throw new RuntimeException('Nie udało się zbudować poprawnego wzoru EAN-13.');
    }

    return $pattern;
}

final class EanLabelsPdfDocument
{
    private float $pageWidthMm;
    private float $pageHeightMm;

    /** @var list<string> */
    private array $pages = [];
    private int $currentPageIndex = -1;

    public function __construct(float $pageWidthMm = 210.0, float $pageHeightMm = 297.0)
    {
        $this->pageWidthMm = $pageWidthMm;
        $this->pageHeightMm = $pageHeightMm;
    }

    public function addPage(): void
    {
        $this->pages[] = "0 g\n0 G\n";
        $this->currentPageIndex = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function fillRectMm(float $xMm, float $yTopMm, float $wMm, float $hMm): void
    {
        if ($wMm <= 0 || $hMm <= 0) {
            return;
        }
        $this->ensurePage();

        $xPt = self::mmToPt($xMm);
        $yPt = self::mmToPt($this->pageHeightMm - ($yTopMm + $hMm));
        $wPt = self::mmToPt($wMm);
        $hPt = self::mmToPt($hMm);

        $this->append(
            self::fmt($xPt) . ' ' .
            self::fmt($yPt) . ' ' .
            self::fmt($wPt) . ' ' .
            self::fmt($hPt) . " re f\n"
        );
    }

    public function strokeRectMm(float $xMm, float $yTopMm, float $wMm, float $hMm, float $lineWidthMm = 0.1): void
    {
        if ($wMm <= 0 || $hMm <= 0) {
            return;
        }
        $this->ensurePage();

        $xPt = self::mmToPt($xMm);
        $yPt = self::mmToPt($this->pageHeightMm - ($yTopMm + $hMm));
        $wPt = self::mmToPt($wMm);
        $hPt = self::mmToPt($hMm);
        $lwPt = max(0.1, self::mmToPt($lineWidthMm));

        $this->append(
            'q ' . self::fmt($lwPt) . ' w ' .
            self::fmt($xPt) . ' ' .
            self::fmt($yPt) . ' ' .
            self::fmt($wPt) . ' ' .
            self::fmt($hPt) . " re S Q\n"
        );
    }

    public function textMm(float $xMm, float $baselineYTopMm, string $text, float $fontSizePt = 8.0): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->ensurePage();

        $xPt = self::mmToPt($xMm);
        $yPt = self::mmToPt($this->pageHeightMm - $baselineYTopMm);
        $safeText = eanLabelsPdfEscape($text);

        $this->append(
            'BT /F1 ' . self::fmt($fontSizePt) . ' Tf 1 0 0 1 ' .
            self::fmt($xPt) . ' ' . self::fmt($yPt) .
            ' Tm (' . $safeText . ") Tj ET\n"
        );
    }

    public function centeredTextInBoxMm(float $xMm, float $yTopMm, float $wMm, float $hMm, string $text, float $fontSizePt = 8.0): void
    {
        $text = trim($text);
        if ($text === '' || $wMm <= 0 || $hMm <= 0) {
            return;
        }

        $textWidthPt = eanLabelsApproxTextWidthPt($text, $fontSizePt);
        $boxWidthPt = self::mmToPt($wMm);
        $leftPaddingPt = max(0.0, ($boxWidthPt - $textWidthPt) / 2.0);
        $fontSizeMm = $fontSizePt * 25.4 / 72.0;
        $baselineYTopMm = $yTopMm + ($hMm / 2.0) + ($fontSizeMm * 0.32);
        $xTextMm = $xMm + (25.4 / 72.0) * $leftPaddingPt;

        $this->textMm($xTextMm, $baselineYTopMm, $text, $fontSizePt);
    }

    public function render(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }

        $widthPt = self::mmToPt($this->pageWidthMm);
        $heightPt = self::mmToPt($this->pageHeightMm);

        $objects = [];
        $nextObjectId = 1;

        $catalogId = $nextObjectId++;
        $pagesId = $nextObjectId++;
        $fontId = $nextObjectId++;

        $pageIds = [];
        $contentIds = [];

        foreach ($this->pages as $pageContent) {
            $contentId = $nextObjectId++;
            $pageId = $nextObjectId++;

            $contentIds[] = $contentId;
            $pageIds[] = $pageId;

            $objects[$contentId] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";

            $objects[$pageId] = '<< /Type /Page /Parent ' . $pagesId . ' 0 R'
                . ' /MediaBox [0 0 ' . self::fmt($widthPt) . ' ' . self::fmt($heightPt) . ']'
                . ' /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >>'
                . ' /Contents ' . $contentId . ' 0 R'
                . ' >>';
        }

        $objects[$catalogId] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
        $objects[$pagesId] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids ['
            . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds))
            . '] >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        ksort($objects);
        $maxObjectId = (int)max(array_keys($objects));

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array_fill(0, $maxObjectId + 1, 0);

        for ($id = 1; $id <= $maxObjectId; $id++) {
            if (!isset($objects[$id])) {
                continue;
            }
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . ($maxObjectId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxObjectId; $id++) {
            if (isset($objects[$id])) {
                $pdf .= sprintf('%010d 00000 n ', $offsets[$id]) . "\n";
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }

        $pdf .= 'trailer' . "\n";
        $pdf .= '<< /Size ' . ($maxObjectId + 1) . ' /Root ' . $catalogId . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function ensurePage(): void
    {
        if ($this->currentPageIndex < 0) {
            $this->addPage();
        }
    }

    private function append(string $chunk): void
    {
        $this->pages[$this->currentPageIndex] .= $chunk;
    }

    private static function mmToPt(float $mm): float
    {
        return $mm * 72.0 / 25.4;
    }

    private static function fmt(float $value): string
    {
        return eanLabelsPdfNum($value);
    }
}

function eanLabelsDrawBarcodePattern(
    EanLabelsPdfDocument $pdf,
    float $xMm,
    float $yTopMm,
    float $wMm,
    float $hMm,
    string $pattern
): void {
    if ($wMm <= 0 || $hMm <= 0 || $pattern === '') {
        return;
    }

    $quietModules = 10;
    $moduleCount = 95 + (2 * $quietModules);
    $moduleWidthMm = $wMm / $moduleCount;
    $barXStartMm = $xMm + ($quietModules * $moduleWidthMm);

    $patternLength = strlen($pattern);
    for ($i = 0; $i < $patternLength; $i++) {
        if ($pattern[$i] !== '1') {
            continue;
        }
        $pdf->fillRectMm($barXStartMm + ($i * $moduleWidthMm), $yTopMm, $moduleWidthMm, $hMm);
    }
}

function eanLabelsDrawSingleLabel(EanLabelsPdfDocument $pdf, array $settings, float $xMm, float $yTopMm, int $inventoryNumber): void
{
    $labelWidthMm = (float)$settings['label_width_mm'];
    $labelHeightMm = (float)$settings['label_height_mm'];
    $innerPaddingMm = (float)$settings['inner_padding_mm'];
    $showInventory = !empty($settings['show_inventory_text']);
    $showEanDigits = !empty($settings['show_ean_digits']);
    $debugBorders = !empty($settings['debug_borders']);
    $prefix = (string)$settings['ean_prefix'];

    $innerPaddingMm = max(0.4, min($innerPaddingMm, min($labelWidthMm, $labelHeightMm) / 4.0));
    $contentX = $xMm + $innerPaddingMm;
    $contentY = $yTopMm + $innerPaddingMm;
    $contentW = max(2.0, $labelWidthMm - (2 * $innerPaddingMm));
    $contentH = max(2.0, $labelHeightMm - (2 * $innerPaddingMm));

    if ($debugBorders) {
        $pdf->strokeRectMm($xMm, $yTopMm, $labelWidthMm, $labelHeightMm, 0.08);
    }

    $eanCode = eanLabelsBuildCodeForInventory($inventoryNumber, $prefix);
    $pattern = eanLabelsEan13Pattern($eanCode);

    $inventoryText = 'Nr ' . $inventoryNumber;
    $inventoryFontPt = (float)$settings['inventory_font_pt'];
    $eanFontPt = (float)$settings['ean_font_pt'];

    $inventoryTextH = $showInventory ? min(max($labelHeightMm * 0.17, 2.8), 4.8) : 0.0;
    $eanTextH = $showEanDigits ? min(max($labelHeightMm * 0.14, 2.4), 4.0) : 0.0;
    $gapTop = $showInventory ? 0.4 : 0.0;
    $gapBottom = $showEanDigits ? 0.4 : 0.0;

    $barcodeY = $contentY + $inventoryTextH + $gapTop;
    $barcodeH = $contentH - $inventoryTextH - $eanTextH - $gapTop - $gapBottom;

    if ($barcodeH < 4.0) {
        $shrink = 4.0 - $barcodeH;
        if ($showEanDigits && $eanTextH > 1.4) {
            $reduce = min($shrink, $eanTextH - 1.4);
            $eanTextH -= $reduce;
            $shrink -= $reduce;
        }
        if ($showInventory && $shrink > 0 && $inventoryTextH > 1.8) {
            $reduce = min($shrink, $inventoryTextH - 1.8);
            $inventoryTextH -= $reduce;
            $shrink -= $reduce;
        }
        $barcodeY = $contentY + $inventoryTextH + ($showInventory ? 0.25 : 0.0);
        $barcodeH = max(4.0, $contentH - $inventoryTextH - $eanTextH - ($showInventory ? 0.25 : 0.0) - ($showEanDigits ? 0.25 : 0.0));
    }

    if ($showInventory) {
        $pdf->centeredTextInBoxMm($contentX, $contentY, $contentW, $inventoryTextH, $inventoryText, $inventoryFontPt);
    }

    eanLabelsDrawBarcodePattern($pdf, $contentX, $barcodeY, $contentW, $barcodeH, $pattern);

    if ($showEanDigits) {
        $eanBoxY = $contentY + $contentH - $eanTextH;
        $pdf->centeredTextInBoxMm($contentX, $eanBoxY, $contentW, $eanTextH, $eanCode, $eanFontPt);
    }
}

function eanLabelsBuildPdf(array $settings): string
{
    $startNumber = (int)$settings['start_number'];
    $endNumber = (int)$settings['end_number'];
    $labelsPerSheet = (int)$settings['labels_per_sheet'];
    $columns = (int)$settings['columns'];
    $rows = (int)$settings['rows'];
    $labelWidthMm = (float)$settings['label_width_mm'];
    $labelHeightMm = (float)$settings['label_height_mm'];
    $marginLeftMm = (float)$settings['margin_left_mm'];
    $marginTopMm = (float)$settings['margin_top_mm'];
    $horizontalGapMm = (float)$settings['horizontal_gap_mm'];
    $verticalGapMm = (float)$settings['vertical_gap_mm'];

    $doc = new EanLabelsPdfDocument(210.0, 297.0);

    $totalLabels = $endNumber - $startNumber + 1;
    for ($offset = 0; $offset < $totalLabels; $offset++) {
        if ($offset % $labelsPerSheet === 0) {
            $doc->addPage();
        }

        $slot = $offset % $labelsPerSheet;
        $row = intdiv($slot, $columns);
        $col = $slot % $columns;

        if ($row >= $rows) {
            // Nie powinno się zdarzyć, ale chroni przed błędną konfiguracją labelsPerSheet/columns.
            $doc->addPage();
            $slot = 0;
            $row = 0;
            $col = 0;
        }

        $xMm = $marginLeftMm + ($col * ($labelWidthMm + $horizontalGapMm));
        $yTopMm = $marginTopMm + ($row * ($labelHeightMm + $verticalGapMm));
        $inventoryNumber = $startNumber + $offset;

        eanLabelsDrawSingleLabel($doc, $settings, $xMm, $yTopMm, $inventoryNumber);
    }

    return $doc->render();
}

function eanLabelsSendPdf(string $pdfBinary, string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'ean-labels.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    echo $pdfBinary;
    exit;
}

function eanLabelsDefaultFormValues(int $suggestedStart): array
{
    $defaultLabelsPerSheet = 48;
    $defaultColumns = 4;
    $defaultRows = (int)ceil($defaultLabelsPerSheet / $defaultColumns);
    $defaultLabelWidth = 45.7;
    $defaultLabelHeight = 21.2;
    $defaultMarginLeft = max(0.0, (210.0 - ($defaultColumns * $defaultLabelWidth)) / 2.0);
    $defaultMarginTop = max(0.0, (297.0 - ($defaultRows * $defaultLabelHeight)) / 2.0);

    return [
        'layout_preset' => 'a4_48_45_7x21_2_4col',
        'ean_prefix' => '200',
        'start_number' => (string)$suggestedStart,
        'end_number' => (string)($suggestedStart + $defaultLabelsPerSheet - 1),
        'labels_per_sheet' => (string)$defaultLabelsPerSheet,
        'columns' => (string)$defaultColumns,
        'label_width_mm' => eanLabelsFormatFloat($defaultLabelWidth, 1),
        'label_height_mm' => eanLabelsFormatFloat($defaultLabelHeight, 1),
        'margin_left_mm' => eanLabelsFormatFloat($defaultMarginLeft, 1),
        'margin_top_mm' => eanLabelsFormatFloat($defaultMarginTop, 1),
        'horizontal_gap_mm' => '0',
        'vertical_gap_mm' => '0',
        'inner_padding_mm' => '1.2',
        'inventory_font_pt' => '7.0',
        'ean_font_pt' => '5.2',
        'show_inventory_text' => '1',
        'show_ean_digits' => '1',
        'debug_borders' => '',
    ];
}

function eanLabelsLayoutPresets(): array
{
    return [
        'custom' => [
            'label' => 'Własny układ (bez zmian)',
            'note' => 'Nie nadpisuje pól. Pozostawia ręcznie ustawione wartości formularza.',
            'fields' => [],
        ],
        'a4_48_45_7x21_2_4col' => [
            'label' => 'A4 / 48 etykiet / 45.7 x 21.2 mm / 4 kolumny',
            'note' => 'Preset dla arkusza A4: 48 etykiet, 4 kolumny, etykieta 45.7 x 21.2 mm, odstępy 0 mm, marginesy wyśrodkowane.',
            'fields' => [
                'labels_per_sheet' => '48',
                'columns' => '4',
                'label_width_mm' => '45.7',
                'label_height_mm' => '21.2',
                'margin_left_mm' => '13.6',
                'margin_top_mm' => '21.3',
                'horizontal_gap_mm' => '0',
                'vertical_gap_mm' => '0',
            ],
        ],
    ];
}

function eanLabelsValidateAndNormalize(array $input): array
{
    $errors = [];

    $prefixRaw = trim((string)($input['ean_prefix'] ?? ''));
    if ($prefixRaw === '') {
        $prefixRaw = '200';
    }
    if (preg_match('/^[0-9]{3}$/', $prefixRaw) !== 1) {
        $errors[] = 'Prefiks EAN musi składać się z dokładnie 3 cyfr (np. 200).';
    }

    $startNumber = eanLabelsParseInt($input['start_number'] ?? null, 0);
    $endNumber = eanLabelsParseInt($input['end_number'] ?? null, 0);
    if ($startNumber < 0 || $endNumber < 0) {
        $errors[] = 'Numery ewidencyjne muszą być dodatnie lub równe 0.';
    }
    if ($endNumber < $startNumber) {
        $errors[] = 'Zakres numerów jest niepoprawny: numer końcowy musi być >= początkowego.';
    }
    if ($endNumber > 999999999) {
        $errors[] = 'EAN-13 w tym generatorze obsługuje numery ewidencyjne maksymalnie do 999999999.';
    }

    $labelsPerSheet = eanLabelsParseInt($input['labels_per_sheet'] ?? null, 48);
    $columns = eanLabelsParseInt($input['columns'] ?? null, 4);
    if ($labelsPerSheet < 1 || $labelsPerSheet > 500) {
        $errors[] = 'Liczba etykiet na arkusz musi być w zakresie 1-500.';
    }
    if ($columns < 1 || $columns > 20) {
        $errors[] = 'Liczba kolumn musi być w zakresie 1-20.';
    }
    if ($columns > $labelsPerSheet && $labelsPerSheet > 0) {
        $errors[] = 'Liczba kolumn nie może być większa niż liczba etykiet na arkusz.';
    }

    $rows = ($labelsPerSheet > 0 && $columns > 0) ? (int)ceil($labelsPerSheet / $columns) : 0;

    $labelWidthMm = eanLabelsParseFloat($input['label_width_mm'] ?? null, 45.7);
    $labelHeightMm = eanLabelsParseFloat($input['label_height_mm'] ?? null, 21.2);
    $marginLeftMm = eanLabelsParseFloat($input['margin_left_mm'] ?? null, 0.0);
    $marginTopMm = eanLabelsParseFloat($input['margin_top_mm'] ?? null, 0.0);
    $horizontalGapMm = eanLabelsParseFloat($input['horizontal_gap_mm'] ?? null, 0.0);
    $verticalGapMm = eanLabelsParseFloat($input['vertical_gap_mm'] ?? null, 0.0);
    $innerPaddingMm = eanLabelsParseFloat($input['inner_padding_mm'] ?? null, 1.2);
    $inventoryFontPt = eanLabelsParseFloat($input['inventory_font_pt'] ?? null, 7.0);
    $eanFontPt = eanLabelsParseFloat($input['ean_font_pt'] ?? null, 5.2);

    foreach ([
        'Szerokość etykiety' => $labelWidthMm,
        'Wysokość etykiety' => $labelHeightMm,
    ] as $label => $value) {
        if ($value <= 0.0) {
            $errors[] = $label . ' musi być większa od 0.';
        }
    }

    foreach ([
        'Margines lewy' => $marginLeftMm,
        'Margines górny' => $marginTopMm,
        'Odstęp poziomy' => $horizontalGapMm,
        'Odstęp pionowy' => $verticalGapMm,
        'Wewnętrzny padding' => $innerPaddingMm,
    ] as $label => $value) {
        if ($value < 0.0) {
            $errors[] = $label . ' nie może być ujemny.';
        }
    }

    if ($inventoryFontPt < 3.0 || $inventoryFontPt > 18.0) {
        $errors[] = 'Rozmiar czcionki numeru ewidencyjnego powinien mieścić się w zakresie 3-18 pt.';
    }
    if ($eanFontPt < 3.0 || $eanFontPt > 18.0) {
        $errors[] = 'Rozmiar czcionki kodu EAN powinien mieścić się w zakresie 3-18 pt.';
    }

    $gridWidthMm = ($columns * $labelWidthMm) + (max(0, $columns - 1) * $horizontalGapMm);
    $gridHeightMm = ($rows * $labelHeightMm) + (max(0, $rows - 1) * $verticalGapMm);

    if ($marginLeftMm + $gridWidthMm > 210.0 + 0.001) {
        $errors[] = 'Układ etykiet przekracza szerokość A4 (210 mm). Zmniejsz szerokość, kolumny, odstępy lub margines lewy.';
    }
    if ($marginTopMm + $gridHeightMm > 297.0 + 0.001) {
        $errors[] = 'Układ etykiet przekracza wysokość A4 (297 mm). Zmniejsz wysokość, liczbę etykiet/kolumn (wiersze), odstępy lub margines górny.';
    }

    $totalLabels = ($endNumber >= $startNumber) ? ($endNumber - $startNumber + 1) : 0;
    if ($totalLabels > 10000) {
        $errors[] = 'Zakres jest zbyt duży (maksymalnie 10 000 etykiet na jedno generowanie PDF).';
    }

    return [
        'errors' => $errors,
        'settings' => [
            'ean_prefix' => $prefixRaw,
            'start_number' => $startNumber,
            'end_number' => $endNumber,
            'labels_per_sheet' => $labelsPerSheet,
            'columns' => $columns,
            'rows' => $rows,
            'label_width_mm' => $labelWidthMm,
            'label_height_mm' => $labelHeightMm,
            'margin_left_mm' => $marginLeftMm,
            'margin_top_mm' => $marginTopMm,
            'horizontal_gap_mm' => $horizontalGapMm,
            'vertical_gap_mm' => $verticalGapMm,
            'inner_padding_mm' => $innerPaddingMm,
            'inventory_font_pt' => $inventoryFontPt,
            'ean_font_pt' => $eanFontPt,
            'show_inventory_text' => !empty($input['show_inventory_text']),
            'show_ean_digits' => !empty($input['show_ean_digits']),
            'debug_borders' => !empty($input['debug_borders']),
            'grid_width_mm' => $gridWidthMm,
            'grid_height_mm' => $gridHeightMm,
            'total_labels' => $totalLabels,
            'pages' => ($labelsPerSheet > 0 && $totalLabels > 0) ? (int)ceil($totalLabels / $labelsPerSheet) : 0,
        ],
    ];
}

$collections = eanLabelsCollections();
$selectedCollection = (string)($_POST['collection'] ?? $_GET['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$selectedLedger = (string)($GLOBALS['app_selected_ledger'] ?? ($_SESSION['selected_ledger'] ?? 'depozytowa'));
$mainTable = (string)$collections[$selectedCollection]['main'];
$currentUsername = (string)($_SESSION['username'] ?? '');
$layoutPresets = eanLabelsLayoutPresets();

$suggestedStart = 1;
try {
    $suggestedStart = max(1, (int)museumSuggestedNextInventoryNumber($pdo, $mainTable, $selectedCollection));
} catch (Throwable $ignored) {
    $suggestedStart = 1;
}

$formValues = eanLabelsDefaultFormValues($suggestedStart);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($formValues) as $key) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $value = $_POST[$key];
        $formValues[$key] = is_scalar($value) ? (string)$value : '';
    }

    // Checkbox fields nie przychodzą w POST, jeśli są odznaczone.
    $formValues['show_inventory_text'] = !empty($_POST['show_inventory_text']) ? '1' : '';
    $formValues['show_ean_digits'] = !empty($_POST['show_ean_digits']) ? '1' : '';
    $formValues['debug_borders'] = !empty($_POST['debug_borders']) ? '1' : '';
}

if (!isset($layoutPresets[(string)($formValues['layout_preset'] ?? '')])) {
    $formValues['layout_preset'] = 'custom';
}

$validation = eanLabelsValidateAndNormalize($formValues);
$settings = $validation['settings'];
$errors = $validation['errors'];
$messages = [];

$canGenerate = eanLabelsCanGenerate();
if (!$canGenerate) {
    http_response_code(403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'generate_pdf') {
    if (!$canGenerate) {
        $errors[] = 'Brak uprawnień do generowania etykiet EAN.';
    } elseif ($errors === []) {
        try {
            $pdfBinary = eanLabelsBuildPdf($settings);
            $filename = 'ean-etykiety-' . $settings['start_number'] . '-' . $settings['end_number'] . '.pdf';
            eanLabelsSendPdf($pdfBinary, $filename);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($errors === [] && (int)$settings['total_labels'] > 0) {
    try {
        $exampleCode = eanLabelsBuildCodeForInventory((int)$settings['start_number'], (string)$settings['ean_prefix']);
        $messages[] = 'Przykład mapowania: numer ewidencyjny ' . (int)$settings['start_number'] . ' -> EAN-13 ' . $exampleCode . '.';
    } catch (Throwable $ignored) {
        // Komunikat przykładowy nie jest krytyczny.
    }
}

$listStmt = $pdo->prepare('SELECT id, list_name FROM lists WHERE collection = ? ORDER BY list_name');
$lists = [];
try {
    $listStmt->execute([$selectedCollection]);
    $lists = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ignored) {
    $lists = [];
}

$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$remainingRightMm = 210.0 - ((float)$settings['margin_left_mm'] + (float)$settings['grid_width_mm']);
$remainingBottomMm = 297.0 - ((float)$settings['margin_top_mm'] + (float)$settings['grid_height_mm']);

$baseQuery = 'collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Generator etykiet EAN (PDF)</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .ean-labels-page {
            display: grid;
            gap: 16px;
            margin: 18px auto 28px;
            max-width: 1180px;
            padding: 0 14px 18px;
        }
        .ean-card {
            background: var(--panel-bg, rgba(255,255,255,0.92));
            border: 1px solid var(--panel-border, rgba(0,0,0,0.12));
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 8px 26px rgba(0,0,0,0.06);
        }
        .ean-card h1,
        .ean-card h2 {
            margin: 0 0 10px;
        }
        .ean-muted {
            color: var(--muted-text, #555);
            margin: 0;
        }
        .ean-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .ean-grid.compact {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .ean-field {
            display: grid;
            gap: 6px;
            align-content: start;
        }
        .ean-field label {
            font-weight: 600;
            font-size: 13px;
        }
        .ean-field input[type="number"],
        .ean-field input[type="text"],
        .ean-field select {
            width: 100%;
            padding: 9px 10px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.2);
            background: rgba(255,255,255,0.9);
            color: inherit;
            font: inherit;
        }
        .ean-preset-row {
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(280px, 1.6fr) minmax(220px, 1fr);
            align-items: end;
            margin-bottom: 12px;
        }
        .ean-preset-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .ean-preset-actions button {
            appearance: none;
            border: 1px solid rgba(0,0,0,0.18);
            border-radius: 10px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.95);
            color: inherit;
            cursor: pointer;
            font: inherit;
        }
        .ean-preset-note {
            margin: 0;
            font-size: 12px;
            color: var(--muted-text, #555);
            line-height: 1.4;
        }
        .ean-field small {
            color: var(--muted-text, #555);
            line-height: 1.35;
        }
        .ean-checks {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 18px;
            align-items: center;
            margin-top: 2px;
        }
        .ean-checks label {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            font-weight: 500;
        }
        .ean-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .ean-actions button,
        .ean-actions a {
            appearance: none;
            border: 1px solid rgba(0,0,0,0.18);
            border-radius: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.95);
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }
        .ean-actions .primary {
            background: #0f766e;
            border-color: #0f766e;
            color: #fff;
            font-weight: 600;
        }
        .ean-alert {
            border-radius: 10px;
            padding: 10px 12px;
            margin: 0;
            border: 1px solid;
        }
        .ean-alert.ok {
            background: rgba(15, 118, 110, 0.08);
            border-color: rgba(15, 118, 110, 0.26);
        }
        .ean-alert.error {
            background: rgba(190, 24, 93, 0.08);
            border-color: rgba(190, 24, 93, 0.26);
        }
        .ean-summary {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .ean-summary-item {
            border: 1px solid rgba(0,0,0,0.09);
            border-radius: 10px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.7);
        }
        .ean-summary-item strong {
            display: block;
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 4px;
        }
        .ean-summary-item span {
            font-size: 16px;
            font-weight: 700;
        }
        .ean-notes {
            margin: 0;
            padding-left: 18px;
            line-height: 1.45;
        }
        .ean-notes li + li {
            margin-top: 4px;
        }
        .ean-preview-box {
            border-radius: 12px;
            border: 1px dashed rgba(0,0,0,0.25);
            padding: 10px;
            background: rgba(255,255,255,0.5);
        }
        .ean-preview-sheet {
            position: relative;
            width: 210px;
            max-width: 100%;
            aspect-ratio: 210 / 297;
            margin: 0 auto;
            background: #fff;
            border: 1px solid rgba(0,0,0,0.22);
            box-shadow: 0 10px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .ean-preview-sheet .grid {
            position: absolute;
            display: grid;
            border: 1px solid rgba(15, 118, 110, 0.6);
            background: rgba(15, 118, 110, 0.03);
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(15, 118, 110, 0.08);
        }
        .ean-preview-sheet .grid .cell {
            border: 1px solid rgba(15, 118, 110, 0.42);
            background: rgba(15, 118, 110, 0.06);
            box-sizing: border-box;
        }
        .ean-preview-sheet .grid .cell.first {
            background: rgba(15, 118, 110, 0.16);
            position: relative;
        }
        .ean-preview-sheet .grid .cell.first::after {
            content: attr(data-size);
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2px;
            color: rgba(0,0,0,0.72);
            font-size: 10px;
            line-height: 1.2;
            font-weight: 600;
        }
        .ean-preview-caption {
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted-text, #555);
            text-align: center;
        }
        @media (max-width: 1100px) {
            .ean-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .ean-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 700px) {
            .ean-grid,
            .ean-grid.compact,
            .ean-summary,
            .ean-preset-row {
                grid-template-columns: 1fr;
            }
            .ean-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'selectedLedger' => $selectedLedger,
    'collections' => $collections,
    'lists' => $lists,
    'username' => $currentUsername,
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
    'showColumnButton' => false,
    'showBulkBar' => false,
    'primaryActions' => [
        ['label' => 'Powrót do listy', 'href' => 'index.php?' . $baseQuery],
        ['label' => 'Import Excel / CSV', 'href' => 'inventory_import.php?' . $baseQuery],
        ['label' => 'Nowy wpis', 'href' => 'neww.php?' . $baseQuery],
    ],
]);
?>

<div class="ean-labels-page">
    <section class="ean-card">
        <h1>Generator etykiet EAN (PDF)</h1>
        <p class="ean-muted">
            Generuje PDF do druku na arkuszach A4 z pociętymi etykietami. Kod EAN-13 jest budowany z numeru ewidencyjnego
            w formacie: <strong>[prefiks 3 cyfry]</strong> + <strong>[numer ewidencyjny do 9 cyfr]</strong> + <strong>cyfra kontrolna</strong>.
        </p>
    </section>

    <?php foreach ($messages as $message): ?>
        <p class="ean-alert ok"><?php echo $esc($message); ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
        <p class="ean-alert error"><?php echo $esc($error); ?></p>
    <?php endforeach; ?>

    <?php if (!$canGenerate): ?>
        <section class="ean-card">
            <h2>Brak uprawnień</h2>
            <p class="ean-muted">Ta funkcja jest dostępna dla użytkowników z uprawnieniem do pracy na wpisach inwentarzowych.</p>
        </section>
    <?php else: ?>
        <section class="ean-card">
            <form method="post" action="ean_labels.php?<?php echo $esc($baseQuery); ?>">
                <input type="hidden" name="action" value="generate_pdf">
                <input type="hidden" name="collection" value="<?php echo $esc($selectedCollection); ?>">

                <h2>Zakres numerów i kodowanie</h2>
                <div class="ean-grid compact">
                    <div class="ean-field">
                        <label for="start_number">Numer początkowy</label>
                        <input type="number" id="start_number" name="start_number" min="0" step="1" value="<?php echo $esc($formValues['start_number']); ?>" required>
                        <small>Domyślnie proponowany następny numer ewidencyjny dla wybranej kolekcji.</small>
                    </div>
                    <div class="ean-field">
                        <label for="end_number">Numer końcowy</label>
                        <input type="number" id="end_number" name="end_number" min="0" step="1" value="<?php echo $esc($formValues['end_number']); ?>" required>
                        <small>PDF może zawierać wiele stron (arkuszy).</small>
                    </div>
                    <div class="ean-field">
                        <label for="ean_prefix">Prefiks EAN (3 cyfry)</label>
                        <input type="text" id="ean_prefix" name="ean_prefix" inputmode="numeric" pattern="[0-9]{3}" maxlength="3" value="<?php echo $esc($formValues['ean_prefix']); ?>" required>
                        <small>Domyślnie <strong>200</strong> (wewnętrzne oznaczenia; nie oficjalny prefiks producenta GS1).</small>
                    </div>
                </div>

                <h2 style="margin-top:14px;">Układ arkusza A4 (mm)</h2>
                <div class="ean-preset-row">
                    <div class="ean-field">
                        <label for="layout_preset">Preset układu</label>
                        <select id="layout_preset" name="layout_preset">
                            <?php foreach ($layoutPresets as $presetKey => $preset): ?>
                                <option value="<?php echo $esc($presetKey); ?>" <?php echo ((string)$formValues['layout_preset'] === (string)$presetKey) ? 'selected' : ''; ?>>
                                    <?php echo $esc((string)($preset['label'] ?? $presetKey)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Wybierz preset i kliknij „Zastosuj preset”, potem ewentualnie skoryguj marginesy/odstępy pod konkretny papier.</small>
                    </div>
                    <div class="ean-field">
                        <label>&nbsp;</label>
                        <div class="ean-preset-actions">
                            <button type="button" id="applyLayoutPresetButton">Zastosuj preset</button>
                            <button type="button" id="saveLayoutPresetButton">Zapisz jako preset</button>
                            <p class="ean-preset-note" id="layoutPresetNote"></p>
                        </div>
                    </div>
                </div>
                <div class="ean-grid">
                    <div class="ean-field">
                        <label for="labels_per_sheet">Etykiet na arkusz</label>
                        <input type="number" id="labels_per_sheet" name="labels_per_sheet" min="1" step="1" value="<?php echo $esc($formValues['labels_per_sheet']); ?>" required>
                        <small>Przykład: 48</small>
                    </div>
                    <div class="ean-field">
                        <label for="columns">Kolumny</label>
                        <input type="number" id="columns" name="columns" min="1" step="1" value="<?php echo $esc($formValues['columns']); ?>" required>
                        <small>Przykład: 4 (wiersze są wyliczane automatycznie).</small>
                    </div>
                    <div class="ean-field">
                        <label for="label_width_mm">Szerokość etykiety</label>
                        <input type="number" id="label_width_mm" name="label_width_mm" min="1" step="0.1" value="<?php echo $esc($formValues['label_width_mm']); ?>" required>
                        <small>Przykład: 45.7 mm</small>
                    </div>
                    <div class="ean-field">
                        <label for="label_height_mm">Wysokość etykiety</label>
                        <input type="number" id="label_height_mm" name="label_height_mm" min="1" step="0.1" value="<?php echo $esc($formValues['label_height_mm']); ?>" required>
                        <small>Przykład: 21.2 mm</small>
                    </div>
                    <div class="ean-field">
                        <label for="margin_left_mm">Margines lewy</label>
                        <input type="number" id="margin_left_mm" name="margin_left_mm" min="0" step="0.1" value="<?php echo $esc($formValues['margin_left_mm']); ?>" required>
                        <small>Pozycja startu siatki etykiet od lewej krawędzi A4.</small>
                    </div>
                    <div class="ean-field">
                        <label for="margin_top_mm">Margines górny</label>
                        <input type="number" id="margin_top_mm" name="margin_top_mm" min="0" step="0.1" value="<?php echo $esc($formValues['margin_top_mm']); ?>" required>
                        <small>Pozycja startu siatki etykiet od góry kartki.</small>
                    </div>
                    <div class="ean-field">
                        <label for="horizontal_gap_mm">Odstęp poziomy</label>
                        <input type="number" id="horizontal_gap_mm" name="horizontal_gap_mm" min="0" step="0.1" value="<?php echo $esc($formValues['horizontal_gap_mm']); ?>" required>
                        <small>Przerwa między kolumnami etykiet (mm).</small>
                    </div>
                    <div class="ean-field">
                        <label for="vertical_gap_mm">Odstęp pionowy</label>
                        <input type="number" id="vertical_gap_mm" name="vertical_gap_mm" min="0" step="0.1" value="<?php echo $esc($formValues['vertical_gap_mm']); ?>" required>
                        <small>Przerwa między wierszami etykiet (mm).</small>
                    </div>
                </div>

                <h2 style="margin-top:14px;">Wygląd etykiety</h2>
                <div class="ean-grid">
                    <div class="ean-field">
                        <label for="inner_padding_mm">Wewnętrzny padding (mm)</label>
                        <input type="number" id="inner_padding_mm" name="inner_padding_mm" min="0" step="0.1" value="<?php echo $esc($formValues['inner_padding_mm']); ?>" required>
                        <small>Odstęp treści (tekst + kod) od krawędzi etykiety.</small>
                    </div>
                    <div class="ean-field">
                        <label for="inventory_font_pt">Czcionka numeru ewid. (pt)</label>
                        <input type="number" id="inventory_font_pt" name="inventory_font_pt" min="3" step="0.1" value="<?php echo $esc($formValues['inventory_font_pt']); ?>" required>
                        <small>Widoczny numer do odczytu ręcznego.</small>
                    </div>
                    <div class="ean-field">
                        <label for="ean_font_pt">Czcionka EAN (pt)</label>
                        <input type="number" id="ean_font_pt" name="ean_font_pt" min="3" step="0.1" value="<?php echo $esc($formValues['ean_font_pt']); ?>" required>
                        <small>Cyfry pod kodem kreskowym (opcjonalnie).</small>
                    </div>
                    <div class="ean-field">
                        <label>Opcje wydruku</label>
                        <div class="ean-checks">
                            <label><input type="checkbox" name="show_inventory_text" value="1" <?php echo !empty($formValues['show_inventory_text']) ? 'checked' : ''; ?>> Pokaż numer ewidencyjny</label>
                            <label><input type="checkbox" name="show_ean_digits" value="1" <?php echo !empty($formValues['show_ean_digits']) ? 'checked' : ''; ?>> Pokaż cyfry EAN</label>
                            <label><input type="checkbox" name="debug_borders" value="1" <?php echo !empty($formValues['debug_borders']) ? 'checked' : ''; ?>> Rysuj obramowanie etykiet (test ustawienia)</label>
                        </div>
                    </div>
                </div>

                <div class="ean-actions">
                    <button type="submit" class="primary">Generuj PDF do druku</button>
                    <a href="ean_labels.php?<?php echo $esc($baseQuery); ?>">Przywróć domyślne wartości</a>
                </div>
            </form>
        </section>

        <section class="ean-card">
            <h2>Podsumowanie układu</h2>
            <div class="ean-summary">
                <div class="ean-summary-item">
                    <strong>Zakres etykiet</strong>
                    <span><?php echo (int)$settings['start_number']; ?> - <?php echo (int)$settings['end_number']; ?></span>
                </div>
                <div class="ean-summary-item">
                    <strong>Liczba etykiet / strony PDF</strong>
                    <span><?php echo (int)$settings['labels_per_sheet']; ?> (<?php echo (int)$settings['columns']; ?> kol. x <?php echo (int)$settings['rows']; ?> wierszy)</span>
                </div>
                <div class="ean-summary-item">
                    <strong>Łącznie / liczba stron</strong>
                    <span><?php echo (int)$settings['total_labels']; ?> / <?php echo (int)$settings['pages']; ?></span>
                </div>
                <div class="ean-summary-item">
                    <strong>Siatka etykiet (szer. x wys.)</strong>
                    <span><?php echo $esc(eanLabelsFormatFloat((float)$settings['grid_width_mm'], 2)); ?> x <?php echo $esc(eanLabelsFormatFloat((float)$settings['grid_height_mm'], 2)); ?> mm</span>
                </div>
                <div class="ean-summary-item">
                    <strong>Wolne miejsce po prawej</strong>
                    <span><?php echo $esc(eanLabelsFormatFloat($remainingRightMm, 2)); ?> mm</span>
                </div>
                <div class="ean-summary-item">
                    <strong>Wolne miejsce na dole</strong>
                    <span><?php echo $esc(eanLabelsFormatFloat($remainingBottomMm, 2)); ?> mm</span>
                </div>
            </div>
        </section>

        <section class="ean-card">
            <h2>Podgląd położenia siatki na A4</h2>
            <div class="ean-preview-box">
                <?php
                $sheetScale = 1.0;
                $firstCellSizeText = eanLabelsFormatFloat((float)$settings['label_width_mm'], 2)
                    . ' x '
                    . eanLabelsFormatFloat((float)$settings['label_height_mm'], 2)
                    . ' mm';
                $previewStyle = [
                    'grid-template-columns:repeat(' . (int)$settings['columns'] . ',' . eanLabelsFormatFloat((float)$settings['label_width_mm'] * $sheetScale, 3) . 'px)',
                    'grid-auto-rows:' . eanLabelsFormatFloat((float)$settings['label_height_mm'] * $sheetScale, 3) . 'px',
                    'column-gap:' . eanLabelsFormatFloat((float)$settings['horizontal_gap_mm'] * $sheetScale, 3) . 'px',
                    'row-gap:' . eanLabelsFormatFloat((float)$settings['vertical_gap_mm'] * $sheetScale, 3) . 'px',
                    'left:' . eanLabelsFormatFloat((float)$settings['margin_left_mm'] * $sheetScale, 3) . 'px',
                    'top:' . eanLabelsFormatFloat((float)$settings['margin_top_mm'] * $sheetScale, 3) . 'px',
                    'width:' . eanLabelsFormatFloat((float)$settings['grid_width_mm'] * $sheetScale, 3) . 'px',
                    'height:' . eanLabelsFormatFloat((float)$settings['grid_height_mm'] * $sheetScale, 3) . 'px',
                ];
                ?>
                <div class="ean-preview-sheet">
                    <div class="grid" style="<?php echo $esc(implode(';', $previewStyle)); ?>">
                        <?php for ($slot = 0; $slot < (int)$settings['labels_per_sheet']; $slot++): ?>
                            <div class="cell<?php echo $slot === 0 ? ' first' : ''; ?>"<?php echo $slot === 0 ? ' data-size="' . $esc($firstCellSizeText) . '"' : ''; ?>></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ean-preview-caption">
                    Połączony podgląd: położenie siatki na A4 + układ pól. Pole: <?php echo $esc($firstCellSizeText); ?>, odstępy: <?php echo $esc(eanLabelsFormatFloat((float)$settings['horizontal_gap_mm'], 2)); ?> x <?php echo $esc(eanLabelsFormatFloat((float)$settings['vertical_gap_mm'], 2)); ?> mm, skala: 1 px = 1 mm.
                </div>
                <div class="ean-preview-caption">
                    Użyj opcji „Rysuj obramowanie etykiet”, aby wykonać wydruk testowy i doprecyzować marginesy.
                </div>
            </div>
        </section>

        <section class="ean-card">
            <h2>Uwagi praktyczne</h2>
            <ul class="ean-notes">
                <li>EAN-13 obsługuje wyłącznie cyfry, więc generator działa dla numerycznych numerów ewidencyjnych.</li>
                <li>Domyślne wartości formularza odpowiadają przykładowi: 48 etykiet / A4, 45.7 x 21.2 mm, 4 kolumny.</li>
                <li>Jeśli arkusz ma przesunięcie fabryczne, skoryguj głównie: margines lewy, margines górny oraz odstępy między etykietami.</li>
                <li>Do kalibracji najpierw wygeneruj 1 stronę z obramowaniem etykiet, a dopiero potem druk docelowy bez obramowania.</li>
            </ul>
        </section>
    <?php endif; ?>
</div>

<?php
$layoutPresetClientMap = [];
foreach ($layoutPresets as $presetKey => $preset) {
    $layoutPresetClientMap[(string)$presetKey] = [
        'note' => (string)($preset['note'] ?? ''),
        'fields' => is_array($preset['fields'] ?? null) ? $preset['fields'] : [],
    ];
}
?>
<script>
(function () {
    const presets = <?php echo json_encode($layoutPresetClientMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const storageKey = 'ean_labels_custom_layout_presets_v1';
    const presetFieldNames = [
        'labels_per_sheet',
        'columns',
        'label_width_mm',
        'label_height_mm',
        'margin_left_mm',
        'margin_top_mm',
        'horizontal_gap_mm',
        'vertical_gap_mm'
    ];
    const select = document.getElementById('layout_preset');
    const applyButton = document.getElementById('applyLayoutPresetButton');
    const saveButton = document.getElementById('saveLayoutPresetButton');
    const noteEl = document.getElementById('layoutPresetNote');

    if (!select || !applyButton) {
        return;
    }

    function safeKeyFromLabel(label) {
        return String(label || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 50);
    }

    function readCustomPresets() {
        try {
            const raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function writeCustomPresets(customPresets) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(customPresets));
            return true;
        } catch (error) {
            return false;
        }
    }

    function collectLayoutFieldsFromForm() {
        const values = {};
        presetFieldNames.forEach(function (fieldName) {
            const el = document.querySelector('[name="' + fieldName.replace(/"/g, '\\"') + '"]');
            values[fieldName] = el ? String(el.value || '') : '';
        });
        return values;
    }

    function registerCustomPresets(customPresets) {
        Object.keys(customPresets).forEach(function (presetKey) {
            const preset = customPresets[presetKey];
            if (!preset || typeof preset !== 'object') {
                return;
            }
            presets[presetKey] = {
                note: String(preset.note || ''),
                fields: (preset.fields && typeof preset.fields === 'object') ? preset.fields : {},
                custom: true
            };
        });
    }

    function renderCustomPresetOptions(customPresets) {
        select.querySelectorAll('option[data-custom-preset="1"]').forEach(function (option) {
            option.remove();
        });

        Object.keys(customPresets).forEach(function (presetKey) {
            const preset = customPresets[presetKey];
            if (!preset || typeof preset !== 'object') {
                return;
            }
            const option = document.createElement('option');
            option.value = presetKey;
            option.textContent = String(preset.label || presetKey);
            option.dataset.customPreset = '1';
            select.appendChild(option);
        });
    }

    function setFieldValue(fieldName, value) {
        const el = document.querySelector('[name="' + fieldName.replace(/"/g, '\\"') + '"]');
        if (!el) {
            return;
        }
        el.value = value;
    }

    function saveCurrentLayoutAsPreset() {
        const label = window.prompt('Nazwa nowego presetu:', '');
        if (label === null) {
            return;
        }
        const trimmedLabel = String(label).trim();
        if (trimmedLabel === '') {
            if (noteEl) {
                noteEl.textContent = 'Nie zapisano: podaj nazwę presetu.';
            }
            return;
        }

        const baseKey = safeKeyFromLabel(trimmedLabel) || 'preset';
        const customPresets = readCustomPresets();
        let presetKey = 'custom_' + baseKey;
        if (Object.prototype.hasOwnProperty.call(customPresets, presetKey)) {
            presetKey = presetKey + '_' + Date.now();
        }

        customPresets[presetKey] = {
            label: trimmedLabel,
            note: 'Preset lokalny zapisany w tej przegladarce.',
            fields: collectLayoutFieldsFromForm()
        };

        if (!writeCustomPresets(customPresets)) {
            if (noteEl) {
                noteEl.textContent = 'Nie udalo sie zapisac presetu (localStorage niedostepny).';
            }
            return;
        }

        registerCustomPresets(customPresets);
        renderCustomPresetOptions(customPresets);
        select.value = presetKey;
        updateNote();
    }

    function updateNote() {
        if (!noteEl) {
            return;
        }
        const selected = presets[select.value] || {};
        noteEl.textContent = selected.note || '';
    }

    function applyPreset() {
        const selected = presets[select.value] || {};
        const fields = selected.fields || {};
        Object.keys(fields).forEach(function (fieldName) {
            setFieldValue(fieldName, fields[fieldName]);
        });
        updateNote();
    }

    applyButton.addEventListener('click', applyPreset);
    if (saveButton) {
        saveButton.addEventListener('click', saveCurrentLayoutAsPreset);
    }
    const customPresets = readCustomPresets();
    registerCustomPresets(customPresets);
    renderCustomPresetOptions(customPresets);
    select.addEventListener('change', updateNote);
    updateNote();
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
