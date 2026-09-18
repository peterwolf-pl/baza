<?php
// Rozpocznij sesję
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Połączenie z bazą danych
include 'db.php';
require_once __DIR__ . '/museum_system.php';

function userCanCreateEntries(): bool {
    return !empty($_SESSION['can_inventory_entries']);
}

$collections = [
    'ksiazki-artystyczne' => ['main' => 'karta_ewidencyjna', 'log' => 'karta_ewidencyjna_log'],
    'kolekcja-maszyn' => ['main' => 'karta_ewidencyjna_maszyny', 'log' => 'karta_ewidencyjna_maszyny_log'],
    'kolekcja-matryc' => ['main' => 'karta_ewidencyjna_matryce', 'log' => 'karta_ewidencyjna_matryce_log'],
    'biblioteka' => ['main' => 'karta_ewidencyjna_bib', 'log' => 'karta_ewidencyjna_bib_log'],
    'kolekcja-klisz' => ['main' => 'karta_ewidencyjna_klisze', 'log' => 'karta_ewidencyjna_klisze_log'],
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$mainTable = $collections[$selectedCollection]['main'];
$logTable = $collections[$selectedCollection]['log'];


function getPrimaryKeyColumn(PDO $pdo, string $table): ?string {
    $stmt = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Column_name'])) {
            return $row['Column_name'];
        }
    }

    return null;
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
    if (!userCanCreateEntries()) {
        http_response_code(403);
        die('Brak uprawnień do tworzenia wpisów do księgi inwentarzowej.');
    }
    try {
        museumEnsureUniqueInventoryNumberConstraint($pdo, $mainTable);
        

        // Automatyczne wypełnianie numeru ewidencyjnego i daty opracowania
        $new_data = [];
        foreach ($valid_columns as $column) {
            if ($column === 'numer_ewidencyjny') {
                // Synchronizacja do aktualnego maksimum w tabeli + atomowe nadanie kolejnego numeru.
                $new_data[$column] = museumNextInventoryNumberFromTable($pdo, $mainTable, $selectedCollection);
            } elseif ($column === 'data_opracowania') {
                // Ustawienie aktualnej daty
                $new_data[$column] = currentProcessingDate($pdo, $mainTable);
            } else {
                if ($column === 'opracowujacy') {
                    $new_data[$column] = $_SESSION['username'] ?? ($_POST[$column] ?? null);
                } else {
                    $new_data[$column] = $_POST[$column] ?? null;
                }

                if ($column === 'dokumentacja_wizualna') {
                    $sourceImageValue = array_key_exists($column, $_POST) ? (string)$_POST[$column] : null;
                    $normalizedImageValue = museumNormalizeImageReference($sourceImageValue);
                    $new_data[$column] = $normalizedImageValue ?? ($sourceImageValue !== null ? '' : null);
                }
            }
        }

        // Tworzenie zapytania SQL
        $sql = "INSERT INTO {$mainTable} (" . implode(", ", array_keys($new_data)) . ") VALUES (" . implode(", ", array_map(fn($key) => ":$key", array_keys($new_data))) . ")";

        // Wykonanie zapytania
        $insert_stmt = $pdo->prepare($sql);
        $insert_stmt->execute($new_data);

        // Przeładuj stronę lub przekieruj do nowo utworzonego wpisu
        $newId = (int)$pdo->lastInsertId();

        $logStmt = $pdo->prepare("INSERT INTO {$logTable}
            (karta_id, user_username, changed_field, old_value, new_value, change_date)
            VALUES (:karta_id, :user_username, :changed_field, :old_value, :new_value, NOW())");
        $logStmt->execute([
            'karta_id' => $newId,
            'user_username' => $_SESSION['username'] ?? null,
            'changed_field' => 'Utworzenie wpisu',
            'old_value' => null,
            'new_value' => 'Utworzenie wpisu',
        ]);

        header("Location: karta.php?id=" . $newId . "&collection=" . urlencode($selectedCollection));
        exit;
    } catch (PDOException $e) {
        if (($e->getCode() ?? '') === '23000' && museumIsInventoryNumberConstraintViolation($e)) {
            $suggestedNumber = null;
            $attemptedInventoryNumber = isset($new_data['numer_ewidencyjny']) ? (string)$new_data['numer_ewidencyjny'] : 'XX';
            try {
                $suggestedNumber = museumSuggestedNextInventoryNumberAfterDuplicate($pdo, $mainTable, $selectedCollection, $attemptedInventoryNumber);
            } catch (Throwable $ignored) {
                $suggestedNumber = null;
            }
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Błąd dodawania: numer inwentarzowy " . $attemptedInventoryNumber . " już istnieje (wymuszona unikalność). Spróbuj ponownie.";
            if ($suggestedNumber !== null && $suggestedNumber > 0) {
                echo " Proponowany kolejny numer: " . $suggestedNumber . ".";
            }
        } elseif (($e->getCode() ?? '') === '23000') {
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Błąd dodawania (naruszenie ograniczenia danych, nie dotyczy numer_ewidencyjny): " . $e->getMessage();
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Błąd dodawania: " . $e->getMessage();
        }
        die();
    } catch (RuntimeException $e) {
        header('Content-Type: text/plain; charset=UTF-8');
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
    <?php if (!userCanCreateEntries()): ?>
        <p style="color:#b10000;">Nie masz uprawnień do tworzenia nowych wpisów.</p>
    <?php else: ?>
    <form method="post" class="add-form">
        <p><strong>Numer inwentarzowy</strong> jest nadawany automatycznie przy zapisie.</p>
        
        <?php foreach ($valid_columns as $column): ?>
            <?php if ($column === 'numer_ewidencyjny') { continue; } ?>
            
                <label for="<?= $column ?>"><?= htmlspecialchars($column) ?></label>
                <input type="text" name="<?= $column ?>" id="<?= $column ?>">
            
        <?php endforeach; ?>
        <input type="submit" name="add_karta" value="Zapisz">
    </form>
    <?php endif; ?>

    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
