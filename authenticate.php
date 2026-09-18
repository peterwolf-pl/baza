<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
session_start();
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

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
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
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = (string)($user['email'] ?? '');
    $_SESSION['can_full_database_view'] = (int)($user['can_full_database_view'] ?? 0);
    $_SESSION['can_edit_lists'] = (int)($user['can_edit_lists'] ?? 0);
    $_SESSION['is_root'] = (int)($user['is_root'] ?? 0);
    $_SESSION['can_inventory_entries'] = (int)($user['can_inventory_entries'] ?? 0);
    $_SESSION['can_update_records'] = (int)($user['can_update_records'] ?? 0);
    $_SESSION['can_manage_deposits'] = (int)($user['can_manage_deposits'] ?? 0);
    $_SESSION['can_generate_reports'] = (int)($user['can_generate_reports'] ?? 0);

    header('Location: /index.php');
    exit;
}

header('Location: login.php?error=1');
exit;
