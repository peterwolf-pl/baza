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

function ensureMoveUsernameColumn(PDO $pdo): bool {
    try {
        $moveTableColumns = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna_przemieszczenia")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('user_username', $moveTableColumns, true)) {
            $pdo->exec("ALTER TABLE karta_ewidencyjna_przemieszczenia ADD COLUMN user_username VARCHAR(255) NULL");
        }

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasMoveUsernameColumn = ensureMoveUsernameColumn($pdo);

function getNextPrzemieszczenieNumber(PDO $pdo): string {
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(CAST(numer_przemieszczenia AS UNSIGNED)), 0) + 1
         FROM karta_ewidencyjna_przemieszczenia
         WHERE numer_przemieszczenia REGEXP '^[0-9]+$'"
    );
    return (string)$stmt->fetchColumn();
}

$bulkMoveSuccess = null;
$bulkMoveError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_przemieszczenie'])) {
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

                $numerPrzemieszczenia = getNextPrzemieszczenieNumber($pdo);

                if ($hasMoveUsernameColumn) {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO karta_ewidencyjna_przemieszczenia
                        (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia, user_username)
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                } else {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO karta_ewidencyjna_przemieszczenia
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
            FROM karta_ewidencyjna_przemieszczenia
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
            FROM karta_ewidencyjna_przemieszczenia
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

$nextPrzemieszczeniaNumber = getNextPrzemieszczenieNumber($pdo);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl - Lista: <?php echo htmlspecialchars($list['list_name']); ?></title>
        <link rel="stylesheet" href="styles.css">
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
        </a><br>
        <div class="header-links-left">
        <a role="button" id="toggleButton" href="index.php">Wróć do głównej</a>
        <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    </div>
        <div class="header-links">
            <div class="header-low">
            <?php foreach ($lists as $l): ?>
                <a href="list_view.php?list_id=<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['list_name']); ?></a>
            <?php endforeach; ?>
        </div></div>
    </div>

    <h1>Lista: <?php echo htmlspecialchars($list['list_name']); ?></h1>
    
    <br><br>

    
   
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
                                <a role="button" id="toggleButton"href="karta.php?id=<?php echo (int)$entryId; ?>">Karta</a>
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
                <button id="toggleBulkPrzemieszczenieButton" type="button" onclick="toggleBulkPrzemieszczenieForm()">Dodaj przemieszczenie całej listy</button>
                <button id="toggleCommonPrzemieszczeniaButton" type="button" onclick="toggleCommonPrzemieszczenia()">Wspólne przemieszczenia listy</button>
            </div>
            <div id="bulkPrzemieszczenieContainer">
                <h3>Nowe przemieszczenie dla całej listy</h3>
                <form method="post" class="bulk-form">
                    <input type="hidden" name="add_bulk_przemieszczenie" value="1">

                    <label for="data_przemieszczenia">Data Przemieszczenia</label>
                    <input type="date" name="data_przemieszczenia" id="data_przemieszczenia" required>

                    <label for="data_zwrotu">Data Zwrotu</label>
                    <input type="date" name="data_zwrotu" id="data_zwrotu">

                    <label for="numer_przemieszczenia">Numer Przemieszczenia (nadawany automatycznie)</label>
                    <input type="text" id="numer_przemieszczenia" value="<?php echo htmlspecialchars($nextPrzemieszczeniaNumber); ?>" readonly>

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
    <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2026
    </div>
    <script>
        // odśwież stan kolumn po SSR
        updateColumnDisplay();
        // wywołanie highlightQuery zostawione do ewentualnej integracji
        // highlightQuery(''); 
    </script>
</body>
</html>