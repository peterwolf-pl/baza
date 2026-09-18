<?php
require_once __DIR__ . '/bootstrap.php';
include 'db.php';
require_once __DIR__ . '/museum_system.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak uprawnien.']);
    exit;
}
appRequireCsrf();

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

    $inventoryNumber = museumNormalizeInventoryLookupValue((string)($input['inventory_number'] ?? ''));
    $collection = (string)($input['collection'] ?? 'ksiazki-artystyczne');

    if (!isset($collections[$collection])) {
        echo json_encode(['success' => false, 'message' => 'Nieprawidlowa kolekcja.']);
        exit;
    }
    if ($inventoryNumber === '') {
        echo json_encode(['success' => false, 'message' => 'Brak numeru ewidencyjnego.']);
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
        echo json_encode(['success' => false, 'message' => 'Niepoprawny identyfikator pozycji.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'entry_id' => $entryId,
        'inventory_number' => (string)($entry['numer_ewidencyjny'] ?? $inventoryNumber),
    ]);
} catch (Throwable $e) {
    appLogException('find_entry_by_inventory.php', $e);
    echo json_encode(['success' => false, 'message' => 'Nie udało się wyszukać pozycji.']);
}
