<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /~g1154085/index.php");
    exit;
}

function require_role($role) {
    if ($_SESSION['user']['Role'] !== $role) {
        header("Location: /~g1154085/index.php");
        exit;
    }
}

function current_user() {
    return $_SESSION['user'];
}
