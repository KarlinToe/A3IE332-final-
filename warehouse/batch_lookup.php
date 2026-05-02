<?php
include '../includes/auth_check.php';
include '../includes/constants.php';
include '../includes/db_compat.php';
include '../includes/get_staff_warehouse.php';

$search_vendor = trim((isset($_GET['vendor_search']) ? $_GET['vendor_search'] : ''));
$search_batch  = trim((isset($_GET['batch_search']) ? $_GET['batch_search'] : ''));
$exp_from      = (isset($_GET['exp_from']) ? $_GET['exp_from'] : '');
$exp_to        = (isset($_GET['exp_to']) ? $_GET['exp_to'] : '');
$sel_vendor    = (isset($_GET['vendor']) ? $_GET['vendor'] : '');
$sel_batch     = (isset($_GET['batch']) ? $_GET['batch'] : '');
$sel_vbatch    = (isset($_GET['vbatch']) ? $_GET['vbatch'] : '');

// Batch list — filtered to batches ever stored at this staff member's warehouse
$where  = ['1=1'];
$params = [];
if ($staff_wid) { $where[] = 'EXISTS (SELECT 1 FROM StoredIn si2 WHERE si2.VendorID=b.VendorID AND si2.BatchNumber=b.BatchNumber AND si2.WarehouseID=?)'; $params[] = $staff_wid; }
if ($search_vendor) { $where[] = 'v.VendorName LIKE ?'; $params[] = '%'.$search_vendor.'%'; }
if ($search_batch)  { $where[] = 'b.BatchNumber LIKE ?'; $params[] = '%'.$search_batch.'%'; }
if ($exp_from)      { $where[] = 'b.ExpiryDate >= ?'; $params[] = $exp_from; }
if ($exp_to)        { $where[] = 'b.ExpiryDate <= ?'; $params[] = $exp_to; }

$stmt = $pdo->prepare("
    SELECT b.VendorID, b.BatchNumber, v.VendorName, b.ManufactureDate, b.ExpiryDate,
           b.TotalVolume, b.MinStorageTemp, b.MaxStorageTemp
    FROM Batch b JOIN Vendor v ON v.VendorID=b.VendorID
    WHERE ".implode(' AND ',$where)."
    ORDER BY v.VendorName, b.BatchNumber
    LIMIT 300
");
$stmt->execute($params);
$batches = $stmt->fetchAll();

// Lot locations for selected batch
$lot_locations = [];
if ($sel_batch && $sel_vbatch) {
    $stmt = $pdo->prepare("
        SELECT bl.LotSeq, bl.LotVolume,
               CASE
                 WHEN si.EndTime IS NULL AND si.WarehouseID IS NOT NULL
                      THEN CONCAT('In storage — ',si.WarehouseID,' Zone ',si.ZoneCode)
                 WHEN ce_veh.FromVehicleID IS NOT NULL
                      THEN CONCAT('In transit on ',ce_veh.FromVehicleID)
                 ELSE 'Delivered / unknown'
               END AS current_location
        FROM BatchLot bl
        LEFT JOIN StoredIn si ON si.VendorID=bl.VendorID AND si.BatchNumber=bl.BatchNumber
             AND si.LotSeq=bl.LotSeq AND si.EndTime IS NULL
        LEFT JOIN (
            SELECT VendorID, BatchNumber, LotSeq, FromVehicleID
            FROM LotCustodyEvent
            WHERE FromLocation='Vehicle'
            ORDER BY EventTime DESC
            LIMIT 1
        ) ce_veh ON ce_veh.VendorID=bl.VendorID AND ce_veh.BatchNumber=bl.BatchNumber AND ce_veh.LotSeq=bl.LotSeq
        WHERE bl.VendorID=? AND bl.BatchNumber=?
        ORDER BY bl.LotSeq
    ");
    $stmt->execute(array($sel_vbatch, $sel_batch));
    $lot_locations = $stmt->fetchAll();
}

// Vendor detail
$vendor_detail = null;
$vendor_batches = [];
if ($sel_vendor) {
    $stmt = $pdo->prepare("SELECT * FROM Vendor WHERE VendorID=?");
    $stmt->execute(array($sel_vendor));
    $vendor_detail = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT b.*, COUNT(bl.LotSeq) AS lot_count
        FROM Batch b LEFT JOIN BatchLot bl ON bl.VendorID=b.VendorID AND bl.BatchNumber=b.BatchNumber
        WHERE b.VendorID=?
        GROUP BY b.BatchNumber ORDER BY b.ExpiryDate DESC
    ");
    $stmt->execute(array($sel_vendor));
    $vendor_batches = $stmt->fetchAll();
}

$page_title = 'Batch & Vendor Lookup';
include '../includes/header.php';
?>
<div class="page-body">

  <?php if ($vendor_detail): ?>
  <!-- Vendor detail panel -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title"><?php echo htmlspecialchars($vendor_detail['VendorName']); ?></h2>
      <a href="?<?php echo http_build_query(array_diff_key($_GET, array('vendor'=>''))); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
    <div class="section-body">
      <div class="detail-grid">
        <div>
          <div class="detail-row"><span class="detail-key">Vendor ID</span><span class="detail-val"><?php echo htmlspecialchars($vendor_detail['VendorID']); ?></span></div>
          <div class="detail-row"><span class="detail-key">FDA registration</span><span class="detail-val"><?php echo htmlspecialchars($vendor_detail['FDARegistration']); ?></span></div>
          <div class="detail-row"><span class="detail-key">Status</span><span class="detail-val"><?php echo status_badge($vendor_detail['Status']); ?></span></div>
        </div>
        <div>
          <div class="detail-row"><span class="detail-key">Email</span><span class="detail-val"><?php echo htmlspecialchars($vendor_detail['Email']); ?></span></div>
          <div class="detail-row"><span class="detail-key">Phone</span><span class="detail-val"><?php echo htmlspecialchars($vendor_detail['Phone']); ?></span></div>
          <div class="detail-row"><span class="detail-key">Address</span><span class="detail-val"><?php echo htmlspecialchars($vendor_detail['StreetAddress'].', '.$vendor_detail['City'].', '.$vendor_detail['State']); ?></span></div>
        </div>
      </div>
      <div style="margin-top:20px">
        <div style="font-weight:600;font-size:13px;margin-bottom:10px">All batches from this vendor</div>
        <div class="table-scroll">
          <table class="data-table">
            <thead><tr><th>Batch</th><th>Manufactured</th><th>Expires</th><th>Total volume</th><th>Lots</th><th>Temp range</th></tr></thead>
            <tbody>
              <?php foreach ($vendor_batches as $b): ?>
              <tr>
                <td class="mono"><?php echo htmlspecialchars($b['BatchNumber']); ?></td>
                <td><?php echo fmt_date($b['ManufactureDate']); ?></td>
                <td><?php echo fmt_date($b['ExpiryDate']); ?></td>
                <td><?php echo $b['TotalVolume']; ?></td>
                <td><?php echo $b['lot_count']; ?></td>
                <td><?php echo $b['MinStorageTemp']; ?>–<?php echo $b['MaxStorageTemp']; ?>°C</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($lot_locations)): ?>
  <!-- Lot location panel -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">Lot locations — Batch <?php echo htmlspecialchars($sel_batch); ?></h2>
      <a href="?<?php echo http_build_query(array_diff_key($_GET, array('batch'=>'','vbatch'=>''))); ?>" class="btn btn-secondary btn-sm">Close</a>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Lot seq</th><th>Volume</th><th>Current location</th></tr></thead>
        <tbody>
          <?php foreach ($lot_locations as $ll): ?>
          <tr>
            <td><?php echo $ll['LotSeq']; ?></td>
            <td><?php echo $ll['LotVolume']; ?></td>
            <td><?php echo htmlspecialchars($ll['current_location']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Search + batch table -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Batch &amp; vendor search</h2></div>
    <div style="padding:14px 20px;border-bottom:1.5px solid #f1f5f9">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Vendor name</label>
          <input type="text" name="vendor_search" value="<?php echo htmlspecialchars($search_vendor); ?>" placeholder="Search vendor..." style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;width:180px"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Batch number</label>
          <input type="text" name="batch_search" value="<?php echo htmlspecialchars($search_batch); ?>" placeholder="e.g. B00001" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;width:140px"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Expires from</label>
          <input type="date" name="exp_from" value="<?php echo htmlspecialchars($exp_from); ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Expires to</label>
          <input type="date" name="exp_to" value="<?php echo htmlspecialchars($exp_to); ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit"></div>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <a href="batch_lookup.php" class="btn btn-secondary btn-sm">Clear</a>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Vendor</th><th>Vendor ID</th><th>Batch</th><th>Manufactured</th><th>Expires</th><th>Total volume</th><th>Temp range</th></tr></thead>
        <tbody>
          <?php if (empty($batches)): ?>
          <tr><td colspan="7" class="empty-row">No batches found.</td></tr>
          <?php else: foreach ($batches as $b): ?>
          <tr>
            <td>
              <a href="?<?php echo http_build_query(array_merge($_GET, array('vendor'=>$b['VendorID']))); ?>"
                 style="color:#4f46e5;font-weight:500"><?php echo htmlspecialchars($b['VendorName']); ?></a>
            </td>
            <td class="mono"><?php echo htmlspecialchars($b['VendorID']); ?></td>
            <td>
              <a href="?<?php echo http_build_query(array_merge($_GET, array('batch'=>$b['BatchNumber'],'vbatch'=>$b['VendorID']))); ?>"
                 class="mono" style="color:#4f46e5"><?php echo htmlspecialchars($b['BatchNumber']); ?></a>
            </td>
            <td><?php echo fmt_date($b['ManufactureDate']); ?></td>
            <td><?php echo fmt_date($b['ExpiryDate']); ?></td>
            <td><?php echo $b['TotalVolume']; ?></td>
            <td><?php echo $b['MinStorageTemp']; ?>–<?php echo $b['MaxStorageTemp']; ?>°C</td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>
