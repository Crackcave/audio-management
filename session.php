<?php
session_start();
$isAdmin = isset($_SESSION['admin']) && $_SESSION['admin'];

if (!$isAdmin && isset($_COOKIE['_remember_me'])) {
    $isAdmin = file_exists(__DIR__.'/remember_me/'.$_COOKIE['_remember_me']);
}

