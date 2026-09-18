<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';

function userCanCreateEntries(): bool
{
    return userCan('inventory_entries');
}

function importCollections(): array
{
    return [
        'ksiazki-artystyczne' => [
            'label' => 'Książki Artystyczne',
            'main' => 'karta_ewidencyjna',
            'log' => 'karta_ewidencyjna_log',
        ],
        'kolekcja-maszyn' => [
            'label' => 'Maszyny',
            'main' => 'karta_ewidencyjna_maszyny',
            'log' => 'karta_ewidencyjna_maszyny_log',
        ],
        'kolekcja-matryc' => [
            'label' => 'Matryce',
            'main' => 'karta_ewidencyjna_matryce',
            'log' => 'karta_ewidencyjna_matryce_log',
        ],
        'biblioteka' => [
            'label' => 'Biblioteka',
            'main' => 'karta_ewidencyjna_bib',
            'log' => 'karta_ewidencyjna_bib_log',
        ],
        'kolekcja-klisz' => [
            'label' => 'Klisze drukarskie',
            'main' => 'karta_ewidencyjna_klisze',
            'log' => 'karta_ewidencyjna_klisze_log',
        ],
    ];
}

function importDefaultColumnOrder(): array
{
    return [
        'numer_ewidencyjny',
        'nazwa_tytul',
        'czas_powstania',
        'inne_numery_ewidencyjne',
        'autor_wytworca',
        'miejsce_powstania',
        'liczba',
        'material',
        'dokumentacja_wizualna',
        'dzial',
        'pochodzenie',
        'technika_wykonania',
        'wymiary',
        'cechy_charakterystyczne',
        'dane_o_dokumentacji_wizualnej',
        'wlasciciel',
        'sposob_oznakowania',
        'autorskie_prawa_majatkowe',
        'kontrola_zbiorow',
        'wartosc_w_dniu_nabycia',
        'wartosc_w_dniu_sporzadzenia',
        'miejsce_przechowywania',
        'uwagi',
        'data_opracowania',
        'opracowujacy',
    ];
}

function importGetTableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($row['Field'])) {
            continue;
        }
        $columns[] = (string)$row['Field'];
    }
    return $columns;
}

function importGetPrimaryKeyColumn(PDO $pdo, string $table): ?string
{
    $stmt = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Column_name'])) {
            return (string)$row['Column_name'];
        }
    }

    return null;
}

function importGetTargetColumns(PDO $pdo, string $table): array
{
    $allColumns = importGetTableColumns($pdo, $table);
    $primaryKey = importGetPrimaryKeyColumn($pdo, $table);
    $preferred = importDefaultColumnOrder();
    $columns = [];

    foreach ($preferred as $column) {
        if ($column === $primaryKey) {
            continue;
        }
        if (in_array($column, $allColumns, true)) {
            $columns[] = $column;
        }
    }

    foreach ($allColumns as $column) {
        if ($column === $primaryKey) {
            continue;
        }
        if (!in_array($column, $columns, true)) {
            $columns[] = $column;
        }
    }

    return $columns;
}

function importCurrentProcessingDate(PDO $pdo, string $table): string
{
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'data_opracowania'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($column['Type'] ?? ''));

    if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d');
}

function importSessionKey(): string
{
    return 'inventory_import_sessions';
}

function importMappingPresetsSessionKey(): string
{
    return 'inventory_import_mapping_presets';
}

function importGetSavedMappingPresets(string $collection): array
{
    $bucket = $_SESSION[importMappingPresetsSessionKey()] ?? [];
    if (!is_array($bucket)) {
        return [];
    }

    $presets = $bucket[$collection] ?? [];
    return is_array($presets) ? $presets : [];
}

function importSaveMappingPreset(string $collection, string $presetName, array $mapping, array $targetColumns): void
{
    $presetName = importNormalizeWhitespace($presetName);
    if ($presetName === '') {
        throw new RuntimeException('Podaj nazwę układu mapowania.');
    }

    $normalizedMapping = importNormalizePostedMapping($mapping, $targetColumns);
    if (!isset($_SESSION[importMappingPresetsSessionKey()]) || !is_array($_SESSION[importMappingPresetsSessionKey()])) {
        $_SESSION[importMappingPresetsSessionKey()] = [];
    }

    if (!isset($_SESSION[importMappingPresetsSessionKey()][$collection]) || !is_array($_SESSION[importMappingPresetsSessionKey()][$collection])) {
        $_SESSION[importMappingPresetsSessionKey()][$collection] = [];
    }

    $_SESSION[importMappingPresetsSessionKey()][$collection][$presetName] = [
        'name' => $presetName,
        'mapping' => $normalizedMapping,
        'updated_at' => time(),
    ];

    uasort($_SESSION[importMappingPresetsSessionKey()][$collection], static function (array $a, array $b): int {
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    while (count($_SESSION[importMappingPresetsSessionKey()][$collection]) > 25) {
        $oldestKey = null;
        $oldestTime = null;
        foreach ($_SESSION[importMappingPresetsSessionKey()][$collection] as $key => $preset) {
            $updatedAt = (int)($preset['updated_at'] ?? 0);
            if ($oldestKey === null || $updatedAt < (int)$oldestTime) {
                $oldestKey = (string)$key;
                $oldestTime = $updatedAt;
            }
        }
        if ($oldestKey === null) {
            break;
        }
        unset($_SESSION[importMappingPresetsSessionKey()][$collection][$oldestKey]);
    }
}

function importLoadMappingPreset(string $collection, string $presetName, array $targetColumns): ?array
{
    $presetName = importNormalizeWhitespace($presetName);
    if ($presetName === '') {
        return null;
    }

    $presets = importGetSavedMappingPresets($collection);
    $preset = $presets[$presetName] ?? null;
    if (!is_array($preset) || !is_array($preset['mapping'] ?? null)) {
        return null;
    }

    return importNormalizePostedMapping($preset['mapping'], $targetColumns);
}

function importCleanupSessions(): void
{
    $key = importSessionKey();
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
        return;
    }

    $now = time();
    $ttl = 3 * 3600;
    foreach ($_SESSION[$key] as $token => $payload) {
        $createdAt = (int)($payload['created_at'] ?? 0);
        if ($createdAt <= 0 || ($now - $createdAt) > $ttl) {
            unset($_SESSION[$key][$token]);
        }
    }

    if (count($_SESSION[$key]) > 8) {
        uasort($_SESSION[$key], static function (array $a, array $b): int {
            return (int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0);
        });
        while (count($_SESSION[$key]) > 8) {
            $firstKey = array_key_first($_SESSION[$key]);
            if ($firstKey === null) {
                break;
            }
            unset($_SESSION[$key][$firstKey]);
        }
    }
}

function importStoreParsedDataset(string $collection, array $dataset): string
{
    importCleanupSessions();
    $token = bin2hex(random_bytes(18));
    $_SESSION[importSessionKey()][$token] = [
        'created_at' => time(),
        'collection' => $collection,
        'file_name' => (string)($dataset['file_name'] ?? ''),
        'headers' => array_values(array_map(static fn($v) => (string)$v, $dataset['headers'] ?? [])),
        'rows' => array_values($dataset['rows'] ?? []),
        'source_kind' => (string)($dataset['source_kind'] ?? ''),
        'meta' => is_array($dataset['meta'] ?? null) ? $dataset['meta'] : [],
    ];

    return $token;
}

function importLoadParsedDataset(string $token, string $collection): ?array
{
    importCleanupSessions();
    $bucket = $_SESSION[importSessionKey()] ?? [];
    if (!is_array($bucket) || !isset($bucket[$token]) || !is_array($bucket[$token])) {
        return null;
    }

    $payload = $bucket[$token];
    if (($payload['collection'] ?? null) !== $collection) {
        return null;
    }

    return $payload;
}

function importForgetParsedDataset(string $token): void
{
    if (isset($_SESSION[importSessionKey()][$token])) {
        unset($_SESSION[importSessionKey()][$token]);
    }
}

function importNormalizeWhitespace(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return $value;
}

function importMaybeConvertToUtf8(string $content): string
{
    if ($content === '') {
        return $content;
    }

    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        return substr($content, 3);
    }

    if (mb_check_encoding($content, 'UTF-8')) {
        return $content;
    }

    $encodings = ['Windows-1250', 'ISO-8859-2', 'Windows-1252'];
    foreach ($encodings as $encoding) {
        $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
        if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    return $content;
}

function importCsvDelimiterLabel(string $delimiter): string
{
    return match ($delimiter) {
        ',' => 'przecinek',
        ';' => 'średnik',
        "\t" => 'tabulator',
        '|' => 'pionowa kreska',
        default => $delimiter,
    };
}

function importDetectCsvDelimiter(string $content): string
{
    $candidates = [',', ';', "\t", '|'];
    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    $sampleLines = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $sampleLines[] = $line;
        if (count($sampleLines) >= 8) {
            break;
        }
    }

    if (empty($sampleLines)) {
        return ',';
    }

    $bestDelimiter = ',';
    $bestScore = -1;
    foreach ($candidates as $delimiter) {
        $counts = [];
        foreach ($sampleLines as $line) {
            $counts[] = max(0, count(str_getcsv($line, $delimiter)) - 1);
        }
        $nonZeroCounts = array_values(array_filter($counts, static fn(int $v): bool => $v > 0));
        if (empty($nonZeroCounts)) {
            $score = 0;
        } else {
            $avg = array_sum($nonZeroCounts) / count($nonZeroCounts);
            $variance = 0.0;
            foreach ($nonZeroCounts as $count) {
                $variance += ($count - $avg) * ($count - $avg);
            }
            $variance /= max(1, count($nonZeroCounts));
            $score = (int)round($avg * 100) - (int)round($variance * 10);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestDelimiter = $delimiter;
        }
    }

    return $bestDelimiter;
}

function importBuildHeadersFromRow(array $row): array
{
    $headers = [];
    foreach ($row as $i => $cell) {
        $base = importNormalizeWhitespace((string)$cell);
        $label = $base !== '' ? $base : ('Kolumna ' . ($i + 1));
        $candidate = $label;
        $suffix = 2;
        while (in_array($candidate, $headers, true)) {
            $candidate = $label . ' (' . $suffix . ')';
            $suffix++;
        }
        $headers[] = $candidate;
    }
    return $headers;
}

function importNormalizeRowsWidth(array $rows): array
{
    $maxWidth = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $maxWidth = max($maxWidth, count($row));
        }
    }

    if ($maxWidth === 0) {
        return [];
    }

    $normalized = [];
    foreach ($rows as $row) {
        $row = is_array($row) ? array_values($row) : [];
        if (count($row) < $maxWidth) {
            $row = array_pad($row, $maxWidth, '');
        }
        $normalized[] = $row;
    }

    return $normalized;
}

function importTrimTrailingEmptyColumns(array $headers, array $rows): array
{
    $width = count($headers);
    while ($width > 0) {
        $idx = $width - 1;
        $headerHasText = trim((string)($headers[$idx] ?? '')) !== '';
        $columnHasData = false;
        foreach ($rows as $row) {
            if (trim((string)($row[$idx] ?? '')) !== '') {
                $columnHasData = true;
                break;
            }
        }
        if ($headerHasText || $columnHasData) {
            break;
        }
        $width--;
    }

    $headers = array_slice($headers, 0, $width);
    $trimmedRows = [];
    foreach ($rows as $row) {
        $trimmedRows[] = array_slice(array_values($row), 0, $width);
    }

    return [$headers, $trimmedRows];
}

function importParseCsvFile(string $path, bool $firstRowIsHeader = true, ?string $delimiterOverride = null): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Nie udało się odczytać pliku CSV/TXT.');
    }

    $content = importMaybeConvertToUtf8($raw);
    $delimiter = $delimiterOverride ?: importDetectCsvDelimiter($content);

    $rows = [];
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('Nie udało się przygotować parsera CSV.');
    }
    fwrite($handle, $content);
    rewind($handle);

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($row === [null]) {
            continue;
        }
        $normalizedRow = [];
        foreach ($row as $cell) {
            $cell = is_string($cell) ? importMaybeConvertToUtf8($cell) : (string)$cell;
            $normalizedRow[] = importNormalizeWhitespace($cell);
        }
        $rows[] = $normalizedRow;
    }
    fclose($handle);

    $rows = importNormalizeRowsWidth($rows);
    if (empty($rows)) {
        throw new RuntimeException('Plik CSV/TXT nie zawiera danych.');
    }

    if ($firstRowIsHeader) {
        $headers = importBuildHeadersFromRow(array_shift($rows));
    } else {
        $width = count($rows[0]);
        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $headers[] = 'Kolumna ' . ($i + 1);
        }
    }

    [$headers, $rows] = importTrimTrailingEmptyColumns($headers, $rows);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'source_kind' => 'csv',
        'meta' => [
            'delimiter' => $delimiter,
            'delimiter_label' => importCsvDelimiterLabel($delimiter),
            'first_row_header' => $firstRowIsHeader ? 1 : 0,
        ],
    ];
}

function importXlsxColumnIndexFromRef(string $cellRef): int
{
    if (!preg_match('/^[A-Z]+/i', $cellRef, $m)) {
        return -1;
    }

    $letters = strtoupper($m[0]);
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function importXlsxExcelSerialToDateTime(float $serial): string
{
    if ($serial <= 0) {
        return (string)$serial;
    }

    $seconds = (int)round(($serial - floor($serial)) * 86400);
    $days = (int)floor($serial);

    // Excel 1900 date system bug handling.
    if ($days > 59) {
        $days -= 1;
    }

    $base = new DateTimeImmutable('1899-12-31 00:00:00', new DateTimeZone('UTC'));
    $dt = $base->modify('+' . $days . ' days')->modify('+' . $seconds . ' seconds');

    if ($seconds === 0) {
        return $dt->format('Y-m-d');
    }

    return $dt->format('Y-m-d H:i:s');
}

function importXmlChildrenByLocalName(SimpleXMLElement $node, string $localName): array
{
    $result = $node->xpath('./*[local-name()="' . $localName . '"]');
    return is_array($result) ? $result : [];
}

function importXlsxDateStyleIndexes(ZipArchive $zip): array
{
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if (!is_string($stylesXml) || $stylesXml === '') {
        return [];
    }

    $xml = @simplexml_load_string($stylesXml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) {
        return [];
    }

    $customDateNumFmtIds = [];
    $numFmtsNodes = importXmlChildrenByLocalName($xml, 'numFmts');
    if (!empty($numFmtsNodes)) {
        foreach (importXmlChildrenByLocalName($numFmtsNodes[0], 'numFmt') as $numFmt) {
            $id = (int)($numFmt['numFmtId'] ?? -1);
            $code = strtolower((string)($numFmt['formatCode'] ?? ''));
            if ($id < 0 || $code === '') {
                continue;
            }
            if (preg_match('/(^|[^\\\\])[ymdhis]/', $code) === 1) {
                $customDateNumFmtIds[$id] = true;
            }
        }
    }

    $builtinDateIds = array_fill_keys([14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true);
    $dateStyleIndexes = [];

    $cellXfsNodes = importXmlChildrenByLocalName($xml, 'cellXfs');
    if (!empty($cellXfsNodes)) {
        $styleIndex = 0;
        foreach (importXmlChildrenByLocalName($cellXfsNodes[0], 'xf') as $xf) {
            $numFmtId = (int)($xf['numFmtId'] ?? -1);
            if (isset($builtinDateIds[$numFmtId]) || isset($customDateNumFmtIds[$numFmtId])) {
                $dateStyleIndexes[$styleIndex] = true;
            }
            $styleIndex++;
        }
    }

    return $dateStyleIndexes;
}

function importXlsxSharedStrings(ZipArchive $zip): array
{
    $xmlRaw = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xmlRaw) || $xmlRaw === '') {
        return [];
    }

    $xml = @simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) {
        return [];
    }

    $strings = [];
    foreach (importXmlChildrenByLocalName($xml, 'si') as $si) {
        $tNodes = importXmlChildrenByLocalName($si, 't');
        if (!empty($tNodes)) {
            $strings[] = importNormalizeWhitespace((string)$tNodes[0]);
            continue;
        }

        $parts = [];
        foreach (importXmlChildrenByLocalName($si, 'r') as $r) {
            $runTextNodes = importXmlChildrenByLocalName($r, 't');
            if (!empty($runTextNodes)) {
                $parts[] = (string)$runTextNodes[0];
            }
        }
        $strings[] = importNormalizeWhitespace(implode('', $parts));
    }

    return $strings;
}

function importXlsxSheetPath(ZipArchive $zip): string
{
    $workbookRelsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (is_string($workbookRelsRaw) && $workbookRelsRaw !== '') {
        $rels = @simplexml_load_string($workbookRelsRaw, 'SimpleXMLElement', LIBXML_NONET);
        if ($rels) {
            foreach (importXmlChildrenByLocalName($rels, 'Relationship') as $rel) {
                $type = (string)($rel['Type'] ?? '');
                $target = (string)($rel['Target'] ?? '');
                if (str_ends_with($type, '/worksheet') && $target !== '') {
                    $target = ltrim(str_replace('\\', '/', $target), '/');
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    return $target;
                }
            }
        }
    }

    return 'xl/worksheets/sheet1.xml';
}

function importParseXlsxFile(string $path, bool $firstRowIsHeader = true): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie udało się otworzyć pliku XLSX.');
    }

    try {
        $sheetPath = importXlsxSheetPath($zip);
        $sheetXmlRaw = $zip->getFromName($sheetPath);
        if (!is_string($sheetXmlRaw) || $sheetXmlRaw === '') {
            throw new RuntimeException('Nie znaleziono arkusza w pliku XLSX.');
        }

        $sheetXml = @simplexml_load_string($sheetXmlRaw, 'SimpleXMLElement', LIBXML_NONET);
        $sheetDataNodes = $sheetXml ? importXmlChildrenByLocalName($sheetXml, 'sheetData') : [];
        if (!$sheetXml || empty($sheetDataNodes)) {
            throw new RuntimeException('Nie udało się odczytać danych z arkusza XLSX.');
        }

        $sharedStrings = importXlsxSharedStrings($zip);
        $dateStyleIndexes = importXlsxDateStyleIndexes($zip);

        $rows = [];
        foreach (importXmlChildrenByLocalName($sheetDataNodes[0], 'row') as $rowNode) {
            $row = [];
            foreach (importXmlChildrenByLocalName($rowNode, 'c') as $cell) {
                $ref = (string)($cell['r'] ?? '');
                $index = $ref !== '' ? importXlsxColumnIndexFromRef($ref) : count($row);
                if ($index < 0) {
                    $index = count($row);
                }

                $type = (string)($cell['t'] ?? '');
                $styleIndex = isset($cell['s']) ? (int)$cell['s'] : null;
                $vNodes = importXmlChildrenByLocalName($cell, 'v');
                $rawValue = !empty($vNodes) ? (string)$vNodes[0] : '';
                $value = '';

                if ($type === 's') {
                    $sharedIndex = (int)$rawValue;
                    $value = isset($sharedStrings[$sharedIndex]) ? (string)$sharedStrings[$sharedIndex] : '';
                } elseif ($type === 'inlineStr') {
                    $isNodes = importXmlChildrenByLocalName($cell, 'is');
                    if (!empty($isNodes)) {
                        $isNode = $isNodes[0];
                        $isTextNodes = importXmlChildrenByLocalName($isNode, 't');
                        if (!empty($isTextNodes)) {
                            $value = (string)$isTextNodes[0];
                        } else {
                            $parts = [];
                            foreach (importXmlChildrenByLocalName($isNode, 'r') as $r) {
                                $runTextNodes = importXmlChildrenByLocalName($r, 't');
                                if (!empty($runTextNodes)) {
                                    $parts[] = (string)$runTextNodes[0];
                                }
                            }
                            $value = implode('', $parts);
                        }
                    }
                } elseif ($type === 'b') {
                    $value = ((string)$rawValue === '1') ? 'TRUE' : 'FALSE';
                } else {
                    $value = (string)$rawValue;
                    if ($value !== '' && $styleIndex !== null && isset($dateStyleIndexes[$styleIndex]) && is_numeric($value)) {
                        $value = importXlsxExcelSerialToDateTime((float)$value);
                    }
                }

                $row[$index] = importNormalizeWhitespace((string)$value);
            }

            if (!empty($row)) {
                ksort($row);
                $maxIndex = max(array_keys($row));
                $denseRow = array_fill(0, $maxIndex + 1, '');
                foreach ($row as $cellIndex => $cellValue) {
                    $denseRow[(int)$cellIndex] = (string)$cellValue;
                }
                $rows[] = $denseRow;
            }
        }
    } finally {
        $zip->close();
    }

    $rows = importNormalizeRowsWidth($rows);
    if (empty($rows)) {
        throw new RuntimeException('Plik XLSX nie zawiera danych do importu.');
    }

    if ($firstRowIsHeader) {
        $headers = importBuildHeadersFromRow(array_shift($rows));
    } else {
        $width = count($rows[0]);
        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $headers[] = 'Kolumna ' . ($i + 1);
        }
    }

    [$headers, $rows] = importTrimTrailingEmptyColumns($headers, $rows);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'source_kind' => 'xlsx',
        'meta' => [
            'first_row_header' => $firstRowIsHeader ? 1 : 0,
        ],
    ];
}

function importStripAccents(string $value): string
{
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ż' => 'z', 'Ź' => 'z',
    ];

    return strtr($value, $map);
}

function importNormalizeKey(string $value): string
{
    $value = importStripAccents(mb_strtolower(trim($value), 'UTF-8'));
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    $value = trim($value, '_');
    return $value;
}

function importDefaultMapping(array $sourceHeaders, array $targetColumns): array
{
    $sourceIndexByNormalized = [];
    foreach ($sourceHeaders as $index => $header) {
        $normalized = importNormalizeKey((string)$header);
        if ($normalized === '') {
            continue;
        }
        if (!isset($sourceIndexByNormalized[$normalized])) {
            $sourceIndexByNormalized[$normalized] = (string)$index;
        }
    }

    $aliases = [
        'nazwa_tytul' => ['nazwa_tytul', 'nazwa', 'tytul', 'nazwa_obiektu', 'tytuł'],
        'autor_wytworca' => ['autor_wytworca', 'autor', 'wytworca', 'tworca', 'twórca'],
        'czas_powstania' => ['czas_powstania', 'rok', 'data_powstania', 'czas'],
        'miejsce_powstania' => ['miejsce_powstania', 'miejsce'],
        'technika_wykonania' => ['technika_wykonania', 'technika'],
        'dokumentacja_wizualna' => ['dokumentacja_wizualna', 'zdjecie', 'zdjęcie', 'foto', 'plik'],
        'dane_o_dokumentacji_wizualnej' => ['dane_o_dokumentacji_wizualnej', 'opis_zdjecia', 'opis_zdjęcia'],
        'wartosc_w_dniu_nabycia' => ['wartosc_w_dniu_nabycia', 'wartosc_nabycia'],
        'wartosc_w_dniu_sporzadzenia' => ['wartosc_w_dniu_sporzadzenia', 'wartosc_sporzadzenia'],
        'miejsce_przechowywania' => ['miejsce_przechowywania', 'lokalizacja', 'miejsce_skladowania'],
        'inne_numery_ewidencyjne' => ['inne_numery_ewidencyjne', 'inne_numery', 'inne_nr'],
    ];

    $mapping = [];
    foreach ($targetColumns as $column) {
        if ($column === 'numer_ewidencyjny') {
            $mapping[$column] = '__AUTO__';
            continue;
        }
        if ($column === 'data_opracowania') {
            $mapping[$column] = '__AUTO__';
            continue;
        }
        if ($column === 'opracowujacy') {
            $mapping[$column] = '__AUTO__';
            continue;
        }

        $candidates = $aliases[$column] ?? [$column];
        $selected = '__SKIP__';
        foreach ($candidates as $candidate) {
            $normalized = importNormalizeKey($candidate);
            if ($normalized !== '' && isset($sourceIndexByNormalized[$normalized])) {
                $selected = $sourceIndexByNormalized[$normalized];
                break;
            }
        }
        $mapping[$column] = $selected;
    }

    return $mapping;
}

function importNormalizePostedMapping(array $posted, array $targetColumns): array
{
    $normalized = [];
    foreach ($targetColumns as $column) {
        $value = $posted[$column] ?? '__SKIP__';
        $value = is_string($value) ? $value : '__SKIP__';
        if ($column === 'numer_ewidencyjny') {
            $value = '__AUTO__';
        } elseif ($column === 'data_opracowania' || $column === 'opracowujacy') {
            if ($value !== '__SKIP__' && $value !== '__AUTO__' && !ctype_digit($value)) {
                $value = '__AUTO__';
            }
        } else {
            if ($value !== '__SKIP__' && !ctype_digit($value)) {
                $value = '__SKIP__';
            }
        }
        $normalized[$column] = $value;
    }
    return $normalized;
}

function importCellFromSourceRow(array $row, string $mappingValue): ?string
{
    if (!ctype_digit($mappingValue)) {
        return null;
    }
    $index = (int)$mappingValue;
    $value = isset($row[$index]) ? (string)$row[$index] : '';
    $value = importNormalizeWhitespace($value);
    return $value === '' ? null : $value;
}

function importBuildPreviewRows(array $sourceRows, array $targetColumns, array $mapping, string $currentUser, string $currentDateValue, int $limit = 20): array
{
    $preview = [];
    $limit = max(1, $limit);

    foreach ($sourceRows as $rowIndex => $sourceRow) {
        if ($rowIndex >= $limit) {
            break;
        }

        $previewRow = [];
        $hasUserData = false;
        foreach ($targetColumns as $column) {
            $mapValue = (string)($mapping[$column] ?? '__SKIP__');
            if ($column === 'numer_ewidencyjny') {
                $previewRow[$column] = '[AUTO]';
                continue;
            }
            if ($column === 'data_opracowania') {
                if ($mapValue === '__AUTO__') {
                    $previewRow[$column] = $currentDateValue;
                } elseif ($mapValue === '__SKIP__') {
                    $previewRow[$column] = '';
                } else {
                    $mappedValue = importCellFromSourceRow($sourceRow, $mapValue) ?? '';
                    if ($mappedValue !== '') {
                        $hasUserData = true;
                    }
                    $previewRow[$column] = $mappedValue;
                }
                continue;
            }
            if ($column === 'opracowujacy') {
                if ($mapValue === '__AUTO__') {
                    $previewRow[$column] = $currentUser;
                } elseif ($mapValue === '__SKIP__') {
                    $previewRow[$column] = '';
                } else {
                    $mappedValue = importCellFromSourceRow($sourceRow, $mapValue) ?? '';
                    if ($mappedValue !== '') {
                        $hasUserData = true;
                    }
                    $previewRow[$column] = $mappedValue;
                }
                continue;
            }

            if ($mapValue === '__SKIP__') {
                $previewRow[$column] = '';
                continue;
            }

            $cell = importCellFromSourceRow($sourceRow, $mapValue);
            if ($cell !== null) {
                $hasUserData = true;
                $previewRow[$column] = $cell;
            } else {
                $previewRow[$column] = '';
            }
        }

        if ($hasUserData) {
            $preview[] = $previewRow;
        }
    }

    return $preview;
}

function importRowHasAnyMappedData(array $sourceRow, array $mapping): bool
{
    foreach ($mapping as $column => $mapValue) {
        $mapValue = (string)$mapValue;
        if ($column === 'numer_ewidencyjny') {
            continue;
        }
        if ($mapValue === '__SKIP__' || $mapValue === '__AUTO__') {
            continue;
        }
        if (importCellFromSourceRow($sourceRow, $mapValue) !== null) {
            return true;
        }
    }

    return false;
}

function importFormatLabel(string $column): string
{
    return str_replace('_', ' ', $column);
}

function importParseUploadedDataset(array $file, bool $firstRowIsHeader, ?string $csvDelimiterOverride = null): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Wybierz plik do importu.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Plik nie został poprawnie przesłany.');
    }

    $fileName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($extension === 'xlsx') {
        $dataset = importParseXlsxFile($file['tmp_name'], $firstRowIsHeader);
    } elseif (in_array($extension, ['csv', 'txt'], true)) {
        $dataset = importParseCsvFile($file['tmp_name'], $firstRowIsHeader, $csvDelimiterOverride);
    } elseif ($extension === 'xls') {
        throw new RuntimeException('Format .xls (stary Excel) nie jest obsługiwany bez dodatkowej biblioteki. Użyj .xlsx lub CSV.');
    } else {
        throw new RuntimeException('Obsługiwane formaty: .xlsx, .csv, .txt');
    }

    $dataset['file_name'] = $fileName;

    $maxRows = 3000;
    if (count($dataset['rows']) > $maxRows) {
        $dataset['rows'] = array_slice($dataset['rows'], 0, $maxRows);
        $dataset['meta']['rows_truncated'] = 1;
        $dataset['meta']['rows_limit'] = $maxRows;
    }

    return $dataset;
}

function importInsertRows(
    PDO $pdo,
    string $mainTable,
    string $logTable,
    string $collection,
    array $targetColumns,
    array $sourceRows,
    array $mapping,
    string $username,
    string $sourceKind = ''
): array {
    museumEnsureUniqueInventoryNumberConstraint($pdo, $mainTable);
    // `museumNextInventoryNumberFromTable()` lazily ensures the sequence table.
    // Doing that inside an active transaction can trigger an implicit commit in MySQL
    // (DDL), which later ends with "There is no active transaction" on commit().
    museumEnsureSequenceTable($pdo);

    $insertSql = 'INSERT INTO ' . $mainTable . ' (' . implode(', ', $targetColumns) . ') VALUES ('
        . implode(', ', array_map(static fn(string $key): string => ':' . $key, $targetColumns)) . ')';
    $insertStmt = $pdo->prepare($insertSql);
    $logStmt = $pdo->prepare(
        "INSERT INTO {$logTable}
            (karta_id, user_username, changed_field, old_value, new_value, change_date)
         VALUES (:karta_id, :user_username, :changed_field, :old_value, :new_value, NOW())"
    );

    $dateValue = importCurrentProcessingDate($pdo, $mainTable);
    $inserted = 0;
    $skipped = 0;
    $normalizedSourceKind = strtolower(trim($sourceKind));
    $importSourceLabel = $normalizedSourceKind === 'xlsx'
        ? 'import Excel'
        : ($normalizedSourceKind === 'csv' ? 'import CSV' : 'import');
    $historyMessage = 'Dodano do bazy przez ' . $importSourceLabel;

    $pdo->beginTransaction();
    try {
        foreach ($sourceRows as $rowIndex => $sourceRow) {
            if (!importRowHasAnyMappedData($sourceRow, $mapping)) {
                $skipped++;
                continue;
            }

            $newData = [];
            foreach ($targetColumns as $column) {
                $mapValue = (string)($mapping[$column] ?? '__SKIP__');

                if ($column === 'numer_ewidencyjny') {
                    $newData[$column] = museumNextInventoryNumberFromTable($pdo, $mainTable, $collection);
                    continue;
                }

                if ($column === 'data_opracowania') {
                    if ($mapValue === '__AUTO__') {
                        $newData[$column] = $dateValue;
                    } elseif ($mapValue === '__SKIP__') {
                        $newData[$column] = null;
                    } else {
                        $newData[$column] = importCellFromSourceRow($sourceRow, $mapValue);
                    }
                    continue;
                }

                if ($column === 'opracowujacy') {
                    if ($mapValue === '__AUTO__') {
                        $newData[$column] = $username !== '' ? $username : null;
                    } elseif ($mapValue === '__SKIP__') {
                        $newData[$column] = null;
                    } else {
                        $newData[$column] = importCellFromSourceRow($sourceRow, $mapValue);
                    }
                    continue;
                }

                if ($mapValue === '__SKIP__' || $mapValue === '__AUTO__') {
                    $newData[$column] = null;
                    continue;
                }

                $newData[$column] = importCellFromSourceRow($sourceRow, $mapValue);
            }

            $insertStmt->execute($newData);
            $newId = (int)$pdo->lastInsertId();
            $logStmt->execute([
                'karta_id' => $newId,
                'user_username' => $username !== '' ? $username : null,
                'changed_field' => $historyMessage,
                'old_value' => null,
                'new_value' => $historyMessage,
            ]);

            $inserted++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $humanRow = isset($rowIndex) ? ((int)$rowIndex + 1) : null;
        $prefix = $humanRow !== null ? ('Błąd w wierszu źródłowym #' . $humanRow . ': ') : '';
        throw new RuntimeException($prefix . $e->getMessage(), 0, $e);
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}

$collections = importCollections();
$selectedCollection = (string)($_GET['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$mainTable = $collections[$selectedCollection]['main'];
$logTable = $collections[$selectedCollection]['log'];
$targetColumns = importGetTargetColumns($pdo, $mainTable);
$currentUsername = (string)($_SESSION['username'] ?? '');

$messages = [];
$errors = [];
$importResult = null;
$activeToken = null;
$dataset = null;
$mapping = [];
$selectedPresetName = '';
$skipRows = 0;

$loadTokenFromRequest = static function (): ?string {
    $token = $_POST['import_token'] ?? $_GET['import_token'] ?? null;
    if (!is_string($token) || $token === '' || preg_match('/^[a-f0-9]{20,}$/', $token) !== 1) {
        return null;
    }
    return $token;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    appRequireCsrf();
    if (!userCanCreateEntries()) {
        http_response_code(403);
        $errors[] = 'Brak uprawnień do tworzenia wpisów do księgi inwentarzowej.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'upload_dataset') {
                $firstRowIsHeader = !empty($_POST['first_row_is_header']);
                $csvDelimiterSetting = (string)($_POST['csv_delimiter'] ?? 'auto');
                $csvDelimiterOverride = match ($csvDelimiterSetting) {
                    'comma' => ',',
                    'semicolon' => ';',
                    'tab' => "\t",
                    'pipe' => '|',
                    default => null,
                };

                $parsed = importParseUploadedDataset($_FILES['import_file'] ?? [], $firstRowIsHeader, $csvDelimiterOverride);
                $activeToken = importStoreParsedDataset($selectedCollection, $parsed);
                $dataset = importLoadParsedDataset($activeToken, $selectedCollection);
                if ($dataset === null) {
                    throw new RuntimeException('Nie udało się zapisać danych tymczasowych importu.');
                }
                $mapping = importDefaultMapping($dataset['headers'], $targetColumns);
                $messages[] = 'Etap 1: dane zostały wczytane. Sprawdź podgląd i mapowanie kolumn, a następnie uruchom import.';
            } elseif ($action === 'preview_mapping' || $action === 'execute_import') {
                $token = $loadTokenFromRequest();
                if ($token === null) {
                    throw new RuntimeException('Brak sesji importu. Wczytaj plik ponownie.');
                }
                $activeToken = $token;
                $dataset = importLoadParsedDataset($activeToken, $selectedCollection);
                if ($dataset === null) {
                    throw new RuntimeException('Sesja importu wygasła lub nie pasuje do wybranej kolekcji. Wczytaj plik ponownie.');
                }

                $skipRows = max(0, min(100000, (int)($_POST['skip_rows'] ?? 0)));
                $mapping = importNormalizePostedMapping(is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [], $targetColumns);
                $selectedPresetName = importNormalizeWhitespace((string)($_POST['mapping_preset_name'] ?? ''));
                $savePresetLabel = importNormalizeWhitespace((string)($_POST['mapping_preset_label'] ?? ''));

                if ($action === 'preview_mapping' && !empty($_POST['save_mapping_preset'])) {
                    importSaveMappingPreset($selectedCollection, $savePresetLabel, $mapping, $targetColumns);
                    $selectedPresetName = $savePresetLabel;
                    $messages[] = 'Układ mapowania został zapisany jako: ' . $selectedPresetName . '.';
                }

                if ($action === 'preview_mapping' && !empty($_POST['load_mapping_preset'])) {
                    if ($selectedPresetName === '') {
                        throw new RuntimeException('Wybierz zapisany układ mapowania do wczytania.');
                    }
                    $loadedPreset = importLoadMappingPreset($selectedCollection, $selectedPresetName, $targetColumns);
                    if ($loadedPreset === null) {
                        throw new RuntimeException('Nie znaleziono wybranego układu mapowania.');
                    }
                    $mapping = $loadedPreset;
                    $messages[] = 'Wczytano zapisany układ mapowania: ' . $selectedPresetName . '.';
                }

                $rowsForProcessing = $skipRows > 0 ? array_slice($dataset['rows'], $skipRows) : $dataset['rows'];

                if ($action === 'execute_import') {
                    $importResult = importInsertRows(
                        $pdo,
                        $mainTable,
                        $logTable,
                        $selectedCollection,
                        $targetColumns,
                        $rowsForProcessing,
                        $mapping,
                        $currentUsername,
                        (string)($dataset['source_kind'] ?? '')
                    );
                    $messages[] = 'Import zakończony. Dodano ' . (int)$importResult['inserted'] . ' rekordów, pominięto ' . (int)$importResult['skipped'] . ' pustych wierszy.'
                        . ($skipRows > 0 ? (' (zignorowano pierwsze ' . $skipRows . ' wierszy danych).') : '');
                    importForgetParsedDataset($activeToken);
                    $dataset = null;
                    $activeToken = null;
                    $mapping = [];
                    $skipRows = 0;
                } else {
                    $messages[] = 'Podgląd został odświeżony zgodnie z aktualnym mapowaniem.';
                }
            }
        } catch (Throwable $e) {
            appLogException('inventory_import.php', $e);
            $errors[] = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'Nie udało się przetworzyć importu.';
            if ($dataset === null) {
                $token = $loadTokenFromRequest();
                if ($token !== null) {
                    $activeToken = $token;
                    $dataset = importLoadParsedDataset($activeToken, $selectedCollection);
                }
            }
        }
    }
}

if ($dataset === null) {
    $token = $loadTokenFromRequest();
    if ($token !== null) {
        $activeToken = $token;
        $dataset = importLoadParsedDataset($activeToken, $selectedCollection);
    }
}

$savedMappingPresets = importGetSavedMappingPresets($selectedCollection);

if ($dataset !== null && empty($mapping)) {
    $mapping = importDefaultMapping($dataset['headers'], $targetColumns);
}

if ($dataset !== null && !empty($mapping)) {
    $mapping = importNormalizePostedMapping($mapping, $targetColumns);
}

$previewRows = [];
$previewSourceRows = [];
if ($dataset !== null && !empty($mapping)) {
    $previewSourceRows = $skipRows > 0 ? array_slice($dataset['rows'], $skipRows) : $dataset['rows'];
    $previewRows = importBuildPreviewRows(
        $previewSourceRows,
        $targetColumns,
        $mapping,
        $currentUsername,
        importCurrentProcessingDate($pdo, $mainTable),
        25
    );
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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Import Excel / CSV</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .import-page {
            display: grid;
            gap: 14px;
        }
        .import-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 10px;
            padding: 14px;
        }
        .import-card h2,
        .import-card h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        .import-hint {
            color: var(--color-muted);
            margin: 0;
        }
        .import-alert {
            border-radius: 8px;
            padding: 10px 12px;
            margin: 0;
        }
        .import-alert.ok {
            background: color-mix(in srgb, var(--color-primary) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--color-primary) 35%, var(--color-border));
        }
        .import-alert.error {
            background: color-mix(in srgb, var(--color-danger) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--color-danger) 35%, var(--color-border));
        }
        .import-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            align-items: end;
        }
        .import-grid label {
            display: grid;
            gap: 4px;
            font-weight: 600;
        }
        .import-grid input,
        .import-grid select {
            width: 100%;
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            background: var(--color-bg);
            color: var(--color-text);
        }
        .import-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .import-actions button,
        .import-actions a {
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 8px 12px;
            background: var(--color-surface-strong);
            color: var(--color-text);
            cursor: pointer;
            text-decoration: none;
        }
        .import-actions button.primary {
            background: var(--color-primary);
            border-color: var(--color-primary-dark);
            color: #fff;
            font-weight: 700;
        }
        .import-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .import-meta li {
            background: var(--color-surface-strong);
            border: 1px solid var(--color-border);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
        }
        .import-preview-wrap,
        .import-mapping-wrap {
            overflow: auto;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: var(--color-bg);
        }
        .import-preview-table,
        .import-mapping-table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
        }
        .import-preview-table th,
        .import-preview-table td,
        .import-mapping-table th,
        .import-mapping-table td {
            border: 1px solid var(--color-border);
            padding: 6px 8px;
            vertical-align: top;
            text-align: left;
            white-space: nowrap;
            max-width: 280px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .import-preview-table th,
        .import-mapping-table th {
            background: var(--color-table-head);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .import-preview-table td:first-child,
        .import-preview-table th:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            background: var(--color-bg);
        }
        .import-preview-table th:first-child {
            background: var(--color-table-head);
        }
        .import-mapping-table select {
            width: 100%;
            min-width: 220px;
            box-sizing: border-box;
            padding: 6px 8px;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            background: var(--color-bg);
            color: var(--color-text);
        }
        .import-system-column {
            font-weight: 700;
        }
        .import-note {
            font-size: 12px;
            color: var(--color-muted);
            white-space: normal;
        }
        @media (max-width: 900px) {
            .import-preview-table th,
            .import-preview-table td,
            .import-mapping-table th,
            .import-mapping-table td {
                max-width: 180px;
            }
            .import-mapping-table select {
                min-width: 180px;
            }
        }
    </style>
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'collections' => $collections,
    'lists' => $lists,
    'username' => $currentUsername,
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
    'showColumnButton' => false,
    'showBulkBar' => false,
    'primaryActions' => [
        ['label' => 'Powrót do listy', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
        ['label' => 'Nowy Wpis', 'href' => 'neww.php?collection=' . rawurlencode($selectedCollection)],
        ['label' => 'Szukaj', 'href' => 'search.php?collection=' . rawurlencode($selectedCollection)],
    ],
]);
?>

<div class="import-page">
    <div class="import-card">
        <h1>Import Excel / CSV do bazy</h1>
        <p class="import-hint">
            Obsługiwane pliki: <strong>.xlsx</strong>, <strong>.csv</strong>, <strong>.txt</strong> (np. dane oddzielone przecinkami).
            Import działa w 2 etapach: najpierw podgląd i mapowanie kolumn, potem zapis do bazy.
        </p>
    </div>

    <?php foreach ($messages as $message): ?>
        <p class="import-alert ok"><?php echo $esc($message); ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
        <p class="import-alert error"><?php echo $esc($error); ?></p>
    <?php endforeach; ?>

    <?php if (!userCanCreateEntries()): ?>
        <div class="import-card">
            <p class="import-alert error" style="margin:0;">Nie masz uprawnień do importu wpisów do księgi inwentarzowej.</p>
        </div>
    <?php else: ?>
        <div class="import-card">
            <h2>Etap 1: Wczytaj plik</h2>
            <form method="post" enctype="multipart/form-data">
                <?= appCsrfField() ?>
                <input type="hidden" name="action" value="upload_dataset">
                <div class="import-grid">
                    <label>
                        Plik importu
                        <input type="file" name="import_file" accept=".xlsx,.csv,.txt" required>
                    </label>
                    <label>
                        Separator CSV/TXT
                        <select name="csv_delimiter">
                            <option value="auto">Automatycznie</option>
                            <option value="comma">Przecinek (,)</option>
                            <option value="semicolon">Średnik (;)</option>
                            <option value="tab">Tabulator</option>
                            <option value="pipe">Pionowa kreska (|)</option>
                        </select>
                    </label>
                    <label>
                        Nagłówki
                        <select name="first_row_is_header">
                            <option value="1">Pierwszy wiersz to nagłówki</option>
                            <option value="0">Brak nagłówków (użyj Kolumna 1..N)</option>
                        </select>
                    </label>
                </div>
                <div class="import-actions">
                    <button class="primary" type="submit">Wczytaj plik i pokaż podgląd</button>
                </div>
            </form>
        </div>

        <?php if ($dataset !== null): ?>
            <div class="import-card">
                <h2>Etap 1 (kontrola): Podgląd danych w schemacie tabeli inwentarzowej</h2>
                <ul class="import-meta">
                    <li>Plik: <?php echo $esc($dataset['file_name'] ?? ''); ?></li>
                    <li>Kolumn źródłowych: <?php echo count($dataset['headers'] ?? []); ?></li>
                    <li>Wierszy źródłowych: <?php echo count($dataset['rows'] ?? []); ?></li>
                    <li>Ignoruj na starcie: <?php echo (int)$skipRows; ?></li>
                    <li>Wierszy po pominięciu: <?php echo count($previewSourceRows); ?></li>
                    <li>Format: <?php echo $esc(strtoupper((string)($dataset['source_kind'] ?? ''))); ?></li>
                    <?php if (($dataset['source_kind'] ?? '') === 'csv' && !empty($dataset['meta']['delimiter_label'])): ?>
                        <li>Separator: <?php echo $esc((string)$dataset['meta']['delimiter_label']); ?></li>
                    <?php endif; ?>
                    <?php if (!empty($dataset['meta']['rows_truncated']) && !empty($dataset['meta']['rows_limit'])): ?>
                        <li>Uwaga: ograniczono do <?php echo (int)$dataset['meta']['rows_limit']; ?> wierszy</li>
                    <?php endif; ?>
                </ul>

                <form method="post" id="mappingForm">
                    <?= appCsrfField() ?>
                    <input type="hidden" name="import_token" value="<?php echo $esc($activeToken ?? ''); ?>">
                    <input type="hidden" name="action" value="preview_mapping" id="mappingActionField">

                    <div class="import-grid" style="margin-top:12px;">
                        <label>
                            Ignoruj pierwsze X wierszy danych
                            <input type="number" name="skip_rows" min="0" step="1" value="<?php echo (int)$skipRows; ?>">
                            <span class="import-note">Dotyczy wierszy danych po wczytaniu pliku (po ewentualnym wierszu nagłówków).</span>
                        </label>
                    </div>

                    <div class="import-preview-wrap" style="margin-top:12px;">
                        <table class="import-preview-table" id="importPreviewTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php foreach ($targetColumns as $column): ?>
                                        <th title="<?php echo $esc($column); ?>"><?php echo $esc(importFormatLabel($column)); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewRows)): ?>
                                    <tr>
                                        <td colspan="<?php echo 1 + count($targetColumns); ?>" class="import-note">
                                            Brak danych w podglądzie. Sprawdź mapowanie kolumn albo zmniejsz liczbę ignorowanych wierszy i odśwież podgląd.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($previewRows as $idx => $row): ?>
                                        <tr>
                                            <td><?php echo (int)$idx + 1; ?></td>
                                            <?php foreach ($targetColumns as $column): ?>
                                                <td title="<?php echo $esc((string)($row[$column] ?? '')); ?>"><?php echo $esc((string)($row[$column] ?? '')); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin-top:14px;">Dostosuj kolumny pliku do tabeli inwentarzowej</h3>
                    <div class="import-grid" style="margin-top:10px; margin-bottom:12px;">
                        <label>
                            Zapisane mapowania
                            <select name="mapping_preset_name">
                                <option value="">Wybierz zapisany układ</option>
                                <?php foreach ($savedMappingPresets as $presetName => $presetData): ?>
                                    <option value="<?php echo $esc((string)$presetName); ?>" <?php echo $selectedPresetName === (string)$presetName ? 'selected' : ''; ?>>
                                        <?php echo $esc((string)$presetName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="import-note">Lista jest zapisywana dla bieżącej kolekcji.</span>
                        </label>
                        <label>
                            Zapisz bieżący układ
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <input type="text" name="mapping_preset_label" value="<?php echo $esc($selectedPresetName); ?>" placeholder="Np. Excel 2026">
                                <button type="submit" name="save_mapping_preset" value="1" onclick="document.getElementById('mappingActionField').value='preview_mapping'; const presetField = this.form.querySelector('input[name=&quot;mapping_preset_label&quot;]'); const selectField = this.form.querySelector('select[name=&quot;mapping_preset_name&quot;]'); if (presetField && selectField) { selectField.value = presetField.value.trim(); }">Zapisz układ</button>
                                <button type="submit" name="load_mapping_preset" value="1" onclick="document.getElementById('mappingActionField').value='preview_mapping';">Wczytaj układ</button>
                            </div>
                            <span class="import-note">Najpierw ustaw mapowanie, potem zapisz je pod własną nazwą.</span>
                        </label>
                    </div>
                    <div class="import-mapping-wrap">
                        <table class="import-mapping-table">
                            <thead>
                                <tr>
                                    <th>Kolumna tabeli inwentarzowej</th>
                                    <th>Źródło (Excel/CSV)</th>
                                    <th>Uwagi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($targetColumns as $column): ?>
                                    <?php
                                    $currentValue = (string)($mapping[$column] ?? '__SKIP__');
                                    $isSystemColumn = in_array($column, ['numer_ewidencyjny', 'data_opracowania', 'opracowujacy'], true);
                                    ?>
                                    <tr>
                                        <td class="<?php echo $isSystemColumn ? 'import-system-column' : ''; ?>"><?php echo $esc($column); ?></td>
                                        <td>
                                            <select name="mapping[<?php echo $esc($column); ?>]">
                                                <?php if ($column === 'numer_ewidencyjny'): ?>
                                                    <option value="__AUTO__" <?php echo $currentValue === '__AUTO__' ? 'selected' : ''; ?>>Automatycznie (numer inwentarzowy)</option>
                                                <?php elseif ($column === 'data_opracowania'): ?>
                                                    <option value="__AUTO__" <?php echo $currentValue === '__AUTO__' ? 'selected' : ''; ?>>Automatycznie (dzisiejsza data)</option>
                                                    <option value="__SKIP__" <?php echo $currentValue === '__SKIP__' ? 'selected' : ''; ?>>Pomiń (zostaw NULL)</option>
                                                    <?php foreach ($dataset['headers'] as $sourceIndex => $header): ?>
                                                        <option value="<?php echo (int)$sourceIndex; ?>" <?php echo $currentValue === (string)$sourceIndex ? 'selected' : ''; ?>><?php echo $esc($header); ?></option>
                                                    <?php endforeach; ?>
                                                <?php elseif ($column === 'opracowujacy'): ?>
                                                    <option value="__AUTO__" <?php echo $currentValue === '__AUTO__' ? 'selected' : ''; ?>>Automatycznie (<?php echo $esc($currentUsername !== '' ? $currentUsername : 'użytkownik z sesji'); ?>)</option>
                                                    <option value="__SKIP__" <?php echo $currentValue === '__SKIP__' ? 'selected' : ''; ?>>Pomiń (zostaw NULL)</option>
                                                    <?php foreach ($dataset['headers'] as $sourceIndex => $header): ?>
                                                        <option value="<?php echo (int)$sourceIndex; ?>" <?php echo $currentValue === (string)$sourceIndex ? 'selected' : ''; ?>><?php echo $esc($header); ?></option>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <option value="__SKIP__" <?php echo $currentValue === '__SKIP__' ? 'selected' : ''; ?>>Pomiń kolumnę</option>
                                                    <?php foreach ($dataset['headers'] as $sourceIndex => $header): ?>
                                                        <option value="<?php echo (int)$sourceIndex; ?>" <?php echo $currentValue === (string)$sourceIndex ? 'selected' : ''; ?>><?php echo $esc($header); ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                        <td class="import-note">
                                            <?php if ($column === 'numer_ewidencyjny'): ?>
                                                Nadawany automatycznie przy imporcie, zgodnie z aktualną numeracją systemu.
                                            <?php elseif ($column === 'data_opracowania'): ?>
                                                Domyślnie bieżąca data/czas. Możesz wskazać kolumnę z pliku.
                                            <?php elseif ($column === 'opracowujacy'): ?>
                                                Domyślnie użytkownik zalogowany w systemie. Możesz wskazać kolumnę z pliku.
                                            <?php else: ?>
                                                Wybierz kolumnę z pliku lub pomiń, jeśli pole ma pozostać puste.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="import-actions">
                        <button type="submit" onclick="document.getElementById('mappingActionField').value='preview_mapping';">Odśwież podgląd</button>
                        <button type="submit" class="primary" onclick="document.getElementById('mappingActionField').value='execute_import'; return confirm('Importować dane do bazy dla kolekcji: <?php echo $esc($collections[$selectedCollection]['label']); ?>?');" formaction="inventory_import.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>" formmethod="post">Importuj do bazy</button>
                        <a href="inventory_import.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>">Wczytaj inny plik</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
