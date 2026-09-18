<?php

declare(strict_types=1);

session_start();

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin.php');
    exit;
}

require_once __DIR__ . '/db.php';

function ledgerSetupH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ledgerSetupQid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ledgerSetupRun(bool $apply): array
{
    $configFile = __DIR__ . '/db_config.php';
    $config = is_file($configFile) ? (require $configFile) : [];
    if (!is_array($config)) {
        $config = [];
    }

    $host = (string)($config['host'] ?? 'localhost');
    $port = (int)($config['port'] ?? 3306);
    $dbName = (string)($config['dbname'] ?? '');
    $user = (string)($config['username'] ?? '');
    $pass = (string)($config['password'] ?? '');

    $ledgerDefinitions = is_array($GLOBALS['app_ledger_definitions'] ?? null) ? $GLOBALS['app_ledger_definitions'] : [];
    $scopedTables = is_array($GLOBALS['app_ledger_scoped_tables'] ?? null) ? $GLOBALS['app_ledger_scoped_tables'] : [];

    $out = [
        'apply' => $apply,
        'host' => $host,
        'port' => $port,
        'db_name' => $dbName,
        'ledger_definitions' => $ledgerDefinitions,
        'scoped_tables' => $scopedTables,
        'source_ledger' => 'depozytowa',
        'rows' => [],
        'warnings' => [],
        'fatal_error' => null,
        'has_errors' => false,
    ];

    if ($dbName === '') {
        $out['fatal_error'] = 'Brak nazwy bazy w db_config.php.';
        $out['has_errors'] = true;
        return $out;
    }
    if ($ledgerDefinitions === []) {
        $out['fatal_error'] = 'Brak mapowania ksiąg (app_ledger_definitions).';
        $out['has_errors'] = true;
        return $out;
    }
    if ($scopedTables === []) {
        $out['fatal_error'] = 'Brak listy tabel ksiąg (app_ledger_scoped_tables).';
        $out['has_errors'] = true;
        return $out;
    }

    if (!isset($ledgerDefinitions['depozytowa'])) {
        $out['warnings'][] = 'Brak jawnej księgi depozytowej w mapowaniu; za źródło przyjęto tabele bazowe (prefiks pusty).';
        $out['source_ledger'] = (string)array_key_first($ledgerDefinitions);
    }

    $sourcePrefix = (string)($ledgerDefinitions[$out['source_ledger']]['table_prefix'] ?? '');
    if ($sourcePrefix !== '') {
        $out['fatal_error'] = "Księga źródłowa '{$out['source_ledger']}' musi mieć pusty prefiks (aktualne tabele bazowe).";
        $out['has_errors'] = true;
        return $out;
    }

    try {
        $rawPdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        $out['fatal_error'] = 'Błąd połączenia z MySQL: ' . $e->getMessage();
        $out['has_errors'] = true;
        return $out;
    }

    foreach ($ledgerDefinitions as $ledgerKey => $ledgerMeta) {
        $ledgerKey = (string)$ledgerKey;
        $label = is_array($ledgerMeta) ? (string)($ledgerMeta['label'] ?? $ledgerKey) : $ledgerKey;
        $prefix = is_array($ledgerMeta) ? (string)($ledgerMeta['table_prefix'] ?? '') : '';

        $row = [
            'ledger_key' => $ledgerKey,
            'label' => $label,
            'prefix' => $prefix,
            'status' => 'ok',
            'messages' => [],
            'tables' => [],
        ];

        if ($prefix === '') {
            $row['status'] = 'info';
            $row['messages'][] = 'Księga źródłowa (Depozytowa): używa aktualnych tabel bazowych bez prefiksu.';
            $out['rows'][] = $row;
            continue;
        }

        $row['messages'][] = $apply
            ? 'Tryb APPLY: tworzenie brakujących prefiksowanych tabel w tej samej bazie.'
            : 'Tryb DRY-RUN: podgląd planowanych CREATE TABLE ... LIKE ...';

        foreach ($scopedTables as $baseTable) {
            $baseTable = (string)$baseTable;
            $targetTable = $prefix . $baseTable;
            $sql = 'CREATE TABLE IF NOT EXISTS ' . ledgerSetupQid($targetTable) . ' LIKE ' . ledgerSetupQid($baseTable);

            $tableRow = [
                'table' => $targetTable,
                'source_table' => $baseTable,
                'status' => $apply ? 'ok' : 'plan',
                'message' => $sql,
            ];

            if ($apply) {
                try {
                    $rawPdo->exec($sql);
                } catch (Throwable $e) {
                    $tableRow['status'] = 'error';
                    $tableRow['message'] = $e->getMessage();
                    $row['status'] = 'error';
                    $out['has_errors'] = true;
                }
            }

            $row['tables'][] = $tableRow;
        }

        $out['rows'][] = $row;
    }

    return $out;
}

if (empty($_SESSION['ledger_setup_csrf'])) {
    $_SESSION['ledger_setup_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = (string)$_SESSION['ledger_setup_csrf'];

$pageMessage = '';
$pageError = '';
$mode = 'dry_run';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['mode'] ?? 'dry_run');
    if (!in_array($mode, ['dry_run', 'apply'], true)) {
        $mode = 'dry_run';
    }

    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $pageError = 'Nieprawidłowy token sesji. Odśwież stronę i spróbuj ponownie.';
        $mode = 'dry_run';
    } elseif ($mode === 'apply' && empty($_POST['confirm_apply'])) {
        $pageError = 'Zaznacz potwierdzenie, aby wykonać APPLY.';
        $mode = 'dry_run';
    } elseif ($mode === 'apply') {
        $pageMessage = 'Uruchomiono APPLY (tworzenie prefiksowanych tabel ksiąg w jednej bazie).';
    } else {
        $pageMessage = 'Uruchomiono DRY-RUN (bez zmian w MySQL).';
    }
}

$run = ledgerSetupRun($mode === 'apply');

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup tabel ksiąg (WWW)</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .ledger-setup-page { max-width: 1100px; margin: 16px auto 28px; padding: 0 10px; }
        .ledger-setup-card { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #ccc); border-radius: 12px; padding: 16px; margin-bottom: 14px; }
        .ledger-setup-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .ledger-setup-status-ok { border-left: 4px solid #198754; }
        .ledger-setup-status-info { border-left: 4px solid #0d6efd; }
        .ledger-setup-status-error { border-left: 4px solid #b02a37; }
        .ledger-setup-note { padding: 10px 12px; border-radius: 8px; background: #f3f5f7; margin: 10px 0; }
        .ledger-setup-error { padding: 10px 12px; border-radius: 8px; background: #fff1f1; border: 1px solid #f0b4b4; color: #8a1f1f; margin: 10px 0; }
        .ledger-setup-ok { padding: 10px 12px; border-radius: 8px; background: #f2fff6; border: 1px solid #b6e2c2; color: #1f5f32; margin: 10px 0; }
        .ledger-setup-list { margin: 8px 0 0 18px; }
        .ledger-setup-table-steps { max-height: 260px; overflow: auto; border: 1px solid #ddd; border-radius: 8px; padding: 8px; background: #fafafa; }
        .ledger-setup-table-steps li { margin-bottom: 4px; }
        .ledger-setup-table-error { color: #b02a37; }
        .ledger-setup-table-plan { color: #9a6700; }
        .ledger-setup-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    </style>
</head>
<body>
    <div class="header">
        <a href="admin.php">
            <img src="bazamka.png" width="300" alt="Logo bazy" class="logo">
        </a>
        <div class="header-links">
            <a href="admin.php" id="toggleButton">Panel admin</a>
        </div>
    </div>

    <main class="ledger-setup-page">
        <section class="ledger-setup-card">
            <h1 style="margin-top:0;">Setup tabel ksiąg (WWW)</h1>
            <p>
                Wszystko działa w <strong>jednej bazie</strong>. Przyciski w bańce <strong>Księga</strong> przełączają zestawy tabel:
                <strong>Depozytowa</strong> używa aktualnych tabel bazowych, a pozostałe księgi używają tabel z prefiksem.
            </p>
            <p>
                To narzędzie tworzy brakujące prefiksowane tabele przez <code>CREATE TABLE IF NOT EXISTS ... LIKE ...</code>
                (klon struktury z tabel bazowych / depozytowej).
            </p>

            <?php if ($pageMessage !== ''): ?>
                <div class="ledger-setup-ok"><?php echo ledgerSetupH($pageMessage); ?></div>
            <?php endif; ?>
            <?php if ($pageError !== ''): ?>
                <div class="ledger-setup-error"><?php echo ledgerSetupH($pageError); ?></div>
            <?php endif; ?>

            <div class="ledger-setup-actions">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo ledgerSetupH($csrfToken); ?>">
                    <input type="hidden" name="mode" value="dry_run">
                    <button type="submit" id="toggleButton">Uruchom DRY-RUN</button>
                </form>

                <form method="post" style="margin:0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo ledgerSetupH($csrfToken); ?>">
                    <input type="hidden" name="mode" value="apply">
                    <label style="display:inline-flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="confirm_apply" value="1">
                        Potwierdzam APPLY (wykonaj zmiany w MySQL)
                    </label>
                    <button type="submit" id="toggleButton" onclick="return confirm('Wykonać setup tabel ksiąg (APPLY) w jednej bazie?');">Uruchom APPLY</button>
                </form>
            </div>
        </section>

        <section class="ledger-setup-card">
            <h2 style="margin-top:0;">Konfiguracja</h2>
            <div class="ledger-setup-grid">
                <div class="ledger-setup-note">
                    <strong>Baza danych</strong><br>
                    <code><?php echo ledgerSetupH((string)($run['db_name'] ?? '')); ?></code>
                </div>
                <div class="ledger-setup-note">
                    <strong>Źródło klonowania</strong><br>
                    <code><?php echo ledgerSetupH((string)($run['source_ledger'] ?? 'depozytowa')); ?></code><br>
                    (tabele bazowe bez prefiksu)
                </div>
                <div class="ledger-setup-note">
                    <strong>Liczba tabel na księgę</strong><br>
                    <code><?php echo ledgerSetupH((string)count($run['scoped_tables'] ?? [])); ?></code>
                </div>
            </div>

            <div class="ledger-setup-grid">
                <?php foreach (($run['ledger_definitions'] ?? []) as $ledgerKey => $ledgerMeta): ?>
                    <div class="ledger-setup-note">
                        <strong><?php echo ledgerSetupH((string)($ledgerMeta['label'] ?? $ledgerKey)); ?></strong><br>
                        Klucz: <code><?php echo ledgerSetupH((string)$ledgerKey); ?></code><br>
                        Prefiks: <code><?php echo ledgerSetupH((string)($ledgerMeta['table_prefix'] ?? '')); ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if (!empty($run['warnings'])): ?>
            <section class="ledger-setup-card">
                <h2 style="margin-top:0;">Uwagi</h2>
                <ul class="ledger-setup-list">
                    <?php foreach ($run['warnings'] as $warning): ?>
                        <li><?php echo ledgerSetupH($warning); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($run['fatal_error'])): ?>
            <section class="ledger-setup-card ledger-setup-status-error">
                <h2 style="margin-top:0;">Błąd krytyczny</h2>
                <p style="margin-bottom:0;"><?php echo ledgerSetupH((string)$run['fatal_error']); ?></p>
            </section>
        <?php endif; ?>

        <?php foreach (($run['rows'] ?? []) as $row): ?>
            <?php
            $statusClass = 'ledger-setup-status-ok';
            if (($row['status'] ?? '') === 'error') {
                $statusClass = 'ledger-setup-status-error';
            } elseif (($row['status'] ?? '') === 'info') {
                $statusClass = 'ledger-setup-status-info';
            }
            ?>
            <section class="ledger-setup-card <?php echo ledgerSetupH($statusClass); ?>">
                <h2 style="margin-top:0; margin-bottom:6px;">
                    <?php echo ledgerSetupH((string)($row['label'] ?? '')); ?>
                </h2>
                <div>
                    Klucz: <code><?php echo ledgerSetupH((string)($row['ledger_key'] ?? '')); ?></code><br>
                    Prefiks: <code><?php echo ledgerSetupH((string)($row['prefix'] ?? '')); ?></code><br>
                    Tabele: <strong><?php echo ledgerSetupH((string)count($row['tables'] ?? [])); ?></strong>
                </div>

                <?php if (!empty($row['messages'])): ?>
                    <ul class="ledger-setup-list">
                        <?php foreach ($row['messages'] as $message): ?>
                            <li><?php echo ledgerSetupH((string)$message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($row['tables'])): ?>
                    <details>
                        <summary>Szczegóły tabel</summary>
                        <div class="ledger-setup-table-steps" style="margin-top:8px;">
                            <ul class="ledger-setup-list" style="margin-top:0;">
                                <?php foreach ($row['tables'] as $tableStep): ?>
                                    <?php
                                    $stepClass = '';
                                    if (($tableStep['status'] ?? '') === 'error') {
                                        $stepClass = 'ledger-setup-table-error';
                                    } elseif (($tableStep['status'] ?? '') === 'plan') {
                                        $stepClass = 'ledger-setup-table-plan';
                                    }
                                    ?>
                                    <li class="<?php echo ledgerSetupH($stepClass); ?>">
                                        <strong><?php echo ledgerSetupH((string)($tableStep['table'] ?? '')); ?></strong>
                                        <= <code><?php echo ledgerSetupH((string)($tableStep['source_table'] ?? '')); ?></code><br>
                                        <?php echo ledgerSetupH((string)($tableStep['message'] ?? '')); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </details>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>

