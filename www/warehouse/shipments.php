<?php
include '../includes/auth_check.php';
include '../includes/constants.php';
include '../includes/db_compat.php';
include '../includes/get_staff_warehouse.php';
$user = current_user();

// Handle shipment status advance: Scheduled -> In Transit
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['advance_status'])) {
    $sid = isset($_POST['shipment_id']) ? $_POST['shipment_id'] : '';
    if (preg_match('/^SHP\d{12}$/', $sid)) {
        $upd = $pdo->prepare("UPDATE Shipment SET Status='in transit' WHERE ShipmentID=? AND Status='scheduled'");
        $upd->execute(array($sid));
        $msg = $upd->rowCount() ? 'advanced' : 'error';
    }
}

// Handle new handoff event
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_handoff'])) {
    $sid      = isset($_POST['h_shipment_id']) ? $_POST['h_shipment_id'] : '';
    $vendor   = isset($_POST['h_vendor_id'])   ? $_POST['h_vendor_id']   : '';
    $batch    = isset($_POST['h_batch_num'])   ? $_POST['h_batch_num']   : '';
    $lot      = (int)(isset($_POST['h_lot_seq']) ? $_POST['h_lot_seq'] : 0);
    $evtime   = isset($_POST['h_event_time'])  ? $_POST['h_event_time']  : app_now();
    $fromloc  = isset($_POST['h_from_loc'])    ? $_POST['h_from_loc']    : '';
    $toloc    = isset($_POST['h_to_loc'])      ? $_POST['h_to_loc']      : '';
    $fromwh   = (isset($_POST['h_from_wh']) && $_POST['h_from_wh'] !== '') ? $_POST['h_from_wh'] : null;
    $fromzone = (isset($_POST['h_from_zone']) && $_POST['h_from_zone'] !== '') ? $_POST['h_from_zone'] : null;
    $fromveh  = (isset($_POST['h_from_veh']) && $_POST['h_from_veh'] !== '') ? $_POST['h_from_veh'] : null;
    $towh     = (isset($_POST['h_to_wh']) && $_POST['h_to_wh'] !== '') ? $_POST['h_to_wh'] : null;
    $tozone   = (isset($_POST['h_to_zone']) && $_POST['h_to_zone'] !== '') ? $_POST['h_to_zone'] : null;
    $toveh    = (isset($_POST['h_to_veh']) && $_POST['h_to_veh'] !== '') ? $_POST['h_to_veh'] : null;
    $toclinic = (isset($_POST['h_to_clinic']) && $_POST['h_to_clinic'] !== '') ? (int)$_POST['h_to_clinic'] : null;
    $cond     = isset($_POST['h_condition'])   ? $_POST['h_condition']   : 'Seal Intact';

    if ($sid && $vendor && $batch && $lot && in_array($fromloc,array('Zone','Vehicle','Clinic')) && in_array($toloc,array('Zone','Vehicle','Clinic')) && in_array($cond,array('Seal Intact','Packaging Damaged'))) {
        $ins = $pdo->prepare("
            INSERT INTO LotCustodyEvent
            (VendorID,BatchNumber,LotSeq,EmployeeID,EventTime,FromLocation,ToLocation,
             FromWarehouseID,FromZoneCode,FromVehicleID,FromClinicID,
             ToWarehouseID,ToZoneCode,ToVehicleID,ToClinicID,ConditionConfirmed)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute(array($vendor,$batch,$lot,$user['EmployeeID'],$evtime,$fromloc,$toloc,
                       $fromwh,$fromzone,$fromveh,null,$towh,$tozone,$toveh,$toclinic,$cond));
        $msg = 'handoff_ok';
    } else {
        $msg = 'handoff_err';
    }
}

// Filters
$tab       = isset($_GET['tab'])    ? $_GET['tab']    : 'outbound';
$sf        = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['df'])     ? $_GET['df']     : '';
$date_to   = isset($_GET['dt'])     ? $_GET['dt']     : '';
if (!in_array($sf,array('','scheduled','in transit','delivered','delayed'))) $sf='';

// Build shipment query scoped to this staff member's warehouse
$params = array();
if ($tab === 'outbound') {
    $where = $staff_wid ? "s.OriginWarehouseID = '$staff_wid'" : "s.OriginWarehouseID IS NOT NULL";
} else {
    $where = $staff_wid ? "(s.DestinationWarehouseID = '$staff_wid' OR s.DestinationClinicID IS NOT NULL)" : "s.DestinationWarehouseID IS NOT NULL OR s.DestinationClinicID IS NOT NULL";
}
if ($sf)        $where .= " AND s.Status='".mysqli_real_escape_string($pdo->conn, $sf)."'";
if ($date_from) $where .= " AND DATE(s.DepartureTime)>='".mysqli_real_escape_string($pdo->conn, $date_from)."'";
if ($date_to)   $where .= " AND DATE(s.DepartureTime)<='".mysqli_real_escape_string($pdo->conn, $date_to)."'";

$stmt = $pdo->prepare("
    SELECT DISTINCT s.ShipmentID, s.Status, s.DepartureTime, s.ArrivalTime, s.VehicleID,
        wh_o.WarehouseName AS origin_name,
        CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END AS dest_name,
        (SELECT COUNT(*) FROM ShipmentLot sl WHERE sl.ShipmentID=s.ShipmentID) AS lot_count,
        (SELECT COUNT(*) FROM ShipmentTempBreach stb WHERE stb.ShipmentID=s.ShipmentID AND stb.ResolutionStatus='Open') AS alert_count
    FROM Shipment s
    JOIN Warehouse wh_o ON wh_o.WarehouseID=s.OriginWarehouseID
    LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID=s.DestinationWarehouseID
    LEFT JOIN Clinic c ON c.ClinicID=s.DestinationClinicID
    WHERE $where
    ORDER BY s.DepartureTime DESC
    LIMIT 200
");
$stmt->execute(array());
$shipments = $stmt->fetchAll();

// For handoff form
$sel_sid = isset($_GET['sid']) ? $_GET['sid'] : '';
$sel_ship = null;
$sel_lots = array();
if (preg_match('/^SHP\d{12}$/', $sel_sid)) {
    $stmt = $pdo->prepare("SELECT * FROM Shipment WHERE ShipmentID=?");
    $stmt->execute(array($sel_sid));
    $sel_ship = $stmt->fetch();
    $stmt = $pdo->prepare("
        SELECT sl.*, ven.VendorName FROM ShipmentLot sl
        JOIN Vendor ven ON ven.VendorID=sl.VendorID
        WHERE sl.ShipmentID=?
    ");
    $stmt->execute(array($sel_sid));
    $sel_lots = $stmt->fetchAll();
}

$all_wh  = $pdo->query("SELECT WarehouseID, WarehouseName FROM Warehouse ORDER BY WarehouseID")->fetchAll();
$all_veh = $pdo->query("SELECT VehicleID FROM Vehicle ORDER BY VehicleID")->fetchAll();

$page_title = 'Shipments';
include '../includes/header.php';
?>
<div class="page-body">

  <?php if ($msg==='advanced'): ?><div class="alert alert-success">Shipment advanced to In Transit.</div><?php endif; ?>
  <?php if ($msg==='error'):    ?><div class="alert alert-error">Could not advance — shipment may not be Scheduled.</div><?php endif; ?>
  <?php if ($msg==='handoff_ok'): ?><div class="alert alert-success">Handoff event recorded.</div><?php endif; ?>
  <?php if ($msg==='handoff_err'): ?><div class="alert alert-error">Could not record handoff — check all required fields.</div><?php endif; ?>

  <div class="section-card">
    <div class="tabs">
      <button class="tab-btn <?php echo $tab==='outbound'?'active':''; ?>" onclick="window.location='?tab=outbound'">Outbound</button>
      <button class="tab-btn <?php echo $tab==='inbound'?'active':''; ?>"  onclick="window.location='?tab=inbound'">Inbound</button>
    </div>

    <div style="padding:14px 20px;border-bottom:1.5px solid #f1f5f9;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Status</label>
          <select name="status" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach (array('scheduled','in transit','delivered','delayed') as $sv): ?>
            <option value="<?php echo $sv; ?>" <?php echo $sf===$sv?'selected':''; ?>><?php echo ucwords($sv); ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">From date</label>
          <input type="date" name="df" value="<?php echo htmlspecialchars($date_from); ?>" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">To date</label>
          <input type="date" name="dt" value="<?php echo htmlspecialchars($date_to); ?>" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="?tab=<?php echo $tab; ?>" class="btn btn-secondary btn-sm">Clear</a>
      </form>
    </div>

    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Shipment</th><th>Status</th><th><?php echo $tab==='outbound'?'Destination':'Origin'; ?></th><th>Departed</th><th>Arrived</th><th>Vehicle</th><th>Lots</th><th>Alerts</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($shipments)): ?>
          <tr><td colspan="9" class="empty-row">No shipments found.</td></tr>
          <?php else: foreach ($shipments as $s): ?>
          <tr>
            <td class="mono"><a href="?tab=<?php echo $tab; ?>&sid=<?php echo urlencode($s['ShipmentID']); ?>" style="color:#4f46e5"><?php echo htmlspecialchars(fmt_sid($s['ShipmentID'])); ?></a></td>
            <td><?php echo status_badge($s['Status']); ?></td>
            <td><?php echo htmlspecialchars($tab==='outbound' ? ($s['dest_name'] ? $s['dest_name'] : '—') : $s['origin_name']); ?></td>
            <td><?php echo fmt_dt($s['DepartureTime']); ?></td>
            <td><?php echo fmt_dt($s['ArrivalTime']); ?></td>
            <td class="mono"><?php echo htmlspecialchars($s['VehicleID'] ? $s['VehicleID'] : '—'); ?></td>
            <td><?php echo $s['lot_count']; ?></td>
            <td><?php echo $s['alert_count']>0?'<span style="color:#dc2626;font-weight:700">'.$s['alert_count'].'</span>':'—'; ?></td>
            <td>
              <?php if ($s['Status']==='scheduled'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="shipment_id" value="<?php echo htmlspecialchars($s['ShipmentID']); ?>">
                <button name="advance_status" value="1" class="btn btn-success btn-sm"
                        onclick="return confirm('Advance to In Transit?')">Depart</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($sel_ship): ?>
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">Record handoff — <?php echo htmlspecialchars(fmt_sid($sel_sid)); ?></h2>
      <a href="?tab=<?php echo $tab; ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
    <div class="section-body">
      <form method="POST">
        <input type="hidden" name="h_shipment_id" value="<?php echo htmlspecialchars($sel_sid); ?>">
        <div class="form-row">
          <div class="form-group">
            <label>Lot</label>
            <select name="h_lot_seq" id="lot-sel" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <?php foreach ($sel_lots as $l): ?>
              <option value="<?php echo $l['LotSeq']; ?>"
                      data-vendor="<?php echo htmlspecialchars($l['VendorID']); ?>"
                      data-batch="<?php echo htmlspecialchars($l['BatchNumber']); ?>">
                <?php echo htmlspecialchars($l['VendorName'].' / '.$l['BatchNumber'].' / Lot '.$l['LotSeq']); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="h_vendor_id" id="h-vendor">
            <input type="hidden" name="h_batch_num" id="h-batch">
          </div>
          <div class="form-group">
            <label>Event time</label>
            <input type="datetime-local" name="h_event_time" value="<?php echo date('Y-m-d\TH:i', strtotime(app_now())); ?>" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>From location type</label>
            <select name="h_from_loc" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option>Zone</option><option>Vehicle</option><option>Clinic</option>
            </select>
          </div>
          <div class="form-group">
            <label>To location type</label>
            <select name="h_to_loc" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option>Vehicle</option><option>Zone</option><option>Clinic</option>
            </select>
          </div>
        </div>
        <div class="form-row-3">
          <div class="form-group">
            <label>From warehouse</label>
            <select name="h_from_wh" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option value="">—</option>
              <?php foreach ($all_wh as $w): ?><option value="<?php echo $w['WarehouseID']; ?>"><?php echo htmlspecialchars($w['WarehouseID']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>From zone</label>
            <input type="text" name="h_from_zone" placeholder="e.g. 01" maxlength="2" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
          </div>
          <div class="form-group">
            <label>From vehicle</label>
            <select name="h_from_veh" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option value="">—</option>
              <?php foreach ($all_veh as $v): ?><option value="<?php echo $v['VehicleID']; ?>"><?php echo htmlspecialchars($v['VehicleID']); ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row-3">
          <div class="form-group">
            <label>To warehouse</label>
            <select name="h_to_wh" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option value="">—</option>
              <?php foreach ($all_wh as $w): ?><option value="<?php echo $w['WarehouseID']; ?>"><?php echo htmlspecialchars($w['WarehouseID']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>To zone</label>
            <input type="text" name="h_to_zone" placeholder="e.g. 03" maxlength="2" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
          </div>
          <div class="form-group">
            <label>To vehicle</label>
            <select name="h_to_veh" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option value="">—</option>
              <?php foreach ($all_veh as $v): ?><option value="<?php echo $v['VehicleID']; ?>"><?php echo htmlspecialchars($v['VehicleID']); ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>To clinic ID (if applicable)</label>
            <input type="number" name="h_to_clinic" placeholder="Leave blank if not clinic" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
          </div>
          <div class="form-group">
            <label>Condition confirmed</label>
            <select name="h_condition" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
              <option>Seal Intact</option>
              <option>Packaging Damaged</option>
            </select>
          </div>
        </div>
        <button type="submit" name="submit_handoff" value="1" class="btn btn-primary">Record handoff</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
var lotSel = document.getElementById('lot-sel');
if (lotSel) {
    function syncLot() {
        var opt = lotSel.options[lotSel.selectedIndex];
        document.getElementById('h-vendor').value = opt.dataset.vendor || '';
        document.getElementById('h-batch').value  = opt.dataset.batch  || '';
    }
    lotSel.addEventListener('change', syncLot);
    syncLot();
}
</script>

<?php include '../includes/footer.php'; ?>
