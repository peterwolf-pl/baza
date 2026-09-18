<?php
require_once __DIR__ . '/bootstrap.php';
include 'db.php';

function ensureUserPermissionColumns(PDO $pdo): void
{
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!appVerifyCsrf()) {
    header('Location: login.php?error=1');
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: login.php?error=1');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM karta_ewidencyjna_users WHERE username = :username");
$stmt->execute(['username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true);
    appApplyUserSession($user);
    header('Location: /index.php');
    exit;
}

header('Location: login.php?error=1');
exit;
