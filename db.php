<?php

if (!function_exists('appLedgerScopedTables')) {
    function appLedgerScopedTables(): array
    {
        return [
            'inventory_sequences',
            'lists',
            'list_items',
            'karta_ewidencyjna',
            'karta_ewidencyjna_log',
            'karta_ewidencyjna_przemieszczenia',
            'karta_ewidencyjna_maszyny',
            'karta_ewidencyjna_maszyny_log',
            'karta_ewidencyjna_maszyny_przemieszczenia',
            'karta_ewidencyjna_matryce',
            'karta_ewidencyjna_matryce_log',
            'karta_ewidencyjna_matryce_przemieszczenia',
            'karta_ewidencyjna_bib',
            'karta_ewidencyjna_bib_log',
            'karta_ewidencyjna_bib_przemieszczenia',
            'karta_ewidencyjna_klisze',
            'karta_ewidencyjna_klisze_log',
            'karta_ewidencyjna_klisze_przemieszczenia',
            'record_attachments',
            'record_attachment_versions',
            'record_share_links',
            'growth_events',
        ];
    }
}

if (!function_exists('appLedgerDefaultDefinitions')) {
    function appLedgerDefaultDefinitions(string $baseDbname): array
    {
        return [
            'inwentarzowa' => [
                'label' => 'Inwentarzowa',
                'table_prefix' => 'ksi_inv__',
                'dbname' => $baseDbname,
            ],
            'depozytowa' => [
                'label' => 'Depozytowa',
                'table_prefix' => '',
                'dbname' => $baseDbname,
            ],
            'nabytki_ubytki' => [
                'label' => 'Nabytków i ubytków',
                'table_prefix' => 'ksi_nu__',
                'dbname' => $baseDbname,
            ],
        ];
    }
}

if (!function_exists('appLedgerConfiguredDefinitions')) {
    function appLedgerConfiguredDefinitions(array $config, string $baseDbname): array
    {
        $defaults = appLedgerDefaultDefinitions($baseDbname);
        $configured = $config['ledger_definitions'] ?? ($config['ledgers'] ?? null);
        if (!is_array($configured) || $configured === []) {
            return $defaults;
        }

        $definitions = [];
        foreach ($configured as $ledgerKey => $ledgerValue) {
            $key = preg_replace('/[^a-z0-9_\\-]/i', '', (string)$ledgerKey);
            $key = is_string($key) ? strtolower($key) : '';
            if ($key === '') {
                continue;
            }

            $defaultMeta = $defaults[$key] ?? [
                'label' => $key,
                'table_prefix' => 'ledger_' . preg_replace('/[^a-z0-9_]/', '_', $key) . '__',
                'dbname' => $baseDbname,
            ];

            if (is_array($ledgerValue)) {
                $label = (string)($ledgerValue['label'] ?? $defaultMeta['label']);
                $tablePrefix = (string)($ledgerValue['table_prefix'] ?? ($ledgerValue['prefix'] ?? $defaultMeta['table_prefix']));
            } else {
                $label = (string)$defaultMeta['label'];
                $tablePrefix = (string)$defaultMeta['table_prefix'];
            }

            $definitions[$key] = [
                'label' => $label,
                'table_prefix' => $tablePrefix,
                'dbname' => $baseDbname,
            ];
        }

        if ($definitions === []) {
            return $defaults;
        }

        if (!isset($definitions['depozytowa'])) {
            $definitions = [
                'depozytowa' => [
                    'label' => 'Depozytowa',
                    'table_prefix' => '',
                    'dbname' => $baseDbname,
                ],
            ] + $definitions;
        } else {
            $definitions['depozytowa']['table_prefix'] = '';
            $definitions['depozytowa']['dbname'] = $baseDbname;
            if (empty($definitions['depozytowa']['label'])) {
                $definitions['depozytowa']['label'] = 'Depozytowa';
            }
        }

        return $definitions;
    }
}

if (!function_exists('appLedgerBuildTableMap')) {
    function appLedgerBuildTableMap(array $ledgerDefinitions, string $selectedLedger): array
    {
        $prefix = '';
        if (isset($ledgerDefinitions[$selectedLedger]) && is_array($ledgerDefinitions[$selectedLedger])) {
            $prefix = (string)($ledgerDefinitions[$selectedLedger]['table_prefix'] ?? '');
        }
        if ($prefix === '') {
            return [];
        }

        $map = [];
        foreach (appLedgerScopedTables() as $baseTable) {
            $map[(string)$baseTable] = $prefix . (string)$baseTable;
        }

        return $map;
    }
}

if (!function_exists('appLedgerCompileSqlRewritePattern')) {
    function appLedgerCompileSqlRewritePattern(array $tableMap): ?string
    {
        if ($tableMap === []) {
            return null;
        }

        $names = array_keys($tableMap);
        usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = implode('|', array_map(static fn(string $name): string => preg_quote($name, '~'), $names));

        if ($alternatives === '') {
            return null;
        }

        return '~(?<![A-Za-z0-9_])(`?)(' . $alternatives . ')\\1(?![A-Za-z0-9_])~u';
    }
}

if (!function_exists('appLedgerRewriteSql')) {
    function appLedgerRewriteSql(string $sql, array $tableMap, ?string $pattern = null): string
    {
        if ($tableMap === [] || $sql === '') {
            return $sql;
        }

        $pattern = $pattern ?? appLedgerCompileSqlRewritePattern($tableMap);
        if ($pattern === null) {
            return $sql;
        }

        $rewritten = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($tableMap): string {
                $quote = (string)($matches[1] ?? '');
                $tableName = (string)($matches[2] ?? '');
                $mapped = $tableMap[$tableName] ?? $tableName;
                return $quote . $mapped . $quote;
            },
            $sql
        );

        return is_string($rewritten) ? $rewritten : $sql;
    }
}

if (!function_exists('appLedgerEnsureMappedTablesExist')) {
    function appLedgerEnsureMappedTablesExist(PDO $rawPdo, array $tableMap): void
    {
        if ($tableMap === []) {
            return;
        }

        $existingTables = [];
        foreach ($rawPdo->query('SHOW TABLES') as $row) {
            $firstValue = null;
            foreach ((array)$row as $value) {
                $firstValue = $value;
                break;
            }
            if (is_string($firstValue) && $firstValue !== '') {
                $existingTables[$firstValue] = true;
            }
        }

        foreach ($tableMap as $baseTable => $targetTable) {
            $baseTable = (string)$baseTable;
            $targetTable = (string)$targetTable;

            if ($baseTable === '' || $targetTable === '') {
                continue;
            }

            if (!isset($existingTables[$baseTable])) {
                // Część tabel (np. tworzone lazy) może jeszcze nie istnieć w bazie bazowej.
                continue;
            }

            if (isset($existingTables[$targetTable])) {
                continue;
            }

            $sql = 'CREATE TABLE IF NOT EXISTS `'
                . str_replace('`', '``', $targetTable)
                . '` LIKE `'
                . str_replace('`', '``', $baseTable)
                . '`';
            $rawPdo->exec($sql);
            $existingTables[$targetTable] = true;
        }
    }
}

if (!class_exists('AppLedgerPDO')) {
    class AppLedgerPDO extends PDO
    {
        private array $ledgerTableMap = [];
        private ?string $ledgerRewritePattern = null;

        public function __construct($dsn, $username = null, $password = null, $options = null, array $ledgerTableMap = [])
        {
            parent::__construct($dsn, $username, $password, is_array($options) ? $options : []);
            $this->ledgerTableMap = $ledgerTableMap;
            $this->ledgerRewritePattern = appLedgerCompileSqlRewritePattern($ledgerTableMap);
        }

        private function rewriteSql(string $sql): string
        {
            return appLedgerRewriteSql($sql, $this->ledgerTableMap, $this->ledgerRewritePattern);
        }

        #[\ReturnTypeWillChange]
        public function prepare($query, $options = [])
        {
            return parent::prepare($this->rewriteSql((string)$query), $options);
        }

        #[\ReturnTypeWillChange]
        public function query($query, ...$fetchModeArgs)
        {
            return parent::query($this->rewriteSql((string)$query), ...$fetchModeArgs);
        }

        #[\ReturnTypeWillChange]
        public function exec($statement)
        {
            return parent::exec($this->rewriteSql((string)$statement));
        }
    }
}

$configFile = __DIR__ . '/db_config.php';
$config = [];

if (is_file($configFile)) {
    $loadedConfig = require $configFile;
    if (is_array($loadedConfig)) {
        $config = $loadedConfig;
    }
    $servername = (string)($config['host'] ?? 'localhost');
    $port = (int)($config['port'] ?? 3306);
    $baseDbname = (string)($config['dbname'] ?? '');
    $usernames = (string)($config['username'] ?? '');
    $passwords = (string)($config['password'] ?? '');
} else {
    $servername = 'localhost';
    $port = 3306;
    $usernames = 'xxx';
    $passwords = 'xxx';
    $baseDbname = 'xxx';
}

$ledgerDefinitions = appLedgerConfiguredDefinitions($config, $baseDbname);
$defaultLedger = (string)($config['default_ledger'] ?? 'depozytowa');
if (!isset($ledgerDefinitions[$defaultLedger])) {
    $defaultLedger = (string)array_key_first($ledgerDefinitions);
}
if ($defaultLedger === '') {
    $defaultLedger = 'depozytowa';
}

$selectedLedger = null;
if (PHP_SAPI !== 'cli') {
    $requestLedger = isset($_GET['ledger']) ? (string)$_GET['ledger'] : (isset($_POST['ledger']) ? (string)$_POST['ledger'] : null);
    if (is_string($requestLedger) && isset($ledgerDefinitions[$requestLedger])) {
        $selectedLedger = $requestLedger;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        if ($selectedLedger !== null) {
            $_SESSION['selected_ledger'] = $selectedLedger;
        } else {
            $sessionLedger = isset($_SESSION['selected_ledger']) ? (string)$_SESSION['selected_ledger'] : '';
            if ($sessionLedger !== '' && isset($ledgerDefinitions[$sessionLedger])) {
                $selectedLedger = $sessionLedger;
            }
        }

        if ($selectedLedger === null) {
            $_SESSION['selected_ledger'] = $defaultLedger;
        }
    }
}

if ($selectedLedger === null) {
    $selectedLedger = $defaultLedger;
}

$dbname = $baseDbname;
$ledgerTableMap = appLedgerBuildTableMap($ledgerDefinitions, $selectedLedger);
$selectedLedgerPrefix = (string)($ledgerDefinitions[$selectedLedger]['table_prefix'] ?? '');

$GLOBALS['app_ledger_definitions'] = $ledgerDefinitions;
$GLOBALS['app_default_ledger'] = $defaultLedger;
$GLOBALS['app_selected_ledger'] = $selectedLedger;
$GLOBALS['app_selected_dbname'] = $dbname;
$GLOBALS['app_ledger_scoped_tables'] = appLedgerScopedTables();
$GLOBALS['app_ledger_table_map'] = $ledgerTableMap;
$GLOBALS['app_selected_ledger_table_prefix'] = $selectedLedgerPrefix;

try {
    if ($ledgerTableMap !== []) {
        $safeLedger = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$selectedLedger) ?: 'ledger';
        $provisionCacheFile = __DIR__ . '/tmp/ledger_ready_' . $safeLedger;
        if (!is_file($provisionCacheFile)) {
            $rawPdoForProvision = new PDO(
                "mysql:host={$servername};port={$port};dbname={$dbname};charset=utf8mb4",
                $usernames,
                $passwords,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            appLedgerEnsureMappedTablesExist($rawPdoForProvision, $ledgerTableMap);
            if (!is_dir(__DIR__ . '/tmp')) {
                @mkdir(__DIR__ . '/tmp', 0775, true);
            }
            @file_put_contents($provisionCacheFile, date('c') . PHP_EOL);
        }
    }

    $pdo = new AppLedgerPDO(
        "mysql:host={$servername};port={$port};dbname={$dbname};charset=utf8mb4",
        $usernames,
        $passwords,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
        $ledgerTableMap
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Błąd połączenia z bazą danych.');
}

?>
