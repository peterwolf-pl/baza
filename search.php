<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';


$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
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

function buildImageUrl(?string $rawImageValue): ?string {
    if ($rawImageValue === null) {
        return null;
    }

    $normalizedImageValue = trim(trim($rawImageValue), " '\"");
    if ($normalizedImageValue === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $normalizedImageValue) === 1) {
        return $normalizedImageValue;
    }

    $relativeImagePath = ltrim($normalizedImageValue, '/');
    $encodedSegments = array_map('rawurlencode', array_filter(explode('/', $relativeImagePath), 'strlen'));

    if (empty($encodedSegments)) {
        return null;
    }

    return 'https://baza.mkal.pl/gfx/' . implode('/', $encodedSegments);
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
        $has_search = true;
    }
}

// Szukanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['query']) && trim((string)$_POST['query']) !== '') {
    $query_string = trim($_POST['query'] ?? '');
    $has_search = true;

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
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $search_state_id = bin2hex(random_bytes(8));
    $_SESSION['search_states'][$search_state_id] = [
        'query_string' => $query_string,
        'search_results' => $search_results,
        'selected_columns' => $selectedColumns,
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
    <script>
        // kolumny widoczności
        let visibleColumns = <?php echo json_encode($selectedColumns); ?>;
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
            document.querySelectorAll('th.data-col').forEach(th => {
                th.style.display = visibleColumns.includes(th.dataset.col) ? '' : 'none';
            });
            document.querySelectorAll('td.data-col').forEach(td => {
                td.style.display = visibleColumns.includes(td.dataset.col) ? '' : 'none';
            });
        }
        window.addEventListener('DOMContentLoaded', updateColumnDisplay);

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
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>

        <div class="header-links">
            <div class="header-low">
            <?php foreach ($lists as $list): ?>
                <a href="list_view.php?list_id=<?php echo $list['id']; ?>&collection=<?php echo urlencode($selectedCollection); ?>"><?php echo htmlspecialchars($list['list_name']); ?></a>
            <?php endforeach; ?>
</div>

            <!-- Masowy dropdown do dodawania do listy oraz globalny zaznacz wszystko -->
            <div class="bulk-bar">
                <label for="bulkList" class="muted">Masowo dodaj do listy</label>
                <select id="bulkList" onchange="handleBulkAdd(this)">
                    <option value="">Wybierz</option>
                    <option value="new">+ Nowa lista</option>
                    <option disabled>──────────</option>
                    <div class="header-low">
                    <?php foreach ($lists as $list): ?>
                        <option value="<?php echo $list['id']; ?>"><?php echo htmlspecialchars($list['list_name']); ?></option>
                    <?php endforeach; ?>
                </div>
                </select>

                <label style="display:inline-flex; align-items:center; gap:6px; margin-left:8px;">
                    <input type="checkbox" id="selectAllBoth" onclick="selectAllBothTables(this)">
                    Zaznacz wszystko
                </label>

                <span id="bulkCount" class="muted">Nic nie zaznaczono</span>
            </div>
        </div>
    </div>

    <a role="button" id="toggleButton" href="index.php?collection=<?php echo urlencode($selectedCollection); ?>">Powrót do strony głównej</a>
    <br><br>

    <!-- Wybór kolumn -->
    <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    <form id="columnSelectorContainer" class="column-selector" method="post" action="" onsubmit="suppressUnloadWarning = true;">
        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
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
        <input type="text" name="query" value="<?php echo htmlspecialchars($query_string); ?>" required>
        <?php foreach ($selectedColumns as $col): ?>
            <input type="hidden" name="visible_columns[]" value="<?php echo $col; ?>">
        <?php endforeach; ?>
        <button type="submit">Szukaj</button>
    </form>
             <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2026
    </div>

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
                            <th>Miniatura foto</th>
                            <?php foreach ($columns as $col): ?>
                                <th class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                    <?php echo htmlspecialchars($col); ?>
                                </th>
                            <?php endforeach; ?>
                            <th>Opcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $row): ?>
                            <?php
                                $idField = isset($row['ID']) ? 'ID' : (isset($row['id']) ? 'id' : $columns[0]);
                                $entryId = $row[$idField];
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="row-select" data-entry-id="<?php echo (int)$entryId; ?>" onclick="toggleRowSelection(this)">
                                </td>
                                <td class="entry-thumbnail-cell">
                                    <?php $thumbnailUrl = buildImageUrl($row['dokumentacja_wizualna'] ?? null); ?>
                                    <?php if ($thumbnailUrl !== null): ?>
                                        <img class="entry-thumbnail" src="<?php echo htmlspecialchars($thumbnailUrl); ?>" alt="Miniatura wpisu">
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
                                    <?php
                                        $kartaHref = 'karta.php?id=' . urlencode((string)$entryId) . '&collection=' . urlencode($selectedCollection);
                                        if ($search_state_id !== '') {
                                            $kartaHref .= '&search_return=' . urlencode('search.php?state=' . $search_state_id);
                                        }
                                    ?>
                                    <a role="button" id="toggleButton" href="<?php echo $kartaHref; ?>">Karta</a>
                                    <select onchange="handleListSelection(this, <?php echo (int)$entryId; ?>)">
                                        <option value="">Dodaj do listy</option>
                                        <option value="new">+ Nowa lista</option>
                                        <option disabled>──────────</option>
                                        <?php foreach ($lists as $list): ?>
                                            <option value="<?php echo $list['id']; ?>"><?php echo htmlspecialchars($list['list_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Brak dokładnych wyników.</p>
            <?php endif; ?>
        </div>

            <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2026
    </div>
        <script>
            updateColumnDisplay();
            highlightQuery("<?php echo htmlspecialchars($query_string); ?>");
        </script>
    <?php endif; ?>
</body>
</html>
