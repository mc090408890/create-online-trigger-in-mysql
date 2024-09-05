<?php
$host = 'localhost';
$dbname = 'database'; // Replace with your database name
$username = 'root'; // Replace with your DB username
$password = ''; // Replace with your DB password

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['table'])) {
    $selectedTable = $_POST['table'];

    // Fetch all columns of the selected table
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = :table");
    $stmt->execute([':dbname' => $dbname, ':table' => $selectedTable]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Generate the column options for the select box
    foreach ($columns as $column) {
        echo "<option value='$column'>$column</option>";
    }
}
