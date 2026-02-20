<?php
session_start();
include 'db.php';

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';

if (isset($_GET['logout_admin'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username !== 'root') {
            $message = 'Do panelu administracyjnego może zalogować się tylko root.';
        } else {
            $stmt = $pdo->prepare('SELECT id, username, password_hash FROM karta_ewidencyjna_users WHERE username = :username LIMIT 1');
            $stmt->execute(['username' => 'root']);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['admin_authenticated'] = true;
                header('Location: admin.php');
                exit;
            }

            $message = 'Nieprawidłowe dane logowania root.';
        }
    }

    if (!empty($_SESSION['admin_authenticated']) && $action === 'change_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');

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

    if (!empty($_SESSION['admin_authenticated']) && $action === 'export_db') {
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $dump = "-- Export bazy danych\n";
        $dump .= '-- Wygenerowano: ' . date('Y-m-d H:i:s') . "\n\n";

        foreach ($tables as $table) {
            $createStmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? '';

            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $dump .= $createSql . ";\n\n";

            $rowsStmt = $pdo->query('SELECT * FROM `' . $table . '`');
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $columns = array_map(static function ($column) {
                    return '`' . $column . '`';
                }, array_keys($row));

                $values = array_map(static function ($value) use ($pdo) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $pdo->quote((string) $value);
                }, array_values($row));

                $dump .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            }

            $dump .= "\n";
        }

        $fileName = 'backup_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($dump));
        echo $dump;
        exit;
    }
}

$usersStmt = $pdo->query('SELECT id, username FROM karta_ewidencyjna_users ORDER BY username ASC');
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
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
            <input type="hidden" name="action" value="admin_login">
            <label>Username:</label>
            <input type="text" name="username" required>
            <label>Password:</label>
            <input type="password" name="password" required>
            <button type="submit" id="toggleButton">Zaloguj jako root</button>
        </form>
    <?php else: ?>
        <h2>Lista użytkowników</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
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
            <input type="hidden" name="action" value="export_db">
            <button type="submit" id="toggleButton">Export do pliku .sql</button>
        </form>
    <?php endif; ?>
</body>
</html>
