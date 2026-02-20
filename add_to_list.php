<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $list_id = isset($input['list_id']) ? (int)$input['list_id'] : 0;
    $entry_id = isset($input['entry_id']) ? (int)$input['entry_id'] : 0;
    $collection = $input['collection'] ?? 'ksiazki-artystyczne';
    $allowedCollections = ['ksiazki-artystyczne', 'kolekcja-maszyn', 'kolekcja-matryc', 'biblioteka'];

    if (!in_array($collection, $allowedCollections, true)) {
        $collection = 'ksiazki-artystyczne';
    }

    if ($list_id <= 0 || $entry_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Brak wymaganych danych.']);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Brak uprawnień.']);
        exit;
    }

    $columns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('collection', $columns, true)) {
        $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
    }

    $listCheck = $pdo->prepare("SELECT id FROM lists WHERE id = ? AND collection = ?");
    $listCheck->execute([$list_id, $collection]);
    if (!$listCheck->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Lista nie należy do tej kolekcji.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_items WHERE list_id = ? AND entry_id = ?");
    $stmt->execute([$list_id, $entry_id]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Wpis już istnieje w tej liście.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
    $success = $stmt->execute([$list_id, $entry_id]);

    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
