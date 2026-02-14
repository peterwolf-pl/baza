<?php
$servername = "localhost";  // Update to your server details
$usernames = "xxx";
$passwords = "xxx";
$dbname = "xxx";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $usernames, $passwords);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
