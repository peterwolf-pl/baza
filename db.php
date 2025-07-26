<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$servername = "localhost";  // Update to your server details
$usernames = "srv51934_mkal_inwentarz";
$passwords = "2akvAaHNNk9Ey4nYGpHs";
$dbname = "srv51934_mkal_inwentarz";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $usernames, $passwords);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
