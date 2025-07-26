<?php include('db.php'); ?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Szukaj wpisu</title>
    <link href="https://fonts.googleapis.com/css2?family=Brygada+1918:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css"> <!-- Odwołanie do wspólnego stylu -->
    <!-- Dodaj link do Font Awesome (jeśli jeszcze go nie dodałeś) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <!-- Dodajemy logo -->
    <a href="https://baza.mkal.pl">
        <img src="bazamka.png" alt="Logo bazy" width="400">
    </a>

    <h1>Wyszukaj wpis</h1>
    <h2>
        <form method="POST" action="">
            Podaj frazę do wyszukiwania (dokładne): 
            <input type="text" name="search_term" value="<?php echo isset($_POST['search_term']) ? $_POST['search_term'] : ''; ?>">
            <br><br>
            Podaj frazę do wyszukiwania (przybliżone): 
            <input type="text" name="fuzzy_search_term" value="<?php echo isset($_POST['fuzzy_search_term']) ? $_POST['fuzzy_search_term'] : ''; ?>">
            <br><br>
            <button type="submit" name="search">Wyszukaj</button>
        </form>
    </h2>

    <?php
    // Ustawienia domyślne sortowania
    $sort_column = 'id'; // Domyślna kolumna sortowania
    $order = 'ASC'; // Domyślny kierunek sortowania

    // Jeśli kliknięto na nagłówek tabeli, zmień kolumnę sortowania
    if (isset($_GET['sort'])) {
        $sort_column = $_GET['sort'];
    }

    // Jeśli kliknięto na nagłówek, zmień kolejność sortowania (ASC/DESC)
    if (isset($_GET['order']) && $_GET['order'] == 'DESC') {
        $order = 'DESC';
    } else {
        $order = 'ASC';
    }

    // Zmiana kierunku sortowania dla następnego kliknięcia
    $next_order = ($order == 'ASC') ? 'DESC' : 'ASC';

    // Dokładne wyszukiwanie lub przybliżone wyszukiwanie
    if (isset($_POST['search'])) {
        $search_term = $_POST['search_term'];
        $fuzzy_search_term = $_POST['fuzzy_search_term'];

        // Jeśli wypełnione jest tylko dokładne wyszukiwanie
        if (!empty($search_term)) {
            $sql = "SELECT * FROM karta_ewidencyjna 
                    WHERE `Numer ewidencyjny` LIKE ? 
                       OR `Nazwa/Tytuł` LIKE ? 
                       OR `Autor/Wytwórca` LIKE ? 
                       OR `Czas powstania` LIKE ? 
                       OR `Material` LIKE ? 
                       OR `Technika wykonania` LIKE ? 
                       OR `Cechy charakterystyczne` LIKE ?
                    ORDER BY `$sort_column` $order";

            $stmt = $conn->prepare($sql);
            $search_term_like = '%' . $search_term . '%';
            $stmt->bind_param('sssssss', $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                echo "<h2>Wyniki dokładne:</h2>";
                echo "<table>";
                echo "<thead>";
                echo "<tr>
                        <th width='55'><a href='?sort=id&order=$next_order&search_term=$search_term'>Lp.</a></th>
                        <th width='200'><a href='?sort=Numer ewidencyjny&order=$next_order&search_term=$search_term'>Nr ewid.</a></th>
                        <th width='200'><a href='?sort=Nazwa/Tytuł&order=$next_order&search_term=$search_term'>Nazwa/Tytuł</a></th>
                        <th width='200'><a href='?sort=Autor/Wytwórca&order=$next_order&search_term=$search_term'>Autor/Wytwórca</a></th>
                        <th width='110'><a href='?sort=Czas powstania&order=$next_order&search_term=$search_term'>Czas powstania</a></th>
                        <th width='100'><a href='?sort=Material&order=$next_order&search_term=$search_term'>Materiał</a></th>
                        <th width='150'><a href='?sort=Technika wykonania&order=$next_order&search_term=$search_term'>Technika wykonania</a></th>
                        <th width='333'><a href='?sort=Cechy charakterystyczne&order=$next_order&search_term=$search_term'>Cechy charakterystyczne</a></th>
                        <th width='88'>Akcje</th>
                      </tr>";
                echo "</thead>";
                echo "<tbody>";

                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $row['id'] . "</td>";
                    echo "<td>" . $row['Numer ewidencyjny'] . "</td>";
                    echo "<td>" . $row['Nazwa/Tytuł'] . "</td>";
                    echo "<td>" . $row['Autor/Wytwórca'] . "</td>";
                    echo "<td>" . $row['Czas powstania'] . "</td>";
                    echo "<td>" . $row['Material'] . "</td>";
                    echo "<td>" . $row['Technika wykonania'] . "</td>";
                    echo "<td>" . $row['Cechy charakterystyczne'] . "</td>";
                    echo "<td> &nbsp; 
                         <a href='card.php?id=" . $row['id'] . "'><i class='fas fa-file-alt'></i></a> &nbsp;  &nbsp;
                                <a href='edit.php?id=" . $row['id'] . "'><i class='fas fa-edit'></i></a> |
                                <a href='index.php?delete=" . $row['id'] . "' onclick='return confirm(\"Czy na pewno chcesz usunąć ten wpis?\")'><i class='fas fa-trash-alt'></i></a>
                          </td>";
                    echo "</tr>";
                }
                echo "</tbody>";
                echo "</table>";
            } else {
                echo "<p>Nie znaleziono dokładnych wyników.</p>";
            }
        }
        // Jeśli wypełnione jest tylko przybliżone wyszukiwanie
        elseif (!empty($fuzzy_search_term)) {
            // Użyj funkcji LEVENSHTEIN do wyszukiwania podobnych wyników z bardziej restrykcyjnym limitem
            $max_distance = 2;  // Zmniejszamy maksymalną odległość na 2, aby wyniki były bardziej dokładne
            $sql_fuzzy = "SELECT * FROM karta_ewidencyjna 
                          WHERE LEVENSHTEIN(`Numer ewidencyjny`, ?) <= ? 
                             OR LEVENSHTEIN(`Nazwa/Tytuł`, ?) <= ? 
                             OR LEVENSHTEIN(`Autor/Wytwórca`, ?) <= ?";

            $stmt_fuzzy = $conn->prepare($sql_fuzzy);
            $stmt_fuzzy->bind_param('sisisi', $fuzzy_search_term, $max_distance, $fuzzy_search_term, $max_distance, $fuzzy_search_term, $max_distance);
            $stmt_fuzzy->execute();
            $result_fuzzy = $stmt_fuzzy->get_result();

            if ($result_fuzzy->num_rows > 0) {
                echo "<h2>Wyniki przybliżone:</h2>";
                echo "<table>";
                echo "<thead>";
                echo "<tr>
                        <th width='55'>Lp.</th>
                        <th width='200'>Nr ewid.</th>
                        <th width='200'>Nazwa/Tytuł</th>
                        <th width='200'>Autor/Wytwórca</th>
                        <th width='110'>Czas powstania</th>
                        <th width='100'>Materiał</th>
                        <th width='150'>Technika wykonania</th>
                        <th width='333'>Cechy charakterystyczne</th>
                        <th width='88'>Akcje</th>
                      </tr>";
                echo "</thead>";
                echo "<tbody>";

                while ($row_fuzzy = $result_fuzzy->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $row_fuzzy['id'] . "</td>";
                    echo "<td>" . $row_fuzzy['Numer ewidencyjny'] . "</td>";
                    echo "<td>" . $row_fuzzy['Nazwa/Tytuł'] . "</td>";
                    echo "<td>" . $row_fuzzy['Autor/Wytwórca'] . "</td>";
                    echo "<td>" . $row_fuzzy['Czas powstania'] . "</td>";
                    echo "<td>" . $row_fuzzy['Material'] . "</td>";
                    echo "<td>" . $row_fuzzy['Technika wykonania'] . "</td>";
                    echo "<td>" . $row_fuzzy['Cechy charakterystyczne'] . "</td>";
                    echo "<td> &nbsp; 
                         <a href='card.php?id=" . $row_fuzzy['id'] . "'><i class='fas fa-file-alt'></i></a> &nbsp;  &nbsp;
                                <a href='edit.php?id=" . $row_fuzzy['id'] . "'><i class='fas fa-edit'></i></a> |
                                <a href='index.php?delete=" . $row_fuzzy['id'] . "' onclick='return confirm(\"Czy na pewno chcesz usunąć ten wpis?\")'><i class='fas fa-trash-alt'></i></a>
                          </td>";
                    echo "</tr>";
                }
                echo "</tbody>";
                echo "</table>";
            } else {
                echo "<p>Nie znaleziono przybliżonych wyników.</p>";
            }
        }
    }
    ?>
</body>
</html>
