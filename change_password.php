<?php
session_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/header.php';

$collections = [
    'ksiazki-artystyczne' => ['label' => 'Książki Artystyczne'],
    'kolekcja-maszyn' => ['label' => 'Maszyny'],
    'kolekcja-matryc' => ['label' => 'Matryce'],
    'biblioteka' => ['label' => 'Biblioteka'],
    'kolekcja-klisz' => ['label' => 'Klisze drukarskie'],
];

$selectedCollection = $_GET['collection'] ?? ($_POST['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$username = (string)($_SESSION['username'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!appVerifyCsrf()) {
        $error = 'Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.';
    }
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($error !== '') {
        // CSRF failed
    } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Uzupełnij wszystkie pola.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Nowe hasła nie są identyczne.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Nowe hasło musi mieć co najmniej 8 znaków.';
    } else {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM karta_ewidencyjna_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, (string)($user['password_hash'] ?? ''))) {
            $error = 'Aktualne hasło jest nieprawidłowe.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare('UPDATE karta_ewidencyjna_users SET password_hash = :password_hash WHERE id = :id');
            $updateStmt->execute([
                'password_hash' => $newHash,
                'id' => $userId,
            ]);
            $message = 'Hasło zostało zmienione.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zmiana hasła | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => [],
        'username' => $username,
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'showColumnButton' => false,
        'showListEditor' => false,
        'primaryActions' => [
            ['label' => 'Powrót do strony głównej', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
        ],
    ]);
    ?>

    <h2>Zmiana hasła</h2>
    <p>Zalogowany użytkownik: <strong><?php echo htmlspecialchars($username); ?></strong></p>

    <?php if ($message !== ''): ?>
        <p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p style="color: #c00; font-weight: bold;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" class="add-form" style="max-width: 480px;">
        <?= appCsrfField() ?>
        <input type="hidden" name="collection" value="<?php echo htmlspecialchars($selectedCollection); ?>">

        <label for="current_password">Aktualne hasło</label>
        <input type="password" id="current_password" name="current_password" required>

        <label for="new_password">Nowe hasło</label>
        <input type="password" id="new_password" name="new_password" required minlength="8">

        <label for="confirm_password">Powtórz nowe hasło</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

        <button type="submit" id="toggleButton">Zmień hasło</button>
    </form>

    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>

