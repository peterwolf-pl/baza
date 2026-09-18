<?php
require_once __DIR__ . '/bootstrap.php';
include 'db.php';

if (empty($_SESSION['is_root'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!appVerifyCsrf()) {
        $error = 'Nieprawidłowy token CSRF.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));

        if ($username === '' || $password === '') {
            $error = 'Podaj nazwę użytkownika i hasło.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Hasło musi mieć co najmniej 8 znaków.';
        } else {
            try {
                $check = $pdo->prepare('SELECT COUNT(*) FROM karta_ewidencyjna_users WHERE username = ?');
                $check->execute([$username]);
                if ($check->fetchColumn()) {
                    $error = 'Użytkownik o takiej nazwie już istnieje.';
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->exec("ALTER TABLE karta_ewidencyjna_users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE karta_ewidencyjna_users ADD COLUMN IF NOT EXISTS can_full_database_view TINYINT(1) NOT NULL DEFAULT 0");
                    $pdo->exec("ALTER TABLE karta_ewidencyjna_users ADD COLUMN IF NOT EXISTS can_edit_lists TINYINT(1) NOT NULL DEFAULT 0");
                    $pdo->exec("ALTER TABLE karta_ewidencyjna_users ADD COLUMN IF NOT EXISTS is_root TINYINT(1) NOT NULL DEFAULT 0");
                    $stmt = $pdo->prepare("INSERT INTO karta_ewidencyjna_users (username, email, password_hash, can_full_database_view, can_edit_lists, is_root, can_inventory_entries, can_update_records, can_manage_deposits, can_generate_reports) VALUES (:username, :email, :password_hash, 0, 0, 0, 0, 0, 0, 0)");
                    $stmt->execute([
                        'username' => $username,
                        'email' => ($email !== '' ? $email : null),
                        'password_hash' => $password_hash,
                    ]);
                    $message = 'Użytkownik został zarejestrowany.';
                }
            } catch (Exception $e) {
                error_log('rejestracja.php: ' . $e->getMessage());
                $error = 'Nie udało się zarejestrować użytkownika.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rejestracja</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php if ($message): ?><p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php if ($error): ?><p style="color:#b00020;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <label>Username:</label>
    <input type="text" name="username" required>
    <label>Password:</label>
    <input type="password" name="password" required minlength="8">
    <label>Email:</label>
    <input type="email" name="email">
    <button type="submit">Register</button>
</form>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
