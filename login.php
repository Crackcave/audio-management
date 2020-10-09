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
<head>
    <style>
        body { margin: 0px; }

        .background {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
        }

        /*
        http://jsfiddle.net/6KaMy/1/
        is there a better way than the absolute positioning and negative margin.
        It has the problem that the content will  will be cut on top if the window is smalller than the content.
        */

        .content {
            width: 200px;
            height: 200px;

            position:absolute;
            left:0; right:0;
            top:0; bottom:0;
            margin:auto;

            max-width:100%;
            max-height:100%;
            overflow:auto;
        }

        input {
            width: 100%;
        }

        .button {
            background-color: #4CAF50; /* Green */
            border: none;
            color: white;
            padding: 15px 32px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="background">
    <div class="content">
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
            <br /><br />
            <input class="button" type="submit" value="Login" />
        </form>
    </div>
</div>

</body>
</html>
