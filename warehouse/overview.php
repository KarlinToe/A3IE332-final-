<?php
include '../includes/auth_check.php';
include '../includes/constants.php';
include '../includes/db_compat.php';
include '../includes/get_staff_warehouse.php';

// All KPIs filtered to this staff member's warehouse
if ($staff_wid) {
    $wf = $pdo->prepare("SELECT COUNT(*) FROM StoredIn WHERE EndTime IS NULL AND WarehouseID=?");
    $wf->execute(array($staff_wid)); $lots_in_storage = (int)$wf->fetchColumn();

    $wf2 = $pdo->prepare("SELECT COUNT(DISTINCT ZoneCode) FROM ZoneTempBreach WHERE ResolutionStatus IN ('Open','Under Review') AND WarehouseID=?");
    $wf2->execute(array($staff_wid)); $zones_with_alerts = (int)$wf2->fetchColumn();

    $wf3 = $pdo->prepare("SELECT COUNT(*) FROM Shipment WHERE DATE(DepartureTime)=? AND Status IN ('scheduled','in transit') AND OriginWarehouseID=?");
    $wf3->execute(array(app_today(), $staff_wid)); $departing_today = (int)$wf3->fetchColumn();

    $wf4 = $pdo->prepare("SELECT COUNT(*) FROM Shipment WHERE DATE(ArrivalTime)=? AND Status='delivered' AND DestinationWarehouseID=?");
    $wf4->execute(array(app_today(), $staff_wid)); $arriving_today = (int)$wf4->fetchColumn();

    $wf5 = $pdo->prepare("
        SELECT COUNT(*) FROM StoredIn si
        JOIN Batch b ON b.VendorID=si.VendorID AND b.BatchNumber=si.BatchNumber
        WHERE si.EndTime IS NULL AND si.WarehouseID=?
          AND b.ExpiryDate BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)
    ");
    $wf5->execute(array($staff_wid, app_today(), app_today())); $expiring_30 = (int)$wf5->fetchColumn();

    $wf6 = $pdo->prepare("
        SELECT w.WarehouseID, w.WarehouseName, w.Type, w.Status, w.City, w.State,
               (SELECT COUNT(*) FROM StoredIn si WHERE si.WarehouseID=w.WarehouseID AND si.EndTime IS NULL) AS lots_stored,
               (SELECT COUNT(DISTINCT ZoneCode) FROM ZoneTempBreach ztb WHERE ztb.WarehouseID=w.WarehouseID AND ztb.ResolutionStatus IN ('Open','Under Review')) AS open_alerts,
               (SELECT COUNT(*) FROM Shipment s WHERE s.OriginWarehouseID=w.WarehouseID AND DATE(s.DepartureTime)=? AND s.Status IN ('scheduled','in transit')) AS departing_today
        FROM Warehouse w WHERE w.WarehouseID=?
    ");
    $wf6->execute(array(app_today(), $staff_wid));
    $warehouses = $wf6->fetchAll();
} else {
    $lots_in_storage = $zones_with_alerts = $departing_today = $arriving_today = $expiring_30 = 0;
    $warehouses = [];
}

$page_title = 'Warehouse Overview — ' . $staff_wname;
include '../includes/header.php';
?>
<div class="page-body">

  <div class="kpi-row-5">
    <div class="kpi-card">
      <span class="kpi-icon">📦</span>
      <div><div class="kpi-value"><?php echo number_format($lots_in_storage); ?></div><div class="kpi-label">Lots in storage</div></div>
    </div>
    <div class="kpi-card <?php echo $zones_with_alerts>0?'kpi-danger':''; ?>">
      <span class="kpi-icon">🌡️</span>
      <div><div class="kpi-value"><?php echo $zones_with_alerts; ?></div><div class="kpi-label">Zones with open alerts</div></div>
    </div>
    <div class="kpi-card">
      <span class="kpi-icon">🚛</span>
      <div><div class="kpi-value"><?php echo $departing_today; ?></div><div class="kpi-label">Departing today</div></div>
    </div>
    <div class="kpi-card">
      <span class="kpi-icon">📥</span>
      <div><div class="kpi-value"><?php echo $arriving_today; ?></div><div class="kpi-label">Arriving today</div></div>
    </div>
    <div class="kpi-card <?php echo $expiring_30>0?'kpi-warn':''; ?>">
      <span class="kpi-icon">⏳</span>
      <div><div class="kpi-value"><?php echo $expiring_30; ?></div><div class="kpi-label">Expiring ≤ 30 days</div></div>
    </div>
  </div>

  <div class="section-card">
    <div class="section-header"><h2 class="section-title">All warehouses</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Status</th><th>Lots stored</th><th>Open alerts</th><th>Departing today</th><th>Zone utilization</th></tr></thead>
        <tbody>
          <?php foreach ($warehouses as $wh): ?>
          <tr class="clickable-row <?php echo $wh['open_alerts']>0?'alert-row':''; ?>"
              onclick="window.location='/~g1154085/warehouse/detail.php?wh=<?php echo urlencode($wh['WarehouseID']); ?>'">
            <td class="mono"><?php echo htmlspecialchars($wh['WarehouseID']); ?></td>
            <td><?php echo htmlspecialchars($wh['WarehouseName']); ?></td>
            <td><?php echo status_badge($wh['Type']); ?></td>
            <td><?php echo status_badge($wh['Status']); ?></td>
            <td><?php echo $wh['lots_stored']; ?></td>
            <td><?php echo $wh['open_alerts']>0 ? '<span style="color:#dc2626;font-weight:700">'.$wh['open_alerts'].'</span>' : '0'; ?></td>
            <td><?php echo $wh['departing_today']; ?></td>
            <td>
              <canvas id="donut-<?php echo htmlspecialchars($wh['WarehouseID']); ?>"
                      width="50" height="50"
                      data-whid="<?php echo htmlspecialchars($wh['WarehouseID']); ?>"></canvas>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var canvases = document.querySelectorAll('canvas[data-whid]');
    if (!canvases.length) return;
    var ids = Array.from(canvases).map(function(c){ return c.dataset.whid; });
    fetch('/~g1154085/warehouse/ajax/get_utilization.php?ids='+ids.map(encodeURIComponent).join(','))
        .then(function(r){ return r.json(); })
        .then(function(data){
            canvases.forEach(function(canvas){
                var whid = canvas.dataset.whid;
                var d = data[whid];
                if (!d) return;
                var used  = d.used  || 0;
                var total = d.total || 1;
                var pct   = Math.round(100*used/total);
                var color = pct>90?'#ef4444':pct>70?'#f59e0b':'#4f46e5';
                new Chart(canvas, { type:'doughnut',
                    data:{ datasets:[{ data:[used, total-used],
                                       backgroundColor:[color,'#f1f5f9'],
                                       borderWidth:0 }]},
                    options:{ responsive:false, cutout:'70%',
                              plugins:{ legend:{display:false}, tooltip:{enabled:false} }}});
            });
        }).catch(function(){});
});
</script>

<?php include '../includes/footer.php'; ?>
