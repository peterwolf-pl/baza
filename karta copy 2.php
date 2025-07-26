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

// Fetch all entries from karta_ewidencyjna_przemieszczenia with the same karta_id
$przemieszczenia_stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna_przemieszczenia WHERE karta_id = :id");
$przemieszczenia_stmt->execute(['id' => $id]);
$przemieszczenia_rows = $przemieszczenia_stmt->fetchAll(PDO::FETCH_ASSOC);


?>


<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Karta Ewidencji</title>

    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .image-container img { max-width: 100%; height: auto; }
        /*.form-container { margin-top: 20px; } */
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
        

        .footer-right {
  position: fixed;
  bottom: 10px;
  right: 30px;
  font-size: 10px;
  font-family: Arial, sans-serif;
}

.footer-right a {
  color: #007bff; /* Możesz zmienić kolor */
  text-decoration: none;
}

.footer-right a:hover {
  text-decoration: underline;
}

    </style>
</head>
<body>
     <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    </div>

        </div>
    <a role="button" id="togglePrzemieszczeniaButton" href="index.php">Powrót do listy</a> 
</div>

    <h1>Karta Ewidencji</h1>
    <table>
     
            
        
              
       

        <?php foreach ($row as $key => $value): ?>
            <tr>
                

                <th width="300px"><?= htmlspecialchars($key) ?></th>
                <td ><?= htmlspecialchars($value) ?></td>
   
 </tr>
                 
        <?php endforeach; ?>

              <tr>
               <?php if ($image_path): ?>
       
            <img src="<?= $image_path ?>" alt="Obrazek obiektu" width="600">
            <?php endif; ?>
        
    </tr>
    
     
    
           
 
       
    </table>

<br><br>

   
        <h2>Edytuj Kartę</h2>
         <table>
        <form method="post">
            <input type="hidden" name="edit_karta" value="1">
            <?php foreach ($row as $key => $value): ?>
                 <tr>
                <th width="300px"><label for="<?= $key ?>"><?= htmlspecialchars($key) ?></label></th>
                <td><input 
                    type="text" 
                    name="<?= $key ?>" 
                    id="<?= $key ?>" 
                    value="<?= htmlspecialchars($value) ?>"></td>
         <?php endforeach; ?>
            </tr>
                
    
    </table>
     
            <button id="toggleButton" type="submit">Zapisz zmiany</button>
        </form>

<br><br>

    <h2>Rejestr Zmian</h2>
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



    <button id="togglePrzemieszczeniaButton" onclick="togglePrzemieszczenia()">Pokaż tabele przemieszczeń</button>
    
    <br><br>
<div class="footer-right">
  Muzeum Książki Artystycznej w Łodzi &reg; All Rights Reserved. &nbsp; &nbsp; &nbsp;  &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2024
</div>



    <!-- Movements and new movement form -->
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($przemieszczenia_rows as $przemieszczenie): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($przemieszczenie['data_przemieszczenia']); ?></td>
                        <td><?php echo htmlspecialchars($przemieszczenie['data_zwrotu']); ?></td>
                        <td><?php echo htmlspecialchars($przemieszczenie['numer_przemieszczenia']); ?></td>
                                                <td><?php echo htmlspecialchars($przemieszczenie['miejsce_przemieszczenia']); ?></td>
                        <td><?php echo htmlspecialchars($przemieszczenie['powod_cel_przemieszczenia']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Add New Movement Form -->
        <h3>Dodaj nowe przemieszczenie:</h3>
        <form method="post" class="add-form">
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

            <button id="togglePrzemieszczeniaButton" type="submit">Dodaj</button>
        </form>



<script>
// JavaScript to toggle the visibility of the przemieszczeniaContainer
function togglePrzemieszczenia() {
    const container = document.getElementById('przemieszczeniaContainer');
    const button = document.getElementById('togglePrzemieszczeniaButton');
    
    if (container.style.display === 'none' || container.style.display === '') {
        container.style.display = 'block';
        button.textContent = 'Ukryj tabelę przemieszczeń';
    } else {
        container.style.display = 'none';
        button.textContent = 'Pokaż tabelę przemieszczeń';
    }
}

// JavaScript to toggle the visibility of the edit form
function toggleEditForm() {
    const editForm = document.getElementById('editForm');
    editForm.style.display = editForm.style.display === 'none' || editForm.style.display === '' ? 'block' : 'none';
}
</script>

</body>
</html>
