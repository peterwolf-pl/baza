<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <link rel="stylesheet" href="styles.css">
    <meta charset="UTF-8">
    <title>Login</title>
</head>
<body>
    <div class="header">
        <a href="https://baza.mkal.pl">
            <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
        </a>
        <div class="header-links"></div>
    </div>

    <?php if ($error): ?>
        <p style="color:#b00020;">Nieprawidłowa nazwa użytkownika lub hasło.</p>
    <?php endif; ?>
    <form action="authenticate.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <label>Username:</label>
        <input type="text" name="username" required autocomplete="username">
        <label>Password:</label>
        <input type="password" name="password" required autocomplete="current-password">
        <button type="submit" id="toggleButton">Login</button>
        <p><a href="forgot_password.php">Nie pamiętam hasła</a></p>
    </form>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
