<?php
// Determines the home warehouse for the logged-in warehouse staff member.
// Uses most frequent ToWarehouseID, with most recent event as tiebreaker.
// Sets $staff_wid (string|null) and $staff_wname (string).
// Include AFTER auth_check.php and db_compat.php.

$staff_wid   = null;
$staff_wname = 'Unknown Warehouse';

if (isset($_SESSION['user']['EmployeeID'])) {
    $eid = $_SESSION['user']['EmployeeID'];
    $ws = $pdo->prepare("
        SELECT ce.ToWarehouseID, COUNT(*) AS cnt, MAX(ce.EventTime) AS last_event, w.WarehouseName
        FROM LotCustodyEvent ce
        JOIN Warehouse w ON w.WarehouseID = ce.ToWarehouseID
        WHERE ce.EmployeeID = ? AND ce.ToWarehouseID IS NOT NULL
        GROUP BY ce.ToWarehouseID, w.WarehouseName
        ORDER BY cnt DESC, last_event DESC
        LIMIT 1
    ");
    $ws->execute(array($eid));
    $wr = $ws->fetch();
    if ($wr) {
        $staff_wid   = $wr['ToWarehouseID'];
        $staff_wname = $wr['WarehouseName'];
    }
}
