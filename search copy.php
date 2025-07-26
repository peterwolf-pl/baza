<?php
session_start();
include 'db.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize results arrays
$search_results = [];
$fuzzy_results = [];


// Get sort parameters from the URL
$sort_column = $_GET['sort'] ?? 'numer_ewidencyjny'; // Default column to sort by
$sort_order = $_GET['order'] ?? 'ASC'; // Default sort order

// Ensure only allowed columns and order values are used to prevent SQL injection
$allowed_columns = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca', 'material'];
if (!in_array($sort_column, $allowed_columns)) {
    $sort_column = 'numer_ewidencyjny';
}
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

// Check if an exact or fuzzy search was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = $_POST['query'] ?? '';

    // Exact Search
    if (!empty($query)) {
        // Modified query to include dynamic ORDER BY clause
        $stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna 
                               WHERE numer_ewidencyjny LIKE :query 
                               OR nazwa_tytul LIKE :query
                               OR autor_wytworca LIKE :query
                               OR material LIKE :query
                               ORDER BY $sort_column $sort_order");
        $stmt->execute(['query' => '%' . $query . '%']);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    // Fuzzy Search with similarity threshold
    if (isset($_POST['fuzzy_search'])) {
        $threshold = 50;  // Set similarity threshold percentage
        $stmt = $pdo->query("SELECT * FROM karta_ewidencyjna");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $similarity_score = 0;
            $fields = ['numer_ewidencyjny', 'nazwa_tytul', 'autor_wytworca', 'material'];

            // Calculate similarity with each field and check if it meets the threshold
            foreach ($fields as $field) {
                similar_text($query, $row[$field], $percent);
                $similarity_score = max($similarity_score, $percent);
            }

            if ($similarity_score >= $threshold) {
                $row['similarity_score'] = $similarity_score;  // Add similarity score for display
                $fuzzy_results[] = $row;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Search Records</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .search-form, .fuzzy-search-form { margin-bottom: 20px; }
        .results-table { border-collapse: collapse; width: 100%; }
        .results-table th, .results-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .results-table th { background-color: #f2f2f2; }

      
        /* Collapsible styling */
        #columnSelectorContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; }
        #toggleButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; }
    
    </style>
</head>
<body>

 <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
    </div>

<h2>Search Records</h2>

<!-- Exact Search Form -->
<form method="post" class="search-form">
    <label for="query">Exact Search:</label>
    <input type="text" name="query" id="query" required placeholder="Enter keywords...">
    <button type="submit" id="toggleButton" >Search</button>
</form>

<!-- Fuzzy Search Form -->
<form method="post" class="fuzzy-search-form">
    <input type="hidden" name="fuzzy_search" value="1">
    <label for="query">Fuzzy Search (approximate match):</label>
    <input type="text" name="query" id="query" required placeholder="Enter keywords...">
    <button type="submit"  id="toggleButton" >Search</button>
</form>

<!-- Display Exact Search Results -->
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($search_results)): ?>
    <h2>Exact Search Results for "<?php echo htmlspecialchars($query); ?>"</h2>

    <?php if (empty($search_results)): ?>
        <p>No exact matches found.</p>
    <?php else: ?>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Numer Ewidencyjny</th>
                    <th>Nazwa Tytuł</th>
                    <th>Autor Wytwórca</th>
                    <th>Material</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($search_results as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result['numer_ewidencyjny']); ?></td>
                        <td><?php echo htmlspecialchars($result['nazwa_tytul']); ?></td>
                        <td><?php echo htmlspecialchars($result['autor_wytworca']); ?></td>
                        <td><?php echo htmlspecialchars($result['material']); ?></td>
                        <td><a href="karta.php?id=<?php echo $result['ID']; ?>">View Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<!-- Display Fuzzy Search Results -->
<?php if (!empty($fuzzy_results)): ?>
    <h2>Fuzzy Search Results for "<?php echo htmlspecialchars($query); ?>" (similarity &ge; 50%)</h2>

    <?php if (empty($fuzzy_results)): ?>
        <p>No fuzzy matches found.</p>
    <?php else: ?>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Numer Ewidencyjny</th>
                    <th>Nazwa Tytuł</th>
                    <th>Autor Wytwórca</th>
                    <th>Material</th>
                    <th>Similarity (%)</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fuzzy_results as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result['numer_ewidencyjny']); ?></td>
                        <td><?php echo htmlspecialchars($result['nazwa_tytul']); ?></td>
                        <td><?php echo htmlspecialchars($result['autor_wytworca']); ?></td>
                        <td><?php echo htmlspecialchars($result['material']); ?></td>
                        <td><?php echo round($result['similarity_score'], 2); ?>%</td>
                        <td><a href="karta.php?id=<?php echo $result['ID']; ?>">View Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
