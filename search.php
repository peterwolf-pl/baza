<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

// Dynamicznie pobierz kolumny
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) { $columns[] = $row['Field']; }

// Pobierz listy
$lists = $pdo->query("SELECT id, list_name FROM lists ORDER BY list_name")->fetchAll(PDO::FETCH_ASSOC);

// Domyślne widoczne kolumny
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];

// Wyniki
$search_results = [];
$fuzzy_results = [];
$query_string = '';
$levenshtein_distance = 2;

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
function minLevenshteinSubstr($haystack, $needle) {
    $h = normalizeText($haystack);
    $n = normalizeText($needle);
    $lenN = strlen($n);
    if ($lenN === 0) return 0;
    $lenH = strlen($h);
    if ($lenH === 0) return $lenN;
    $min = PHP_INT_MAX;

    // tokeny alfanumeryczne
    $tokens = preg_split('/[^a-z0-9]+/i', $h, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $tok) {
        $lt = strlen($tok);
        if ($lt === 0) continue;
        if ($lt >= $lenN) {
            for ($i = 0; $i <= $lt - $lenN; $i++) {
                $sub = substr($tok, $i, $lenN);
                $d = levenshtein($sub, $n);
                if ($d < $min) $min = $d;
                if ($min === 0) return 0;
            }
        } else {
            $d = levenshtein($tok, $n);
            if ($d < $min) $min = $d;
        }
    }
    // sliding window
    if ($lenH >= $lenN) {
        for ($i = 0; $i <= $lenH - $lenN; $i++) {
            $sub = substr($h, $i, $lenN);
            $d = levenshtein($sub, $n);
            if ($d < $min) $min = $d;
            if ($min === 0) return 0;
        }
    }
    return $min === PHP_INT_MAX ? $lenN : $min;
}

/** Budowa kandydatów fuzzy z progresywnym prefiksem **/
function fetchFuzzyCandidates(PDO $pdo, array $fields, string $q_like): array {
    $q_like = normalizeForLike($q_like);
    $len = mb_strlen($q_like, 'UTF-8');
    $lengths = [];
    if ($len >= 4) { $lengths = [4,3,2,1]; }
    elseif ($len === 3) { $lengths = [3,2,1]; }
    elseif ($len === 2) { $lengths = [2,1]; }
    else { $lengths = [1]; }

    foreach ($lengths as $L) {
        $prefix = mb_substr($q_like, 0, $L, 'UTF-8');
        $like = ($L >= 3) ? ($prefix . '%') : ('%' . $prefix . '%');

        $conds = [];
        $params = [];
        foreach ($fields as $ff) {
            $conds[] = "LOWER(CAST($ff AS CHAR)) LIKE ?";
            $params[] = $like;
        }
        if (!$conds) { break; }

        $limit = ($L >= 3) ? 1500 : (($L === 2) ? 800 : 300);
        $sql = "SELECT * FROM karta_ewidencyjna WHERE (" . implode(' OR ', $conds) . ") LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) return $rows;
    }

    // fallback
    $hasIdUpper = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (strcasecmp($r['Field'], 'ID') === 0 || strcasecmp($r['Field'], 'id') === 0) { $hasIdUpper = true; break; }
        }
    } catch (\Throwable $e) {}

    if ($hasIdUpper) {
        $sql = "SELECT * FROM karta_ewidencyjna ORDER BY ID DESC LIMIT 500";
    } else {
        $sql = "SELECT * FROM karta_ewidencyjna LIMIT 500";
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Szukanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['query'])) {
    $query_string = trim($_POST['query'] ?? '');
    $levenshtein_distance = isset($_POST['levenshtein_distance']) ? (int)$_POST['levenshtein_distance'] : 2;

    $q_like = normalizeForLike($query_string);

    // Dokładne LIKE w całej tabeli
    $where = [];
    foreach ($columns as $col) { $where[] = "LOWER(CAST($col AS CHAR)) LIKE :query"; }
    $where_clause = implode(' OR ', $where);
    $stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna WHERE $where_clause LIMIT 100");
    $stmt->execute(['query' => '%' . $q_like . '%']);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fuzzy
    $fuzzyFields = ['nazwa_tytul','autor_wytworca','miejsce_powstania','technika_wykonania','material'];

    $candidates = fetchFuzzyCandidates($pdo, $fuzzyFields, $query_string);

    $ranked = [];
    foreach ($candidates as $row) {
        $best = PHP_INT_MAX;
        foreach ($fuzzyFields as $ff) {
            if (!array_key_exists($ff, $row)) continue;
            $val = $row[$ff] ?? '';
            if ($val === '' || $val === null) continue;
            $d = minLevenshteinSubstr($val, $query_string);
            if ($d < $best) $best = $d;
            if ($best === 0) break;
        }
        if ($best <= $levenshtein_distance) { $row['_lev'] = $best; $ranked[] = $row; }
    }

    usort($ranked, function($a, $b){
        $da = $a['_lev'] ?? 9999; $db = $b['_lev'] ?? 9999;
        if ($da === $db) {
            $ida = $a['ID'] ?? $a['id'] ?? 0;
            $idb = $b['ID'] ?? $b['id'] ?? 0;
            return $ida <=> $idb;
        }
        return $da <=> $db;
    });

    foreach ($ranked as $r) {
        unset($r['_lev']);
        $fuzzy_results[] = $r;
        if (count($fuzzy_results) >= 50) break;
    }
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
            const fuzzyChecks = document.querySelectorAll('#tableFuzzy tbody input.row-select[type="checkbox"]');

            const exactAll = document.querySelector('#tableExact thead input.select-all');
            const fuzzyAll = document.querySelector('#tableFuzzy thead input.select-all');
            const masterAll = document.querySelector('#selectAllBoth');

            const allExactChecked = exactChecks.length > 0 && Array.from(exactChecks).every(cb => cb.checked);
            const allFuzzyChecked = fuzzyChecks.length > 0 && Array.from(fuzzyChecks).every(cb => cb.checked);
            const allCheckedGlobal = (exactChecks.length + fuzzyChecks.length) > 0 &&
                                     Array.from(document.querySelectorAll('table tbody input.row-select')).every(cb => cb.checked);

            if (exactAll) exactAll.checked = allExactChecked;
            if (fuzzyAll) fuzzyAll.checked = allFuzzyChecked;
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
                        body: JSON.stringify({ name: newListName })
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
                        body: JSON.stringify({ list_id: listId, entry_id: id })
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
                        body: JSON.stringify({ name: newListName, entry_id: entryId })
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
                    body: JSON.stringify({ list_id: selectedValue, entry_id: entryId })
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
        function highlightQuery(query, fuzzy = false) {
            if (!query || query.length < 2) return;
            const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const rx = new RegExp('(' + escaped + ')', 'gi');
            const scopeSelector = fuzzy ? ".fuzzy tbody td.data-col" : ".exact tbody td.data-col";
            document.querySelectorAll(scopeSelector).forEach(td => {
                td.innerHTML = td.textContent.replace(rx, `<span class="${fuzzy ? 'fuzzy-highlight' : 'highlight'}">$1</span>`);
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
            <?php foreach ($lists as $list): ?>
                <a href="list_view.php?list_id=<?php echo $list['id']; ?>"><?php echo htmlspecialchars($list['list_name']); ?></a>
            <?php endforeach; ?>

            <!-- Masowy dropdown do dodawania do listy oraz globalny zaznacz wszystko -->
            <div class="bulk-bar">
                <label for="bulkList" class="muted">Masowo dodaj do listy</label>
                <select id="bulkList" onchange="handleBulkAdd(this)">
                    <option value="">Wybierz</option>
                    <option value="new">+ Nowa lista</option>
                    <option disabled>──────────</option>
                    <?php foreach ($lists as $list): ?>
                        <option value="<?php echo $list['id']; ?>"><?php echo htmlspecialchars($list['list_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="display:inline-flex; align-items:center; gap:6px; margin-left:8px;">
                    <input type="checkbox" id="selectAllBoth" onclick="selectAllBothTables(this)">
                    Zaznacz wszystko
                </label>

                <span id="bulkCount" class="muted">Nic nie zaznaczono</span>
            </div>
        </div>
    </div>

    <a href="https://baza.mkal.pl">Powrót do strony głównej</a>
    <br><br>

    <!-- Wybór kolumn -->
    <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    <form id="columnSelectorContainer" class="column-selector" method="post" action="" onsubmit="suppressUnloadWarning = true;">
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
        <input type="hidden" name="levenshtein_distance" value="<?php echo (int)$levenshtein_distance; ?>">
        <button type="submit">Zastosuj kolumny</button>
    </form>

    <h2>Wyszukiwanie</h2>
    <form method="post" onsubmit="suppressUnloadWarning = true;">
        <input type="text" name="query" value="<?php echo htmlspecialchars($query_string); ?>" required>
        <label>
            Odległość Levenshteina:
            <input type="range" name="levenshtein_distance" min="1" max="5" value="<?php echo (int)$levenshtein_distance; ?>"
                   oninput="document.getElementById('levVal').textContent = this.value;">
            <span id="levVal"><?php echo (int)$levenshtein_distance; ?></span>
        </label>
        <?php foreach ($selectedColumns as $col): ?>
            <input type="hidden" name="visible_columns[]" value="<?php echo $col; ?>">
        <?php endforeach; ?>
        <button type="submit">Szukaj</button>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="data-table exact">
            <?php if (!empty($search_results)): ?>
                <h3>Dokładne wyniki</h3>
                <table id="tableExact">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="select-all" onclick="selectAllInTable(this, '#tableExact')">
                            </th>
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
                                <?php foreach ($columns as $col): ?>
                                    <td class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                        <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="no-highlight">
                                    <a role="button" href="karta.php?id=<?php echo $entryId; ?>">Karta</a>
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

        <div class="data-table fuzzy">
            <?php if (!empty($fuzzy_results)): ?>
                <h3>Przybliżone wyniki</h3>
                <table id="tableFuzzy">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="select-all" onclick="selectAllInTable(this, '#tableFuzzy')">
                            </th>
                            <?php foreach ($columns as $col): ?>
                                <th class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                    <?php echo htmlspecialchars($col); ?>
                                </th>
                            <?php endforeach; ?>
                            <th>Opcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fuzzy_results as $row): ?>
                            <?php
                                $idField = isset($row['ID']) ? 'ID' : (isset($row['id']) ? 'id' : $columns[0]);
                                $entryId = $row[$idField];
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="row-select" data-entry-id="<?php echo (int)$entryId; ?>" onclick="toggleRowSelection(this)">
                                </td>
                                <?php foreach ($columns as $col): ?>
                                    <td class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                        <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="no-highlight">
                                    <a role="button" href="karta.php?id=<?php echo $entryId; ?>">Karta</a>
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
                <p>Brak przybliżonych wyników.</p>
            <?php endif; ?>
        </div>
        <script>
            updateColumnDisplay();
            highlightQuery("<?php echo htmlspecialchars($query_string); ?>", false);
            highlightQuery("<?php echo htmlspecialchars($query_string); ?>", true);
        </script>
    <?php endif; ?>
</body>
</html>
