<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

// AJAX: pobieranie wierszy
if (isset($_GET['action']) && $_GET['action'] === 'fetch_rows') {
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = 30;

    try {
        // Pobierz kolumny
        $columns = [];
        $query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }

        // !!! UWAGA: w MySQL nie używaj bindValue do LIMIT/OFFSET !!!
        $stmt = $pdo->query("SELECT * FROM karta_ewidencyjna LIMIT $offset, $limit");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode([
            'rows' => $rows,
            'columns' => $columns
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

    $columns = [];
    $query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    $selectedColumns = array_values(array_intersect($columns, $requested));
    $_SESSION['visible_columns'] = $selectedColumns;

    echo json_encode(['success' => true]);
    exit;
}

// Pobierz kolumny do headera
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Pobierz listy (do opcji i headera)
$lists = $pdo->query("SELECT id, list_name FROM lists ORDER BY list_name")->fetchAll(PDO::FETCH_ASSOC);

// Domyślne kolumny (wspólne ustawienie między podstronami)
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];
$selectedColumns = isset($_SESSION['visible_columns']) && is_array($_SESSION['visible_columns'])
    ? array_values(array_intersect($columns, $_SESSION['visible_columns']))
    : $defaultVisibleColumns;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl</title>
        <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>

        <div class="header-links">

             

 

<div class="header-low">
    <a role="button" id="toggleButton" href="lists.php">Edytor list</a>
<strong>Listy:</strong>
            <?php
            foreach ($lists as $list) {
                echo "<a href='list_view.php?list_id={$list['id']}'>{$list['list_name']}</a> &nbsp; ";
            }
            ?>

</div>

<a role="button" href="logout.php" class="back-link" id="toggleButton">Wyloguj się</a>
        </div>
    </div>
<div class="header-links-left">
    <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    
    <a role="button" id="toggleButton" href="neww.php">Nowy Wpis</a> 
    
    <a role="button" id="toggleButton" href="search.php">Szukaj</a>
   
 </div>
    <div id="columnSelectorContainer" class="column-selector">
        <?php foreach ($columns as $col): ?>
            <label>
                <input type="checkbox" class="column-checkbox" value="<?php echo $col; ?>" 
                       onclick="toggleColumn('<?php echo $col; ?>')" 
                       <?php echo in_array($col, $selectedColumns) ? 'checked' : ''; ?>>
                <?php echo $col; ?>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="data-table">
        <table id="dataTable">
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th class="<?php echo $col; ?>" 
                            style="display: <?php echo in_array($col, $selectedColumns) ? '' : 'none'; ?>;">
                            <?php echo $col; ?>
                        </th>
                    <?php endforeach; ?>
                    <th>Opcje</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            </tbody>
        </table>
    </div>

<script>
// przekazanie PHP -> JS dla opcji list
const phpLists = <?php echo json_encode($lists); ?>;

// Kolumny widoczne na start
const defaultVisibleColumns = <?php echo json_encode($selectedColumns); ?>;
let saveColumnsTimeout = null;

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

    fetch('?action=save_visible_columns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visible_columns: visibleColumns })
    }).catch(err => console.error('Błąd zapisu kolumn:', err));
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
            body: JSON.stringify({ list_id: selectedValue, entry_id: entryId })
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

// Infinite scroll
let offset = 0;
const limit = 20;
let loading = false;
let noMoreRows = false;

// Generuj <option> list na podstawie phpLists
function getListOptionsHtml() {
    let html = `<option value="">Dodaj do listy</option>
                <option value="new">+ Nowa lista</option>
                <option disabled>──────────</option>`;
    phpLists.forEach(list => {
        html += `<option value="${list.id}">${list.list_name}</option>`;
    });
    return html;
}

function loadRows() {
    if (loading || noMoreRows) return;
    loading = true;

    fetch(`?action=fetch_rows&offset=${offset}`)
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

            if (rows.length === 0) {
                noMoreRows = true;
                return;
            }

            rows.forEach(row => {
                const tr = document.createElement('tr');
                columns.forEach(col => {
                    const td = document.createElement('td');
                    td.className = col;
                    const th = document.querySelector(`th.${col}`);
                    td.style.display = th && th.style.display === 'none' ? 'none' : '';
                    // null/undefined na pusty string
                    td.textContent = (row[col] === null || row[col] === undefined) ? '' : (row[col] + '').replace(/'/g, "");
                    tr.appendChild(td);
                });

                // Opcje
                const tdOptions = document.createElement('td');
                tdOptions.width = "222";
                // użyj klucza ID lub id (wykryj automatycznie)
                const idField = row['ID'] !== undefined ? 'ID' : (row['id'] !== undefined ? 'id' : columns[0]);
                tdOptions.innerHTML = `
                    <a role="button" id="toggleButton" href="karta.php?id=${row[idField]}">Karta</a>
                    <select onchange="handleListSelection(this, ${row[idField]})">
                        ${getListOptionsHtml()}
                    </select>
                `;
                tr.appendChild(tdOptions);

                tableBody.appendChild(tr);
            });

            offset += rows.length;
            loading = false;
        })
        .catch(e => {
            console.error(e);
            loading = false;
        });
}

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
