<?php
include '../includes/auth_check.php';
include '../includes/db_compat.php';
global $conn;

$eid = $_SESSION['user']['EmployeeID'];
echo "<h3>Logged in as: " . $eid . "</h3>";

// Test the exact query from home.php
$result = mysqli_query($conn, "
    SELECT DISTINCT s.ShipmentID, s.Status
    FROM Shipment s
    JOIN LotCustodyEvent ce ON ce.FromVehicleID=s.VehicleID 
        AND ce.FromLocation='Vehicle'
        AND ce.EventTime=s.ArrivalTime 
        AND ce.EmployeeID='$eid'
    LIMIT 5
");

if (!$result) {
    echo "<p style='color:red'>Query failed: " . mysqli_error($conn) . "</p>";
} elseif (mysqli_num_rows($result) === 0) {
    echo "<p style='color:orange'>Query ran but returned 0 rows for $eid</p>";
} else {
    echo "<p style='color:green'>Found rows!</p>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<pre>" . print_r($row, true) . "</pre>";
    }
}

// Also check if $conn is valid
echo "<p>Connection status: " . ($conn ? "OK" : "FAILED") . "</p>";
?>