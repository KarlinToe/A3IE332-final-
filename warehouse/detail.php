<?php
include '../includes/auth_check.php';
include '../includes/constants.php';
include '../includes/db_compat.php';

$whid = isset($_GET['wh']) ? $_GET['wh'] : '';
if (!preg_match('/^WH-\d{3}$/', $whid)) {
    header('Location: /~g1154085/warehouse/overview.php'); exit;
}

// Warehouse info
$stmt = $pdo->prepare("SELECT * FROM Warehouse WHERE WarehouseID=?");
$stmt->execute(array($whid));
$wh = $stmt->fetch();
if (!$wh) { header('Location: /~g1154085/warehouse/overview.php'); exit; }

// Handle excursion status update
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_excursion'])) {
    $zone    = (isset($_POST['zone_code']) ? $_POST['zone_code'] : '');
    $start   = (isset($_POST['start_time']) ? $_POST['start_time'] : '');
    $newstat = (isset($_POST['resolution']) ? $_POST['resolution'] : '');
    if (in_array($newstat,['Open','Under Review','Resolved']) && $zone && $start) {
        $upd = $pdo->prepare("UPDATE ZoneTempBreach SET ResolutionStatus=? WHERE WarehouseID=? AND ZoneCode=? AND StartTime=?");
        $upd->execute(array($newstat, $whid, $zone, $start));
        $msg = 'success';
    }
}

// Zones with utilization (W-KPI-1)
$stmt = $pdo->prepare("
    SELECT sz.ZoneCode, sz.Classification, sz.MinTemp, sz.MaxTemp, sz.CapacityVolume,
           COALESCE(SUM(bl.LotVolume),0) AS used_volume,
           ROUND(100.0*COALESCE(SUM(bl.LotVolume),0)/sz.CapacityVolume,1) AS util_pct,
           (SELECT COUNT(*) FROM ZoneTempBreach ztb
            WHERE ztb.WarehouseID=sz.WarehouseID AND ztb.ZoneCode=sz.ZoneCode
              AND ztb.ResolutionStatus IN ('Open','Under Review')) AS open_alerts
    FROM StorageZone sz
    LEFT JOIN StoredIn si ON si.WarehouseID=sz.WarehouseID AND si.ZoneCode=sz.ZoneCode AND si.EndTime IS NULL
    LEFT JOIN BatchLot bl ON bl.VendorID=si.VendorID AND bl.BatchNumber=si.BatchNumber AND bl.LotSeq=si.LotSeq
    WHERE sz.WarehouseID=?
    GROUP BY sz.ZoneCode, sz.Classification, sz.MinTemp, sz.MaxTemp, sz.CapacityVolume
    ORDER BY sz.ZoneCode
");
$stmt->execute(array($whid));
$zones = $stmt->fetchAll();

// Selected zone drill-down
$sel_zone = isset($_GET['zone']) ? $_GET['zone'] : '';

$zone_lots = [];
$zone_readings = [];
$zone_breaches = [];
$zone_info = null;

if ($sel_zone) {
    // Zone info
    foreach ($zones as $z) {
        if ($z['ZoneCode'] === $sel_zone) { $zone_info = $z; break; }
    }

    // Lots in zone
    $stmt = $pdo->prepare("
        SELECT v.VendorName, si.BatchNumber, si.LotSeq, bl.LotVolume,
               b.ExpiryDate, DATEDIFF(b.ExpiryDate, '.app_today().') AS days_until_expiry
        FROM StoredIn si
        JOIN BatchLot bl ON bl.VendorID=si.VendorID AND bl.BatchNumber=si.BatchNumber AND bl.LotSeq=si.LotSeq
        JOIN Batch b     ON b.VendorID=si.VendorID AND b.BatchNumber=si.BatchNumber
        JOIN Vendor v    ON v.VendorID=si.VendorID
        WHERE si.WarehouseID=? AND si.ZoneCode=? AND si.EndTime IS NULL
        ORDER BY b.ExpiryDate ASC
    ");
    $stmt->execute(array($whid, $sel_zone));
    $zone_lots = $stmt->fetchAll();

    // 7-day temp chart
    $stmt = $pdo->prepare("
        SELECT sr.ReadingTime, sr.Temperature,
               sz.MinTemp AS zone_min, sz.MaxTemp AS zone_max
        FROM SensorReading sr
        JOIN StorageZone sz ON sz.WarehouseID=sr.WarehouseID AND sz.ZoneCode=sr.ZoneCode
        WHERE sr.WarehouseID=? AND sr.ZoneCode=?
          AND sr.ReadingTime >= DATE_SUB('.app_now().', INTERVAL 7 DAY)
        ORDER BY sr.ReadingTime ASC
    ");
    $stmt->execute(array($whid, $sel_zone));
    $zone_readings = $stmt->fetchAll();

    // Breach history
    $stmt = $pdo->prepare("
        SELECT * FROM ZoneTempBreach
        WHERE WarehouseID=? AND ZoneCode=?
        ORDER BY StartTime DESC
    ");
    $stmt->execute(array($whid, $sel_zone));
    $zone_breaches = $stmt->fetchAll();
}

$page_title = $wh['WarehouseName'];
include '../includes/header.php';
?>
<div class="page-body">

  <?php if ($msg==='success'): ?>
    <div class="alert alert-success">Excursion status updated.</div>
  <?php endif; ?>

  <!-- Warehouse header -->
  <div class="section-card">
    <div class="section-header">
      <div>
        <h2 class="section-title"><?php echo htmlspecialchars($wh['WarehouseName']); ?></h2>
        <div class="text-muted mt-8"><?php echo htmlspecialchars($wh['StreetAddress'].', '.$wh['City'].', '.$wh['State']); ?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <?php echo status_badge($wh['Type']); ?>
        <?php echo status_badge($wh['Status']); ?>
      </div>
    </div>
  </div>

  <!-- Zone table -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Storage zones</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Zone</th><th>Type</th><th>Temp range</th><th>Capacity</th><th>In use</th><th>Util %</th><th>Alerts</th></tr></thead>
        <tbody>
          <?php foreach ($zones as $z): ?>
          <tr class="clickable-row <?php echo $z['open_alerts']>0?'alert-row':''; ?>"
              onclick="window.location='?wh=<?php echo urlencode($whid); ?>&zone=<?php echo urlencode($z['ZoneCode']); ?>'">
            <td class="mono"><?php echo htmlspecialchars($z['ZoneCode']); ?></td>
            <td><?php echo status_badge($z['Classification']); ?></td>
            <td><?php echo $z['MinTemp']; ?>–<?php echo $z['MaxTemp']; ?>°C</td>
            <td><?php echo number_format($z['CapacityVolume']); ?></td>
            <td><?php echo number_format($z['used_volume']); ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden">
                  <div style="width:<?php echo min(100,$z['util_pct']); ?>%;height:100%;background:<?php echo $z['util_pct']>90?'#ef4444':$z['util_pct']>70?'#f59e0b':'#4f46e5'; ?>;border-radius:3px"></div>
                </div>
                <?php echo $z['util_pct']; ?>%
              </div>
            </td>
            <td><?php echo $z['open_alerts']>0?'<span style="color:#dc2626;font-weight:700">'.$z['open_alerts'].' OPEN</span>':'—'; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($sel_zone && $zone_info): ?>

  <!-- Zone drill-down -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">Zone <?php echo htmlspecialchars($sel_zone); ?> — <?php echo htmlspecialchars(ucwords($zone_info['Classification'])); ?></h2>
      <a href="?wh=<?php echo urlencode($whid); ?>" class="btn btn-secondary btn-sm">Close zone</a>
    </div>

    <!-- Lots in zone -->
    <div style="padding:0 0 0 0">
      <div style="padding:14px 20px;border-bottom:1.5px solid #f1f5f9;font-weight:600;font-size:13px">Lots currently in storage</div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>Vendor</th><th>Batch</th><th>Lot</th><th>Volume</th><th>Expires</th><th>Days until expiry</th></tr></thead>
          <tbody>
            <?php if (empty($zone_lots)): ?>
            <tr><td colspan="6" class="empty-row">No lots in this zone.</td></tr>
            <?php else: foreach ($zone_lots as $l):
                $cls = $l['days_until_expiry']<=7?'expiry-critical':($l['days_until_expiry']<=30?'expiry-warning':'');
            ?>
            <tr class="<?php echo $cls; ?>">
              <td><?php echo htmlspecialchars($l['VendorName']); ?></td>
              <td class="mono"><?php echo htmlspecialchars($l['BatchNumber']); ?></td>
              <td><?php echo $l['LotSeq']; ?></td>
              <td><?php echo $l['LotVolume']; ?></td>
              <td><?php echo fmt_date($l['ExpiryDate']); ?></td>
              <td><?php echo $l['days_until_expiry']<=0?'<span class="text-danger font-bold">EXPIRED</span>':$l['days_until_expiry'].' days'; ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 7-day temp chart -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Zone <?php echo htmlspecialchars($sel_zone); ?> — 7-day temperature</h2></div>
    <div class="chart-wrap">
      <?php if (empty($zone_readings)): ?>
        <p class="text-muted text-center" style="padding:20px">No sensor readings in the past 7 days.</p>
      <?php else: ?>
        <canvas id="zone-temp-chart" height="80"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- Breach history -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Breach history — Zone <?php echo htmlspecialchars($sel_zone); ?></h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Start</th><th>End</th><th>Max deviation</th><th>Status</th><th>Update</th></tr></thead>
        <tbody>
          <?php if (empty($zone_breaches)): ?>
          <tr><td colspan="5" class="empty-row">No breaches recorded.</td></tr>
          <?php else: foreach ($zone_breaches as $br): ?>
          <tr <?php echo $br['ResolutionStatus']==='Open'?'style="background:#fff1f2"':''; ?>>
            <td><?php echo fmt_dt($br['StartTime']); ?></td>
            <td><?php echo fmt_dt($br['EndTime']); ?></td>
            <td><?php echo $br['MaxDeviation']; ?>°C</td>
            <td><?php echo status_badge($br['ResolutionStatus']); ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:6px;align-items:center">
                <input type="hidden" name="zone_code"  value="<?php echo htmlspecialchars($sel_zone); ?>">
                <input type="hidden" name="start_time" value="<?php echo htmlspecialchars($br['StartTime']); ?>">
                <select name="resolution" style="padding:3px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:inherit;">
                  <?php foreach (['Open','Under Review','Resolved'] as $opt): ?>
                  <option <?php echo $br['ResolutionStatus']===$opt?'selected':''; ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
                <button name="update_excursion" value="1" class="btn btn-primary btn-sm">Save</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($zone_readings)):
      $zmin = (float)$zone_readings[0]['zone_min'];
      $zmax = (float)$zone_readings[0]['zone_max'];
  ?>
  <script>
  (function(){
      var readings = <?php echo json_encode(array_map(function($r){ return ['t'=>$r['ReadingTime'],'temp'=>(float)$r['Temperature']]; }, $zone_readings)); ?>;
      var zmin = <?php echo $zmin; ?>, zmax = <?php echo $zmax; ?>;
      var labels = readings.map(function(r){ return r.t.substring(5,16); });
      var temps  = readings.map(function(r){ return r.temp; });
      var ptColors = temps.map(function(t){ return (t<zmin||t>zmax)?'#ef4444':'#4f46e5'; });
      var ctx = document.getElementById('zone-temp-chart').getContext('2d');
      new Chart(ctx, { type:'line',
          data:{ labels:labels, datasets:[
              { label:'Temperature (°C)', data:temps, borderColor:'#4f46e5', borderWidth:1.5,
                pointBackgroundColor:ptColors, pointRadius:2, tension:0.2, fill:false,
                segment:{ borderColor:function(c){ var i=c.p0DataIndex; return (temps[i]<zmin||temps[i]>zmax)?'#ef4444':'#4f46e5'; }}},
              { label:'Min '+zmin+'°C', data:temps.map(function(){return zmin;}), borderColor:'#10b981', borderDash:[6,3], pointRadius:0, borderWidth:1.5, fill:false },
              { label:'Max '+zmax+'°C', data:temps.map(function(){return zmax;}), borderColor:'#f97316', borderDash:[6,3], pointRadius:0, borderWidth:1.5, fill:false }
          ]},
          options:{ responsive:true, plugins:{legend:{position:'top'}},
                    scales:{ x:{ticks:{maxTicksLimit:12,maxRotation:45}},
                             y:{title:{display:true,text:'Temperature (°C)'}}}}});
  })();
  </script>
  <?php endif; ?>

  <?php endif; // end zone drill-down ?>

</div>
<?php include '../includes/footer.php'; ?>
