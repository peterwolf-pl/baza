<?php
session_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


$collections = [
    'ksiazki-artystyczne' => [
        'main' => 'karta_ewidencyjna',
        'moves' => 'karta_ewidencyjna_przemieszczenia',
    ],
    'kolekcja-maszyn' => [
        'main' => 'karta_ewidencyjna_maszyny',
        'moves' => 'karta_ewidencyjna_maszyny_przemieszczenia',
    ],
    'kolekcja-matryc' => [
        'main' => 'karta_ewidencyjna_matryce',
        'moves' => 'karta_ewidencyjna_matryce_przemieszczenia',
    ],
    'biblioteka' => [
        'main' => 'karta_ewidencyjna_bib',
        'moves' => 'karta_ewidencyjna_bib_przemieszczenia',
    ],
    'kolekcja-klisz' => [
        'main' => 'karta_ewidencyjna_klisze',
        'moves' => 'karta_ewidencyjna_klisze_przemieszczenia',
    ],
];

$selectedCollection = $_GET['collection'] ?? ($_POST['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection]['main'];
$movesTable = $collections[$selectedCollection]['moves'];

$listColumns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('collection', $listColumns, true)) {
    $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
}

$list_id = $_GET['list_id'] ?? null;
if (!$list_id) {
    echo "Nieprawidłowy identyfikator listy.";
    exit;
}

function ensureMoveUsernameColumn(PDO $pdo, string $movesTable): bool {
    try {
        $moveTableColumns = $pdo->query("SHOW COLUMNS FROM {$movesTable}")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('user_username', $moveTableColumns, true)) {
            $pdo->exec("ALTER TABLE {$movesTable} ADD COLUMN user_username VARCHAR(255) NULL");
        }

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasMoveUsernameColumn = ensureMoveUsernameColumn($pdo, $movesTable);

function buildImagePaths(?string $rawImageValue, string $collection): array {
    $urls = museumBuildMediaUrls($rawImageValue, $collection, true);
    return [$urls[0] ?? null, $urls[1] ?? null];
}


$bulkMoveSuccess = null;
$bulkMoveError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_przemieszczenie'])) {
    if (!userCan('move_records')) {
        $bulkMoveError = 'Brak uprawnień do dodawania przemieszczeń.';
    } else {
    $dataPrzemieszczenia = trim($_POST['data_przemieszczenia'] ?? '');
    $dataZwrotu = trim($_POST['data_zwrotu'] ?? '');
    $miejscePrzemieszczenia = trim($_POST['miejsce_przemieszczenia'] ?? '');
    $powodCelPrzemieszczenia = trim($_POST['powod_cel_przemieszczenia'] ?? '');

    if ($dataPrzemieszczenia === '' || $miejscePrzemieszczenia === '') {
        $bulkMoveError = 'Uzupełnij pola wymagane: data i miejsce przemieszczenia.';
    } else {
        $entryIdsStmt = $pdo->prepare("SELECT DISTINCT entry_id FROM list_items WHERE list_id = ?");
        $entryIdsStmt->execute([$list_id]);
        $entryIds = $entryIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($entryIds)) {
            $bulkMoveError = 'Ta lista nie zawiera pozycji do przemieszczenia.';
        } else {
            try {
                $pdo->beginTransaction();

                $numerPrzemieszczenia = (string)museumNextSequenceValue($pdo, museumMoveSequenceKey($selectedCollection));

                if ($hasMoveUsernameColumn) {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO {$movesTable}
                        (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia, user_username)
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                } else {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO {$movesTable}
                        (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia)
                        VALUES (?, ?, ?, ?, ?, ?)"
                    );
                }

                foreach ($entryIds as $entryId) {
                    $params = [
                        (int)$entryId,
                        $dataPrzemieszczenia,
                        $dataZwrotu !== '' ? $dataZwrotu : null,
                        $numerPrzemieszczenia,
                        $miejscePrzemieszczenia,
                        $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null
                    ];

                    if ($hasMoveUsernameColumn) {
                        $params[] = $_SESSION['username'] ?? null;
                    }

                    $insertStmt->execute($params);
                }

                $pdo->commit();
                $bulkMoveSuccess = 'Dodano przemieszczenie nr ' . $numerPrzemieszczenia . ' do wszystkich pozycji z tej listy.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $bulkMoveError = 'Nie udało się dodać przemieszczeń: ' . $e->getMessage();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $bulkMoveError = 'Nie udało się dodać przemieszczeń: ' . $e->getMessage();
            }
        }
    }
    }
}

// Pobierz nazwy kolumn z tabeli
$columns = [];
$colsStmt = $pdo->query("SHOW COLUMNS FROM {$mainTable}");
while ($row = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Pobierz listy do nagłówka i selecta
$listsStmt = $pdo->prepare("SELECT id, list_name FROM lists WHERE collection = ? ORDER BY list_name");
$listsStmt->execute([$selectedCollection]);
$lists = $listsStmt->fetchAll(PDO::FETCH_ASSOC);

// Domyślne widoczne kolumny
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];
$showThumbnailColumn = $_SESSION['show_thumbnail_column'] ?? true;
$thumbnailSize = isset($_SESSION['thumbnail_size']) ? max(25, min(111, (int)$_SESSION['thumbnail_size'])) : 25;

if (isset($_POST['show_thumbnail_column'])) {
    $showThumbnailColumn = $_POST['show_thumbnail_column'] === '1';
    $_SESSION['show_thumbnail_column'] = $showThumbnailColumn;
}
if (isset($_POST['thumbnail_size'])) {
    $thumbnailSize = max(25, min(111, (int)$_POST['thumbnail_size']));
    $_SESSION['thumbnail_size'] = $thumbnailSize;
}

// Przechwytywanie wyboru kolumn przez POST lub sesję (wspólne między podstronami)
$selectedColumns = isset($_SESSION['visible_columns']) && is_array($_SESSION['visible_columns'])
    ? array_values(array_intersect($columns, $_SESSION['visible_columns']))
    : $defaultVisibleColumns;

if (isset($_POST['visible_columns']) && is_array($_POST['visible_columns'])) {
    $selectedColumns = array_values(array_intersect($columns, $_POST['visible_columns']));
    $_SESSION['visible_columns'] = $selectedColumns;
}

// Pobierz wpisy z danej listy
$stmt = $pdo->prepare("
    SELECT ke.*
    FROM list_items li
    JOIN {$mainTable} ke ON li.entry_id = ke.ID
    WHERE li.list_id = ?
");
$stmt->execute([$list_id]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz nazwę listy
$stmtName = $pdo->prepare("SELECT list_name FROM lists WHERE id = ? AND collection = ?");
$stmtName->execute([$list_id, $selectedCollection]);
$list = $stmtName->fetch(PDO::FETCH_ASSOC);
if (!$list) {
    echo "Lista nie istnieje.";
    exit;
}
$canEditLists = userCan('edit_lists');
$canMoveRecords = userCan('move_records');

$entryIdsForList = array_values(array_unique(array_map(
    static fn(array $entry): int => (int)($entry['ID'] ?? $entry['id'] ?? 0),
    $entries
)));
$entryIdsForList = array_values(array_filter($entryIdsForList, static fn(int $id): bool => $id > 0));

$commonPrzemieszczenia = [];
if (!empty($entryIdsForList)) {
    $placeholders = implode(',', array_fill(0, count($entryIdsForList), '?'));

    if ($hasMoveUsernameColumn) {
        $commonMovesSql = "
            SELECT
                data_przemieszczenia,
                data_zwrotu,
                numer_przemieszczenia,
                miejsce_przemieszczenia,
                powod_cel_przemieszczenia,
                user_username,
                COUNT(DISTINCT karta_id) AS karta_count
            FROM {$movesTable}
            WHERE karta_id IN ($placeholders)
            GROUP BY data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia, user_username
            HAVING karta_count = ?
            ORDER BY CAST(numer_przemieszczenia AS UNSIGNED) DESC, data_przemieszczenia DESC
        ";
    } else {
        $commonMovesSql = "
            SELECT
                data_przemieszczenia,
                data_zwrotu,
                numer_przemieszczenia,
                miejsce_przemieszczenia,
                powod_cel_przemieszczenia,
                COUNT(DISTINCT karta_id) AS karta_count
            FROM {$movesTable}
            WHERE karta_id IN ($placeholders)
            GROUP BY data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia
            HAVING karta_count = ?
            ORDER BY CAST(numer_przemieszczenia AS UNSIGNED) DESC, data_przemieszczenia DESC
        ";
    }

    $commonMovesStmt = $pdo->prepare($commonMovesSql);
    $commonMovesStmt->execute(array_merge($entryIdsForList, [count($entryIdsForList)]));
    $commonPrzemieszczenia = $commonMovesStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl - Lista: <?php echo htmlspecialchars($list['list_name']); ?></title>
        <link rel="stylesheet" href="styles.css">
    <style>:root { --thumbnail-height: <?php echo (int)$thumbnailSize; ?>px; }</style>
    <script>
        // przekazujemy wybrane kolumny do JS
        let visibleColumns = <?php echo json_encode($selectedColumns); ?>;
        let showThumbnailColumn = <?php echo json_encode((bool)$showThumbnailColumn); ?>;
        const selectedCollection = <?php echo json_encode($selectedCollection); ?>;
        const currentListId = <?php echo json_encode((int)$list_id); ?>;
        const canEditLists = <?php echo json_encode($canEditLists); ?>;
        let scannerMode = null;
        let scannerBusy = false;
        let scannerStatusTimerId = null;
        const scannedRowsByInventory = new Map();
        const scannerMissingRowsByInventory = new Map();
        let scannerOriginalFaviconHref = null;
        const scannerModeSessionKey = `scanner-mode:${selectedCollection}:${currentListId}`;
        const scannerPresenceSessionKey = `scanner-presence:${selectedCollection}:${currentListId}`;
        const scannerPresenceState = {
            presentInventoryNumbers: new Set(),
            missingInventoryScanCounts: new Map(),
            lastScanStatus: null
        };
        const scannerFeedbackAudioSources = {
            success: 'audio/ok.mp3',
            error: 'audio/no.mp3'
        };
        const scannerFeedbackAudioCache = {
            success: null,
            error: null
        };

        function tryDecodeInventoryNumberFromEan(value) {
            const candidate = String(value ?? '').replace(/\s+/g, '').trim();
            if (!/^\d{13}$/.test(candidate)) {
                return null;
            }

            let sum = 0;
            for (let i = 0; i < 12; i += 1) {
                const digit = Number.parseInt(candidate.charAt(i), 10);
                const position = i + 1;
                sum += position % 2 === 0 ? digit * 3 : digit;
            }
            const expectedChecksum = (10 - (sum % 10)) % 10;
            const actualChecksum = Number.parseInt(candidate.charAt(12), 10);
            if (expectedChecksum !== actualChecksum) {
                return null;
            }

            const inventoryDigits = candidate.slice(3, 12);
            const normalized = inventoryDigits.replace(/^0+/, '');
            return normalized === '' ? '0' : normalized;
        }

        function normalizeInventoryNumber(value) {
            const trimmed = String(value ?? '').trim();
            if (trimmed === '') {
                return '';
            }

            return tryDecodeInventoryNumberFromEan(trimmed) ?? trimmed;
        }

        function buildCardUrl(entryId) {
            const normalizedEntryId = Number.parseInt(String(entryId ?? ''), 10);
            if (!Number.isInteger(normalizedEntryId) || normalizedEntryId <= 0) {
                return null;
            }

            return `karta.php?id=${encodeURIComponent(String(normalizedEntryId))}&collection=${encodeURIComponent(selectedCollection)}`;
        }

        function focusScannerInput(defer = false) {
            const scannerInput = document.getElementById('scannerInput');
            if (!scannerInput || scannerInput.disabled) {
                return;
            }

            const applyFocus = () => {
                scannerInput.focus();
            };

            applyFocus();

            if (defer) {
                window.setTimeout(applyFocus, 0);
            }
        }

        function getScannerFeedbackAudio(type) {
            if (type !== 'success' && type !== 'error') {
                return null;
            }
            if (scannerFeedbackAudioCache[type] !== null) {
                return scannerFeedbackAudioCache[type];
            }

            const source = scannerFeedbackAudioSources[type];
            const audio = new Audio(source);
            audio.preload = 'auto';
            scannerFeedbackAudioCache[type] = audio;
            return audio;
        }

        function playScannerFeedback(type) {
            const audio = getScannerFeedbackAudio(type);
            if (!audio) {
                return;
            }

            audio.currentTime = 0;
            const playbackPromise = audio.play();
            if (playbackPromise && typeof playbackPromise.catch === 'function') {
                playbackPromise.catch(() => {});
            }
        }

        function ensureScannerFaviconLink() {
            const existingIcon = document.querySelector('link[rel~="icon"]');
            if (existingIcon) {
                if (scannerOriginalFaviconHref === null) {
                    scannerOriginalFaviconHref = existingIcon.getAttribute('href') ?? '';
                }
                return existingIcon;
            }

            const createdIcon = document.createElement('link');
            createdIcon.rel = 'icon';
            document.head.appendChild(createdIcon);
            if (scannerOriginalFaviconHref === null) {
                scannerOriginalFaviconHref = '';
            }
            return createdIcon;
        }

        function setScannerErrorFavicon() {
            const faviconNode = ensureScannerFaviconLink();
            const iconSvg = `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
                    <rect width="64" height="64" rx="10" fill="#c40000" />
                    <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" font-family="Arial, sans-serif" font-size="46" fill="#ffffff" font-weight="700">X</text>
                </svg>
            `.trim();
            faviconNode.href = `data:image/svg+xml,${encodeURIComponent(iconSvg)}`;
        }

        function restoreScannerFavicon() {
            if (scannerOriginalFaviconHref === null) {
                return;
            }
            const faviconNode = ensureScannerFaviconLink();
            if (scannerOriginalFaviconHref === '') {
                faviconNode.removeAttribute('href');
                return;
            }
            faviconNode.href = scannerOriginalFaviconHref;
        }

        function syncScannerModeButtons() {
            const presenceButton = document.getElementById('scannerPresenceButton');
            const addButton = document.getElementById('scannerAddButton');
            const cardButton = document.getElementById('scannerCardButton');

            if (presenceButton) {
                presenceButton.classList.toggle('active', scannerMode === 'presence');
            }
            if (addButton) {
                addButton.classList.toggle('active', scannerMode === 'add');
            }
            if (cardButton) {
                cardButton.classList.toggle('active', scannerMode === 'card');
            }
        }

        function saveScannerModeToSession() {
            try {
                if (scannerMode === null) {
                    window.sessionStorage.removeItem(scannerModeSessionKey);
                    return;
                }
                window.sessionStorage.setItem(scannerModeSessionKey, scannerMode);
            } catch (error) {
                // Brak dostepu do sessionStorage nie powinien zatrzymywac skanera.
            }
        }

        function loadScannerModeFromSession() {
            try {
                const savedMode = window.sessionStorage.getItem(scannerModeSessionKey);
                if (savedMode === 'presence' || savedMode === 'add' || savedMode === 'card') {
                    scannerMode = savedMode;
                }
            } catch (error) {
                // Brak dostepu do sessionStorage nie powinien zatrzymywac skanera.
            }
        }

        function saveScannerPresenceStateToSession() {
            try {
                const serializedState = JSON.stringify({
                    present_inventory_numbers: Array.from(scannerPresenceState.presentInventoryNumbers),
                    missing_inventory_scan_counts: Array.from(scannerPresenceState.missingInventoryScanCounts.entries()),
                    last_scan_status: scannerPresenceState.lastScanStatus
                });
                window.sessionStorage.setItem(scannerPresenceSessionKey, serializedState);
            } catch (error) {
                // Brak dostepu do sessionStorage nie powinien zatrzymywac skanera.
            }
        }

        function loadScannerPresenceStateFromSession() {
            try {
                const serializedState = window.sessionStorage.getItem(scannerPresenceSessionKey);
                if (!serializedState) {
                    return;
                }

                const parsedState = JSON.parse(serializedState);
                scannerPresenceState.presentInventoryNumbers.clear();
                scannerPresenceState.missingInventoryScanCounts.clear();
                scannerPresenceState.lastScanStatus = null;

                if (Array.isArray(parsedState.present_inventory_numbers)) {
                    parsedState.present_inventory_numbers.forEach(value => {
                        const inventoryNumber = normalizeInventoryNumber(value);
                        if (inventoryNumber !== '') {
                            scannerPresenceState.presentInventoryNumbers.add(inventoryNumber);
                        }
                    });
                }

                if (Array.isArray(parsedState.missing_inventory_scan_counts)) {
                    parsedState.missing_inventory_scan_counts.forEach(item => {
                        if (!Array.isArray(item) || item.length < 2) {
                            return;
                        }
                        const inventoryNumber = normalizeInventoryNumber(item[0]);
                        const count = Number.parseInt(String(item[1]), 10);
                        if (inventoryNumber !== '' && Number.isInteger(count) && count > 0) {
                            scannerPresenceState.missingInventoryScanCounts.set(inventoryNumber, count);
                        }
                    });
                }

                const status = parsedState.last_scan_status;
                if (status === 'success' || status === 'error') {
                    scannerPresenceState.lastScanStatus = status;
                }
            } catch (error) {
                // Uszkodzony stan lub brak storage - pomijamy i zaczynamy od zera.
            }
        }

        function showScannerStatus(message, type = 'success') {
            const statusNode = document.getElementById('scannerStatusMessage');
            if (!statusNode) {
                return;
            }

            statusNode.textContent = message;
            statusNode.hidden = message === '';

            if (message === '') {
                statusNode.removeAttribute('data-type');
                return;
            }

            statusNode.dataset.type = type;

            if (scannerStatusTimerId !== null) {
                window.clearTimeout(scannerStatusTimerId);
            }

            scannerStatusTimerId = window.setTimeout(() => {
                statusNode.textContent = '';
                statusNode.hidden = true;
                statusNode.removeAttribute('data-type');
            }, 3200);
        }

        function initScannerRowsIndex() {
            scannedRowsByInventory.clear();

            document.querySelectorAll('tr[data-inventory-number]').forEach(row => {
                const inventoryNumber = normalizeInventoryNumber(row.dataset.inventoryNumber);
                if (!inventoryNumber) {
                    return;
                }

                if (!scannedRowsByInventory.has(inventoryNumber)) {
                    scannedRowsByInventory.set(inventoryNumber, []);
                }
                scannedRowsByInventory.get(inventoryNumber).push(row);
            });
        }

        function markRowsAsPresent(rows) {
            rows.forEach(row => {
                row.classList.add('scanner-present-row');
                const flagNode = row.querySelector('.scanner-present-flag');
                if (flagNode) {
                    flagNode.hidden = false;
                }
            });
        }

        function updateMissingRowContent(row, inventoryNumber, count) {
            const codeNode = row.querySelector('.scanner-missing-code');
            if (codeNode) {
                codeNode.textContent = inventoryNumber;
            }

            const countNode = row.querySelector('.scanner-missing-count');
            if (countNode) {
                countNode.textContent = String(count);
            }
        }

        function buildMissingRow(inventoryNumber) {
            const row = document.createElement('tr');
            row.classList.add('scanner-missing-row');
            row.dataset.inventoryNumber = inventoryNumber;
            row.dataset.scanCount = '1';

            const iconCell = document.createElement('td');
            iconCell.classList.add('scanner-missing-flag-cell');

            const flagNode = document.createElement('span');
            flagNode.classList.add('scanner-missing-flag');
            flagNode.setAttribute('aria-label', 'Pozycja spoza listy');
            flagNode.textContent = 'X';
            iconCell.appendChild(flagNode);

            const codeCell = document.createElement('td');
            codeCell.classList.add('scanner-missing-code');

            const countCell = document.createElement('td');
            countCell.classList.add('scanner-missing-count');

            row.appendChild(iconCell);
            row.appendChild(codeCell);
            row.appendChild(countCell);

            updateMissingRowContent(row, inventoryNumber, 1);
            return row;
        }

        function applyScannerPresenceStateToUi() {
            scannerPresenceState.presentInventoryNumbers.forEach(inventoryNumber => {
                const rows = scannedRowsByInventory.get(inventoryNumber) ?? [];
                if (rows.length > 0) {
                    markRowsAsPresent(rows);
                }
            });

            const container = document.getElementById('scannerUnknownContainer');
            const tableBody = document.getElementById('scannerUnknownTableBody');
            if (container && tableBody) {
                tableBody.innerHTML = '';
                scannerMissingRowsByInventory.clear();

                scannerPresenceState.missingInventoryScanCounts.forEach((count, inventoryNumber) => {
                    if (scannerPresenceState.presentInventoryNumbers.has(inventoryNumber)) {
                        return;
                    }

                    const newRow = buildMissingRow(inventoryNumber);
                    newRow.dataset.scanCount = String(count);
                    updateMissingRowContent(newRow, inventoryNumber, count);
                    scannerMissingRowsByInventory.set(inventoryNumber, newRow);
                    tableBody.appendChild(newRow);
                });

                container.hidden = scannerMissingRowsByInventory.size === 0;
            }

            if (scannerPresenceState.lastScanStatus === 'error') {
                setScannerErrorFavicon();
            } else if (scannerPresenceState.lastScanStatus === 'success') {
                restoreScannerFavicon();
            }
        }

        function markInventoryAsMissing(inventoryNumber) {
            const container = document.getElementById('scannerUnknownContainer');
            const tableBody = document.getElementById('scannerUnknownTableBody');
            if (!container || !tableBody) {
                return;
            }

            const existingRow = scannerMissingRowsByInventory.get(inventoryNumber);
            if (existingRow) {
                const nextCount = (Number.parseInt(existingRow.dataset.scanCount ?? '1', 10) || 1) + 1;
                existingRow.dataset.scanCount = String(nextCount);
                updateMissingRowContent(existingRow, inventoryNumber, nextCount);
                container.hidden = false;
                scannerPresenceState.presentInventoryNumbers.delete(inventoryNumber);
                scannerPresenceState.missingInventoryScanCounts.set(inventoryNumber, nextCount);
                scannerPresenceState.lastScanStatus = 'error';
                saveScannerPresenceStateToSession();
                return;
            }

            const newRow = buildMissingRow(inventoryNumber);
            scannerMissingRowsByInventory.set(inventoryNumber, newRow);
            tableBody.appendChild(newRow);
            container.hidden = false;
            scannerPresenceState.presentInventoryNumbers.delete(inventoryNumber);
            scannerPresenceState.missingInventoryScanCounts.set(inventoryNumber, 1);
            scannerPresenceState.lastScanStatus = 'error';
            saveScannerPresenceStateToSession();
        }

        function clearMissingInventoryMarker(inventoryNumber) {
            const row = scannerMissingRowsByInventory.get(inventoryNumber);
            if (!row) {
                return;
            }
            row.remove();
            scannerMissingRowsByInventory.delete(inventoryNumber);
            scannerPresenceState.missingInventoryScanCounts.delete(inventoryNumber);
            saveScannerPresenceStateToSession();

            const container = document.getElementById('scannerUnknownContainer');
            if (container && scannerMissingRowsByInventory.size === 0) {
                container.hidden = true;
            }
        }

        function setScannerMode(mode) {
            const nextMode = mode === 'add' || mode === 'card' ? mode : 'presence';
            scannerMode = scannerMode === nextMode ? null : nextMode;
            saveScannerModeToSession();
            syncScannerModeButtons();

            if (scannerMode === null) {
                showScannerStatus('Tryb skanera wylaczony.', 'info');
            }

            focusScannerInput(true);
        }

        function handlePresenceScan(inventoryNumber) {
            const rows = scannedRowsByInventory.get(inventoryNumber) ?? [];
            if (rows.length > 0) {
                clearMissingInventoryMarker(inventoryNumber);
                markRowsAsPresent(rows);
                scannerPresenceState.presentInventoryNumbers.add(inventoryNumber);
                scannerPresenceState.missingInventoryScanCounts.delete(inventoryNumber);
                scannerPresenceState.lastScanStatus = 'success';
                saveScannerPresenceStateToSession();
                restoreScannerFavicon();
                showScannerStatus(`Pozycja ${inventoryNumber} jest na liscie i zostala zaznaczona.`, 'success');
                playScannerFeedback('success');
                return;
            }

            markInventoryAsMissing(inventoryNumber);
            setScannerErrorFavicon();
            showScannerStatus(`Pozycja ${inventoryNumber} nie nalezy do tej listy.`, 'error');
            playScannerFeedback('error');
        }

        async function handleAddModeScan(inventoryNumber) {
            if (!canEditLists) {
                showScannerStatus('Brak uprawnien do dodawania pozycji do listy.', 'error');
                return;
            }
            if (scannerBusy) {
                showScannerStatus('Poczekaj na zakonczenie poprzedniego skanu.', 'info');
                return;
            }

            scannerBusy = true;
            showScannerStatus(`Dodawanie pozycji ${inventoryNumber} do listy...`, 'info');

            try {
                const response = await fetch('add_to_list_by_inventory.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        list_id: currentListId,
                        inventory_number: inventoryNumber,
                        collection: selectedCollection
                    })
                });
                const responseData = await response.json();

                if (!response.ok || !responseData.success) {
                    const message = responseData.message || 'Nie udalo sie dodac pozycji do listy.';
                    showScannerStatus(message, 'error');
                    return;
                }

                if (responseData.already_exists) {
                    const rows = scannedRowsByInventory.get(inventoryNumber) ?? [];
                    if (rows.length > 0) {
                        markRowsAsPresent(rows);
                    }
                    showScannerStatus(`Pozycja ${inventoryNumber} juz byla na tej liscie.`, 'info');
                    return;
                }

                showScannerStatus(`Pozycja ${inventoryNumber} zostala dodana. Odswiezanie widoku...`, 'success');
                window.setTimeout(() => {
                    window.location.reload();
                }, 700);
            } catch (error) {
                showScannerStatus('Blad polaczenia. Sprobuj ponownie.', 'error');
            } finally {
                scannerBusy = false;
            }
        }

        async function handleCardModeScan(inventoryNumber) {
            const rows = scannedRowsByInventory.get(inventoryNumber) ?? [];
            const localRow = rows.find(row => Number.parseInt(String(row.dataset.entryId ?? ''), 10) > 0);
            if (localRow) {
                const localCardUrl = buildCardUrl(localRow.dataset.entryId ?? '');
                if (localCardUrl !== null) {
                    showScannerStatus(`Otwieranie karty dla ${inventoryNumber}...`, 'info');
                    window.location.href = localCardUrl;
                    return;
                }
            }

            if (scannerBusy) {
                showScannerStatus('Poczekaj na zakonczenie poprzedniego skanu.', 'info');
                return;
            }

            scannerBusy = true;
            showScannerStatus(`Wyszukiwanie karty dla ${inventoryNumber}...`, 'info');

            try {
                const response = await fetch('find_entry_by_inventory.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        inventory_number: inventoryNumber,
                        collection: selectedCollection
                    })
                });
                const responseData = await response.json();

                if (!response.ok || !responseData.success) {
                    const message = responseData.message || 'Nie znaleziono karty dla zeskanowanego numeru.';
                    showScannerStatus(message, 'error');
                    return;
                }

                const cardUrl = buildCardUrl(responseData.entry_id);
                if (cardUrl === null) {
                    showScannerStatus('Niepoprawny identyfikator karty.', 'error');
                    return;
                }

                showScannerStatus(`Otwieranie karty dla ${inventoryNumber}...`, 'info');
                window.location.href = cardUrl;
            } catch (error) {
                showScannerStatus('Blad polaczenia. Sprobuj ponownie.', 'error');
            } finally {
                scannerBusy = false;
            }
        }

        async function processScannerScan(rawValue) {
            const inventoryNumber = normalizeInventoryNumber(rawValue);
            if (inventoryNumber === '') {
                return;
            }

            if (scannerMode === 'add') {
                await handleAddModeScan(inventoryNumber);
                return;
            }
            if (scannerMode === 'card') {
                await handleCardModeScan(inventoryNumber);
                return;
            }
            if (scannerMode === 'presence') {
                handlePresenceScan(inventoryNumber);
                return;
            }

            showScannerStatus('Najpierw wlacz tryb skanera.', 'info');
        }

        async function handleScannerInputKeydown(event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            const inputNode = event.currentTarget;
            const scannedValue = inputNode.value;
            inputNode.value = '';

            await processScannerScan(scannedValue);
            focusScannerInput(true);
        }

        function toggleColumnSelector() {
            const container = document.getElementById('columnSelectorContainer');
            const button = document.getElementById('toggleColumndButton');
            const isHidden = window.getComputedStyle(container).display === 'none';
            if (isHidden) {
                container.style.display = 'block';
                button.textContent = 'Ukryj ustawienia wyświetlania';
            } else {
                container.style.display = 'none';
                button.textContent = 'Wybierz kolumny';
            }
        }

        function toggleColumn(col) {
            const idx = visibleColumns.indexOf(col);
            if (idx === -1) visibleColumns.push(col);
            else visibleColumns.splice(idx, 1);
            updateColumnDisplay();
        }

        function updateColumnDisplay() {
            document.querySelectorAll('th.thumbnail-col, td.thumbnail-col').forEach(el => {
                el.style.display = showThumbnailColumn ? '' : 'none';
            });
            document.querySelectorAll('th.data-col').forEach(th => {
                th.style.display = visibleColumns.includes(th.dataset.col) ? '' : 'none';
            });
            document.querySelectorAll('td.data-col').forEach(td => {
                td.style.display = visibleColumns.includes(td.dataset.col) ? '' : 'none';
            });
        }

        function toggleBulkPrzemieszczenieForm() {
            const container = document.getElementById('bulkPrzemieszczenieContainer');
            const button = document.getElementById('toggleBulkPrzemieszczenieButton');
            const isHidden = window.getComputedStyle(container).display === 'none';
            if (isHidden) {
                container.style.display = 'block';
                button.textContent = 'Ukryj formularz przemieszczenia listy';
            } else {
                container.style.display = 'none';
                button.textContent = 'Dodaj przemieszczenie całej listy';
            }
        }

        function toggleCommonPrzemieszczenia() {
            const container = document.getElementById('commonPrzemieszczeniaContainer');
            const button = document.getElementById('toggleCommonPrzemieszczeniaButton');
            const isHidden = window.getComputedStyle(container).display === 'none';
            if (isHidden) {
                container.style.display = 'block';
                button.textContent = 'Ukryj wspólne przemieszczenia listy';
            } else {
                container.style.display = 'none';
                button.textContent = 'Wspólne przemieszczenia listy';
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            updateColumnDisplay();
            initScannerRowsIndex();
            loadScannerModeFromSession();
            syncScannerModeButtons();
            loadScannerPresenceStateFromSession();
            applyScannerPresenceStateToUi();
            focusScannerInput();
            const scannerInput = document.getElementById('scannerInput');
            if (scannerInput) {
                scannerInput.addEventListener('keydown', handleScannerInputKeydown);
            }
            const shouldOpenBulkForm = <?php echo json_encode($bulkMoveSuccess !== null || $bulkMoveError !== null); ?>;
            if (shouldOpenBulkForm) {
                const container = document.getElementById('bulkPrzemieszczenieContainer');
                const button = document.getElementById('toggleBulkPrzemieszczenieButton');
                if (container && button) {
                    container.style.display = 'block';
                    button.textContent = 'Ukryj formularz przemieszczenia listy';
                }
            }
        });

        function handleListSelection(select, entryId) {
            const selectedValue = select.value;

            function showTemporaryMessage(message, type = 'success') {
                const messageContainer = document.createElement('div');
                messageContainer.textContent = message;
                messageContainer.style.position = 'fixed';
                messageContainer.style.top = '20px';
                messageContainer.style.right = '20px';
                messageContainer.style.padding = '10px 20px';
                messageContainer.style.borderRadius = '5px';
                messageContainer.style.color = 'white';
                messageContainer.style.backgroundColor = type === 'success' ? 'green' : 'red';
                messageContainer.style.zIndex = '1000';
                document.body.appendChild(messageContainer);
                setTimeout(() => { messageContainer.remove(); }, 1000);
            }

            if (selectedValue === "new") {
                const newListName = prompt("Podaj nazwę nowej listy:");
                if (newListName) {
                    fetch('add_list.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: newListName, entry_id: entryId, collection: selectedCollection })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showTemporaryMessage("Lista została utworzona i wpis dodano do listy.");
                            location.reload();
                        } else {
                            showTemporaryMessage("Wystąpił błąd: " + data.message, 'error');
                        }
                    });
                }
            } else if (selectedValue) {
                fetch('add_to_list.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ list_id: selectedValue, entry_id: entryId, collection: selectedCollection })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showTemporaryMessage("Wpis dodano do listy.");
                    } else {
                        showTemporaryMessage("Wystąpił błąd: " + data.message, 'error');
                    }
                });
            }
        }

        // opcjonalne podświetlanie, tutaj nie ma pola wyszukiwania, więc wyłączone
        function highlightQuery(query, fuzzy = false) {
            if (!query || query.length < 2) return;
            const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const rx = new RegExp('(' + escaped + ')', 'gi');
            const scopeSelector = ".data-table tbody td.data-col";
            document.querySelectorAll(scopeSelector).forEach(td => {
                td.innerHTML = td.textContent.replace(rx, `<span class="${fuzzy ? 'fuzzy-highlight' : 'highlight'}">$1</span>`);
            });
        }
    </script>
</head>
<body>
    <?php
    $scannerHeaderHtml = <<<HTML
        <div class="app-header-scanner app-header-bubble">
            <strong>Skaner:</strong>
            <button type="button" id="scannerPresenceButton" class="scanner-mode-button" onclick="setScannerMode('presence')">spr. obecnosc</button>
            <button type="button" id="scannerAddButton" class="scanner-mode-button" onclick="setScannerMode('add')">dodawanie do listy</button>
            <button type="button" id="scannerCardButton" class="scanner-mode-button" onclick="setScannerMode('card')">Spr. karty</button>
            <input type="text" id="scannerInput" class="scanner-input" placeholder="Zeskanuj numer ewidencyjny i nacisnij Enter" autocomplete="off" spellcheck="false">
            <p id="scannerStatusMessage" class="scanner-status" role="status" aria-live="polite" hidden></p>
        </div>
    HTML;

    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => $lists,
        'username' => $_SESSION['username'] ?? '',
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'showColumnButton' => true,
        'showListEditor' => false,
        'scannerHtml' => $scannerHeaderHtml,
        'primaryActions' => [
            ['label' => 'Wróć do głównej', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
        ],
    ]);
    ?>

    <h1>Lista: <?php echo htmlspecialchars($list['list_name']); ?></h1>
    
    <br><br>

    
   
    <form id="columnSelectorContainer" class="column-selector" method="post" action="">
        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
        <input type="hidden" name="thumbnail_size" value="<?php echo (int)$thumbnailSize; ?>">
        <input type="hidden" name="show_thumbnail_column" value="0">
        <label>
            <input type="checkbox" name="show_thumbnail_column" value="1" onclick="showThumbnailColumn=this.checked;updateColumnDisplay();" <?php echo $showThumbnailColumn ? 'checked' : ''; ?>>
            Miniatura foto
        </label>
        <?php foreach ($columns as $col): ?>
            <label>
                <input type="checkbox" name="visible_columns[]" value="<?php echo $col; ?>"
                       onclick="toggleColumn('<?php echo $col; ?>')"
                       <?php echo in_array($col, $selectedColumns) ? 'checked' : ''; ?>>
                <?php echo $col; ?>
            </label>
        <?php endforeach; ?>
        <button type="submit">Zastosuj kolumny</button>
    </form>

    <div class="data-table">
        <?php if (!empty($entries)): ?>
            <table>
                <thead>
                    <tr>
                        <th class="thumbnail-col" style="display:<?php echo $showThumbnailColumn ? "" : "none"; ?>">Miniatura foto</th>
                        <?php foreach ($columns as $col): ?>
                            <th class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                <?php echo htmlspecialchars($col); ?>
                            </th>
                        <?php endforeach; ?>
                        <th>Opcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $row): ?>
                        <?php
                        $idField = isset($row['ID']) ? 'ID' : (isset($row['id']) ? 'id' : $columns[0]);
                        $entryId = isset($row[$idField]) ? (int)$row[$idField] : 0;
                        $inventoryNumber = trim((string)($row['numer_ewidencyjny'] ?? ''));
                        ?>
                        <tr data-entry-id="<?php echo $entryId; ?>" data-inventory-number="<?php echo htmlspecialchars($inventoryNumber, ENT_QUOTES, 'UTF-8'); ?>">
                            <td class="entry-thumbnail-cell thumbnail-col" style="display:<?php echo $showThumbnailColumn ? "" : "none"; ?>">
                                <?php $thumbnailUrls = museumBuildMediaUrls($row['dokumentacja_wizualna'] ?? null, $selectedCollection, true); ?>
                                <?php if ($thumbnailUrls !== []): ?>
                                    <img class="entry-thumbnail" <?php echo museumImgSrcFallbackAttributes($thumbnailUrls); ?> alt="Miniatura wpisu">
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($columns as $col): ?>
                                <td class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                    <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="no-highlight">
                                <span class="scanner-present-flag" aria-label="Pozycja obecna" hidden>&#10003;</span>
                                <a role="button" id="toggleButton"href="karta.php?id=<?php echo (int)$entryId; ?>&collection=<?php echo urlencode($selectedCollection); ?>">Karta</a>
                                <select onchange="handleListSelection(this, <?php echo (int)$entryId; ?>)">
                                    <option value="">Dodaj do listy</option>
                                    <option value="new">+ Nowa lista</option>
                                    <option disabled>──────────</option>
                                    <?php foreach ($lists as $l): ?>
                                        <option value="<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['list_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="bulk-actions">
                <?php if ($canMoveRecords): ?>
                <button id="toggleBulkPrzemieszczenieButton" type="button" onclick="toggleBulkPrzemieszczenieForm()">Dodaj przemieszczenie całej listy</button>
                <?php endif; ?>
                <button id="toggleCommonPrzemieszczeniaButton" type="button" onclick="toggleCommonPrzemieszczenia()">Wspólne przemieszczenia listy</button>
            </div>
            <div id="scannerUnknownContainer" class="scanner-unknown-container" hidden>
                <h3>Poza listą (zeskanowane numery ewidencyjne)</h3>
                <table class="scanner-unknown-table">
                    <thead>
                        <tr>
                            <th>Oznaczenie</th>
                            <th>Numer ewidencyjny</th>
                            <th>Liczba skanów</th>
                        </tr>
                    </thead>
                    <tbody id="scannerUnknownTableBody"></tbody>
                </table>
            </div>
            <div id="bulkPrzemieszczenieContainer">
                <h3>Nowe przemieszczenie dla całej listy</h3>
                <form method="post" class="bulk-form">
                    <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
                    <input type="hidden" name="add_bulk_przemieszczenie" value="1">

                    <label for="data_przemieszczenia">Data Przemieszczenia</label>
                    <input type="date" name="data_przemieszczenia" id="data_przemieszczenia" required>

                    <label for="data_zwrotu">Data Zwrotu</label>
                    <input type="date" name="data_zwrotu" id="data_zwrotu">

                    <label for="numer_przemieszczenia">Numer Przemieszczenia (nadawany automatycznie)</label>
                    <input type="text" id="numer_przemieszczenia" value="Nadawany przy zapisie" readonly>

                    <label for="miejsce_przemieszczenia">Miejsce Przemieszczenia</label>
                    <input type="text" name="miejsce_przemieszczenia" id="miejsce_przemieszczenia" required>

                    <label for="powod_cel_przemieszczenia">Powód/Cel Przemieszczenia</label>
                    <textarea name="powod_cel_przemieszczenia" id="powod_cel_przemieszczenia"></textarea>

                    <button type="submit">Dodaj</button>
                </form>
            </div>

            <div id="commonPrzemieszczeniaContainer">
                <h3>Wspólne przemieszczenia dla pozycji z tej listy</h3>
                <?php if (!empty($commonPrzemieszczenia)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data Przemieszczenia</th>
                                <th>Data Zwrotu</th>
                                <th>Numer Przemieszczenia</th>
                                <th>Miejsce Przemieszczenia</th>
                                <th>Powód/Cel Przemieszczenia</th>
                                <?php if ($hasMoveUsernameColumn): ?>
                                    <th>Użytkownik</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commonPrzemieszczenia as $przemieszczenie): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($przemieszczenie['data_przemieszczenia'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($przemieszczenie['data_zwrotu'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($przemieszczenie['numer_przemieszczenia'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($przemieszczenie['miejsce_przemieszczenia'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($przemieszczenie['powod_cel_przemieszczenia'] ?? ''); ?></td>
                                    <?php if ($hasMoveUsernameColumn): ?>
                                        <td><?php echo htmlspecialchars($przemieszczenie['user_username'] ?? ''); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Brak wspólnych przemieszczeń dla wszystkich pozycji na tej liście.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Brak wpisów na tej liście.</p>
        <?php endif; ?>

        <?php if ($bulkMoveSuccess): ?>
            <p class="message-success"><?php echo htmlspecialchars($bulkMoveSuccess); ?></p>
        <?php endif; ?>
        <?php if ($bulkMoveError): ?>
            <p class="message-error"><?php echo htmlspecialchars($bulkMoveError); ?></p>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
    <script>
        // odśwież stan kolumn po SSR
        updateColumnDisplay();
        // wywołanie highlightQuery zostawione do ewentualnej integracji
        // highlightQuery(''); 
    </script>
</body>
</html>
