<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak uprawnień.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$entry_id = isset($input['entry_id']) ? (int)$input['entry_id'] : 0;
$user = (int)$_SESSION['user_id'];

if ($name === '' || $entry_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Brak wymaganych danych.']);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO lists (list_name, user) VALUES (?, ?)");
$stmt->execute([$name, $user]);

$list_id = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
$success = $stmt->execute([$list_id, $entry_id]);

echo json_encode(['success' => $success, 'list_id' => $list_id]);
?>
