<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'localhost';
$db   = 'u801126150_pos222';
$user = 'u801126150_pos222';
$pass = 'Juliocesar1234$';

$mysqli = new mysqli($host, $user, $pass, $db);
echo "✅ Conectado OK. Server: " . $mysqli->server_info;
