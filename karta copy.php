<?php
// Include database connection


session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");  // Redirect to login if not logged in
    exit;
}

include 'db.php';

// Check if the ID is set in the URL
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Fetch the record with the specified ID
    $stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die("Record not found.");
    }

    // Clean the 'dokumentacja_wizualna' entry by removing single quotes
    $cleaned_filename = !empty($row['dokumentacja_wizualna']) ? str_replace("'", "", $row['dokumentacja_wizualna']) : null;
    $image_path = $cleaned_filename ? 'https://mkalodz.pl/bazagfx/' . htmlspecialchars($cleaned_filename) : null;

 if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_karta'])) {
    // Collect and sanitize all data from the form
    $updated_data = [
        'numer_ewidencyjny' => $_POST['numer_ewidencyjny'] ?? $row['numer_ewidencyjny'],
        'nazwa_tytul' => $_POST['nazwa_tytul'] ?? $row['nazwa_tytul'],
        'autor_wytworca' => $_POST['autor_wytworca'] ?? $row['autor_wytworca'],
        'data_powstania' => $_POST['data_powstania'] ?? $row['data_powstania'],
        'material' => $_POST['material'] ?? $row['material'],
        'wymiary' => $_POST['wymiary'] ?? $row['wymiary'],
        'opis' => $_POST['opis'] ?? $row['opis'],
        'dokumentacja_wizualna' => $_POST['dokumentacja_wizualna'] ?? $row['dokumentacja_wizualna'],
        'status' => $_POST['status'] ?? $row['status'],
        'lokalizacja' => $_POST['lokalizacja'] ?? $row['lokalizacja'],
        'uwagi' => $_POST['uwagi'] ?? $row['uwagi'],
        'numer_inwentarza' => $_POST['numer_inwentarza'] ?? $row['numer_inwentarza'],
        'numer_katalogowy' => $_POST['numer_katalogowy'] ?? $row['numer_katalogowy'],
        'data_dodania' => $_POST['data_dodania'] ?? $row['data_dodania'],
        'kategoria' => $_POST['kategoria'] ?? $row['kategoria'],
        'kod_miejsca' => $_POST['kod_miejsca'] ?? $row['kod_miejsca'],
        'czy_wystawiany' => $_POST['czy_wystawiany'] ?? $row['czy_wystawiany'],
        'data_ostatniej_inwentaryzacji' => $_POST['data_ostatniej_inwentaryzacji'] ?? $row['data_ostatniej_inwentaryzacji'],
        'wartosc' => $_POST['wartosc'] ?? $row['wartosc'],
        'stan_obiektu' => $_POST['stan_obiektu'] ?? $row['stan_obiektu'],
        'data_ostatniej_konserwacji' => $_POST['data_ostatniej_konserwacji'] ?? $row['data_ostatniej_konserwacji'],
        'czy_podlega_konserwacji' => $_POST['czy_podlega_konserwacji'] ?? $row['czy_podlega_konserwacji'],
        'opis_stanu' => $_POST['opis_stanu'] ?? $row['opis_stanu'],
        'osoba_odpowiedzialna' => $_POST['osoba_odpowiedzialna'] ?? $row['osoba_odpowiedzialna'],
        'jednostka' => $_POST['jednostka'] ?? $row['jednostka'],
        'numer_zewnetrzny' => $_POST['numer_zewnetrzny'] ?? $row['numer_zewnetrzny'],
    ];

    // Update query
    $update_stmt = $pdo->prepare("UPDATE karta_ewidencyjna SET 
        numer_ewidencyjny = :numer_ewidencyjny,
        nazwa_tytul = :nazwa_tytul, 
        autor_wytworca = :autor_wytworca, 
        data_powstania = :data_powstania,
        material = :material,
        wymiary = :wymiary,
        opis = :opis,
        dokumentacja_wizualna = :dokumentacja_wizualna,
        status = :status,
        lokalizacja = :lokalizacja,
        uwagi = :uwagi,
        numer_inwentarza = :numer_inwentarza,
        numer_katalogowy = :numer_katalogowy,
        data_dodania = :data_dodania,
        kategoria = :kategoria,
        kod_miejsca = :kod_miejsca,
        czy_wystawiany = :czy_wystawiany,
        data_ostatniej_inwentaryzacji = :data_ostatniej_inwentaryzacji,
        wartosc = :wartosc,
        stan_obiektu = :stan_obiektu,
        data_ostatniej_konserwacji = :data_ostatniej_konserwacji,
        czy_podlega_konserwacji = :czy_podlega_konserwacji,
        opis_stanu = :opis_stanu,
        osoba_odpowiedzialna = :osoba_odpowiedzialna,
        jednostka = :jednostka,
        numer_zewnetrzny = :numer_zewnetrzny
        WHERE ID = :id");

    $update_stmt->execute(array_merge($updated_data, ['id' => $id]));

    // Refresh the page to show updated data
    header("Location: karta.php?id=" . $id);
    exit;
}


    // Fetch all entries from karta_ewidencyjna_przemieszczenia with the same karta_id
    $przemieszczenia_stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna_przemieszczenia WHERE karta_id = :id");
    $przemieszczenia_stmt->execute(['id' => $id]);
    $przemieszczenia_rows = $przemieszczenia_stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    die("No ID provided.");
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>View Record</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; display: flex; gap: 20px; }
        .details { width: 50%; }
        .image-container { width: 700px; padding-top: 150px;}
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .back-link { margin-top: 20px; display: inline-block; }
        #przemieszczeniaContainer { display: none; padding-top: 10px; }
        #togglePrzemieszczeniaButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #togglePrzemieszczeniaButton:focus { outline: none; }
         #toggleButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; }
        .add-form, .edit-form { margin-top: 20px; }
        .add-form input, .add-form textarea, .edit-form input, .edit-form textarea { width: 100%; padding: 8px; margin-bottom: 10px; }
        .image-container img { max-width: 100%; height: auto; }
    </style>
</head>
<body>

    
    
<div class="details">
    <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    <br>
    <h1>Karta ewidencji</h1>

    <!-- Display existing data -->
    <table>
        <?php foreach ($row as $column => $value): ?>
            <tr>
                <th><?php echo htmlspecialchars($column); ?></th>
                <td><?php echo htmlspecialchars($value !== null ? str_replace("'", "", $value) : "-"); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <!-- Edit button to toggle edit form -->
    <button id="toggleButton" onclick="toggleEditForm()">Edytuj kartę ewidencji</button>

   <!-- Edit form to update all fields in karta_ewidencyjna -->
<form method="post" class="edit-form" id="editForm" style="display: none;">
    <input type="hidden" name="edit_karta" value="1">
    
    <label for="numer_ewidencyjny">Numer Ewidencyjny</label>
    <input type="text" name="numer_ewidencyjny" id="numer_ewidencyjny" value="<?php echo htmlspecialchars($row['numer_ewidencyjny']); ?>">

    <label for="nazwa_tytul">Nazwa Tytuł</label>
    <input type="text" name="nazwa_tytul" id="nazwa_tytul" value="<?php echo htmlspecialchars($row['nazwa_tytul']); ?>">

    <label for="autor_wytworca">Autor Wytwórca</label>
    <input type="text" name="autor_wytworca" id="autor_wytworca" value="<?php echo htmlspecialchars($row['autor_wytworca']); ?>">

    <label for="data_powstania">Data Powstania</label>
    <input type="date" name="data_powstania" id="data_powstania" value="<?php echo htmlspecialchars($row['data_powstania']); ?>">

    <label for="material">Materiał</label>
    <input type="text" name="material" id="material" value="<?php echo htmlspecialchars($row['material']); ?>">

    <label for="wymiary">Wymiary</label>
    <input type="text" name="wymiary" id="wymiary" value="<?php echo htmlspecialchars($row['wymiary']); ?>">

    <label for="opis">Opis</label>
    <textarea name="opis" id="opis"><?php echo htmlspecialchars($row['opis']); ?></textarea>

    <label for="dokumentacja_wizualna">Dokumentacja Wizualna</label>
    <input type="text" name="dokumentacja_wizualna" id="dokumentacja_wizualna" value="<?php echo htmlspecialchars($row['dokumentacja_wizualna']); ?>">

    <label for="status">Status</label>
    <input type="text" name="status" id="status" value="<?php echo htmlspecialchars($row['status']); ?>">

    <label for="lokalizacja">Lokalizacja</label>
    <input type="text" name="lokalizacja" id="lokalizacja" value="<?php echo htmlspecialchars($row['lokalizacja']); ?>">

    <label for="uwagi">Uwagi</label>
    <textarea name="uwagi" id="uwagi"><?php echo htmlspecialchars($row['uwagi']); ?></textarea>

    <label for="numer_inwentarza">Numer Inwentarza</label>
    <input type="text" name="numer_inwentarza" id="numer_inwentarza" value="<?php echo htmlspecialchars($row['numer_inwentarza']); ?>">

    <label for="numer_katalogowy">Numer Katalogowy</label>
    <input type="text" name="numer_katalogowy" id="numer_katalogowy" value="<?php echo htmlspecialchars($row['numer_katalogowy']); ?>">

    <label for="data_dodania">Data Dodania</label>
    <input type="date" name="data_dodania" id="data_dodania" value="<?php echo htmlspecialchars($row['data_dodania']); ?>">

    <label for="kategoria">Kategoria</label>
    <input type="text" name="kategoria" id="kategoria" value="<?php echo htmlspecialchars($row['kategoria']); ?>">

    <label for="kod_miejsca">Kod Miejsca</label>
    <input type="text" name="kod_miejsca" id="kod_miejsca" value="<?php echo htmlspecialchars($row['kod_miejsca']); ?>">

    <label for="czy_wystawiany">Czy Wystawiany</label>
    <input type="text" name="czy_wystawiany" id="czy_wystawiany" value="<?php echo htmlspecialchars($row['czy_wystawiany']); ?>">

    <label for="data_ostatniej_inwentaryzacji">Data Ostatniej Inwentaryzacji</label>
    <input type="date" name="data_ostatniej_inwentaryzacji" id="data_ostatniej_inwentaryzacji" value="<?php echo htmlspecialchars($row['data_ostatniej_inwentaryzacji']); ?>">

    <label for="wartosc">Wartość</label>
    <input type="text" name="wartosc" id="wartosc" value="<?php echo htmlspecialchars($row['wartosc']); ?>">

    <label for="stan_obiektu">Stan Obiektu</label>
    <input type="text" name="stan_obiektu" id="stan_obiektu" value="<?php echo htmlspecialchars($row['stan_obiektu']); ?>">

    <label for="data_ostatniej_konserwacji">Data Ostatniej Konserwacji</label>
    <input type="date" name="data_ostatniej_konserwacji" id="data_ostatniej_konserwacji" value="<?php echo htmlspecialchars($row['data_ostatniej_konserwacji']); ?>">

    <label for="czy_podlega_konserwacji">Czy Podlega Konserwacji</label>
    <input type="text" name="czy_podlega_konserwacji" id="czy_podlega_konserwacji" value="<?php echo htmlspecialchars($row['czy_podlega_konserwacji']); ?>">

    <label for="opis_stanu">Opis Stanu</label>
    <textarea name="opis_stanu" id="opis_stanu"><?php echo htmlspecialchars($row['opis_stanu']); ?></textarea>

    <label for="osoba_odpowiedzialna">Osoba Odpowiedzialna</label>
    <input type="text" name="osoba_odpowiedzialna" id="osoba_odpowiedzialna" value="<?php echo htmlspecialchars($row['osoba_odpowiedzialna']); ?>">

    <label for="jednostka">Jednostka</label>
    <input type="text" name="jednostka" id="jednostka" value="<?php echo htmlspecialchars($row['jednostka']); ?>">

    <label for="numer_zewnetrzny">Numer Zewnętrzny</label>
    <input type="text" name="numer_zewnetrzny" id="numer_zewnetrzny" value="<?php echo htmlspecialchars($row['numer_zewnetrzny']); ?>">

    <button id="togglePrzemieszczeniaButton" type="submit">Zapisz zmiany</button>
</form>

        <!-- Add other fields here as needed -->

     

    <button id="togglePrzemieszczeniaButton" onclick="togglePrzemieszczenia()">Pokaż tabele przemieszczeń</button>
    
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
    </div>

    <a role="button" id="togglePrzemieszczeniaButton" href="index.php">Powrót do listy</a> 
</div>

<div class="image-container">
    <?php if ($image_path): ?>
        <img src="<?php echo $image_path; ?>" alt="Obrazek obiektu">
    <?php else: ?>
        <p>Brak dostępnego obrazka</p>
    <?php endif; ?>
</div>

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

