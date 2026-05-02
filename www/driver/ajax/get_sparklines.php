<?php
include '../../includes/auth_check.php';
include '../../includes/db_compat.php';
header('Content-Type: application/json');
$raw = isset($_GET['ids']) ? $_GET['ids'] : '';
if (!$raw) { echo '{}'; exit; }
$ids = array_filter(array_map('trim', explode(',', $raw)), function($id){ return preg_match('/^SHP\d{12}$/', $id); });
if (empty($ids)) { echo '{}'; exit; }
$safe = array_map(function($id) use ($conn){ return "'".mysqli_real_escape_string($conn,$id)."'"; }, $ids);
$in   = implode(',', $safe);
$r    = mysqli_query($conn, "SELECT ShipmentID, Temperature, ReadingStatus FROM SensorReading WHERE ShipmentID IN ($in) ORDER BY ShipmentID, ReadingTime ASC");
$out  = array();
while ($row = mysqli_fetch_assoc($r)) {
    $out[$row['ShipmentID']][] = array('Temperature'=>$row['Temperature'],'ReadingStatus'=>$row['ReadingStatus']);
}
echo json_encode($out);
