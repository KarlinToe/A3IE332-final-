<?php
error_reporting(0);


if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';
header('Content-Type: application/json');

$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
if (strlen($q) < 2) { echo '[]'; exit; }

// Escape LIKE wildcards in the input, then wrap in %
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
$like = '%' . $escaped . '%';

$out = [];

// Helper to safely run a prepared LIKE query
function runSearch($conn, $sql, $like, $callback) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);

    // Bind results manually instead of using get_result() (no mysqlnd needed)
    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) return;

    $fields = [];
    $row    = [];
    while ($field = mysqli_fetch_field($meta)) {
        $fields[] = $field->name;
        $row[$field->name] = null;
    }
    $bindArgs = [$stmt];
    foreach ($fields as $f) {
        $bindArgs[] = &$row[$f];
    }
    call_user_func_array('mysqli_stmt_bind_result', $bindArgs);

    while (mysqli_stmt_fetch($stmt)) {
        // Copy to avoid reference issues
        $callback(array_map(function($v){ return $v; }, $row));
    }
    mysqli_stmt_close($stmt);
}

runSearch($conn,
    "SELECT ShipmentID, Status FROM Shipment WHERE ShipmentID LIKE ? LIMIT 3",
    $like,
    function($row) use (&$out) {
        $out[] = [
            'type'  => 'shipment',
            'label' => $row['ShipmentID'],
            'meta'  => $row['Status'],
            'url'   => '/~g1154085/driver/shipment_detail.php?id=' . urlencode($row['ShipmentID'])
        ];
    }
);

runSearch($conn,
    "SELECT b.BatchNumber, v.VendorName, b.VendorID
     FROM Batch b JOIN Vendor v ON v.VendorID = b.VendorID
     WHERE b.BatchNumber LIKE ? LIMIT 3",
    $like,
    function($row) use (&$out) {
        $out[] = [
            'type'  => 'batch',
            'label' => $row['BatchNumber'],
            'meta'  => $row['VendorName'],
            'url'   => '/~g1154085/warehouse/batch_lookup.php?batch=' . urlencode($row['BatchNumber'])
                       . '&vbatch=' . urlencode($row['VendorID'])
        ];
    }
);

runSearch($conn,
    "SELECT VendorID, VendorName, Status FROM Vendor WHERE VendorName LIKE ? LIMIT 3",
    $like,
    function($row) use (&$out) {
        $out[] = [
            'type'  => 'vendor',
            'label' => $row['VendorName'],
            'meta'  => $row['Status'],
            'url'   => '/~g1154085/warehouse/batch_lookup.php?vendor=' . urlencode($row['VendorID'])
        ];
    }
);

runSearch($conn,
    "SELECT ClinicID, ClinicName, City, State FROM Clinic WHERE ClinicName LIKE ? LIMIT 3",
    $like,
    function($row) use (&$out) {
        $out[] = [
            'type'  => 'clinic',
            'label' => $row['ClinicName'],
            'meta'  => $row['City'] . ', ' . $row['State'],
            'url'   => '/~g1154085/warehouse/shipments.php?clinic=' . urlencode($row['ClinicID'])
        ];
    }
);

echo json_encode($out);