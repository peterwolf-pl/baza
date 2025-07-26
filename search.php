<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

// Lista wszystkich pól tabeli `karta_ewidencyjna`
$fields = [
    'numer_ewidencyjny', 'nazwa_tytul', 'czas_powstania', 'inne_numery_ewidencyjne',
    'autor_wytworca', 'miejsce_powstania', 'liczba', 'material', 
    'dokumentacja_wizualna', 'dzial', 'pochodzenie', 'technika_wykonania', 
    'wymiary', 'cechy_charakterystyczne', 'dane_o_dokumentacji_wizualnej', 
    'wlasciciel', 'sposob_oznakowania', 'autorskie_prawa_majatkowe', 
    'kontrola_zbiorow', 'wartosc_w_dniu_nabycia', 'wartosc_w_dniu_sporzadzenia', 
    'miejsce_przechowywania', 'uwagi', 'data_opracowania', 'opracowujacy', 
    'przemieszczenia'
];

$search_results = [];
$fuzzy_results = [];

/**
 * Funkcja normalizująca tekst.
 * - Trymowanie białych znaków
 * - Zamiana na małe litery
 * - Usunięcie dodatkowych białych znaków wewnętrznych
 *
 * @param string $text Tekst do normalizacji
 * @return string
 */
function normalizeText($text) {
    return strtolower(trim(preg_replace('/\s+/', ' ', $text)));
}

// Obsługa zapytania wyszukiwania
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim($_POST['query'] ?? '');
    $levenshtein_distance = (int)($_POST['levenshtein_distance'] ?? 3);

    if (!empty($query)) {
        // Normalizacja zapytania
        $query = normalizeText($query);

        // Tworzenie warunków wyszukiwania SQL dla wszystkich pól
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = "LOWER($field) LIKE :query";
        }
        $where_clause = implode(' OR ', $conditions);

        // Dokładne wyszukiwanie
        try {
            $stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna WHERE $where_clause");
            $stmt->execute(['query' => '%' . $query . '%']);
            $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo "<p><strong>SQL Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }

        // Przybliżone wyszukiwanie
        try {
            $sql_fuzzy = "
                SELECT * 
                FROM karta_ewidencyjna
                WHERE (
                    LEVENSHTEIN(nazwa_tytul, ?) <= ?
                    OR LEVENSHTEIN(autor_wytworca, ?) <= ?
                )
                LIMIT 50";
            
            $stmt_fuzzy = $pdo->prepare($sql_fuzzy);
            $stmt_fuzzy->execute([$query, $levenshtein_distance, $query, $levenshtein_distance]);
            $fuzzy_results = $stmt_fuzzy->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo "<p><strong>SQL Error (Fuzzy):</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p><strong>Warning:</strong> Zapytanie nie może być puste!</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Search Records in All Fields</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .header { margin-bottom: 20px; }
        .search-form { margin-bottom: 20px; }
        .results-table { border-collapse: collapse; width: 100%; }
        .results-table th, .results-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .results-table th { background-color: #f2f2f2; }
        #levenshtein_distance_value { font-weight: bold; }
        .highlight { background-color: yellow; font-weight: bold; }
        .fuzzy-highlight { background-color: lightgreen; font-weight: bold; }
    </style>
    <script>
        function updateLevenshteinValue(val) {
            document.getElementById('levenshtein_distance_value').textContent = val;
        }

        function highlightQuery(query, fuzzy = false) {
            if (query.length > 0) {
                const tables = document.querySelectorAll('.results-table tbody');
                tables.forEach(tbody => {
                    tbody.querySelectorAll('td').forEach(td => {
                        const regex = new RegExp('(' + query + ')', 'gi');
                        if (fuzzy) {
                            td.innerHTML = td.textContent.replace(regex, '<span class="fuzzy-highlight">$1</span>');
                        } else {
                            td.innerHTML = td.textContent.replace(regex, '<span class="highlight">$1</span>');
                        }
                    });
                });
            }
        }
    </script>
</head>
<body>

<div class="header">
    <a href="https://baza.mkal.pl">
        <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
    </a>
</div>

<a href="https://baza.mkal.pl">Powrót do strony głównej</a>

<h2>Search Records in All Fields</h2>

<!-- Formularz wyszukiwania -->
<form method="post" class="search-form">
    <label for="query">Search:</label>
    <input type="text" name="query" id="query" placeholder="Enter keywords..." required>
    <br><br>
    <label for="levenshtein_distance">Levenshtein Distance:</label>
    <input type="range" name="levenshtein_distance" id="levenshtein_distance" min="1" max="5" value="3" oninput="updateLevenshteinValue(this.value)">
    <span id="levenshtein_distance_value">3</span>
    <br><br>
    <button type="submit" name="search">Search</button>
</form>

<!-- Wyniki wyszukiwania -->
<?php if (!empty($search_results)): ?>
    <h2>Exact Search Results</h2>
    <table class="results-table">
        <thead>
            <tr>
                <?php foreach ($fields as $field): ?>
                    <th><?php echo htmlspecialchars($field); ?></th>
                <?php endforeach; ?>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($search_results as $result): ?>
                <tr>
                    <?php foreach ($fields as $field): ?>
                        <td><?php echo htmlspecialchars($result[$field] ?? ''); ?></td>
                    <?php endforeach; ?>
                    <td><a href="karta.php?id=<?php echo $result['ID']; ?>">View Details</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>
        highlightQuery("<?php echo htmlspecialchars($query); ?>");
    </script>
<?php endif; ?>

<!-- Wyniki wyszukiwania fuzzy -->
<?php if (!empty($fuzzy_results)): ?>
    <h2>Fuzzy Search Results</h2>
    <table class="results-table">
        <thead>
            <tr>
                <?php foreach ($fields as $field): ?>
                    <th><?php echo htmlspecialchars($field); ?></th>
                <?php endforeach; ?>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fuzzy_results as $result): ?>
                <tr>
                    <?php foreach ($fields as $field): ?>
                        <td><?php echo htmlspecialchars($result[$field] ?? ''); ?></td>
                    <?php endforeach; ?>
                    <td><a href="karta.php?id=<?php echo $result['ID']; ?>">View Details</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>
        highlightQuery("<?php echo htmlspecialchars($query); ?>", true);
    </script>
<?php endif; ?>

<?php if (empty($search_results) && empty($fuzzy_results) && !empty($query)): ?>
    <p>Brak wyników w wyszukiwaniu.</p>
<?php endif; ?>

</body>
</html>
