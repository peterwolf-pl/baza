<?php
// Rozpocznij sesję
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Połączenie z bazą danych
include 'db.php';

// Sprawdzenie, czy ID zostało przekazane w URL
if (!isset($_GET['id'])) {
    die("Brak ID w zapytaniu.");
}

$id = intval($_GET['id']);

// Pobranie danych karty ewidencyjnej
$stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna WHERE ID = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("Nie znaleziono rekordu.");
}

// Pobranie ścieżki obrazka
$image_path = !empty($row['dokumentacja_wizualna']) 
    ? 'https://mkalodz.pl/bazagfx/' . htmlspecialchars(str_replace("'", "", $row['dokumentacja_wizualna']))
    : null;

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

$moveAddError = null;
$moveAddSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_przemieszczenie'])) {
    $dataPrzemieszczenia = trim($_POST['data_przemieszczenia'] ?? '');
    $dataZwrotu = trim($_POST['data_zwrotu'] ?? '');
    $miejscePrzemieszczenia = trim($_POST['miejsce_przemieszczenia'] ?? '');
    $powodCelPrzemieszczenia = trim($_POST['powod_cel_przemieszczenia'] ?? '');

    if ($dataPrzemieszczenia === '' || $miejscePrzemieszczenia === '') {
        $moveAddError = 'Uzupełnij pola wymagane: data i miejsce przemieszczenia.';
    } else {
        try {
            $numerPrzemieszczenia = getNextPrzemieszczenieNumber($pdo);

            if ($hasMoveUsernameColumn) {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO karta_ewidencyjna_przemieszczenia
                    (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia, user_username)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $id,
                    $dataPrzemieszczenia,
                    $dataZwrotu !== '' ? $dataZwrotu : null,
                    $numerPrzemieszczenia,
                    $miejscePrzemieszczenia,
                    $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null,
                    $_SESSION['username'] ?? null
                ]);
            } else {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO karta_ewidencyjna_przemieszczenia
                    (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $id,
                    $dataPrzemieszczenia,
                    $dataZwrotu !== '' ? $dataZwrotu : null,
                    $numerPrzemieszczenia,
                    $miejscePrzemieszczenia,
                    $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null
                ]);
            }

            $moveAddSuccess = 'Dodano przemieszczenie nr ' . $numerPrzemieszczenia . '.';
        } catch (PDOException $e) {
            $moveAddError = 'Błąd dodawania przemieszczenia: ' . $e->getMessage();
        }
    }
}


// Obsługa formularza edycji
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_karta'])) {
    try {
        // Obsługiwane kolumny
        $valid_columns = [
            'numer_ewidencyjny', 'nazwa_tytul', 'czas_powstania', 'inne_numery_ewidencyjne',
            'autor_wytworca', 'miejsce_powstania', 'liczba', 'material', 
            'dokumentacja_wizualna', 'dzial', 'pochodzenie', 'technika_wykonania', 
            'wymiary', 'cechy_charakterystyczne', 'dane_o_dokumentacji_wizualnej', 
            'wlasciciel', 'sposob_oznakowania', 'autorskie_prawa_majatkowe', 
            'kontrola_zbiorow', 'wartosc_w_dniu_nabycia', 'wartosc_w_dniu_sporzadzenia', 
            'miejsce_przechowywania', 'uwagi', 'data_opracowania', 'opracowujacy', 
            'przemieszczenia'
        ];

        // Filtrowanie danych
        $updated_data = [];
        foreach ($valid_columns as $column) {
            if (isset($_POST[$column])) {
                $updated_data[$column] = $_POST[$column];
            } else {
                $updated_data[$column] = $row[$column];
            }
        }

        // Tworzenie zapytania SQL
        $sql = "UPDATE karta_ewidencyjna SET " . 
            implode(", ", array_map(fn($key) => "$key = :$key", array_keys($updated_data))) . 
            " WHERE ID = :id";

        $updated_data['id'] = $id; // Dodanie ID do parametrów

        // Wykonanie zapytania
        $update_stmt = $pdo->prepare($sql);
        $update_stmt->execute($updated_data);

        // Logowanie zmian
        $changes = [];
        foreach ($updated_data as $key => $new_value) {
            if ($row[$key] != $new_value) {
                $changes[] = [
                    'field' => $key,
                    'old_value' => $row[$key],
                    'new_value' => $new_value
                ];
            }
        }

        if (!empty($changes)) {
            $log_stmt = $pdo->prepare("INSERT INTO karta_ewidencyjna_log 
                (karta_id, user_username, changed_field, old_value, new_value, change_date) 
                VALUES (:karta_id, :user_username, :changed_field, :old_value, :new_value, NOW())");

            foreach ($changes as $change) {
                $log_stmt->execute([
                    'karta_id' => $id,
                    'user_username' => $_SESSION['username'],
                    'changed_field' => $change['field'],
                    'old_value' => $change['old_value'],
                    'new_value' => $change['new_value']
                ]);
            }
        }

        // Przeładuj stronę
        header("Location: karta.php?id=" . $id);
        exit;

    } catch (PDOException $e) {
        echo "Błąd aktualizacji: " . $e->getMessage();
        die();
    }
}







// Pobranie logów zmian
$log_stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna_log WHERE karta_id = :id ORDER BY change_date DESC");
$log_stmt->execute(['id' => $id]);
$log_entries = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobranie przemieszczeń
$przemieszczenia_stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna_przemieszczenia WHERE karta_id = :id");
$przemieszczenia_stmt->execute(['id' => $id]);
$przemieszczenia_rows = $przemieszczenia_stmt->fetchAll(PDO::FETCH_ASSOC);
$nextPrzemieszczeniaNumber = getNextPrzemieszczenieNumber($pdo);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Karta Ewidencyjna kolekcji MKA</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px;}
        table { width: 80%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .image-container img { max-width: 80%; height: auto; }
        button { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        button:focus { outline: none; }
        .footer-right { position: fixed; bottom: 10px; right: 5%; font-size: 10px; font-family: Arial, sans-serif; }
        .footer-right a { color: #007bff; text-decoration: none; }
        .footer-right a:hover { text-decoration: underline; }
        #editKartaContainer, #logContainer, #przemieszczeniaContainer { display: none; padding-top: 10px; }

 h1, h2, h3 {font-family: GrohmanGrotesk;
                    src: url("/gg.woff2") format('woff2');}
                    .back-link { margin-top: 20px; display: inline-block; }
        #przemieszczeniaContainer { display: none; padding-top: 10px; }
        #togglePrzemieszczeniaButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #togglePrzemieszczeniaButton:focus { outline: none; }
         #toggleButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; }
        .add-form, .edit-form { margin-top: 20px; }
        .add-form input, .add-form textarea, .edit-form input, .edit-form textarea { width: 100%; padding: 8px; margin-bottom: 10px; }
        .message-success { color: #0a7d1a; font-weight: bold; margin-top: 10px; }
        .message-error { color: #c40000; font-weight: bold; margin-top: 10px; }


    </style>
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    </div>

            </div>
    <a role="button" id="toggleButton" href="index.php">Powrót do listy</a> 
</div>
    
    <h1>Karta Ewidencji</h1>
    <table>
        <?php foreach ($row as $key => $value): ?>
            <tr>
                <th width="300px"><?= htmlspecialchars($key) ?></th>
                <td><?= htmlspecialchars($value) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($image_path): ?>
            <tr>
                <td colspan="2">
                    <img src="<?= $image_path ?>" alt="Obrazek obiektu" width="600">
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <!-- Tabela Edycji Karty -->
    <button id="toggleEditKartaButton" onclick="toggleEditKarta()">Pokaż/Ukryj tabelę edycji karty</button>
    <div id="editKartaContainer">
        <h2>Edytuj Kartę</h2>
        <form method="post">
            <input type="hidden" name="edit_karta" value="1">
            <table>
                <?php foreach ($row as $key => $value): ?>
                    <tr>
                        <th width="300px"><label for="<?= $key ?>"><?= htmlspecialchars($key) ?></label></th>
                        <td><input type="text" name="<?= $key ?>" id="<?= $key ?>" value="<?= htmlspecialchars($value) ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit">Zapisz zmiany</button>
        </form>
    </div>

    <!-- Tabela Historii Zmian -->
    <button id="toggleLogButton" onclick="toggleLog()">Pokaż/Ukryj historię zmian</button>
    <div id="logContainer">
        <h2>Historia Zmian</h2>
        <table>
            <thead>
                <tr>
                    <th>Data zmiany</th>
                    <th>Użytkownik</th>
                    <th>Pole</th>
                    <th>Stara wartość</th>
                    <th>Nowa wartość</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_entries as $entry): ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['change_date']) ?></td>
                        <td><?= htmlspecialchars($entry['user_username']) ?></td>
                        <td><?= htmlspecialchars($entry['changed_field']) ?></td>
                        <td><?= htmlspecialchars($entry['old_value']) ?></td>
                        <td><?= htmlspecialchars($entry['new_value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Tabela Przemieszczeń -->
    <button id="togglePrzemieszczeniaButton" onclick="togglePrzemieszczenia()">Pokaż/Ukryj tabelę przemieszczeń</button>
    <div id="przemieszczeniaContainer">
        <h2>Przemieszczenia</h2>
        <table>
            <thead>
                <tr>
                    <th>Data Przemieszczenia</th>
                    <th>Data Zwrotu</th>
                    <th>Numer Przemieszczenia</th>
                    <th>Miejsce Przemieszczenia</th>
                    <th>Powód/Cel Przemieszczenia</th>
                    <th>Użytkownik</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($przemieszczenia_rows as $przemieszczenie): ?>
                    <tr>
                        <td><?= htmlspecialchars($przemieszczenie['data_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['data_zwrotu']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['numer_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['miejsce_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['powod_cel_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['user_username'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

<br><br>

   <h3>Dodaj nowe przemieszczenie:</h3>
        <form method="post" class="add-form">
            <input type="hidden" name="add_przemieszczenie" value="1">
            <label for="data_przemieszczenia">Data Przemieszczenia</label>
            <input type="date" name="data_przemieszczenia" id="data_przemieszczenia" required>

            <label for="data_zwrotu">Data Zwrotu</label>
            <input type="date" name="data_zwrotu" id="data_zwrotu">

            <label for="numer_przemieszczenia">Numer Przemieszczenia (nadawany automatycznie)</label>
            <input type="text" id="numer_przemieszczenia" value="<?= htmlspecialchars($nextPrzemieszczeniaNumber) ?>" readonly>

            <label for="miejsce_przemieszczenia">Miejsce Przemieszczenia</label>
            <input type="text" name="miejsce_przemieszczenia" id="miejsce_przemieszczenia" required>

            <label for="powod_cel_przemieszczenia">Powód/Cel Przemieszczenia</label>
            <textarea name="powod_cel_przemieszczenia" id="powod_cel_przemieszczenia"></textarea>

            <button id="togglePrzemieszczeniaButton" type="submit">Dodaj</button>
        </form>

        <?php if ($moveAddSuccess): ?>
            <p class="message-success"><?= htmlspecialchars($moveAddSuccess) ?></p>
        <?php endif; ?>
        <?php if ($moveAddError): ?>
            <p class="message-error"><?= htmlspecialchars($moveAddError) ?></p>
        <?php endif; ?>

    </div>




    <div class="footer-right">
        Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2024
    </div>

    <script>
        function toggleEditKarta() {
            const container = document.getElementById('editKartaContainer');
            container.style.display = container.style.display === 'none' || container.style.display === '' ? 'block' : 'none';
        }
        function toggleLog() {
            const container = document.getElementById('logContainer');
            container.style.display = container.style.display === 'none' || container.style.display === '' ? 'block' : 'none';
        }
        function togglePrzemieszczenia() {
            const container = document.getElementById('przemieszczeniaContainer');
            container.style.display = container.style.display === 'none' || container.style.display === '' ? 'block' : 'none';
        }

        window.addEventListener('DOMContentLoaded', () => {
            const shouldOpen = <?= json_encode($moveAddSuccess !== null || $moveAddError !== null) ?>;
            if (shouldOpen) {
                const container = document.getElementById('przemieszczeniaContainer');
                if (container) {
                    container.style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>
