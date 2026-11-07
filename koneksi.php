<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "db_kelasmasak";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Conection failed: ". $conn->connect_error);
}
?>