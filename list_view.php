<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$list_id = $_GET['list_id'] ?? null;
if (!$list_id) {
    echo "Nieprawidłowy identyfikator listy.";
    exit;
}

$bulkMoveSuccess = null;
$bulkMoveError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_przemieszczenie'])) {
    $dataPrzemieszczenia = trim($_POST['data_przemieszczenia'] ?? '');
    $dataZwrotu = trim($_POST['data_zwrotu'] ?? '');
    $numerPrzemieszczenia = trim($_POST['numer_przemieszczenia'] ?? '');
    $miejscePrzemieszczenia = trim($_POST['miejsce_przemieszczenia'] ?? '');
    $powodCelPrzemieszczenia = trim($_POST['powod_cel_przemieszczenia'] ?? '');

    if ($dataPrzemieszczenia === '' || $numerPrzemieszczenia === '' || $miejscePrzemieszczenia === '') {
        $bulkMoveError = 'Uzupełnij pola wymagane: data, numer i miejsce przemieszczenia.';
    } else {
        $entryIdsStmt = $pdo->prepare("SELECT DISTINCT entry_id FROM list_items WHERE list_id = ?");
        $entryIdsStmt->execute([$list_id]);
        $entryIds = $entryIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($entryIds)) {
            $bulkMoveError = 'Ta lista nie zawiera pozycji do przemieszczenia.';
        } else {
            try {
                $pdo->beginTransaction();
                $insertStmt = $pdo->prepare(
                    "INSERT INTO karta_ewidencyjna_przemieszczenia
                    (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($entryIds as $entryId) {
                    $insertStmt->execute([
                        (int)$entryId,
                        $dataPrzemieszczenia,
                        $dataZwrotu !== '' ? $dataZwrotu : null,
                        $numerPrzemieszczenia,
                        $miejscePrzemieszczenia,
                        $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null
                    ]);
                }

                $pdo->commit();
                $bulkMoveSuccess = 'Dodano przemieszczenie do wszystkich pozycji z tej listy.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $bulkMoveError = 'Nie udało się dodać przemieszczeń: ' . $e->getMessage();
            }
        }
    }
}

// Pobierz nazwy kolumn z tabeli
$columns = [];
$colsStmt = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
while ($row = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Pobierz listy do nagłówka i selecta
$lists = $pdo->query("SELECT id, list_name FROM lists ORDER BY list_name")->fetchAll(PDO::FETCH_ASSOC);

// Domyślne widoczne kolumny
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];

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
    JOIN karta_ewidencyjna ke ON li.entry_id = ke.ID
    WHERE li.list_id = ?
");
$stmt->execute([$list_id]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz nazwę listy
$stmtName = $pdo->prepare("SELECT list_name FROM lists WHERE id = ?");
$stmtName->execute([$list_id]);
$list = $stmtName->fetch(PDO::FETCH_ASSOC);
if (!$list) {
    echo "Lista nie istnieje.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl - Lista: <?php echo htmlspecialchars($list['list_name']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .header-links { float: right; margin-top: 20px; }
        .header-links a { margin-left: 10px; text-decoration: none; color: #007BFF; font-weight: bold; }
        .header-links a:hover { text-decoration: underline; }
        .data-table table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
        .data-table th { background-color: #f2f2f2; }
        #columnSelectorContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; margin-bottom: 20px; }
        #toggleColumndButton { font-family: Arial, sans-serif; font-size: 16px; cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        .no-highlight { user-select: text; }
        .logo { display: inline-block; }
        .highlight { background: yellow; font-weight: bold; }
        .fuzzy-highlight { background: #bfffbf; font-weight: bold; }
        th.data-col, td.data-col { /* sterowane JSem przez display */ }
        #bulkPrzemieszczenieContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; margin-top: 15px; }
        #toggleBulkPrzemieszczenieButton { font-family: Arial, sans-serif; font-size: 16px; cursor: pointer; margin-top: 15px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        .bulk-form input, .bulk-form textarea { width: 100%; padding: 8px; margin-bottom: 10px; box-sizing: border-box; }
        .message-success { color: #0a7d1a; font-weight: bold; margin-top: 10px; }
        .message-error { color: #c40000; font-weight: bold; margin-top: 10px; }
    </style>
    <script>
        // przekazujemy wybrane kolumny do JS
        let visibleColumns = <?php echo json_encode($selectedColumns); ?>;

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

        window.addEventListener('DOMContentLoaded', () => {
            updateColumnDisplay();
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
                        body: JSON.stringify({ name: newListName, entry_id: entryId })
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
                    body: JSON.stringify({ list_id: selectedValue, entry_id: entryId })
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
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>
        <div class="header-links">
            <?php foreach ($lists as $l): ?>
                <a href="list_view.php?list_id=<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['list_name']); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <h1>Lista: <?php echo htmlspecialchars($list['list_name']); ?></h1>
    <a href="index.php">Wróć do głównej</a>
    <br><br>

    <!-- Wybór kolumn identyczny jak w pierwszym pliku -->
    <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    <form id="columnSelectorContainer" class="column-selector" method="post" action="">
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
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <td class="data-col" data-col="<?php echo $col; ?>" style="display:<?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>">
                                    <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="no-highlight">
                                <?php
                                $idField = isset($row['ID']) ? 'ID' : (isset($row['id']) ? 'id' : $columns[0]);
                                $entryId = $row[$idField];
                                ?>
                                <a role="button" href="karta.php?id=<?php echo (int)$entryId; ?>">Karta</a>
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

            <button id="toggleBulkPrzemieszczenieButton" type="button" onclick="toggleBulkPrzemieszczenieForm()">Dodaj przemieszczenie całej listy</button>
            <div id="bulkPrzemieszczenieContainer">
                <h3>Nowe przemieszczenie dla całej listy</h3>
                <form method="post" class="bulk-form">
                    <input type="hidden" name="add_bulk_przemieszczenie" value="1">

                    <label for="data_przemieszczenia">Data Przemieszczenia</label>
                    <input type="date" name="data_przemieszczenia" id="data_przemieszczenia" required>

                    <label for="data_zwrotu">Data Zwrotu</label>
                    <input type="date" name="data_zwrotu" id="data_zwrotu">

                    <label for="numer_przemieszczenia">Numer Przemieszczenia</label>
                    <input type="text" name="numer_przemieszczenia" id="numer_przemieszczenia" required>

                    <label for="miejsce_przemieszczenia">Miejsce Przemieszczenia</label>
                    <input type="text" name="miejsce_przemieszczenia" id="miejsce_przemieszczenia" required>

                    <label for="powod_cel_przemieszczenia">Powód/Cel Przemieszczenia</label>
                    <textarea name="powod_cel_przemieszczenia" id="powod_cel_przemieszczenia"></textarea>

                    <button type="submit">Dodaj</button>
                </form>
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

    <script>
        // odśwież stan kolumn po SSR
        updateColumnDisplay();
        // wywołanie highlightQuery zostawione do ewentualnej integracji
        // highlightQuery(''); 
    </script>
</body>
</html>
