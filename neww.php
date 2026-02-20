<?php
// Rozpocznij sesję
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Połączenie z bazą danych
include 'db.php';

$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$mainTable = $collections[$selectedCollection];


function getPrimaryKeyColumn(PDO $pdo, string $table): ?string {
    $stmt = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Column_name'])) {
            return $row['Column_name'];
        }
    }

    return null;
}

function nextNumericValue(PDO $pdo, string $table, string $column): int {
    $stmt = $pdo->query("SELECT COALESCE(MAX(CAST({$column} AS UNSIGNED)), 0) + 1 AS next_val FROM {$table}");
    return (int)$stmt->fetchColumn();
}

function currentProcessingDate(PDO $pdo, string $table): string {
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'data_opracowania'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($column['Type'] ?? ''));

    if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d');
}

$valid_columns = [
            'numer_ewidencyjny', 'nazwa_tytul', 'czas_powstania', 'inne_numery_ewidencyjne',
            'autor_wytworca', 'miejsce_powstania', 'liczba', 'material', 
            'dokumentacja_wizualna', 'dzial', 'pochodzenie', 'technika_wykonania', 
            'wymiary', 'cechy_charakterystyczne', 'dane_o_dokumentacji_wizualnej', 
            'wlasciciel', 'sposob_oznakowania', 'autorskie_prawa_majatkowe', 
            'kontrola_zbiorow', 'wartosc_w_dniu_nabycia', 'wartosc_w_dniu_sporzadzenia', 
            'miejsce_przechowywania', 'uwagi', 'data_opracowania', 'opracowujacy'
        ];

// Obsługa formularza dodawania nowej karty
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        

        // Automatyczne wypełnianie numeru ewidencyjnego i daty opracowania
        $new_data = [];
        foreach ($valid_columns as $column) {
            if ($column === 'numer_ewidencyjny') {
                // Automatyczne generowanie numeru ewidencyjnego (ostatni numer + 1)
                $new_data[$column] = nextNumericValue($pdo, $mainTable, 'numer_ewidencyjny');
            } elseif ($column === 'data_opracowania') {
                // Ustawienie aktualnej daty
                $new_data[$column] = currentProcessingDate($pdo, $mainTable);
            } else {
                if ($column === 'opracowujacy') {
                    $new_data[$column] = $_SESSION['username'] ?? ($_POST[$column] ?? null);
                } else {
                    $new_data[$column] = $_POST[$column] ?? null;
                }
            }
        }

        $primaryKeyColumn = getPrimaryKeyColumn($pdo, $mainTable);
        if ($primaryKeyColumn !== null && !array_key_exists($primaryKeyColumn, $new_data)) {
            $new_data[$primaryKeyColumn] = nextNumericValue($pdo, $mainTable, $primaryKeyColumn);
        }

        // Tworzenie zapytania SQL
        $sql = "INSERT INTO {$mainTable} (" . implode(", ", array_keys($new_data)) . ") VALUES (" . implode(", ", array_map(fn($key) => ":$key", array_keys($new_data))) . ")";

        // Wykonanie zapytania
        $insert_stmt = $pdo->prepare($sql);
        $insert_stmt->execute($new_data);

        // Przeładuj stronę lub przekieruj do nowo utworzonego wpisu
        $newId = isset($primaryKeyColumn, $new_data[$primaryKeyColumn]) ? (int)$new_data[$primaryKeyColumn] : (int)$pdo->lastInsertId();
        header("Location: karta.php?id=" . $newId . "&collection=" . urlencode($selectedCollection));
        exit;
    } catch (PDOException $e) {
        echo "Błąd dodawania: " . $e->getMessage();
        die();
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Dodaj Nową Kartę Ewidencyjną</title>
        <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    </div>

    <a role="button" id="toggleButton" href="index.php?collection=<?php echo urlencode($selectedCollection); ?>">Powrót do listy</a> 
    
    <h1>Dodaj Nową Pozycję Ewidencyjną</h1>
    <form method="post" class="add-form">
        
        <?php foreach ($valid_columns as $column): ?>
            
                <label for="<?= $column ?>"><?= htmlspecialchars($column) ?></label>
                <input type="text" name="<?= $column ?>" id="<?= $column ?>">
            
        <?php endforeach; ?>
        <input type="submit" name="add_karta" value="Zapisz">
    </form>

    <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2024
    </div>
</body>
</html>