<?php
include '../includes/auth_check.php';
include '../includes/constants.php';
include '../includes/db_compat.php';
include '../includes/get_staff_warehouse.php';

$df   = (isset($_GET['df']) ? $_GET['df'] : '');
$dt   = (isset($_GET['dt']) ? $_GET['dt'] : '');
$emp  = trim((isset($_GET['emp']) ? $_GET['emp'] : ''));
$cond = isset($_GET['cond']) ? $_GET['cond'] : '';
$dir  = (isset($_GET['dir']) ? $_GET['dir'] : '');
$export = isset($_GET['export']);

$where  = ['1=1'];
$params = [];
// Always filter to this staff member's warehouse
if ($staff_wid) {
    $where[] = '(ce.ToWarehouseID=? OR ce.FromWarehouseID=?)';
    $params[] = $staff_wid;
    $params[] = $staff_wid;
}
if ($df)   { $where[] = 'ce.EventTime >= ?'; $params[] = $df.' 00:00:00'; }
if ($dt)   { $where[] = 'ce.EventTime <= ?'; $params[] = $dt.' 23:59:59'; }
if ($emp)  { $where[] = '(e.FirstName LIKE ? OR e.LastName LIKE ? OR ce.EmployeeID = ?)'; $params[] = '%'.$emp.'%'; $params[] = '%'.$emp.'%'; $params[] = $emp; }
if ($cond && in_array($cond,['Seal Intact','Packaging Damaged'])) { $where[] = 'ce.ConditionConfirmed = ?'; $params[] = $cond; }
if ($dir === 'out') { $where[] = "ce.ToLocation = 'Vehicle'"; }
if ($dir === 'in')  { $where[] = "ce.FromLocation = 'Vehicle'"; }

$sql = "
    SELECT ce.CustodyEventID, ce.EventTime,
           CONCAT(bl.VendorID,'/',bl.BatchNumber,'/',bl.LotSeq) AS lot_id,
           CONCAT(e.FirstName,' ',e.LastName) AS emp_name, e.Role,
           ce.FromLocation,
           COALESCE(CONCAT(ce.FromWarehouseID,'-',ce.FromZoneCode), ce.FromVehicleID, CAST(ce.FromClinicID AS CHAR)) AS from_detail,
           ce.ToLocation,
           COALESCE(CONCAT(ce.ToWarehouseID,'-',ce.ToZoneCode), ce.ToVehicleID, CAST(ce.ToClinicID AS CHAR)) AS to_detail,
           ce.ConditionConfirmed
    FROM LotCustodyEvent ce
    JOIN BatchLot bl ON bl.VendorID=ce.VendorID AND bl.BatchNumber=ce.BatchNumber AND bl.LotSeq=ce.LotSeq
    JOIN Employee e  ON e.EmployeeID=ce.EmployeeID
    WHERE ".implode(' AND ',$where)."
    ORDER BY ce.EventTime DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// CSV export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="custody_log_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Event ID','Event Time','Lot','Employee','Role','From Location','From Detail','To Location','To Detail','Condition']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['CustodyEventID'],$r['EventTime'],$r['lot_id'],$r['emp_name'],$r['Role'],
                       $r['FromLocation'],$r['from_detail'],$r['ToLocation'],$r['to_detail'],$r['ConditionConfirmed']]);
    }
    fclose($out);
    exit;
}

$page_title = 'Custody Event Log';
include '../includes/header.php';
?>
<div class="page-body">

  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">Custody event log</h2>
      <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'1'])); ?>" class="btn btn-secondary btn-sm">Export CSV</a>
    </div>

    <!-- Filters -->
    <div style="padding:14px 20px;border-bottom:1.5px solid #f1f5f9">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">From date</label>
          <input type="date" name="df" value="<?php echo htmlspecialchars($df); ?>" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">To date</label>
          <input type="date" name="dt" value="<?php echo htmlspecialchars($dt); ?>" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Employee</label>
          <input type="text" name="emp" value="<?php echo htmlspecialchars($emp); ?>" placeholder="Name or ID" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;width:160px"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Condition</label>
          <select name="cond" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="Seal Intact" <?php echo $cond==='Seal Intact'?'selected':''; ?>>Seal Intact</option>
            <option value="Packaging Damaged" <?php echo $cond==='Packaging Damaged'?'selected':''; ?>>Packaging Damaged</option>
          </select></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Direction</label>
          <select name="dir" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="out" <?php echo $dir==='out'?'selected':''; ?>>Loading out</option>
            <option value="in"  <?php echo $dir==='in'?'selected':''; ?>>Receiving in</option>
          </select></div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="custody_log.php" class="btn btn-secondary btn-sm">Clear</a>
      </form>
    </div>

    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Time</th><th>Lot</th><th>Employee</th><th>Role</th><th>From</th><th>To</th><th>Condition</th></tr></thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="empty-row">No events found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
          <tr>
            <td style="white-space:nowrap"><?php echo fmt_dt($r['EventTime']); ?></td>
            <td class="mono" style="font-size:11px"><?php echo htmlspecialchars($r['lot_id']); ?></td>
            <td><?php echo htmlspecialchars($r['emp_name']); ?></td>
            <td><?php echo htmlspecialchars($r['Role']); ?></td>
            <td><?php echo htmlspecialchars($r['FromLocation'].($r['from_detail']?' ('.$r['from_detail'].')':'')); ?></td>
            <td><?php echo htmlspecialchars($r['ToLocation'].($r['to_detail']?' ('.$r['to_detail'].')':'')); ?></td>
            <td><?php echo status_badge($r['ConditionConfirmed']); ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 20px;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9">
      Showing <?php echo count($rows); ?> records. Custody events are append-only and cannot be edited or deleted.
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>
