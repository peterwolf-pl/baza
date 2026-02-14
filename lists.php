<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

// Edycja nazwy listy
if (isset($_POST['edit_list']) && isset($_POST['list_id'], $_POST['list_name'])) {
    $stmt = $pdo->prepare("UPDATE lists SET list_name = ? WHERE id = ?");
    $stmt->execute([trim($_POST['list_name']), (int)$_POST['list_id']]);
    header("Location: lists.php?edit={$_POST['list_id']}");
    exit;
}

// Usuwanie listy
if (isset($_POST['delete_list']) && isset($_POST['list_id'])) {
    $list_id = (int)$_POST['list_id'];
    $pdo->prepare("DELETE FROM list_items WHERE list_id = ?")->execute([$list_id]);
    $pdo->prepare("DELETE FROM lists WHERE id = ?")->execute([$list_id]);
    header("Location: lists.php");
    exit;
}

// Usuwanie wpisu z listy
if (isset($_POST['remove_entry']) && isset($_POST['entry_id'], $_POST['list_id'])) {
    $pdo->prepare("DELETE FROM list_items WHERE list_id = ? AND entry_id = ?")->execute([
        (int)$_POST['list_id'], (int)$_POST['entry_id']
    ]);
    header("Location: lists.php?edit={$_POST['list_id']}");
    exit;
}

// Dodawanie wpisu do listy (po ID karty)
if (isset($_POST['add_entry']) && isset($_POST['list_id'], $_POST['entry_id'])) {
    $pdo->prepare("INSERT IGNORE INTO list_items (list_id, entry_id) VALUES (?, ?)")->execute([
        (int)$_POST['list_id'], (int)$_POST['entry_id']
    ]);
    header("Location: lists.php?edit={$_POST['list_id']}");
    exit;
}

// Pobierz listy
$lists = $pdo->query("SELECT * FROM lists ORDER BY list_name")->fetchAll(PDO::FETCH_ASSOC);

// Wybrana lista do edycji
$edit_list = null;
$list_entries = [];
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_list = $pdo->query("SELECT * FROM lists WHERE id = $edit_id")->fetch(PDO::FETCH_ASSOC);
    // Zawartość listy z danymi rekordów
    $list_entries = $pdo->query(
        "SELECT e.*, li.entry_id FROM list_items li
         JOIN karta_ewidencyjna e ON li.entry_id = e.ID
         WHERE li.list_id = $edit_id"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzanie listami | baza.mkal.pl</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .lists-table, .entries-table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #ccc; padding: 8px; }
        th { background: #f4f4f4; }
        .edit-btn, .delete-btn, .remove-btn { background: #007BFF; color: white; border: none; padding: 4px 8px; cursor: pointer; border-radius: 4px; margin-right: 4px;}
        .delete-btn, .remove-btn { background: #dc3545; }
        .back-link { display: inline-block; margin-bottom: 18px; }
        .form-inline { display: inline; }
    </style>
</head>
<body>
    <a href="https://baza.mkal.pl" class="back-link">Powrót do strony głównej</a>
    <h2>Listy</h2>

    <?php if ($edit_list): ?>
        <h3>Edycja listy: <?php echo htmlspecialchars($edit_list['list_name']); ?></h3>
        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <input type="text" name="list_name" value="<?php echo htmlspecialchars($edit_list['list_name']); ?>" required>
            <button type="submit" name="edit_list" class="edit-btn">Zmień nazwę</button>
        </form>
        <form method="post" onsubmit="return confirm('Usunąć całą listę? Wszystkie przypisania zostaną usunięte.');" style="display:inline;">
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <button type="submit" name="delete_list" class="delete-btn">Usuń listę</button>
        </form>
        <h4>Zawartość listy</h4>
        <table class="entries-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tytuł / Nazwa</th>
                    <th>Autor/Wytwórca</th>
                    <th>Opcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list_entries as $entry): ?>
                <tr>
                    <td><?php echo $entry['ID']; ?></td>
                    <td><?php echo htmlspecialchars($entry['nazwa_tytul']); ?></td>
                    <td><?php echo htmlspecialchars($entry['autor_wytworca']); ?></td>
                    <td>
                        <a href="karta.php?id=<?php echo $entry['ID']; ?>" class="edit-btn" target="_blank">Karta</a>
                        <form method="post" class="form-inline" onsubmit="return confirm('Usunąć rekord z listy?');">
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
            <input type="hidden" name="list_id" value="<?php echo $edit_list['id']; ?>">
            <input type="number" name="entry_id" placeholder="ID rekordu" min="1" required>
            <button type="submit" name="add_entry" class="edit-btn">Dodaj</button>
        </form>
        <a href="lists.php">← Powrót do list</a>
        <hr>
    <?php endif; ?>

    <table class="lists-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nazwa listy</th>
                <th>Ilość rekordów</th>
                <th>Opcje</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lists as $list): 
                $count = $pdo->query("SELECT COUNT(*) FROM list_items WHERE list_id = " . (int)$list['id'])->fetchColumn();
            ?>
            <tr>
                <td><?php echo $list['id']; ?></td>
                <td><?php echo htmlspecialchars($list['list_name']); ?></td>
                <td><?php echo $count; ?></td>
                <td>
                    <a href="lists.php?edit=<?php echo $list['id']; ?>" class="edit-btn">Edytuj/Zobacz</a>
                    <form method="post" class="form-inline" onsubmit="return confirm('Usunąć tę listę?');">
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
