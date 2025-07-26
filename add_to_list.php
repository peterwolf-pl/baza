<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

try {
    // Odczyt danych przesłanych w żądaniu
    $input = json_decode(file_get_contents('php://input'), true);
    $list_id = $input['list_id'] ?? null;
    $entry_id = $input['entry_id'] ?? null;

    // Walidacja danych
    if (!$list_id || !$entry_id) {
        echo json_encode(['success' => false, 'message' => 'Brak wymaganych danych.']);
        exit;
    }

    // Sprawdzenie, czy użytkownik jest zalogowany
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Brak uprawnień.']);
        exit;
    }

    // Sprawdzenie, czy wpis już jest w liście
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_items WHERE list_id = ? AND entry_id = ?");
    $stmt->execute([$list_id, $entry_id]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Wpis już istnieje w tej liście.']);
        exit;
    }

    // Dodanie wpisu do listy
    $stmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
    $success = $stmt->execute([$list_id, $entry_id]);

    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
