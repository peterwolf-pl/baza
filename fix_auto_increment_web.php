<?php
declare(strict_types=1);

// Jednorazowy skrypt naprawczy uruchamiany z przeglądarki.
// Wykonuje się tylko raz. Kolejne wejścia pokażą blokadę.
// Po użyciu usuń plik z serwera.

session_start();
if (empty($_SESSION['is_root'])) {
    http_response_code(403);
    exit('Brak uprawnień.');
}

require_once __DIR__ . '/db.php';

$lockFile = __DIR__ . '/tmp/fix_auto_increment_web.lock';
if (is_file($lockFile)) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Skrypt został już uruchomiony (one-shot). Usuń plik fix_auto_increment_web.php z serwera.\n";
    exit;
}

function qid_web(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

$tables = [
    'karta_ewidencyjna',
    'karta_ewidencyjna_maszyny',
    'karta_ewidencyjna_matryce',
    'karta_ewidencyjna_bib',
    'karta_ewidencyjna_klisze',
];

$results = [];

foreach ($tables as $table) {
    $rowOut = [
        'table' => $table,
        'status' => 'ok',
        'messages' => [],
    ];

    try {
        $columns = $pdo->query('SHOW COLUMNS FROM ' . qid_web($table))->fetchAll(PDO::FETCH_ASSOC);
        $pkRows = $pdo->query('SHOW KEYS FROM ' . qid_web($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);

        if (count($pkRows) !== 1) {
            $rowOut['status'] = 'skip';
            $rowOut['messages'][] = 'PRIMARY KEY nie jest pojedynczą kolumną - pominięto.';
            $results[] = $rowOut;
            continue;
        }

        $pkColumn = (string)($pkRows[0]['Column_name'] ?? '');
        if ($pkColumn === '') {
            $rowOut['status'] = 'error';
            $rowOut['messages'][] = 'Nie udało się odczytać nazwy kolumny PRIMARY KEY.';
            $results[] = $rowOut;
            continue;
        }

        $columnMeta = null;
        foreach ($columns as $col) {
            if ((string)($col['Field'] ?? '') === $pkColumn) {
                $columnMeta = $col;
                break;
            }
        }

        if ($columnMeta === null) {
            $rowOut['status'] = 'error';
            $rowOut['messages'][] = 'Nie znaleziono definicji kolumny PK.';
            $results[] = $rowOut;
            continue;
        }

        $type = (string)($columnMeta['Type'] ?? '');
        $extra = strtolower((string)($columnMeta['Extra'] ?? ''));
        $unsigned = stripos($type, 'unsigned') !== false;
        $isAutoIncrement = str_contains($extra, 'auto_increment');

        $isIntegerType = preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/i', $type) === 1;
        if (!$isIntegerType) {
            $rowOut['status'] = 'skip';
            $rowOut['messages'][] = "PK {$pkColumn} nie jest typem liczbowym ({$type}) - pominięto.";
            $results[] = $rowOut;
            continue;
        }

        $maxStmt = $pdo->query(
            'SELECT COALESCE(MAX(CAST(' . qid_web($pkColumn) . ' AS UNSIGNED)), 0) FROM ' . qid_web($table)
        );
        $maxId = (int)$maxStmt->fetchColumn();
        $nextAuto = max(1, $maxId + 1);

        $baseType = preg_replace('/\s+unsigned/i', '', $type) ?? $type;
        $baseType = trim($baseType);
        $normalizedType = $unsigned ? ($baseType . ' UNSIGNED') : $baseType;

        $modifySql = 'ALTER TABLE ' . qid_web($table)
            . ' MODIFY COLUMN ' . qid_web($pkColumn)
            . ' ' . $normalizedType . ' NOT NULL AUTO_INCREMENT';
        $autoSql = 'ALTER TABLE ' . qid_web($table) . ' AUTO_INCREMENT = ' . $nextAuto;

        $rowOut['messages'][] = "PK: {$pkColumn}";
        $rowOut['messages'][] = "Type: {$type}";
        $rowOut['messages'][] = "MAX(PK): {$maxId}";
        $rowOut['messages'][] = "Docelowy AUTO_INCREMENT: {$nextAuto}";

        $pdo->beginTransaction();
        if (!$isAutoIncrement) {
            $pdo->exec($modifySql);
            $rowOut['messages'][] = "Wykonano: {$modifySql}";
        } else {
            $rowOut['messages'][] = 'Kolumna ma już AUTO_INCREMENT.';
        }
        $pdo->exec($autoSql);
        $rowOut['messages'][] = "Wykonano: {$autoSql}";
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $rowOut['status'] = 'error';
        $rowOut['messages'][] = $e->getMessage();
    }

    $results[] = $rowOut;
}

if (!is_dir(__DIR__ . '/tmp')) {
    @mkdir(__DIR__ . '/tmp', 0775, true);
}
@file_put_contents($lockFile, date('c') . PHP_EOL);

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fix AUTO_INCREMENT</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; line-height: 1.4; }
        .ok { border-left: 4px solid #198754; padding: 10px 14px; background: #f3fff7; margin: 12px 0; }
        .error { border-left: 4px solid #b02a37; padding: 10px 14px; background: #fff5f5; margin: 12px 0; }
        .skip { border-left: 4px solid #9a6700; padding: 10px 14px; background: #fff8e6; margin: 12px 0; }
        code { background: #f2f2f2; padding: 1px 4px; border-radius: 4px; }
        ul { margin: 8px 0 0 18px; }
    </style>
</head>
<body>
    <h1>Naprawa AUTO_INCREMENT (4 kolekcje)</h1>
    <p>Skrypt działa w trybie one-shot (jednorazowym). Po wykonaniu usuń plik <code>fix_auto_increment_web.php</code> z serwera.</p>

    <?php foreach ($results as $item): ?>
        <div class="<?php echo htmlspecialchars((string)$item['status']); ?>">
            <strong><?php echo htmlspecialchars((string)$item['table']); ?></strong>
            <ul>
                <?php foreach ($item['messages'] as $msg): ?>
                    <li><?php echo htmlspecialchars((string)$msg); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</body>
</html>
