<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informacje o projekcie</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .project-info {
            max-width: 760px;
            margin: 20px auto;
            padding: 24px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
            line-height: 1.6;
        }

        .project-info h1 {
            margin-top: 0;
        }

        .project-info ul {
            margin-top: 0;
        }

        .project-info .actions {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="project-info">
        <h1>Informacje o projekcie</h1>
        <p>
            To strona informacyjna projektu <strong>baza.mkal.pl</strong>. Znajdziesz tu szybki opis
            celu systemu i jego głównych możliwości.
        </p>
        <ul>
            <li>zarządzanie wpisami kolekcji muzealnych,</li>
            <li>wyszukiwanie i filtrowanie danych,</li>
            <li>tworzenie list roboczych i obsługa dokumentacji.</li>
        </ul>
        <p>
            W razie potrzeby rozbudowy treści tej sekcji można dodać dodatkowe informacje, np.
            instrukcję obsługi, kontakt do administratora lub historię zmian.
        </p>

        <div class="actions">
            <a role="button" id="toggleButton" href="index.php">Powrót do strony głównej</a>
        </div>
    </div>
</body>
</html>
