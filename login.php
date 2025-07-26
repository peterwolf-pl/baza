<!DOCTYPE html>
<html lang="pl">
<head>
      <style>
        /* Basic styling for layout */
        body { font-family: Arial, sans-serif; padding: 20px; }
        .column-selector, .data-table { margin-top: 20px; }
        .column-selector label { display: block; }
        .data-table table { border-collapse: collapse; width: 100%; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; }
        .data-table th { background-color: #f2f2f2; }

        /* Collapsible styling */
        #columnSelectorContainer { display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; }
        #toggleButton { cursor: pointer; margin-bottom: 10px; background-color: #007BFF; color: white; border: none; padding: 8px 16px; border-radius: 5px; }
        #toggleButton:focus { outline: none; }
    </style>
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
