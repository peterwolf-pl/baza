<?php
// Rozpocznij sesję
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Połączenie z bazą danych
include 'db.php';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_karta'])) {
    try {
        

        // Automatyczne wypełnianie numeru ewidencyjnego i daty opracowania
        $new_data = [];
        foreach ($valid_columns as $column) {
            if ($column === 'numer_ewidencyjny') {
                // Automatyczne generowanie numeru ewidencyjnego (ostatni numer + 1)
                $stmt = $pdo->query("SELECT IFNULL(MAX(numer_ewidencyjny), 0) AS max_num FROM karta_ewidencyjna");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $new_data[$column] = $result['max_num'] + 1;
            } elseif ($column === 'data_opracowania') {
                // Ustawienie aktualnej daty
                $new_data[$column] = date('Y-m-d');
            } else {
                $new_data[$column] = $_POST[$column] ?? null;
            }
        }

        // Tworzenie zapytania SQL
        $sql = "INSERT INTO karta_ewidencyjna (" . implode(", ", array_keys($new_data)) . ") VALUES (" . implode(", ", array_map(fn($key) => ":$key", array_keys($new_data))) . ")";

        // Wykonanie zapytania
        $insert_stmt = $pdo->prepare($sql);
        $insert_stmt->execute($new_data);

        // Przeładuj stronę lub przekieruj do nowo utworzonego wpisu
        header("Location: karta.php?id=" . $pdo->lastInsertId());
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
    <style>
        body { font-family: Arial, sans-serif; padding: 20px;}
        table { width: 80%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .add-form { margin-top: 20px; max-width: 600px; }
        .add-form label { font-weight: bold; display: block; margin-top: 10px; }
        .add-form input, .add-form textarea { width: 100%; padding: 8px; margin-top: 5px; margin-bottom: 15px; }
        .add-form input[type="submit"] { background-color: #007BFF; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 5px; }
        .add-form input[type="submit"]:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    </div>

    <a role="button" href="index.php">Powrót do listy</a> 
    
    <h1>Dodaj Nową Pozycję Ewidencyjną</h1>
    <form method="post" class="add-form">
        
        <?php foreach ($valid_columns as $column): ?>
            
                <label for="<?= $column ?>"><?= htmlspecialchars($column) ?></label>
                <input type="text" name="<?= $column ?>" id="<?= $column ?>">
            
        <?php endforeach; ?>
        <input type="submit" value="Zapisz">
    </form>

    <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2024
    </div>
</body>
</html>
