<?php
session_start();
require_once __DIR__ . '/app_settings.php';

$organizationProfile = appSettingsGetOrganizationProfile();
$organizationName = $organizationProfile['name'] !== '' ? $organizationProfile['name'] : 'Baza';
$organizationWebsite = $organizationProfile['website'] !== '' ? $organizationProfile['website'] : 'https://baza.mkal.pl';
$organizationAddress = $organizationProfile['address'];
$organizationEmail = $organizationProfile['contact_email'];

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

        .project-info .logo {
            filter: none !important;
        }

        @media (prefers-color-scheme: dark) {
            .project-info {
                color: #000;
            }

            .project-info a:not(#toggleButton) {
                color: #000;
            }
        }
    </style>
</head>
<body>
    <div class="project-info">
        <h1>Informacje o projekcie:</h1>
        <h2>Baza <?php echo htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8'); ?></h2>
          <a href="<?php echo htmlspecialchars($organizationWebsite, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="bazamka.png" width="400" alt="<?php echo htmlspecialchars('Logo bazy ' . $organizationName, ENT_QUOTES, 'UTF-8'); ?>" class="logo">
        </a>
        <p>
            To strona informacyjna projektu  <a href="<?php echo htmlspecialchars($organizationWebsite, ENT_QUOTES, 'UTF-8'); ?>"><strong><?php echo htmlspecialchars($organizationWebsite, ENT_QUOTES, 'UTF-8'); ?></strong></a>. 
            Znajdziesz tu szybki opis
            celu systemu i jego głównych możliwości.
        </p>
        <ul>
            <li>zarządzanie wpisami kolekcji muzealnych,</li>
            <li>wyszukiwanie i filtrowanie danych,</li>
            <li>tworzenie list roboczych i obsługa dokumentacji.</li>
            <li>rejestracja wszelkich zmian we wpisach</li>
            <li>historia przemieszczeń</li>
        </ul>
        <h3>Zgodność i podstawa prawna</h3>
        <p>
            System wspiera prowadzenie ewidencji muzealiów oraz organizację dokumentacji zgodnie z
            obowiązującymi przepisami i wytycznymi (przy założeniu wdrożenia właściwych procedur
            organizacyjnych w muzeum), w szczególności:
        </p>
        <ul>
            <li>Ustawą z dnia 21 listopada 1996 r. o muzeach,</li>
            <li>Rozporządzeniem Ministra Kultury z dnia 30 sierpnia 2004 r. w sprawie zakresu, form i sposobu ewidencjonowania zabytków w muzeach,</li>
            <li>Rozporządzeniem Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO) w zakresie ochrony danych osobowych, kontroli dostępu i rozliczalności działań użytkowników,</li>
            <li>wytycznymi Narodowego Instytutu Muzealnictwa i Ochrony Zbiorów (NIMOZ) dotyczącymi dokumentacji, ewidencji i bezpieczeństwa zbiorów.</li>
        </ul>
        <br>
        <h3>Autorzy projektu:</h3>
             <ul>
           <li> Martyn Kramek - współpraca merytoryczna</li>
           <li> Piotr Wilkocki - kodowanie </li>
           <li> Kacper Zagdan - font/design Grohman-Grotesk </li>
            </ul>
<?php if ($organizationWebsite !== ''): ?>
<a href="<?php echo htmlspecialchars($organizationWebsite, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8'); ?></a> <strong>|    </strong>
<?php else: ?>
<?php echo htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8'); ?> <strong>|    </strong>
<?php endif; ?>
<?php if ($organizationAddress !== ''): ?>
<?php echo htmlspecialchars($organizationAddress, ENT_QUOTES, 'UTF-8'); ?> <strong>|    </strong>
<?php endif; ?>
<?php if ($organizationEmail !== ''): ?>
<a href="mailto:<?php echo htmlspecialchars($organizationEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($organizationEmail, ENT_QUOTES, 'UTF-8'); ?></a> <strong>|    </strong>
<?php endif; ?>
<a href="https://github.com/peterwolf-pl/baza">source code on Github</a> by <a href="https://peterwolf.pl">peterwolf.pl</a>  <strong>|    </strong>    
        <div class="actions">
            <a role="button" id="toggleButton" href="index.php">Powrót do strony głównej</a>
        </div>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
