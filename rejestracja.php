<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Hash the password
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert user into the database
    $stmt = $pdo->prepare("INSERT INTO karta_ewidencyjna_users (username, password_hash) VALUES (:username, :password_hash)");
    $stmt->execute(['username' => $username, 'password_hash' => $password_hash]);
    
    echo "User registered successfully.";
}
?>
<form method="post">
    <label>Username:</label>
    <input type="text" name="username" required>
    <label>Password:</label>
    <input type="password" name="password" required>
    <button type="submit">Register</button>
</form>
