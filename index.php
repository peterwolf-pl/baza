<?php
session_start();
// Include database connection
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");  // Redirect to login if not logged in
    exit;
}

include 'db.php';

// Obsługa żądania AJAX do lazy loading (pobieranie wierszy)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_rows') {
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = 30; // ilość wierszy do pobrania na raz

    try {
        // Pobieramy kolumny i dane
        $columns = [];
        $query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }

        $stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna LIMIT :offset, :limit");
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = [
            'rows' => $rows,
            'columns' => $columns
        ];

        header('Content-Type: application/json');
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        $message = $e->getMessage();
        error_log($message);
        echo json_encode(['error' => $message]);
    }
    exit;
}

// Fetch table column headers
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Fetch all lists for header links
$lists = $pdo->query("SELECT id, list_name FROM lists ORDER BY list_name")->fetchAll(PDO::FETCH_ASSOC);

// Domyślne kolumny widoczne
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl</title>
    <style>
        /* Basic styling for layout */
        body { font-family: Arial, sans-serif; padding: 20px; }
        .column-selector, .data-table { margin-top: 20px; }
        .column-selector label { display: block; }
        .data-table table { border-collapse: collapse; width: 100%; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; }
        .data-table th { background-color: #f2f2f2; }
        .header-links { float: right; margin-top: 20px; }
        .header-links a { margin-left: 10px; text-decoration: none; color: #007BFF; font-weight: bold; }
        .header-links a:hover { text-decoration: underline; }

        /* Collapsible styling */
        #columnSelectorContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; }
        #toggleButton, #toggleColumndButton { font-family: Arial, sans-serif; font-size: 4; cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; font-family: Arial, sans-serif; font-size: 6;}
    </style>
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>
        <div class="header-links">
            <?php
            foreach ($lists as $list) {
                echo "<a href='list_view.php?list_id={$list['id']}'>{$list['list_name']}</a>";
            }
            ?>
        </div>
    </div>

    <button id="toggleColumndButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
    &nbsp; &nbsp; &nbsp; &nbsp; 
    <a role="button" href="logout.php" class="back-link" id="toggleButton">Wyloguj się</a> 
    &nbsp; &nbsp; 
    <a role="button" id="toggleButton" href="search.php">Szukaj</a>
    <a role="button" id="toggleButton" href="neww.php">Nowy Wpis</a>

    <div id="columnSelectorContainer" class="column-selector">
        <?php foreach ($columns as $col): ?>
            <label>
                <input type="checkbox" class="column-checkbox" value="<?php echo $col; ?>" 
                       onclick="toggleColumn('<?php echo $col; ?>')" 
                       <?php echo in_array($col, $defaultVisibleColumns) ? 'checked' : ''; ?>>
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
                            style="display: <?php echo in_array($col, $defaultVisibleColumns) ? '' : 'none'; ?>;">
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
// JavaScript to toggle the visibility of the column selector
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


// Function to toggle individual columns
function toggleColumn(column) {
    let header = document.querySelector(`th.${column}`);
    let cells = document.querySelectorAll(`td.${column}`);
    let displayStyle = header.style.display === 'none' ? '' : 'none';

    header.style.display = displayStyle;
    cells.forEach(cell => {
        cell.style.display = displayStyle;
    });
}

// Obsługa dodawania do list
function handleListSelection(select, entryId) {
    const selectedValue = select.value;

    // Funkcja wyświetlająca dynamiczny komunikat
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

        setTimeout(() => {
            messageContainer.remove();
        }, 1000);
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
                    // Sprawdź czy kolumna jest widoczna
                    const th = document.querySelector(`th.${col}`);
                    td.style.display = th && th.style.display === 'none' ? 'none' : '';
                    td.textContent = (row[col] || '').replace(/'/g, "");
                    tr.appendChild(td);
                });

                // Opcje
                const tdOptions = document.createElement('td');
                tdOptions.width = "222";
                tdOptions.innerHTML = `
                    <a role="button" id="toggleButton" href="karta.php?id=${row['ID']}">Karta</a>
                    <select onchange="handleListSelection(this, ${row['ID']})">
                        <option value="">Dodaj do listy</option>
                        <option value="new">+ Nowa lista</option>
                        <option disabled>──────────</option>
                        <?php
                        foreach ($lists as $list) {
                            echo "<option value='{$list['id']}'>{$list['list_name']}</option>";
                        }
                        ?>
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

// Ładowanie początkowych danych
loadRows();

// Nasłuchiwanie przewijania
window.addEventListener('scroll', function() {
    const scrollPosition = window.innerHeight + window.scrollY;
    const threshold = 100; // odległość od dołu w px przy której ładujemy kolejne
    if (scrollPosition >= document.body.offsetHeight - threshold && !loading && !noMoreRows) {
        loadRows();
    }
});
</script>
</body>
</html>
