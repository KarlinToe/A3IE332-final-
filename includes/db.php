<?php
$host = "mydb.itap.purdue.edu";
$user = "g1154085";
$pass = "1RfxCfDD";
$db   = "g1154085";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
