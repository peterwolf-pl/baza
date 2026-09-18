<?php
require_once __DIR__ . '/bootstrap.php';
include 'db.php';
require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/museum_system.php';
appEnsureCsrfToken();

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ensureUserPermissionColumns(PDO $pdo): void {
    $required = [
        'email' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN email VARCHAR(255) NULL",
        'can_full_database_view' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_full_database_view TINYINT(1) NOT NULL DEFAULT 0",
        'can_edit_lists' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_edit_lists TINYINT(1) NOT NULL DEFAULT 0",
        'is_root' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN is_root TINYINT(1) NOT NULL DEFAULT 0",
        'can_inventory_entries' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_inventory_entries TINYINT(1) NOT NULL DEFAULT 0",
        'can_update_records' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_update_records TINYINT(1) NOT NULL DEFAULT 0",
        'can_manage_deposits' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_manage_deposits TINYINT(1) NOT NULL DEFAULT 0",
        'can_generate_reports' => "ALTER TABLE karta_ewidencyjna_users ADD COLUMN can_generate_reports TINYINT(1) NOT NULL DEFAULT 0",
    ];

    $columns = $pdo->query("SHOW COLUMNS FROM karta_ewidencyjna_users")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($required as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

ensureUserPermissionColumns($pdo);
museumEnsureBackupTables($pdo);

$appConfig = appSettingsLoadConfig();
$organizationProfile = appSettingsGetOrganizationProfile($appConfig);

function hBytes(?int $bytes): string {
    if ($bytes === null) {
        return '-';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 2, ',', ' ') . ' ' . $units[$unit];
}

function backupScheduleStatus(PDO $pdo): array {
    $rules = [
        'daily' => ['label' => 'Codzienny', 'maxAgeDays' => 1],
        'weekly' => ['label' => 'Tygodniowy', 'maxAgeDays' => 7],
        'monthly' => ['label' => 'Miesięczny', 'maxAgeDays' => 31],
    ];
    $stmt = $pdo->prepare(
        "SELECT `id`, `finished_at`, `started_at`
         FROM `system_backup_runs`
         WHERE `backup_kind` = :backup_kind
           AND `status` = 'success'
         ORDER BY COALESCE(`finished_at`, `started_at`) DESC, `id` DESC
         LIMIT 1"
    );

    $out = [];
    foreach ($rules as $kind => $rule) {
        $stmt->execute(['backup_kind' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $lastAt = $row['finished_at'] ?? $row['started_at'] ?? null;
        $isOverdue = true;
        if ($lastAt) {
            $lastTs = strtotime((string)$lastAt);
            $isOverdue = $lastTs === false ? true : ((time() - $lastTs) > ($rule['maxAgeDays'] * 86400));
        }
        $out[$kind] = [
            'label' => $rule['label'],
            'last_at' => $lastAt,
            'is_overdue' => $isOverdue,
        ];
    }

    return $out;
}

$message = '';

if (isset($_GET['logout_admin'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!appVerifyCsrf()) {
        $message = 'Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.';
        $action = '';
    }

    if ($action === 'admin_login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        {
            $stmt = $pdo->prepare('SELECT * FROM karta_ewidencyjna_users WHERE username = :username LIMIT 1');
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash']) && (!empty($user['is_root']) || ($user['username'] ?? '') === 'root')) {
                session_regenerate_id(true);
                appEnsureCsrfToken();
                $_SESSION['admin_authenticated'] = true;
                appApplyUserSession($user);
                header('Location: admin.php');
                exit;
            }

            $message = 'Nieprawidłowe dane logowania lub brak uprawnień root.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'change_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($userId <= 0 || $newPassword === '') {
            $message = 'Podaj poprawne dane do zmiany hasła.';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('UPDATE karta_ewidencyjna_users SET password_hash = :password_hash WHERE id = :id');
            $stmt->execute([
                'password_hash' => $newPasswordHash,
                'id' => $userId,
            ]);
            $message = 'Hasło użytkownika zostało zmienione.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'update_user_profile') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $email = trim((string)($_POST['email'] ?? ''));
        if ($userId <= 0) {
            $message = 'Nieprawidłowy użytkownik.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Nieprawidłowy adres e-mail.';
        } else {
            $stmt = $pdo->prepare('UPDATE karta_ewidencyjna_users SET email = :email WHERE id = :id');
            $stmt->execute(['email' => ($email !== '' ? $email : null), 'id' => $userId]);
            $message = 'Dane użytkownika zostały zaktualizowane.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'create_user') {
        $newUsername = trim((string)($_POST['new_username'] ?? ''));
        $newPassword = (string)($_POST['new_user_password'] ?? '');
        $newEmail = trim((string)($_POST['new_user_email'] ?? ''));

        if ($newUsername === '' || $newPassword === '') {
            $message = 'Podaj login i hasło nowego użytkownika.';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Hasło musi mieć co najmniej 8 znaków.';
        } elseif ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Podaj poprawny adres e-mail.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO karta_ewidencyjna_users (
                    username,
                    email,
                    password_hash,
                    can_full_database_view,
                    can_edit_lists,
                    is_root,
                    can_inventory_entries,
                    can_update_records,
                    can_manage_deposits,
                    can_generate_reports
                ) VALUES (
                    :username,
                    :email,
                    :password_hash,
                    :can_full_database_view,
                    :can_edit_lists,
                    :is_root,
                    :can_inventory_entries,
                    :can_update_records,
                    :can_manage_deposits,
                    :can_generate_reports
                )');
                $stmt->execute([
                    'username' => $newUsername,
                    'email' => ($newEmail !== '' ? $newEmail : null),
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'can_full_database_view' => isset($_POST['can_full_database_view']) ? 1 : 0,
                    'can_edit_lists' => isset($_POST['can_edit_lists']) ? 1 : 0,
                    'is_root' => isset($_POST['is_root']) ? 1 : 0,
                    'can_inventory_entries' => isset($_POST['can_inventory_entries']) ? 1 : 0,
                    'can_update_records' => isset($_POST['can_update_records']) ? 1 : 0,
                    'can_manage_deposits' => isset($_POST['can_manage_deposits']) ? 1 : 0,
                    'can_generate_reports' => isset($_POST['can_generate_reports']) ? 1 : 0,
                ]);
                $message = 'Nowy użytkownik został dodany.';
            } catch (PDOException $e) {
                $message = 'Nie udało się dodać użytkownika (prawdopodobnie login już istnieje).';
            }
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'update_permissions') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $message = 'Nieprawidłowy użytkownik.';
        } else {
            $stmt = $pdo->prepare('UPDATE karta_ewidencyjna_users
                SET can_full_database_view = :can_full_database_view,
                    can_edit_lists = :can_edit_lists,
                    is_root = :is_root,
                    can_inventory_entries = :can_inventory_entries,
                    can_update_records = :can_update_records,
                    can_manage_deposits = :can_manage_deposits,
                    can_generate_reports = :can_generate_reports
                WHERE id = :id');
            $stmt->execute([
                'can_full_database_view' => isset($_POST['can_full_database_view']) ? 1 : 0,
                'can_edit_lists' => isset($_POST['can_edit_lists']) ? 1 : 0,
                'is_root' => isset($_POST['is_root']) ? 1 : 0,
                'can_inventory_entries' => isset($_POST['can_inventory_entries']) ? 1 : 0,
                'can_update_records' => isset($_POST['can_update_records']) ? 1 : 0,
                'can_manage_deposits' => isset($_POST['can_manage_deposits']) ? 1 : 0,
                'can_generate_reports' => isset($_POST['can_generate_reports']) ? 1 : 0,
                'id' => $userId,
            ]);
            $message = 'Uprawnienia użytkownika zostały zaktualizowane.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'export_db') {
        try {
            $backup = museumRunSqlBackup($pdo, [
                'backup_kind' => 'manual',
                'storage_scope' => 'local',
                'initiated_by' => (string)($_SESSION['username'] ?? 'root'),
                'note' => 'Eksport .sql uruchomiony z panelu administratora (z pobraniem pliku).',
                'return_dump' => true,
            ]);

            $dump = (string)($backup['dump'] ?? '');
            $fileName = (string)($backup['file_name'] ?? ('backup_' . date('Ymd_His') . '.sql'));
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . strlen($dump));
            echo $dump;
            exit;
        } catch (Throwable $e) {
            appLogException('admin.php export_db', $e);
            $message = 'Nie udało się wykonać backupu SQL.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'run_backup_job') {
        $backupKind = trim((string)($_POST['backup_kind'] ?? ''));
        $allowedKinds = ['daily', 'weekly', 'monthly'];
        if (!in_array($backupKind, $allowedKinds, true)) {
            $message = 'Nieprawidłowy typ backupu.';
        } else {
            try {
                $backup = museumRunSqlBackup($pdo, [
                    'backup_kind' => $backupKind,
                    'storage_scope' => 'local',
                    'initiated_by' => (string)($_SESSION['username'] ?? 'root'),
                    'note' => 'Backup ' . $backupKind . ' uruchomiony z panelu administratora.',
                    'return_dump' => false,
                ]);
                $message = 'Wykonano backup ' . $backupKind . ': ' . (string)$backup['file_name']
                    . ' (retencja usunęła: ' . (int)($backup['retention_deleted_count'] ?? 0) . ').';
            } catch (Throwable $e) {
                appLogException('admin.php run_backup_job', $e);
                $message = 'Nie udało się wykonać backupu ' . $backupKind . '.';
            }
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'record_restore_test') {
        $testDate = trim((string)($_POST['test_date'] ?? ''));
        $result = trim((string)($_POST['result'] ?? ''));
        $protocolRef = trim((string)($_POST['protocol_ref'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $backupRunId = (int)($_POST['backup_run_id'] ?? 0);
        $allowedResults = ['OK', 'FAIL', 'PARTIAL'];

        if ($testDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $testDate)) {
            $message = 'Podaj poprawną datę testu odtwarzania.';
        } elseif (!in_array($result, $allowedResults, true)) {
            $message = 'Nieprawidłowy wynik testu odtwarzania.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO system_restore_test_logs (backup_run_id, test_date, result, protocol_ref, tested_by, notes)
                 VALUES (:backup_run_id, :test_date, :result, :protocol_ref, :tested_by, :notes)'
            );
            $stmt->execute([
                'backup_run_id' => $backupRunId > 0 ? $backupRunId : null,
                'test_date' => $testDate,
                'result' => $result,
                'protocol_ref' => $protocolRef !== '' ? $protocolRef : null,
                'tested_by' => (string)($_SESSION['username'] ?? 'root'),
                'notes' => $notes !== '' ? $notes : null,
            ]);
            $message = 'Zapisano wpis testu odtwarzania.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'update_organization_profile') {
        $organizationName = trim((string)($_POST['organization_name'] ?? ''));
        $organizationWebsite = trim((string)($_POST['organization_website'] ?? ''));
        $organizationAddress = trim((string)($_POST['organization_address'] ?? ''));
        $organizationEmail = trim((string)($_POST['organization_contact_email'] ?? ''));

        if ($organizationName === '') {
            $message = 'Podaj nazwę organizacji.';
        } elseif ($organizationWebsite !== '' && !filter_var($organizationWebsite, FILTER_VALIDATE_URL)) {
            $message = 'Podaj poprawny adres strony WWW.';
        } elseif ($organizationEmail !== '' && !filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Podaj poprawny adres e-mail.';
        } else {
            try {
                $appConfig['organization_profile'] = [
                    'name' => $organizationName,
                    'website' => $organizationWebsite,
                    'address' => $organizationAddress,
                    'contact_email' => $organizationEmail,
                ];
                appSettingsSaveConfig($appConfig);
                $organizationProfile = appSettingsGetOrganizationProfile($appConfig);
                $message = 'Dane organizacji zostały zapisane.';
            } catch (Throwable $e) {
                appLogException('admin.php organization', $e);
                $message = 'Nie udało się zapisać danych organizacji.';
            }
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'upload_logo') {
        $file = $_FILES['logo_file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $message = 'Wybierz plik logo do przesłania.';
        } elseif ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = 'Wystąpił błąd podczas przesyłania pliku logo.';
        } else {
            $tmpName = (string)($file['tmp_name'] ?? '');
            $mimeType = '';
            if ($tmpName !== '' && is_uploaded_file($tmpName)) {
                $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                if ($finfo) {
                    $detected = finfo_file($finfo, $tmpName);
                    if (is_string($detected)) {
                        $mimeType = $detected;
                    }
                    finfo_close($finfo);
                }
            }

            $allowedMimeTypes = [
                'image/png',
            ];

            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $message = 'Nie udało się odczytać przesłanego pliku.';
            } elseif (!in_array($mimeType, $allowedMimeTypes, true)) {
                $message = 'Logo musi być plikiem PNG.';
            } else {
                $targetPath = __DIR__ . '/bazamka.png';
                if (move_uploaded_file($tmpName, $targetPath)) {
                    $message = 'Nowe logo zostało zapisane.';
                } else {
                    $message = 'Nie udało się zapisać nowego logo.';
                }
            }
        }
    }
}

$usersStmt = $pdo->query('SELECT id, username, email, is_root, can_full_database_view, can_edit_lists, can_inventory_entries, can_update_records, can_manage_deposits, can_generate_reports FROM karta_ewidencyjna_users ORDER BY username ASC');
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
$backupSchedule = [];
$backupRuns = [];
$restoreTests = [];

if (!empty($_SESSION['admin_authenticated'])) {
    $backupSchedule = backupScheduleStatus($pdo);
    $backupRunsStmt = $pdo->query(
        "SELECT id, backup_kind, status, file_name, file_path, file_size_bytes, sha256_hash, initiated_by,
                retention_deleted_count, deleted_by_retention, deleted_at, started_at, finished_at
         FROM system_backup_runs
         ORDER BY id DESC
         LIMIT 30"
    );
    $backupRuns = $backupRunsStmt->fetchAll(PDO::FETCH_ASSOC);

    $restoreTestsStmt = $pdo->query(
        "SELECT id, backup_run_id, test_date, result, protocol_ref, tested_by, notes, created_at
         FROM system_restore_test_logs
         ORDER BY id DESC
         LIMIT 20"
    );
    $restoreTests = $restoreTestsStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel administracyjny</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <a href="login.php">
            <img src="bazamka.png" width="300" alt="Logo bazy" class="logo">
        </a>
        <div class="header-links">
            <a href="login.php" id="toggleButton">Powrót</a>
            <?php if (!empty($_SESSION['admin_authenticated'])): ?>
                <a href="admin.php?logout_admin=1" id="toggleButton">Wyloguj admina</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <p><?= h($message) ?></p>
    <?php endif; ?>

    <?php if (empty($_SESSION['admin_authenticated'])): ?>
        <h2>Logowanie do panelu administratora</h2>
        <form method="post" class="add-form" style="max-width:400px;">
            <?= appCsrfField() ?>
            <input type="hidden" name="action" value="admin_login">
            <label>Username:</label>
            <input type="text" name="username" required>
            <label>Password:</label>
            <input type="password" name="password" required>
            <button type="submit" id="toggleButton">Zaloguj jako root</button>
        </form>
    <?php else: ?>
        <section class="admin-user-create-panel">
            <h2>Dane organizacji</h2>
            <form method="post" class="add-form" style="max-width:700px; margin-bottom:24px;">
            <?= appCsrfField() ?>
                <input type="hidden" name="action" value="update_organization_profile">
                <label for="organization_name">Nazwa organizacji</label>
                <input type="text" id="organization_name" name="organization_name" value="<?= h($organizationProfile['name']) ?>" required>

                <label for="organization_website">Strona WWW</label>
                <input type="url" id="organization_website" name="organization_website" value="<?= h($organizationProfile['website']) ?>" placeholder="https://...">

                <label for="organization_contact_email">E-mail kontaktowy</label>
                <input type="email" id="organization_contact_email" name="organization_contact_email" value="<?= h($organizationProfile['contact_email']) ?>" placeholder="kontakt@...">

                <label for="organization_address">Adres / opis</label>
                <textarea id="organization_address" name="organization_address" rows="3" placeholder="Adres organizacji lub krótki opis"><?= h($organizationProfile['address']) ?></textarea>

                <button type="submit" id="toggleButton">Zapisz dane organizacji</button>
            </form>

            <h2>Logo bazy</h2>
            <form method="post" enctype="multipart/form-data" class="add-form" style="max-width:700px; margin-bottom:24px;">
            <?= appCsrfField() ?>
                <input type="hidden" name="action" value="upload_logo">
                <label for="logo_file">Prześlij nowe logo (PNG)</label>
                <input type="file" id="logo_file" name="logo_file" accept=".png,image/png" required>
                <button type="submit" id="toggleButton">Wgraj nowe logo</button>
            </form>

            <h2>Dodaj nowego użytkownika</h2>
            <form method="post" class="add-form admin-user-create-form">
            <?= appCsrfField() ?>
                <input type="hidden" name="action" value="create_user">
                <label>Username:</label>
                <input type="text" name="new_username" required>
                <label>E-mail:</label>
                <input type="email" name="new_user_email">
                <label>Password:</label>
                <input type="password" name="new_user_password" required>
                <div class="admin-user-create-permissions">
                    <label><input type="checkbox" name="can_full_database_view" value="1"> pełny podgląd zawartości bazy</label>
                    <label><input type="checkbox" name="can_edit_lists" value="1"> edycja list</label>
                    <label><input type="checkbox" name="is_root" value="1"> uprawnienia root (panel admin)</label>
                    <label><input type="checkbox" name="can_inventory_entries" value="1"> wpisy do księgi inwentarzowej</label>
                    <label><input type="checkbox" name="can_update_records" value="1"> aktualizacja danych ewidencyjnych</label>
                    <label><input type="checkbox" name="can_manage_deposits" value="1"> dokumentacja depozytów</label>
                    <label><input type="checkbox" name="can_generate_reports" value="1"> generowanie raportów</label>
                </div>
                <button type="submit" id="toggleButton">Dodaj użytkownika</button>
            </form>
        </section>

        <h2>Lista użytkowników</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>E-mail</th>
                    <th>Uprawnienia</th>
                    <th>Zmiana hasła</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= h($user['id']) ?></td>
                        <td><?= h($user['username']) ?></td>
                        <td>
                            <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
            <?= appCsrfField() ?>
                                <input type="hidden" name="action" value="update_user_profile">
                                <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                                <input type="email" name="email" value="<?= h($user['email'] ?? '') ?>" placeholder="E-mail">
                                <button type="submit" id="toggleButton">Zapisz</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" style="display:flex; flex-direction:column; gap:6px; margin:0;">
            <?= appCsrfField() ?>
                                <input type="hidden" name="action" value="update_permissions">
                                <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                                <label><input type="checkbox" name="can_full_database_view" value="1" <?= !empty($user['can_full_database_view']) ? 'checked' : '' ?>> pełny podgląd zawartości bazy</label>
                                <label><input type="checkbox" name="can_edit_lists" value="1" <?= !empty($user['can_edit_lists']) ? 'checked' : '' ?>> edycja list</label>
                                <label><input type="checkbox" name="is_root" value="1" <?= !empty($user['is_root']) ? 'checked' : '' ?>> uprawnienia root (panel admin)</label>
                                <label><input type="checkbox" name="can_inventory_entries" value="1" <?= !empty($user['can_inventory_entries']) ? 'checked' : '' ?>> wpisy do księgi inwentarzowej</label>
                                <label><input type="checkbox" name="can_update_records" value="1" <?= !empty($user['can_update_records']) ? 'checked' : '' ?>> aktualizacja danych ewidencyjnych</label>
                                <label><input type="checkbox" name="can_manage_deposits" value="1" <?= !empty($user['can_manage_deposits']) ? 'checked' : '' ?>> dokumentacja depozytów</label>
                                <label><input type="checkbox" name="can_generate_reports" value="1" <?= !empty($user['can_generate_reports']) ? 'checked' : '' ?>> generowanie raportów</label>
                                <button type="submit" id="toggleButton">Zapisz uprawnienia</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
            <?= appCsrfField() ?>
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                                <input type="password" name="new_password" placeholder="Nowe hasło" required>
                                <button type="submit" id="toggleButton">Zmień</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Eksport bazy danych</h2>
        <form method="post">
            <?= appCsrfField() ?>
            <input type="hidden" name="action" value="export_db">
            <button type="submit" id="toggleButton">Backup SQL + pobierz plik</button>
        </form>

        <h2>Narzędzia ksiąg (jedna baza / osobne tabele)</h2>
        <p style="max-width:900px;">
            Konfiguracja tabel dla przycisków z bańki <code>Księga</code> (Inwentarzowa / Depozytowa / Nabytków i ubytków)
            wewnątrz jednej bazy danych. Narzędzie WWW wykonuje dry-run lub apply i klonuje układ tabel z obecnej struktury (Depozytowa).
        </p>
        <p>
            <a href="setup_ledger_databases.php" id="toggleButton">Otwórz setup tabel ksiąg (WWW)</a>
            <span style="opacity:.8; margin-left:8px;">CLI: <code>php cli/setup_ledger_databases.php --apply</code></span>
        </p>

        <h2>Backupy (harmonogram + retencja)</h2>
        <p style="max-width:900px;">
            Backupy wykonywane z tego panelu są zapisywane także lokalnie w katalogu <code>output/backups/sql/</code> i logowane.
            Retencja usuwa starsze kopie lokalne wg domyślnych limitów (daily/weekly/monthly/manual).
        </p>
        <p style="max-width:900px;">
            Automatyzacja serwerowa: można uruchamiać skrypt CLI <code>php cli/run_backup.php daily|weekly|monthly</code> z cron/systemd.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin:10px 0 14px;">
            <?php foreach ($backupSchedule as $kind => $status): ?>
                <div style="border:1px solid #ccc; border-radius:8px; padding:10px 12px; min-width:220px;">
                    <strong><?= h($status['label']) ?></strong><br>
                    Ostatni sukces:
                    <br><?= h($status['last_at'] ?? 'brak') ?><br>
                    Status:
                    <strong style="color: <?= !empty($status['is_overdue']) ? '#b10000' : '#0a6b1a' ?>;">
                        <?= !empty($status['is_overdue']) ? 'Zaległy' : 'OK' ?>
                    </strong>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" class="add-form" style="max-width:700px; margin-bottom:24px;">
            <?= appCsrfField() ?>
            <input type="hidden" name="action" value="run_backup_job">
            <label for="backup_kind">Uruchom backup cykliczny</label>
            <select id="backup_kind" name="backup_kind" required>
                <option value="daily">daily (codzienny)</option>
                <option value="weekly">weekly (tygodniowy)</option>
                <option value="monthly">monthly (miesięczny)</option>
            </select>
            <button type="submit" id="toggleButton">Uruchom backup</button>
        </form>

        <h2>Testy odtwarzania (protokoły)</h2>
        <form method="post" class="add-form" style="max-width:900px; margin-bottom:24px;">
            <?= appCsrfField() ?>
            <input type="hidden" name="action" value="record_restore_test">
            <label for="test_date">Data testu</label>
            <input type="date" id="test_date" name="test_date" value="<?= h(date('Y-m-d')) ?>" required>

            <label for="result">Wynik</label>
            <select id="result" name="result" required>
                <option value="OK">OK</option>
                <option value="PARTIAL">PARTIAL</option>
                <option value="FAIL">FAIL</option>
            </select>

            <label for="backup_run_id">Powiązany backup (ID, opcjonalnie)</label>
            <input type="number" id="backup_run_id" name="backup_run_id" min="1" placeholder="np. 42">

            <label for="protocol_ref">Numer protokołu (opcjonalnie)</label>
            <input type="text" id="protocol_ref" name="protocol_ref" placeholder="np. PROT/IT/2026/03">

            <label for="notes">Uwagi</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Zakres testu, czas odtworzenia, wynik walidacji..."></textarea>

            <button type="submit" id="toggleButton">Zapisz test odtwarzania</button>
        </form>

        <h2>Log backupów (ostatnie 30)</h2>
        <div style="overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Plik</th>
                        <th>Rozmiar</th>
                        <th>SHA-256</th>
                        <th>Start / Koniec</th>
                        <th>Uruchomił</th>
                        <th>Retencja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($backupRuns)): ?>
                        <tr><td colspan="9">Brak wpisów backupów.</td></tr>
                    <?php else: ?>
                        <?php foreach ($backupRuns as $run): ?>
                            <tr>
                                <td><?= h($run['id']) ?></td>
                                <td><?= h($run['backup_kind']) ?></td>
                                <td><?= h($run['status']) ?></td>
                                <td title="<?= h($run['file_path'] ?? '') ?>"><?= h($run['file_name'] ?? '-') ?></td>
                                <td><?= hBytes(isset($run['file_size_bytes']) ? (int)$run['file_size_bytes'] : null) ?></td>
                                <td><code><?= h($run['sha256_hash'] ? substr((string)$run['sha256_hash'], 0, 12) . '…' : '-') ?></code></td>
                                <td><?= h($run['started_at'] ?? '-') ?><br><?= h($run['finished_at'] ?? '-') ?></td>
                                <td><?= h($run['initiated_by'] ?? '-') ?></td>
                                <td>
                                    usunięto: <?= h($run['retention_deleted_count'] ?? 0) ?><br>
                                    wpis usunięty: <?= !empty($run['deleted_by_retention']) ? 'tak' : 'nie' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h2>Log testów odtwarzania (ostatnie 20)</h2>
        <div style="overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data testu</th>
                        <th>Wynik</th>
                        <th>Backup ID</th>
                        <th>Protokół</th>
                        <th>Testował</th>
                        <th>Utworzono</th>
                        <th>Uwagi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($restoreTests)): ?>
                        <tr><td colspan="8">Brak wpisów testów odtwarzania.</td></tr>
                    <?php else: ?>
                        <?php foreach ($restoreTests as $test): ?>
                            <tr>
                                <td><?= h($test['id']) ?></td>
                                <td><?= h($test['test_date']) ?></td>
                                <td><?= h($test['result']) ?></td>
                                <td><?= h($test['backup_run_id'] ?? '-') ?></td>
                                <td><?= h($test['protocol_ref'] ?? '-') ?></td>
                                <td><?= h($test['tested_by'] ?? '-') ?></td>
                                <td><?= h($test['created_at'] ?? '-') ?></td>
                                <td><?= h($test['notes'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
