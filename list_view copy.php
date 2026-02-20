<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$list_id = $_GET['list_id'] ?? null;

if (!$list_id) {
    echo "Nieprawidłowy identyfikator listy.";
    exit;
}

// Fetch list name
$stmt = $pdo->prepare("SELECT list_name FROM lists WHERE id = ?");
$stmt->execute([$list_id]);
$list = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$list) {
    echo "Lista nie istnieje.";
    exit;
}

// Fetch entries in the list
$stmt = $pdo->prepare("
    SELECT ke.* 
    FROM list_items li
    JOIN karta_ewidencyjna ke ON li.entry_id = ke.ID
    WHERE li.list_id = ?
");
$stmt->execute([$list_id]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>baza.mkal.pl - Lista: <?php echo htmlspecialchars($list['list_name']); ?></title>
        <link rel="stylesheet" href="styles.css">
</head>
<body>
        <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy Muzeum Książki Artystycznej" class="logo">
        </a>
        <div class="header-links">
            <?php
            foreach ($lists as $list) {
                echo "<a href='list_view.php?list_id={$list['id']}'>{$list['list_name']}</a>";
            }
            ?>
        </div>
    </div>
    
    <h1>Lista: <?php echo htmlspecialchars($list['list_name']); ?></h1>
    <a href="index.php">Wróć do głównej</a>
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <?php
                    // Dynamically fetch table column names
                    $columns = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($columns as $col) {
                        echo "<th>" . htmlspecialchars($col['Field']) . "</th>";
                    }
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td><?php echo htmlspecialchars($entry[$col['Field']] ?? ''); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
