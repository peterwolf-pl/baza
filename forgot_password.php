<?php
session_start();
require_once __DIR__ . '/auth.php';
include 'db.php';

function ensurePasswordResetTables(PDO $pdo): void {
    $pdo->exec("ALTER TABLE karta_ewidencyjna_users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL");
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
}

ensurePasswordResetTables($pdo);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Podaj poprawny adres e-mail.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, email FROM karta_ewidencyjna_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, email, token_hash, expires_at) VALUES (:user_id, :email, :token_hash, :expires_at)')
                ->execute([
                    'user_id' => (int)$user['id'],
                    'email' => (string)$user['email'],
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                ]);

            $resetLink = appPublicUrl('reset_password.php', ['t' => $token]);
            $subject = 'Reset hasła - baza.mkal.pl';
            $body = "Link do resetu hasła (ważny 1h):\n" . $resetLink;
            @mail((string)$user['email'], $subject, $body);
        }

        $message = 'Jeśli adres istnieje w systemie, wysłano link do resetu hasła.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Reset hasła</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Nie pamiętam hasła</h2>
    <?php if ($message !== ''): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <form method="post" style="max-width:480px;">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required>
        <button type="submit" id="toggleButton">Wyślij link resetu</button>
    </form>
    <p><a href="login.php">Powrót do logowania</a></p>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
