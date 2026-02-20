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
    </div>
    <form action="authenticate.php" method="post">
        <label>Username:</label>
        <input type="text" name="username" required>
        <label>Password:</label>
        <input type="password" name="password" required>
        <button type="submit" id="toggleButton">Login</button>
    </form>
</body>
</html>