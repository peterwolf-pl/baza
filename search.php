<?php
session_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';


$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
    'kolekcja-klisz' => 'karta_ewidencyjna_klisze',
];

$selectedCollection = $_GET['collection'] ?? ($_POST['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection];

$listColumns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('collection', $listColumns, true)) {
    $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
}

// Dynamicznie pobierz kolumny
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM {$mainTable}");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) { $columns[] = $row['Field']; }

// Pobierz listy
$listsStmt = $pdo->prepare("SELECT id, list_name FROM lists WHERE collection = ? ORDER BY list_name");
$listsStmt->execute([$selectedCollection]);
$lists = $listsStmt->fetchAll(PDO::FETCH_ASSOC);

// Domyślne widoczne kolumny
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];

// Wyniki
$search_results = [];
$query_string = '';
$has_search = false;
$search_state_id = '';
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

// Przechwytywanie wyboru kolumn
$selectedColumns = isset($_SESSION['visible_columns']) && is_array($_SESSION['visible_columns'])
    ? array_values(array_intersect($columns, $_SESSION['visible_columns']))
    : $defaultVisibleColumns;

if (isset($_POST['visible_columns']) && is_array($_POST['visible_columns'])) {
    $selectedColumns = array_values(array_intersect($columns, $_POST['visible_columns']));
    $_SESSION['visible_columns'] = $selectedColumns;
}

/** Utils **/
function asciiFold($s) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t === false) { $t = $s; }
    $t = preg_replace('/\s+/', ' ', $t);
    return $t;
}
function normalizeForLike($text) {
    $t = trim($text);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = mb_strtolower($t, 'UTF-8');
    return $t;
}
function normalizeText($text) {
    $t = trim($text);
    $t = mb_strtolower($t, 'UTF-8');
    $t = asciiFold($t);
    return $t;
}
function normalizeSearchQuery($text) {
    $t = normalizeText($text);
    $t = preg_replace('/[^a-z0-9\s]+/', ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

function buildThumbPath(string $encodedPath): string {
    return 'thumbs/' . ltrim($encodedPath, '/');
}

function buildImagePaths(?string $rawImageValue, string $collection): array {
    $normalizedImageValue = museumNormalizeImageReference($rawImageValue);
    if ($normalizedImageValue === null) {
        return [null, null];
    }

    if (preg_match('#^https?://#i', $normalizedImageValue) === 1) {
        return [$normalizedImageValue, null];
    }

    $relativeImagePath = ltrim($normalizedImageValue, '/');
    $encodedSegments = array_map('rawurlencode', array_filter(explode('/', $relativeImagePath), 'strlen'));

    if (empty($encodedSegments)) {
        return [null, null];
    }

    $encodedPath = implode('/', $encodedSegments);
    $thumbPath = buildThumbPath($encodedPath);

    if ($collection === 'ksiazki-artystyczne') {
        return [
            'https://mkalodz.pl/bazagfx/' . $thumbPath,
            'https://baza.mkal.pl/gfx/' . $thumbPath,
        ];
    }

    return [
        'https://baza.mkal.pl/gfx/' . $thumbPath,
        'https://mkalodz.pl/bazagfx/' . $thumbPath,
    ];
}
function sqlFoldExpr($field) {
    $expr = "LOWER(CAST($field AS CHAR))";
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z'
    ];
    foreach ($map as $from => $to) {
        $expr = "REPLACE($expr, '$from', '$to')";
    }
    return $expr;
}

function fetchGlobalInventoryMatches(PDO $pdo, array $collections, string $queryString): array {
    $needle = trim($queryString);
    if ($needle === '') {
        return [];
    }

    $results = [];
    foreach ($collections as $collectionKey => $tableName) {
        $stmt = $pdo->prepare("SELECT * FROM {$tableName} WHERE TRIM(CAST(numer_ewidencyjny AS CHAR)) = :inventory LIMIT 20");
        $stmt->execute(['inventory' => $needle]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['__collection_key'] = $collectionKey;
            $results[] = $row;
        }
    }

    return $results;
}
if (!isset($_SESSION['search_states']) || !is_array($_SESSION['search_states'])) {
    $_SESSION['search_states'] = [];
}

if (isset($_GET['state'])) {
    $search_state_id = (string)$_GET['state'];
    if (isset($_SESSION['search_states'][$search_state_id])) {
        $state = $_SESSION['search_states'][$search_state_id];
        $query_string = (string)($state['query_string'] ?? '');
        $search_results = is_array($state['search_results'] ?? null) ? $state['search_results'] : [];
        $state_columns = is_array($state['selected_columns'] ?? null) ? $state['selected_columns'] : [];
        if (!empty($state_columns)) {
            $selectedColumns = array_values(array_intersect($columns, $state_columns));
        }
        if (array_key_exists('show_thumbnail_column', $state)) {
            $showThumbnailColumn = (bool)$state['show_thumbnail_column'];
        }
        if (array_key_exists('thumbnail_size', $state)) {
            $thumbnailSize = max(25, min(111, (int)$state['thumbnail_size']));
        }
        $has_search = true;
    }
}

// Szukanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['query']) && trim((string)$_POST['query']) !== '') {
    $query_string = trim($_POST['query'] ?? '');
    $has_search = true;
    $inventoryLookupValue = museumNormalizeInventoryLookupValue($query_string);

    $q_like = normalizeForLike($query_string);
    $q_fold = normalizeSearchQuery($query_string);
    $q_tokens = array_values(array_filter(explode(' ', $q_fold)));

    // Dokładne LIKE w całej tabeli
    $where = [];
    $params = ['query' => '%' . $q_like . '%', 'query_fold' => '%' . $q_fold . '%'];
    foreach ($columns as $col) {
        $where[] = "LOWER(CAST($col AS CHAR)) LIKE :query";
        $where[] = sqlFoldExpr($col) . " LIKE :query_fold";
    }

    $tokIdx = 0;
    foreach ($q_tokens as $tok) {
        if (mb_strlen($tok, 'UTF-8') < 3) continue;
        $key = 'tok_' . $tokIdx++;
        $params[$key] = '%' . $tok . '%';
        foreach ($columns as $col) {
            $where[] = sqlFoldExpr($col) . " LIKE :$key";
        }
    }

    $where_clause = implode(' OR ', $where);
    $stmt = $pdo->prepare("SELECT * FROM {$mainTable} WHERE $where_clause LIMIT 100");
    $stmt->execute($params);
    $search_results = [];
    $seen = [];

    foreach (fetchGlobalInventoryMatches($pdo, $collections, $inventoryLookupValue) as $row) {
        $rowCollection = (string)($row['__collection_key'] ?? $selectedCollection);
        $idField = array_key_exists('ID', $row) ? 'ID' : (array_key_exists('id', $row) ? 'id' : null);
        $rowId = $idField !== null ? (string)($row[$idField] ?? '') : '';
        $dedupeKey = $rowCollection . ':' . $rowId;
        if ($dedupeKey === ':' || isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $search_results[] = $row;
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['__collection_key'] = $selectedCollection;
        $idField = array_key_exists('ID', $row) ? 'ID' : (array_key_exists('id', $row) ? 'id' : null);
        $rowId = $idField !== null ? (string)($row[$idField] ?? '') : '';
        $dedupeKey = $selectedCollection . ':' . $rowId;
        if ($dedupeKey === ':' || isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $search_results[] = $row;
    }

    $search_state_id = bin2hex(random_bytes(8));
    $_SESSION['search_states'][$search_state_id] = [
        'query_string' => $query_string,
        'search_results' => $search_results,
        'selected_columns' => $selectedColumns,
        'show_thumbnail_column' => $showThumbnailColumn,
        'thumbnail_size' => $thumbnailSize,
        'created_at' => time()
    ];
    if (count($_SESSION['search_states']) > 20) {
        uasort($_SESSION['search_states'], static function($a, $b) {
            return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
        });
        $_SESSION['search_states'] = array_slice($_SESSION['search_states'], -20, null, true);
    }

    header('Location: search.php?collection=' . urlencode($selectedCollection) . '&state=' . urlencode($search_state_id));
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Wyniki wyszukiwania | baza.mkal.pl</title>
        <link rel="stylesheet" href="styles.css">
    <style>:root { --thumbnail-height: <?php echo (int)$thumbnailSize; ?>px; }</style>
    <script>
        // kolumny widoczności
        let visibleColumns = <?php echo json_encode($selectedColumns); ?>;
        let showThumbnailColumn = <?php echo json_encode((bool)$showThumbnailColumn); ?>;
        let thumbnailSizePx = <?php echo (int)$thumbnailSize; ?>;
        const selectedCollection = <?php echo json_encode($selectedCollection); ?>;

        // globalny zbiór zaznaczeń z obu tabel
        const selectedIds = new Set();
        let suppressUnloadWarning = false;

        function toggleColumnSelector() {
            const c = document.getElementById('columnSelectorContainer');
            const b = document.getElementById('toggleColumndButton');
            const isHidden = window.getComputedStyle(c).display === 'none';
            c.style.display = isHidden ? 'block' : 'none';
            b.textContent = isHidden ? 'Ukryj ustawienia wyświetlania' : 'Wybierz kolumny';
        }
        function toggleColumn(col) {
            const i = visibleColumns.indexOf(col);
            if (i === -1) visibleColumns.push(col); else visibleColumns.splice(i, 1);
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

        function updateThumbnailSize(size) {
            thumbnailSizePx = Math.max(25, Math.min(111, Number(size) || 25));
            document.documentElement.style.setProperty('--thumbnail-height', thumbnailSizePx + 'px');

            const slider = document.getElementById('thumbnailSizeSlider');
            const value = document.getElementById('thumbnailSizeValue');
            if (slider) slider.value = String(thumbnailSizePx);
            if (value) value.textContent = thumbnailSizePx + 'px';
            const hidden = document.getElementById('thumbnailSizeHidden');
            if (hidden) hidden.value = String(thumbnailSizePx);
        }
        window.addEventListener('DOMContentLoaded', () => {
            updateColumnDisplay();
            updateThumbnailSize(thumbnailSizePx);
        });

        function toast(msg, type='success'){
            const el = document.createElement('div');
            el.textContent = msg;
            el.style.position='fixed'; el.style.top='20px'; el.style.right='20px';
            el.style.padding='10px 20px'; el.style.borderRadius='5px'; el.style.color='white';
            el.style.backgroundColor = type==='success' ? 'green' : 'red';
            el.style.zIndex='1000'; document.body.appendChild(el);
            setTimeout(()=>el.remove(), 1200);
        }

        // checkboxy wierszy
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
                if (sourceCb.checked) { selectedIds.add(cb.dataset.entryId); }
                else { selectedIds.delete(cb.dataset.entryId); }
            });
            updateBulkUi();
            syncSelectAllStates();
        }
        // globalny zaznacz wszystko
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
        // spójność stanów select all
        function syncSelectAllStates() {
            const exactChecks = document.querySelectorAll('#tableExact tbody input.row-select[type="checkbox"]');
            const exactAll = document.querySelector('#tableExact thead input.select-all');
            const masterAll = document.querySelector('#selectAllBoth');

            const allExactChecked = exactChecks.length > 0 && Array.from(exactChecks).every(cb => cb.checked);
            const allCheckedGlobal = exactChecks.length > 0 &&
                                     Array.from(document.querySelectorAll('table tbody input.row-select')).every(cb => cb.checked);

            if (exactAll) exactAll.checked = allExactChecked;
            if (masterAll) masterAll.checked = allCheckedGlobal;
        }

        function getSelectedIds() { return Array.from(selectedIds); }

        function clearSelections() {
            selectedIds.clear();
            document.querySelectorAll('input.row-select[type="checkbox"]').forEach(cb => cb.checked = false);
            document.querySelectorAll('input.select-all[type="checkbox"]').forEach(cb => cb.checked = false);
            const masterAll = document.getElementById('selectAllBoth');
            if (masterAll) masterAll.checked = false;
            updateBulkUi();
        }

        function updateBulkUi() {
            const cnt = selectedIds.size;
            const info = document.getElementById('bulkCount');
            if (info) info.textContent = cnt > 0 ? `Zaznaczone: ${cnt}` : 'Nic nie zaznaczono';
        }

        // ostrzeżenie przy opuszczaniu jeśli coś zaznaczone
        window.addEventListener('beforeunload', function (e) {
            if (suppressUnloadWarning) return;
            if (selectedIds.size > 0) { e.preventDefault(); e.returnValue = ''; }
        });

        // masowe dodawanie do listy
        async function handleBulkAdd(selectEl) {
            const val = selectEl.value;
            if (!val) return;
            const ids = getSelectedIds();
            if (ids.length === 0) {
                toast('Najpierw zaznacz wpisy', 'error');
                selectEl.value = '';
                return;
            }

            suppressUnloadWarning = true;

            let listId = val;
            if (val === 'new') {
                const newListName = prompt('Podaj nazwę nowej listy:');
                if (!newListName) { selectEl.value = ''; suppressUnloadWarning = false; return; }
                try {
                    const res = await fetch('add_list.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: newListName, collection: selectedCollection })
                    });
                    const data = await res.json();
                    if (!data.success || !data.id) {
                        toast('Błąd tworzenia listy', 'error');
                        selectEl.value = '';
                        suppressUnloadWarning = false;
                        return;
                    }
                    listId = data.id;
                } catch (e) {
                    toast('Błąd sieci przy tworzeniu listy', 'error');
                    selectEl.value = '';
                    suppressUnloadWarning = false;
                    return;
                }
            }

            try {
                await Promise.all(ids.map(id =>
                    fetch('add_to_list.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ list_id: listId, entry_id: id, collection: selectedCollection })
                    }).then(r => r.json())
                ));
                toast('Dodano zaznaczone wpisy do listy');
                clearSelections(); // zeruj zaznaczenia po sukcesie
            } catch (e) {
                toast('Błąd podczas dodawania do listy', 'error');
            } finally {
                selectEl.value = '';
                suppressUnloadWarning = false;
            }
        }

        // pojedynczy dropdown w wierszu
        function handleListSelection(select, entryId) {
            const selectedValue = select.value;
            if (!selectedValue) return;

            function showTemporaryMessage(message, type = 'success') { toast(message, type); }

            if (selectedValue === "new") {
                const newListName = prompt("Podaj nazwę nowej listy:");
                if (newListName) {
                    fetch('add_list.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: newListName, entry_id: entryId, collection: selectedCollection })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showTemporaryMessage("Lista została utworzona i wpis dodano do listy.");
                            location.reload();
                        } else {
                            showTemporaryMessage("Wystąpił błąd: " + data.message, 'error');
                        }
                    }).finally(()=>{ select.value = ''; });
                } else {
                    select.value = '';
                }
            } else {
                fetch('add_to_list.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ list_id: selectedValue, entry_id: entryId, collection: selectedCollection })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showTemporaryMessage("Wpis dodano do listy.");
                    } else {
                        showTemporaryMessage("Wystąpił błąd: " + data.message, 'error');
                    }
                }).finally(() => { select.value = ''; });
            }
        }

        // highlight w komórkach danych
        function highlightQuery(query) {
            if (!query || query.length < 2) return;
            const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const rx = new RegExp('(' + escaped + ')', 'gi');
            document.querySelectorAll('.exact tbody td.data-col').forEach(td => {
                td.innerHTML = td.textContent.replace(rx, `<span class="highlight">$1</span>`);
            });
        }
    </script>
</head>
<body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => $lists,
        'username' => $_SESSION['username'] ?? '',
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'showColumnButton' => true,
        'showBulkBar' => true,
        'primaryActions' => [
            ['label' => 'Powrót do strony głównej', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
        ],
    ]);
    ?>

    <br>

    <!-- Wybór kolumn -->
    <form id="columnSelectorContainer" class="column-selector" method="post" action="" onsubmit="suppressUnloadWarning = true;">
        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
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
        <!-- Ukryte pola aby nie zgubić zapytania po zmianie kolumn -->
        <input type="hidden" name="query" value="<?php echo htmlspecialchars($query_string); ?>">
        <button type="submit">Zastosuj kolumny</button>
    </form>

    <h2>Wyszukiwanie</h2>
    <form method="post" onsubmit="suppressUnloadWarning = true;">
        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
        <input type="hidden" name="show_thumbnail_column" value="<?php echo $showThumbnailColumn ? '1' : '0'; ?>">
        <input type="hidden" name="thumbnail_size" value="<?php echo (int)$thumbnailSize; ?>" id="thumbnailSizeHidden">
        <input type="text" name="query" value="<?php echo htmlspecialchars($query_string); ?>" required>
        <?php foreach ($selectedColumns as $col): ?>
            <input type="hidden" name="visible_columns[]" value="<?php echo $col; ?>">
        <?php endforeach; ?>
        <button type="submit">Szukaj</button>
        <label class="thumbnail-size-control" for="thumbnailSizeSlider">
            <input type="range" id="thumbnailSizeSlider" min="25" max="111" value="<?php echo (int)$thumbnailSize; ?>" oninput="updateThumbnailSize(this.value)">
            <span id="thumbnailSizeValue"><?php echo (int)$thumbnailSize; ?>px</span>
        </label>
    </form>
    <?php include __DIR__ . '/footer.php'; ?>

    <?php if ($has_search): ?>
        <div class="data-table exact">
            <?php if (!empty($search_results)): ?>
                <h3>Dokładne wyniki</h3>
                <table id="tableExact">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="select-all" onclick="selectAllInTable(this, '#tableExact')">
                            </th>
                            <th class="thumbnail-col" style="display:<?php echo $showThumbnailColumn ? "" : "none"; ?>">Miniatura foto</th>
                            <?php foreach ($columns as $col): ?>
                                <th class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                    <?php echo htmlspecialchars($col); ?>
                                </th>
                            <?php endforeach; ?>
                            <th>Kolekcja</th>
                            <th>Opcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $row): ?>
                            <?php
                                $idField = isset($row['ID']) ? 'ID' : (isset($row['id']) ? 'id' : $columns[0]);
                                $entryId = $row[$idField];
                                $rowCollection = (string)($row['__collection_key'] ?? $selectedCollection);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="row-select" data-entry-id="<?php echo (int)$entryId; ?>" onclick="toggleRowSelection(this)" <?php echo $rowCollection === $selectedCollection ? '' : 'disabled'; ?>>
                                </td>
                                <td class="entry-thumbnail-cell thumbnail-col" style="display:<?php echo $showThumbnailColumn ? "" : "none"; ?>">
                                    <?php [$thumbnailUrl, $thumbnailFallbackUrl] = buildImagePaths($row['dokumentacja_wizualna'] ?? null, $rowCollection); ?>
                                    <?php if ($thumbnailUrl !== null): ?>
                                        <img class="entry-thumbnail" src="<?php echo htmlspecialchars($thumbnailUrl); ?>" alt="Miniatura wpisu"<?php if ($thumbnailFallbackUrl !== null): ?> onerror="if (this.src !== <?php echo json_encode($thumbnailFallbackUrl); ?>) this.src = <?php echo json_encode($thumbnailFallbackUrl); ?>;"<?php endif; ?>>
                                    <?php else: ?>
                                        <span>—</span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($columns as $col): ?>
                                    <td class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                        <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                    </td>
                                <?php endforeach; ?>
                                <?php
                                        $kartaHref = 'karta.php?id=' . urlencode((string)$entryId) . '&collection=' . urlencode($rowCollection);
                                        if ($search_state_id !== '') {
                                            $kartaHref .= '&search_return=' . urlencode('search.php?state=' . $search_state_id);
                                        }
                                    ?>
                                <td class="no-highlight"><?php echo htmlspecialchars($rowCollection); ?></td>
                                <td class="no-highlight">
                                    <a role="button" id="toggleButton" href="<?php echo $kartaHref; ?>">Karta</a>
                                    <?php if ($rowCollection === $selectedCollection): ?>
                                    <select onchange="handleListSelection(this, <?php echo (int)$entryId; ?>)">
                                        <option value="">Dodaj do listy</option>
                                        <option value="new">+ Nowa lista</option>
                                        <option disabled>──────────</option>
                                        <?php foreach ($lists as $list): ?>
                                            <option value="<?php echo $list['id']; ?>"><?php echo htmlspecialchars($list['list_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                    <span style="margin-left:8px;opacity:.7;">Listy tylko w aktywnej kolekcji</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Brak dokładnych wyników.</p>
            <?php endif; ?>
        </div>

        <?php include __DIR__ . '/footer.php'; ?>
        <script>
            updateColumnDisplay();
            highlightQuery("<?php echo htmlspecialchars($query_string); ?>");
        </script>
    <?php endif; ?>
</body>
</html>
