<?php
$conn = new mysqli('localhost', 'root', '', 'guillermosdatabase_latest');
if ($conn->connect_error) {
    die('Connect failed: ' . $conn->connect_error);
}
$result = $conn->query('DESCRIBE orders');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
} else {
    echo 'Table does not exist or error: ' . $conn->error;
}
$conn->close();
?>