<?php
session_start();
if (isset($_GET['logout']) && $_GET['logout']) {
    $_SESSION['admin'] = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?: null;
    $password = $_POST['password'] ?: null;
    $hashed = hash('sha512', $password);
    if ($username === 'admin' && $hashed === 'a99249c0ff04a8bceac42025d8609fdf65a3060378f8dbfc49ebed6289bd853aa453cc66116c1f804d78c92aad7c6d931bac744f227942bed4676a205f654d5d') {
        $_SESSION['admin'] = true;
    }
}

?>

<html>
<body>
<?php if ($_SESSION['admin']) { ?>
    DU BIST ADMIN
<?php } else { ?>
    DU BIST KEIN ADMIN
<?php } ?>
<br />
<form method="post">
    <label for="username">
        Username:
    </label>
    <input type="text" id="username" name="username">
    <br />
    <label for="password">
        Password:
    </label>
    <input type="password" id="password" name="password">
    <input type="submit" />
</form>
</body>
</html>
