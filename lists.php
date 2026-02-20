<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
];

$selectedCollection = $_GET['collection'] ?? ($_POST['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection];

$columns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('collection', $columns, true)) {
    $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
}

function collectionRedirect(string $collection, string $suffix = ''): void {
    header("Location: lists.php?collection=" . urlencode($collection) . $suffix);
    exit;
}

if (isset($_POST['edit_list']) && isset($_POST['list_id'], $_POST['list_name'])) {
    $stmt = $pdo->prepare("UPDATE lists SET list_name = ? WHERE id = ? AND collection = ?");
    $stmt->execute([trim($_POST['list_name']), (int)$_POST['list_id'], $selectedCollection]);
    collectionRedirect($selectedCollection, '&edit=' . (int)$_POST['list_id']);
}

if (isset($_POST['delete_list']) && isset($_POST['list_id'])) {
    $list_id = (int)$_POST['list_id'];
    $pdo->prepare("DELETE li FROM list_items li JOIN lists l ON l.id = li.list_id WHERE li.list_id = ? AND l.collection = ?")
        ->execute([$list_id, $selectedCollection]);
    $pdo->prepare("DELETE FROM lists WHERE id = ? AND collection = ?")->execute([$list_id, $selectedCollection]);
    collectionRedirect($selectedCollection);
}

if (isset($_POST['remove_entry']) && isset($_POST['entry_id'], $_POST['list_id'])) {
    $pdo->prepare("DELETE li FROM list_items li JOIN lists l ON l.id = li.list_id WHERE li.list_id = ? AND li.entry_id = ? AND l.collection = ?")
        ->execute([(int)$_POST['list_id'], (int)$_POST['entry_id'], $selectedCollection]);
    collectionRedirect($selectedCollection, '&edit=' . (int)$_POST['list_id']);
}

if (isset($_POST['add_entry']) && isset($_POST['list_id'], $_POST['entry_id'])) {
    $check = $pdo->prepare("SELECT id FROM lists WHERE id = ? AND collection = ?");
    $check->execute([(int)$_POST['list_id'], $selectedCollection]);
    if ($check->fetchColumn()) {
        $pdo->prepare("INSERT IGNORE INTO list_items (list_id, entry_id) VALUES (?, ?)")
            ->execute([(int)$_POST['list_id'], (int)$_POST['entry_id']]);
    }
    collectionRedirect($selectedCollection, '&edit=' . (int)$_POST['list_id']);
}

$listStmt = $pdo->prepare("SELECT * FROM lists WHERE collection = ? ORDER BY list_name");
$listStmt->execute([$selectedCollection]);
$lists = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$edit_list = null;
$list_entries = [];
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $editStmt = $pdo->prepare("SELECT * FROM lists WHERE id = ? AND collection = ?");
    $editStmt->execute([$edit_id, $selectedCollection]);
    $edit_list = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($edit_list) {
        $entriesStmt = $pdo->prepare(
            "SELECT e.*, li.entry_id FROM list_items li
             JOIN {$mainTable} e ON li.entry_id = e.ID
             WHERE li.list_id = ?"
        );
        $entriesStmt->execute([$edit_id]);
        $list_entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzanie listami | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <a role="button" id="toggleButton" href="index.php?collection=<?php echo urlencode($selectedCollection); ?>" class="back-link">Powrót do strony głównej</a>
    <h2>Listy (<?php echo htmlspecialchars($selectedCollection); ?>)</h2>

    <?php if ($edit_list): ?>
        <h3>Edycja listy: <?php echo htmlspecialchars($edit_list['list_name']); ?></h3>
        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <input type="text" name="list_name" value="<?php echo htmlspecialchars($edit_list['list_name']); ?>" required>
            <button type="submit" name="edit_list" class="edit-btn">Zmień nazwę</button>
        </form>
        <form method="post" onsubmit="return confirm('Usunąć całą listę? Wszystkie przypisania zostaną usunięte.');" style="display:inline;">
            <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <button type="submit" name="delete_list" class="delete-btn">Usuń listę</button>
        </form>
        <h4>Zawartość listy</h4>
        <table class="entries-table">
            <thead>
                <tr><th>ID</th><th>Tytuł / Nazwa</th><th>Autor/Wytwórca</th><th>Opcje</th></tr>
            </thead>
            <tbody>
                <?php foreach ($list_entries as $entry): ?>
                <tr>
                    <td><?php echo $entry['ID']; ?></td>
                    <td><?php echo htmlspecialchars($entry['nazwa_tytul']); ?></td>
                    <td><?php echo htmlspecialchars($entry['autor_wytworca']); ?></td>
                    <td>
                        <a href="karta.php?id=<?php echo $entry['ID']; ?>&collection=<?php echo urlencode($selectedCollection); ?>" class="edit-btn" target="_blank">Karta</a>
                        <form method="post" class="form-inline" onsubmit="return confirm('Usunąć rekord z listy?');">
                            <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
                            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
                            <input type="hidden" name="entry_id" value="<?php echo $entry['ID']; ?>">
                            <button type="submit" name="remove_entry" class="remove-btn">Usuń z listy</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <h4>Dodaj rekord do listy (po ID karty):</h4>
        <form method="post" style="margin-bottom:30px;">
            <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <input type="number" name="entry_id" placeholder="ID rekordu" min="1" required>
            <button type="submit" name="add_entry" class="edit-btn">Dodaj</button>
        </form>
        <a href="lists.php?collection=<?php echo urlencode($selectedCollection); ?>">← Powrót do list</a>
        <hr>
    <?php endif; ?>

    <table class="lists-table">
        <thead><tr><th>ID</th><th>Nazwa listy</th><th>Ilość rekordów</th><th>Opcje</th></tr></thead>
        <tbody>
            <?php foreach ($lists as $list):
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM list_items WHERE list_id = ?");
                $countStmt->execute([(int)$list['id']]);
                $count = $countStmt->fetchColumn();
            ?>
            <tr>
                <td><?php echo $list['id']; ?></td>
                <td><?php echo htmlspecialchars($list['list_name']); ?></td>
                <td><?php echo $count; ?></td>
                <td>
                    <a href="lists.php?collection=<?php echo urlencode($selectedCollection); ?>&edit=<?php echo $list['id']; ?>" class="edit-btn">Edytuj/Zobacz</a>
                    <form method="post" class="form-inline" onsubmit="return confirm('Usunąć tę listę?');">
                        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">
                        <input type="hidden" name="list_id" value="<?php echo $list['id']; ?>">
                        <button type="submit" name="delete_list" class="delete-btn">Usuń</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
