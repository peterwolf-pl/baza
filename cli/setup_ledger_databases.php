<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ten skrypt uruchamiaj z CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';

function qid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function usage(): void
{
    echo "Użycie:\n";
    echo "  php cli/setup_ledger_databases.php           # dry-run (plan utworzenia tabel ksiąg)\n";
    echo "  php cli/setup_ledger_databases.php --apply   # utwórz brakujące tabele ksiąg\n";
    echo "\n";
    echo "Założenia:\n";
    echo "  - wszystko działa w jednej bazie (z db_config.php),\n";
    echo "  - obecne tabele są traktowane jako księga Depozytowa,\n";
    echo "  - pozostałe księgi dostają prefiksowane tabele (klon struktury przez CREATE TABLE ... LIKE).\n";
}

$apply = in_array('--apply', $argv ?? [], true);
if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
    usage();
    exit(0);
}

$configFile = dirname(__DIR__) . '/db_config.php';
$config = is_file($configFile) ? (require $configFile) : [];
if (!is_array($config)) {
    fwrite(STDERR, "BŁĄD: Nie udało się wczytać db_config.php.\n");
    exit(1);
}

$host = (string)($config['host'] ?? 'localhost');
$port = (int)($config['port'] ?? 3306);
$user = (string)($config['username'] ?? '');
$pass = (string)($config['password'] ?? '');
$dbName = (string)($config['dbname'] ?? '');

$ledgerDefinitions = is_array($GLOBALS['app_ledger_definitions'] ?? null) ? $GLOBALS['app_ledger_definitions'] : [];
$scopedTables = is_array($GLOBALS['app_ledger_scoped_tables'] ?? null) ? $GLOBALS['app_ledger_scoped_tables'] : [];

if ($dbName === '') {
    fwrite(STDERR, "BŁĄD: Brak nazwy bazy w db_config.php.\n");
    exit(1);
}
if ($ledgerDefinitions === []) {
    fwrite(STDERR, "BŁĄD: Brak mapowania ksiąg (app_ledger_definitions).\n");
    exit(1);
}
if ($scopedTables === []) {
    fwrite(STDERR, "BŁĄD: Brak listy tabel ksiąg (app_ledger_scoped_tables).\n");
    exit(1);
}

$sourceLedgerKey = isset($ledgerDefinitions['depozytowa']) ? 'depozytowa' : (string)array_key_first($ledgerDefinitions);
$sourcePrefix = (string)($ledgerDefinitions[$sourceLedgerKey]['table_prefix'] ?? '');
if ($sourcePrefix !== '') {
    fwrite(STDERR, "BŁĄD: Księga źródłowa '{$sourceLedgerKey}' musi mieć pusty prefiks (aktualne tabele bazowe).\n");
    exit(1);
}

try {
    $adminPdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD połączenia: {$e->getMessage()}\n");
    exit(1);
}

echo $apply ? "TRYB: APPLY (tworzenie tabel ksiąg)\n" : "TRYB: DRY-RUN (podgląd)\n";
echo "Baza: {$dbName}\n";
echo "Źródło (obecne tabele): {$sourceLedgerKey}\n";
echo "Liczba tabel klonowanych per księga: " . count($scopedTables) . "\n\n";

$hasErrors = false;

foreach ($ledgerDefinitions as $ledgerKey => $ledgerMeta) {
    $ledgerKey = (string)$ledgerKey;
    $label = is_array($ledgerMeta) ? (string)($ledgerMeta['label'] ?? $ledgerKey) : $ledgerKey;
    $prefix = is_array($ledgerMeta) ? (string)($ledgerMeta['table_prefix'] ?? '') : '';

    echo "[{$ledgerKey}] {$label}\n";
    echo "  prefix: " . ($prefix !== '' ? $prefix : '(brak / tabele bazowe)') . "\n";

    if ($prefix === '') {
        echo "  status: źródłowa księga (bez zmian)\n\n";
        continue;
    }

    foreach ($scopedTables as $baseTable) {
        $baseTable = (string)$baseTable;
        $sourceTable = $baseTable;
        $targetTable = $prefix . $baseTable;
        $sql = 'CREATE TABLE IF NOT EXISTS ' . qid($targetTable) . ' LIKE ' . qid($sourceTable);

        echo "  TABLE: {$targetTable}  <=  {$sourceTable}\n";
        if ($apply) {
            try {
                $adminPdo->exec($sql);
            } catch (Throwable $e) {
                $hasErrors = true;
                echo "    BŁĄD: {$e->getMessage()}\n";
            }
        }
    }

    echo "\n";
}

if (!$apply) {
    echo "Dry-run zakończony. Uruchom z --apply, aby utworzyć prefiksowane tabele ksiąg.\n";
}

exit($hasErrors ? 1 : 0);

