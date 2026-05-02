<?php
include '../../includes/auth_check.php';
include '../../includes/db_compat.php';
header('Content-Type: application/json');
$raw = isset($_GET['ids']) ? $_GET['ids'] : '';
if (!$raw) { echo '{}'; exit; }
$ids = array_filter(array_map('trim', explode(',', $raw)), function($id){ return preg_match('/^WH-\d{3}$/', $id); });
if (empty($ids)) { echo '{}'; exit; }
$safe = array_map(function($id) use ($conn){ return "'".mysqli_real_escape_string($conn,$id)."'"; }, $ids);
$in   = implode(',', $safe);
$r    = mysqli_query($conn, "
    SELECT sz.WarehouseID, SUM(sz.CapacityVolume) AS total,
           COALESCE(SUM(bl.LotVolume),0) AS used
    FROM StorageZone sz
    LEFT JOIN StoredIn si ON si.WarehouseID=sz.WarehouseID AND si.ZoneCode=sz.ZoneCode AND si.EndTime IS NULL
    LEFT JOIN BatchLot bl ON bl.VendorID=si.VendorID AND bl.BatchNumber=si.BatchNumber AND bl.LotSeq=si.LotSeq
    WHERE sz.WarehouseID IN ($in)
    GROUP BY sz.WarehouseID
");
$out = array();
while ($row = mysqli_fetch_assoc($r)) {
    $out[$row['WarehouseID']] = array('total'=>(int)$row['total'],'used'=>(int)$row['used']);
}
echo json_encode($out);
