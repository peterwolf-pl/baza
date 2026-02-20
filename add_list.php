<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak uprawnień.']);
    exit;
}

function ensureListsCollectionColumn(PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('collection', $columns, true)) {
        $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
    }
}

$allowedCollections = ['ksiazki-artystyczne', 'kolekcja-maszyn', 'kolekcja-matryc', 'biblioteka'];

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$entry_id = isset($input['entry_id']) ? (int)$input['entry_id'] : 0;
$collection = $input['collection'] ?? 'ksiazki-artystyczne';
$user = (int)$_SESSION['user_id'];

if (!in_array($collection, $allowedCollections, true)) {
    $collection = 'ksiazki-artystyczne';
}

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Brak nazwy listy.']);
    exit;
}

ensureListsCollectionColumn($pdo);

$stmt = $pdo->prepare("INSERT INTO lists (list_name, user, collection) VALUES (?, ?, ?)");
$stmt->execute([$name, $user, $collection]);

$list_id = (int)$pdo->lastInsertId();

if ($entry_id > 0) {
    $stmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
    $stmt->execute([$list_id, $entry_id]);
}

echo json_encode(['success' => true, 'list_id' => $list_id, 'id' => $list_id]);
