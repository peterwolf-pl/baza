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


// Fetch table column headers
$columns = [];
$query = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna");
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Define default visible columns
$defaultVisibleColumns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca'];
?>




<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl</title>
    <script src="script.js"></script>
    <style>
        /* Basic styling for layout */
        body { font-family: Arial, sans-serif; padding: 20px; }
        .column-selector, .data-table { margin-top: 20px; }
        .column-selector label { display: block; }
        .data-table table { border-collapse: collapse; width: 100%; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; }
        .data-table th { background-color: #f2f2f2; }

        /* Collapsible styling */
        #columnSelectorContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; }
        #toggleButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; }
    </style>
</head>
<body>
 <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>
    </div>

<button id="toggleButton" onclick="toggleColumnSelector()">Wybierz kolumny</button>
&nbsp; &nbsp; &nbsp; &nbsp; 
 <a role="button" href="logout.php" class="back-link" id="toggleButton" >Wyloguj się</a> 
 &nbsp; &nbsp; 
<a role="button" id="toggleButton" href="search.php">Szukaj</a>
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
            <?php
            // Fetch all rows
            $stmt = $pdo->query("SELECT * FROM karta_ewidencyjna");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <td class="<?php echo $col; ?>" 
                            style="display: <?php echo in_array($col, $defaultVisibleColumns) ? '' : 'none'; ?>;">
                            <?php echo htmlspecialchars(str_replace("'", "", $row[$col])); ?>
                        </td>
                    <?php endforeach; ?>
                    <td><a role="button" id="toggleButton" href="karta.php?id=<?php echo $row['ID']; ?>">Karta</a></td>

                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
// JavaScript to toggle the visibility of the column selector
function toggleColumnSelector() {
    const container = document.getElementById('columnSelectorContainer');
    const button = document.getElementById('toggleButton');
    
    if (container.style.display === 'none') {
        container.style.display = 'block';
        button.textContent = 'Ukryj ustawienia wyświetlania';
    } else {
        container.style.display = 'none';
        button.textContent = 'Ustawienia wyświetlania';
    }
}

// Function to toggle individual columns (same as before)
function toggleColumn(column) {
    let header = document.querySelector(`th.${column}`);
    let cells = document.querySelectorAll(`td.${column}`);
    let displayStyle = header.style.display === 'none' ? '' : 'none';

    header.style.display = displayStyle;
    cells.forEach(cell => {
        cell.style.display = displayStyle;
    });
}
</script>

</body>
</html>
