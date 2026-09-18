<?php
session_start();
include 'db.php';
require_once __DIR__ . '/museum_system.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak uprawnien.']);
    exit;
}
if (empty($_SESSION['can_edit_lists']) && empty($_SESSION['is_root'])) {
    echo json_encode(['success' => false, 'message' => 'Brak uprawnien do edycji list.']);
    exit;
}

$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
    'kolekcja-klisz' => 'karta_ewidencyjna_klisze',
];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $listId = isset($input['list_id']) ? (int)$input['list_id'] : 0;
    $inventoryNumber = museumNormalizeInventoryLookupValue((string)($input['inventory_number'] ?? ''));
    $collection = (string)($input['collection'] ?? 'ksiazki-artystyczne');

    if (!isset($collections[$collection])) {
        echo json_encode(['success' => false, 'message' => 'Nieprawidlowa kolekcja.']);
        exit;
    }
    if ($listId <= 0 || $inventoryNumber === '') {
        echo json_encode(['success' => false, 'message' => 'Brak wymaganych danych skanera.']);
        exit;
    }

    $listColumns = $pdo->query("SHOW COLUMNS FROM lists")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('collection', $listColumns, true)) {
        $pdo->exec("ALTER TABLE lists ADD COLUMN collection VARCHAR(64) NOT NULL DEFAULT 'ksiazki-artystyczne'");
    }

    $listCheckStmt = $pdo->prepare("SELECT id FROM lists WHERE id = ? AND collection = ?");
    $listCheckStmt->execute([$listId, $collection]);
    if (!$listCheckStmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Lista nie nalezy do tej kolekcji.']);
        exit;
    }

    $mainTable = $collections[$collection];
    $mainTableSql = '`' . str_replace('`', '``', $mainTable) . '`';

    $entryStmt = $pdo->prepare(
        "SELECT ID, numer_ewidencyjny
         FROM {$mainTableSql}
         WHERE TRIM(CAST(`numer_ewidencyjny` AS CHAR)) = :inventory
         LIMIT 1"
    );
    $entryStmt->execute(['inventory' => $inventoryNumber]);
    $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        echo json_encode([
            'success' => false,
            'message' => 'Nie znaleziono pozycji o takim numerze ewidencyjnym.',
            'code' => 'inventory_not_found'
        ]);
        exit;
    }

    $entryId = (int)($entry['ID'] ?? 0);
    if ($entryId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Niepoprawny rekord pozycji.']);
        exit;
    }

    $existsStmt = $pdo->prepare("SELECT 1 FROM list_items WHERE list_id = ? AND entry_id = ? LIMIT 1");
    $existsStmt->execute([$listId, $entryId]);
    if ($existsStmt->fetchColumn()) {
        echo json_encode([
            'success' => true,
            'already_exists' => true,
            'entry_id' => $entryId,
            'inventory_number' => (string)($entry['numer_ewidencyjny'] ?? $inventoryNumber)
        ]);
        exit;
    }

    $insertStmt = $pdo->prepare("INSERT INTO list_items (list_id, entry_id) VALUES (?, ?)");
    $insertStmt->execute([$listId, $entryId]);

    echo json_encode([
        'success' => true,
        'added' => true,
        'entry_id' => $entryId,
        'inventory_number' => (string)($entry['numer_ewidencyjny'] ?? $inventoryNumber)
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
