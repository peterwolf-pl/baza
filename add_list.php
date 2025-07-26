<?php
session_start();
include 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$name = $input['name'];
$entry_id = $input['entry_id'];
$user = $_SESSION['user_id'];

$stmt = $pdo->prepare("INSERT INTO lists (list_name, user) VALUES (?, ?)");
$stmt->execute([$name, $user]);

$list_id = $pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
$success = $stmt->execute([$list_id, $entry_id]);

echo json_encode(['success' => $success]);
?>
