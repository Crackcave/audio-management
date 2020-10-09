<?php
include_once 'session.php';
if (isset($_GET['logout']) && $_GET['logout']) {
    $_SESSION['admin'] = false;
}
$loginFailed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?: null;
    $password = $_POST['password'] ?: null;
    $secret = trim(file_get_contents(__DIR__.'/secret'));
    if ($username === 'admin' && $secret === $password) {
        $_SESSION['admin'] = true;
        $rememberMe = md5(uniqid(mt_rand(), true));
        setcookie('_remember_me', $rememberMe, time() + (60 * 60 * 24 * 365));
        file_put_contents(__DIR__.'/remember_me/'.$rememberMe, '1');
        header("Location: index.php");
        exit();
    }
    $loginFailed = true;
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
            height: 250px;

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
            width: 100%;
        }
    </style>
</head>
<body>
<div class="background">
    <div class="content">
        <form method="post">
            <?php if ($loginFailed) { ?><p style="color: red;">Wrong password</p><?php } ?>
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
        <p><a href="index.php">Back</a></p>
    </div>
</div>

</body>
</html>
