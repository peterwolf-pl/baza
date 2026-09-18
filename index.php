<?php
session_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/auth.php';
$username = $_SESSION['username'] ?? '';
$canFullDatabaseView = userCan('full_view');
$canEditLists = userCan('edit_lists');

// Zsynchronizuj wybór księgi przed cache HIT. Inaczej przy cache trafieniu db.php się nie wykona
// i sesja może zostać na poprzedniej księdze.
$requestedLedgerForSession = isset($_GET['ledger']) ? (string)$_GET['ledger'] : '';
if ($requestedLedgerForSession !== '' && preg_match('/^[a-z0-9_-]{1,64}$/i', $requestedLedgerForSession) === 1) {
    $_SESSION['selected_ledger'] = strtolower($requestedLedgerForSession);
}

$isIndexPageRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['action']);
$indexCacheFile = null;
$indexCacheTtlSeconds = 30;

if ($isIndexPageRequest) {
    $indexCacheDir = __DIR__ . '/tmp/cache';
    $cacheKeyPayload = [
        'route' => 'index',
        'user' => (int)($_SESSION['user_id'] ?? 0),
        'collection' => (string)($_GET['collection'] ?? 'ksiazki-artystyczne'),
        'ledger' => (string)($_GET['ledger'] ?? ($_SESSION['selected_ledger'] ?? 'depozytowa')),
        'visible_columns' => isset($_SESSION['visible_columns']) && is_array($_SESSION['visible_columns'])
            ? array_values($_SESSION['visible_columns'])
            : [],
        'show_thumbnail_column' => isset($_SESSION['show_thumbnail_column']) ? (bool)$_SESSION['show_thumbnail_column'] : true,
        'thumbnail_size' => isset($_SESSION['thumbnail_size']) ? (int)$_SESSION['thumbnail_size'] : 25,
    ];
    $cacheKeyJson = json_encode($cacheKeyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cacheKeyJson === false) {
        $cacheKeyJson = serialize($cacheKeyPayload);
    }
    $indexCacheFile = $indexCacheDir . '/index_' . hash('sha256', $cacheKeyJson) . '.html';

    if (is_file($indexCacheFile) && (time() - (int)filemtime($indexCacheFile)) <= $indexCacheTtlSeconds) {
        header('Cache-Control: private, max-age=' . $indexCacheTtlSeconds);
        header('Vary: Cookie');
        header('X-Index-Cache: HIT');
        readfile($indexCacheFile);
        exit;
    }

    if (!is_dir($indexCacheDir)) {
        @mkdir($indexCacheDir, 0775, true);
    }
    ob_start();
}


include 'db.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';

function ensureListsCollectionColumn(PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('collection', $columns, true)) {
        $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
    }
}

ensureListsCollectionColumn($pdo);

$collections = [
    'ksiazki-artystyczne' => [
        'label' => 'Książki Artystyczne',
        'main' => 'karta_ewidencyjna',
        'log' => 'karta_ewidencyjna_log',
        'moves' => 'karta_ewidencyjna_przemieszczenia',
    ],
    'kolekcja-maszyn' => [
        'label' => 'Maszyny',
        'main' => 'karta_ewidencyjna_maszyny',
        'log' => 'karta_ewidencyjna_maszyny_log',
        'moves' => 'karta_ewidencyjna_maszyny_przemieszczenia',
    ],
    'kolekcja-matryc' => [
        'label' => 'Matryce',
        'main' => 'karta_ewidencyjna_matryce',
        'log' => 'karta_ewidencyjna_matryce_log',
        'moves' => 'karta_ewidencyjna_matryce_przemieszczenia',
    ],
    'biblioteka' => [
        'label' => 'Biblioteka',
        'main' => 'karta_ewidencyjna_bib',
        'log' => 'karta_ewidencyjna_bib_log',
        'moves' => 'karta_ewidencyjna_bib_przemieszczenia',
    ],
    'kolekcja-klisz' => [
        'label' => 'Klisze drukarskie',
        'main' => 'karta_ewidencyjna_klisze',
        'log' => 'karta_ewidencyjna_klisze_log',
        'moves' => 'karta_ewidencyjna_klisze_przemieszczenia',
    ],
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection]['main'];


function buildImagePaths(?string $rawImageValue, string $collection): array {
    $urls = museumBuildMediaUrls($rawImageValue, $collection, true);
    return [$urls[0] ?? null, $urls[1] ?? null];
}

// AJAX: pobieranie wierszy
if (isset($_GET['action']) && $_GET['action'] === 'fetch_rows') {
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = 30;

    try {
        // Pobierz kolumny
        $columns = [];
        $query = $pdo->query("SHOW COLUMNS FROM {$mainTable}");
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }

        // Pobierz nazwę kolumny klucza głównego (na różnych kolekcjach może się różnić)
        $primaryKeyColumn = null;
        $pkStmt = $pdo->query("SHOW KEYS FROM {$mainTable} WHERE Key_name = 'PRIMARY'");
        while ($pkRow = $pkStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($pkRow['Column_name'])) {
                $primaryKeyColumn = $pkRow['Column_name'];
                break;
            }
        }

        // !!! UWAGA: w MySQL nie używaj bindValue do LIMIT/OFFSET !!!
        if ($primaryKeyColumn !== null) {
            $selectSql = "SELECT *, {$primaryKeyColumn} AS __row_id FROM {$mainTable}";
        } else {
            $selectSql = "SELECT * FROM {$mainTable}";
        }
        $selectSql .= " LIMIT $offset, $limit";

        $stmt = $pdo->query($selectSql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (!$canFullDatabaseView) {
                $row = appFilterPreviewRow($row);
            }
            $thumbnailPaths = museumBuildMediaUrls($row['dokumentacja_wizualna'] ?? null, $selectedCollection, true);
            $row['__thumbnail_url'] = $thumbnailPaths[0] ?? null;
            $row['__thumbnail_fallback_url'] = $thumbnailPaths[1] ?? null;
            $row['__thumbnail_urls'] = $thumbnailPaths;
            $row['__can_full_view'] = $canFullDatabaseView ? 1 : 0;
        }
        unset($row);

        if (!$canFullDatabaseView) {
            $columns = array_values(array_intersect($columns, ['nazwa_tytul', 'autor_wytworca']));
        }

        header('Content-Type: application/json');
        echo json_encode([
            'rows' => $rows,
            'columns' => $columns,
            'primary_key' => $primaryKeyColumn
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}


// AJAX: zapisywanie wybranych kolumn z index.php
if (isset($_GET['action']) && $_GET['action'] === 'save_visible_columns') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $requested = (isset($data['visible_columns']) && is_array($data['visible_columns'])) ? $data['visible_columns'] : [];
    $showThumbnailColumn = isset($data['show_thumbnail_column']) ? (bool)$data['show_thumbnail_column'] : true;
    $thumbnailSize = isset($data['thumbnail_size']) ? (int)$data['thumbnail_size'] : 25;
    $thumbnailSize = max(25, min(111, $thumbnailSize));

    $columns = [];
    $query = $pdo->query("SHOW COLUMNS FROM {$mainTable}");
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    if (!$canFullDatabaseView) {
        $requested = array_values(array_intersect($requested, ['nazwa_tytul', 'autor_wytworca']));
        $showThumbnailColumn = true;
    }
    $selectedColumns = array_values(array_intersect($columns, $requested));
    $_SESSION['visible_columns'] = $selectedColumns;
    $_SESSION['show_thumbnail_column'] = $showThumbnailColumn;
    $_SESSION['thumbnail_size'] = $thumbnailSize;

    echo json_encode(['success' => true]);
    exit;
}

// Pobierz kolumny do headera
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM {$mainTable}");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Pobierz listy (do opcji i headera)
$listStmt = $pdo->prepare("SELECT id, list_name FROM lists WHERE collection = ? ORDER BY list_name");
$listStmt->execute([$selectedCollection]);
$lists = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Domyślne kolumny (wspólne ustawienie między podstronami)
$defaultVisibleColumns = $canFullDatabaseView
    ? ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca']
    : ['nazwa_tytul', 'autor_wytworca'];
$selectedColumns = isset($_SESSION['visible_columns']) && is_array($_SESSION['visible_columns'])
    ? array_values(array_intersect($columns, $_SESSION['visible_columns']))
    : $defaultVisibleColumns;
$selectedColumns = $canFullDatabaseView
    ? $selectedColumns
    : array_values(array_intersect($selectedColumns, ['nazwa_tytul', 'autor_wytworca']));
$showThumbnailColumn = $_SESSION['show_thumbnail_column'] ?? true;
$showThumbnailColumn = $canFullDatabaseView ? $showThumbnailColumn : true;
$thumbnailSize = isset($_SESSION['thumbnail_size']) ? max(25, min(111, (int)$_SESSION['thumbnail_size'])) : 25;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl</title>
        <link rel="stylesheet" href="styles.css">
    <style>:root { --thumbnail-height: <?php echo (int)$thumbnailSize; ?>px; }</style>
</head>
<body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => $lists,
        'username' => $username,
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'showColumnButton' => true,
        'showBulkBar' => true,
        'primaryActions' => [
            ['label' => 'Nowy Wpis', 'href' => 'neww.php?collection=' . rawurlencode($selectedCollection)],
            ['label' => 'Fast Mobile Adder', 'href' => 'mobile_add.php?collection=' . rawurlencode($selectedCollection)],
            ['label' => 'Szukaj', 'href' => 'search.php?collection=' . rawurlencode($selectedCollection)],
        ],
    ]);
    ?>
    <div id="columnSelectorContainer" class="column-selector">
        <label class="thumbnail-size-control" for="thumbnailSizeSlider">
            Rozmiar miniatury
            <input type="range" id="thumbnailSizeSlider" min="25" max="111" value="<?php echo (int)$thumbnailSize; ?>" oninput="updateThumbnailSize(this.value)">
            <span id="thumbnailSizeValue"><?php echo (int)$thumbnailSize; ?>px</span>
        </label>
        <label>
            <input type="checkbox" id="thumbnailColumnCheckbox" onclick="toggleThumbnailColumn()" <?php echo $showThumbnailColumn ? 'checked' : ''; ?>>
            Miniatura foto
        </label>
        <?php foreach ($columns as $col): ?>
            <label>
                <input type="checkbox" class="column-checkbox" value="<?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>" 
                       onclick="toggleColumn('<?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>')" 
                       <?php echo in_array($col, $selectedColumns) ? 'checked' : ''; ?>>
                <?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="data-table">
        <table id="dataTable">
            <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" class="select-all" onclick="selectAllInTable(this, '#dataTable')">
                    </th>
                    <th id="thumbnailHeader" class="thumbnail-col" style="display: <?php echo $showThumbnailColumn ? "" : "none"; ?>;">Miniatura foto</th>
                    <?php foreach ($columns as $col): ?>
                        <th class="<?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>" 
                            style="display: <?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>;">
                            <?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>
                        </th>
                    <?php endforeach; ?>
                    <th>Opcje</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            </tbody>
        </table>
    </div>
    <?php
    $footerShowThemeToggle = true;
    $footerShowLoadCacheButton = true;
    $footerShowInfoButton = true;
    include __DIR__ . '/footer.php';
    ?>
<script>
// przekazanie PHP -> JS dla opcji list
const phpLists = <?php echo json_encode($lists); ?>;
const selectedCollection = <?php echo json_encode($selectedCollection); ?>;
const selectedLedger = <?php echo json_encode((string)($GLOBALS['app_selected_ledger'] ?? 'depozytowa')); ?>;
const canFullDatabaseView = <?php echo json_encode((bool)$canFullDatabaseView); ?>;
const canEditLists = <?php echo json_encode((bool)$canEditLists); ?>;

// Kolumny widoczne na start
const defaultVisibleColumns = <?php echo json_encode($selectedColumns); ?>;
let showThumbnailColumn = <?php echo json_encode((bool)$showThumbnailColumn); ?>;
let thumbnailSizePx = <?php echo (int)$thumbnailSize; ?>;
let saveColumnsTimeout = null;
const selectedIds = new Set();
const THEME_STORAGE_KEY = 'index-theme-override';

function applyThemeOverride(theme) {
    const root = document.documentElement;
    const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
    root.classList.remove('theme-light', 'theme-dark');
    root.classList.add(`theme-${normalizedTheme}`);
    root.style.colorScheme = normalizedTheme;
    const button = document.getElementById('themeToggleButton');
    if (button) {
        button.title = normalizedTheme === 'dark'
            ? 'Aktywny tryb: ciemny. Kliknij, aby przełączyć na jasny.'
            : 'Aktywny tryb: jasny. Kliknij, aby przełączyć na ciemny.';
    }
}

function initializeThemeOverride() {
    const storedTheme = localStorage.getItem(THEME_STORAGE_KEY);
    if (storedTheme === 'light' || storedTheme === 'dark') {
        applyThemeOverride(storedTheme);
        return;
    }

    const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyThemeOverride(systemPrefersDark ? 'dark' : 'light');
}

function toggleThemeOverride() {
    const root = document.documentElement;
    const nextTheme = root.classList.contains('theme-dark') ? 'light' : 'dark';
    localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
    applyThemeOverride(nextTheme);
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
function persistVisibleColumns() {
    const visibleColumns = [];
    document.querySelectorAll('input.column-checkbox:checked').forEach(cb => {
        visibleColumns.push(cb.value);
    });

    fetch(`?collection=${encodeURIComponent(selectedCollection)}&ledger=${encodeURIComponent(selectedLedger)}&action=save_visible_columns`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visible_columns: visibleColumns, show_thumbnail_column: showThumbnailColumn, thumbnail_size: thumbnailSizePx })
    }).catch(err => console.error('Błąd zapisu kolumn:', err));
}

function updateThumbnailSize(size) {
    thumbnailSizePx = Math.max(25, Math.min(111, Number(size) || 25));
    document.documentElement.style.setProperty('--thumbnail-height', thumbnailSizePx + 'px');

    const slider = document.getElementById('thumbnailSizeSlider');
    const value = document.getElementById('thumbnailSizeValue');
    if (slider) slider.value = String(thumbnailSizePx);
    if (value) value.textContent = thumbnailSizePx + 'px';

    if (saveColumnsTimeout) clearTimeout(saveColumnsTimeout);
    saveColumnsTimeout = setTimeout(persistVisibleColumns, 150);
}

function toggleThumbnailColumn() {
    const checkbox = document.getElementById('thumbnailColumnCheckbox');
    showThumbnailColumn = checkbox ? checkbox.checked : true;

    const thumbnailHeader = document.getElementById('thumbnailHeader');
    if (thumbnailHeader) {
        thumbnailHeader.style.display = showThumbnailColumn ? '' : 'none';
    }

    document.querySelectorAll('td.thumbnail-col').forEach(cell => {
        cell.style.display = showThumbnailColumn ? '' : 'none';
    });

    if (saveColumnsTimeout) clearTimeout(saveColumnsTimeout);
    saveColumnsTimeout = setTimeout(persistVisibleColumns, 150);
}

function toggleColumn(column) {
    let header = document.querySelector(`th.${column}`);
    let cells = document.querySelectorAll(`td.${column}`);
    let displayStyle = header.style.display === 'none' ? '' : 'none';
    header.style.display = displayStyle;
    cells.forEach(cell => {
        cell.style.display = displayStyle;
    });

    if (saveColumnsTimeout) clearTimeout(saveColumnsTimeout);
    saveColumnsTimeout = setTimeout(persistVisibleColumns, 150);
}

// Dodawanie do list
function handleListSelection(select, entryId) {
    const selectedValue = select.value;
    const normalizedEntryId = Number.parseInt(entryId, 10);

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

    if (selectedValue && (!Number.isInteger(normalizedEntryId) || normalizedEntryId <= 0)) {
        showTemporaryMessage("Wystąpił błąd: nie udało się odczytać ID wpisu.", 'error');
        select.value = '';
        return;
    }

    if (selectedValue === "new") {
        const newListName = prompt("Podaj nazwę nowej listy:");
        if (newListName) {
            fetch('add_list.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: newListName, entry_id: normalizedEntryId, collection: selectedCollection })
            })
            .then(response => response.json())
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
            body: JSON.stringify({ list_id: selectedValue, entry_id: normalizedEntryId, collection: selectedCollection })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showTemporaryMessage("Wpis dodano do listy.");
            } else {
                showTemporaryMessage("Wystąpił błąd: " + data.message, 'error');
            }
        });
    }
}

function toggleRowSelection(cb) {
    const id = cb.dataset.entryId;
    if (!id) return;
    if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
    updateBulkUi();
    syncSelectAllStates();
}

function selectAllInTable(sourceCb, tableSelector) {
    const checks = document.querySelectorAll(`${tableSelector} tbody input.row-select[type="checkbox"]`);
    checks.forEach(cb => {
        cb.checked = sourceCb.checked;
        if (sourceCb.checked) selectedIds.add(cb.dataset.entryId);
        else selectedIds.delete(cb.dataset.entryId);
    });
    updateBulkUi();
    syncSelectAllStates();
}

function selectAllBothTables(masterCb) {
    const allChecks = document.querySelectorAll('table tbody input.row-select[type="checkbox"]');
    allChecks.forEach(cb => {
        cb.checked = masterCb.checked;
        if (masterCb.checked) selectedIds.add(cb.dataset.entryId);
        else selectedIds.delete(cb.dataset.entryId);
    });
    document.querySelectorAll('input.select-all[type="checkbox"]').forEach(cb => cb.checked = masterCb.checked);
    updateBulkUi();
}

function syncSelectAllStates() {
    const tableChecks = document.querySelectorAll('#dataTable tbody input.row-select[type="checkbox"]');
    const tableAll = document.querySelector('#dataTable thead input.select-all');
    const masterAll = document.getElementById('selectAllBoth');
    const allChecked = tableChecks.length > 0 && Array.from(tableChecks).every(cb => cb.checked);
    if (tableAll) tableAll.checked = allChecked;
    if (masterAll) masterAll.checked = allChecked;
}

function getSelectedIds() {
    return Array.from(selectedIds);
}

function clearSelections() {
    selectedIds.clear();
    document.querySelectorAll('input.row-select[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('input.select-all[type="checkbox"]').forEach(cb => cb.checked = false);
    const masterAll = document.getElementById('selectAllBoth');
    if (masterAll) masterAll.checked = false;
    updateBulkUi();
}

function updateBulkUi() {
    const info = document.getElementById('bulkCount');
    if (!info) return;
    const count = selectedIds.size;
    info.textContent = count === 0 ? 'Nic nie zaznaczono' : `Zaznaczono: ${count}`;
}

async function handleBulkAdd(selectEl) {
    const listId = selectEl.value;
    if (!listId) return;

    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Najpierw zaznacz rekordy.');
        selectEl.value = '';
        return;
    }

    if (listId === 'new') {
        const name = prompt('Podaj nazwę nowej listy:');
        if (!name) {
            selectEl.value = '';
            return;
        }

        const firstId = Number.parseInt(ids[0], 10);
        const resp = await fetch('add_list.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, entry_id: firstId, collection: selectedCollection })
        });
        const data = await resp.json();
        if (!data.success || !data.list_id) {
            alert('Nie udało się utworzyć listy: ' + (data.message || 'nieznany błąd'));
            selectEl.value = '';
            return;
        }

        const newListId = data.list_id;
        for (let i = 1; i < ids.length; i += 1) {
            await fetch('add_to_list.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ list_id: newListId, entry_id: Number.parseInt(ids[i], 10), collection: selectedCollection })
            });
        }
        alert(`Utworzono listę i dodano ${ids.length} rekordów.`);
        clearSelections();
        location.reload();
        return;
    }

    let ok = 0;
    for (const id of ids) {
        const resp = await fetch('add_to_list.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ list_id: listId, entry_id: Number.parseInt(id, 10), collection: selectedCollection })
        });
        const data = await resp.json();
        if (data.success) ok += 1;
    }
    alert(`Dodano ${ok}/${ids.length} rekordów do listy.`);
    clearSelections();
    selectEl.value = '';
}

// Infinite scroll
let offset = 0;
const limit = 20;
let loading = false;
let noMoreRows = false;
let loadAndCacheIntervalId = null;
let loadAndCacheInProgress = false;
let loadAndCacheLastOffset = 0;
let loadAndCacheStallCount = 0;

function getLoadCacheButton() {
    return document.getElementById('loadCacheButton');
}

function updateLoadCacheButton(text, disabled) {
    const button = getLoadCacheButton();
    if (!button) return;
    button.textContent = text;
    button.disabled = disabled;
}

function stopLoadAndCache(markAsDone = false) {
    if (loadAndCacheIntervalId !== null) {
        window.clearInterval(loadAndCacheIntervalId);
        loadAndCacheIntervalId = null;
    }
    loadAndCacheInProgress = false;
    updateLoadCacheButton(markAsDone ? 'Cached' : 'Load&Cache', false);
}

function startLoadAndCache() {
    if (loadAndCacheInProgress) return;
    if (noMoreRows) {
        updateLoadCacheButton('Cached', false);
        return;
    }

    loadAndCacheInProgress = true;
    loadAndCacheLastOffset = offset;
    loadAndCacheStallCount = 0;
    updateLoadCacheButton('Ładowanie...', true);
    loadRows();

    loadAndCacheIntervalId = window.setInterval(() => {
        if (noMoreRows) {
            stopLoadAndCache(true);
            return;
        }

        if (loading) {
            return;
        }

        if (offset === loadAndCacheLastOffset) {
            loadAndCacheStallCount += 1;
            if (loadAndCacheStallCount >= 5) {
                stopLoadAndCache(false);
                return;
            }
        } else {
            loadAndCacheLastOffset = offset;
            loadAndCacheStallCount = 0;
        }

        updateLoadCacheButton(`Ładowanie (${offset})`, true);
        loadRows();
    }, 250);
}

// Generuj <option> list na podstawie phpLists
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
}

function getListOptionsHtml() {
    let html = `<option value="">Dodaj do listy</option>
                <option value="new">+ Nowa lista</option>
                <option disabled>──────────</option>`;
    phpLists.forEach(list => {
        html += `<option value="${Number(list.id)}">${escapeHtml(list.list_name)}</option>`;
    });
    return html;
}

function getRowId(row, columns, primaryKeyColumn) {
    if (row['__row_id'] !== undefined && row['__row_id'] !== null && row['__row_id'] !== '') {
        return Number.parseInt(row['__row_id'], 10);
    }

    if (primaryKeyColumn && row[primaryKeyColumn] !== undefined && row[primaryKeyColumn] !== null && row[primaryKeyColumn] !== '') {
        return Number.parseInt(row[primaryKeyColumn], 10);
    }

    const directIdKey = Object.keys(row).find(key => key && key.trim().toLowerCase() === 'id');
    if (directIdKey) {
        return Number.parseInt(row[directIdKey], 10);
    }

    if (Array.isArray(columns) && columns.length > 0) {
        const firstColumn = columns[0];
        if (row[firstColumn] !== undefined && row[firstColumn] !== null && row[firstColumn] !== '') {
            return Number.parseInt(row[firstColumn], 10);
        }
    }

    return NaN;
}



function loadRows() {
    if (loading || noMoreRows) return;
    loading = true;

    fetch(`?collection=${encodeURIComponent(selectedCollection)}&ledger=${encodeURIComponent(selectedLedger)}&action=fetch_rows&offset=${offset}`)
        .then(async response => {
            if (!response.ok) {
                const text = await response.text();
                console.error('Server error:', response.status, text);
                loading = false;
                return null;
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;
            if (data.error) {
                console.error('Fetch error:', data.error);
                loading = false;
                return;
            }
            const tableBody = document.getElementById('tableBody');
            const columns = data.columns;
            const rows = data.rows;
            const primaryKeyColumn = data.primary_key || null;

            if (rows.length === 0) {
                noMoreRows = true;
                return;
            }

            rows.forEach(row => {
                const tr = document.createElement('tr');
                const rowId = getRowId(row, columns, primaryKeyColumn);
                const hasValidRowId = Number.isInteger(rowId) && rowId > 0;
                const entryIdForHandlers = hasValidRowId ? rowId : 0;
                const rowHasFullView = !!Number(row.__can_full_view || 0);
                const kartaHref = hasValidRowId && rowHasFullView
                    ? `karta.php?id=${rowId}&collection=${encodeURIComponent(selectedCollection)}`
                    : '#';

                const tdSelect = document.createElement('td');
                if (hasValidRowId) {
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.className = 'row-select';
                    cb.dataset.entryId = String(rowId);
                    cb.onclick = () => toggleRowSelection(cb);
                    if (selectedIds.has(String(rowId))) {
                        cb.checked = true;
                    }
                    tdSelect.appendChild(cb);
                } else {
                    tdSelect.textContent = '—';
                }
                tr.appendChild(tdSelect);

                const tdThumbnail = document.createElement('td');
                tdThumbnail.classList.add('entry-thumbnail-cell', 'thumbnail-col');
                tdThumbnail.style.display = showThumbnailColumn ? '' : 'none';
                if (row.__thumbnail_url) {
                    const img = document.createElement('img');
                    img.classList.add('entry-thumbnail');
                    img.alt = 'Miniatura wpisu';
                    const thumbUrls = Array.isArray(row.__thumbnail_urls) && row.__thumbnail_urls.length
                        ? row.__thumbnail_urls.filter(Boolean)
                        : [row.__thumbnail_url, row.__thumbnail_fallback_url].filter(Boolean);
                    img.src = thumbUrls[0];
                    if (thumbUrls.length > 1) {
                        img.setAttribute('data-image-fallbacks', JSON.stringify(thumbUrls.slice(1)));
                        img.onerror = function () { museumNextImageFallback(img); };
                    }
                    if (hasValidRowId && rowHasFullView) {
                        const thumbLink = document.createElement('a');
                        thumbLink.href = kartaHref;
                        thumbLink.title = 'Otwórz kartę wpisu';
                        thumbLink.appendChild(img);
                        tdThumbnail.appendChild(thumbLink);
                    } else {
                        tdThumbnail.appendChild(img);
                    }
                } else {
                    tdThumbnail.textContent = '—';
                }
                tr.appendChild(tdThumbnail);

                columns.forEach(col => {
                    const td = document.createElement('td');
                    td.className = col;
                    const th = document.querySelector(`th.${col}`);
                    td.style.display = th && th.style.display === 'none' ? 'none' : '';
                    // null/undefined na pusty string
                    td.textContent = (row[col] === null || row[col] === undefined) ? '' : String(row[col]);
                    tr.appendChild(td);
                });

                // Opcje
                const tdOptions = document.createElement('td');
                tdOptions.width = "222";
                tdOptions.innerHTML = `
                    ${rowHasFullView && hasValidRowId ? `<a role="button" id="toggleButton" href="${kartaHref}">Karta</a>` : `<span class="muted">Podgląd ograniczony</span>`}
                    ${canEditLists ? `<select onchange="handleListSelection(this, ${entryIdForHandlers})">${getListOptionsHtml()}</select>` : ''}
                `;
                tr.appendChild(tdOptions);

                tableBody.appendChild(tr);
            });

            offset += rows.length;
            if (typeof window.resortSortableTableById === 'function') {
                window.resortSortableTableById('dataTable');
            }
            updateBulkUi();
            syncSelectAllStates();
            loading = false;
        })
        .catch(e => {
            console.error(e);
            loading = false;
        });
}

toggleThumbnailColumn();
updateThumbnailSize(thumbnailSizePx);
initializeThemeOverride();
updateBulkUi();

// Ładowanie początkowe
loadRows();

// Nasłuchiwanie przewijania
window.addEventListener('scroll', function() {
    const scrollPosition = window.innerHeight + window.scrollY;
    const threshold = 100;
    if (scrollPosition >= document.body.offsetHeight - threshold && !loading && !noMoreRows) {
        loadRows();
    }
});
</script>
</body>
</html>
<?php
if ($isIndexPageRequest) {
    $pageContent = ob_get_contents();
    header('Cache-Control: private, max-age=' . $indexCacheTtlSeconds);
    header('Vary: Cookie');
    header('X-Index-Cache: MISS');
    if ($pageContent !== false && $indexCacheFile !== null && is_dir(dirname($indexCacheFile))) {
        @file_put_contents($indexCacheFile, $pageContent, LOCK_EX);
    }
    ob_end_flush();
}
?>
