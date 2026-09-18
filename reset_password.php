<?php
require_once __DIR__ . '/bootstrap.php';
include 'db.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$message = '';
$ok = false;
$showForm = false;

if ($token === '') {
    $message = 'Brak tokenu resetu.';
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT id, user_id, token_hash, expires_at, used_at
         FROM password_reset_tokens
         WHERE token_hash = :token_hash
           AND used_at IS NULL
           AND expires_at >= NOW()
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $matched = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$matched) {
        $message = 'Link resetu jest nieprawidłowy lub wygasł.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        appRequireCsrf();
        $password = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8) {
            $message = 'Hasło musi mieć co najmniej 8 znaków.';
            $showForm = true;
        } elseif ($password !== $confirm) {
            $message = 'Hasła nie są takie same.';
            $showForm = true;
        } else {
            $pdo->prepare('UPDATE karta_ewidencyjna_users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => (int)$matched['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)$matched['id']]);
            $message = 'Hasło zostało zresetowane.';
            $ok = true;
        }
    } else {
        $showForm = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Ustaw nowe hasło</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Reset hasła</h2>
    <?php if ($message !== ''): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($showForm && !$ok): ?>
        <form method="post" style="max-width:480px;">
            <?= appCsrfField() ?>
            <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
            <label for="new_password">Nowe hasło</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">
            <label for="confirm_password">Powtórz hasło</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
            <button type="submit" id="toggleButton">Zapisz hasło</button>
        </form>
    <?php endif; ?>
    <p><a href="login.php">Powrót do logowania</a></p>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
