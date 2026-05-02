<?php
// ── Snapshot date as specified by professor: March 16 2026 23:59:59 EST ──
define('APP_TODAY', '2026-03-16');
define('APP_NOW',   '2026-03-16 23:59:59');

function app_today() {
    return APP_TODAY;
}
function app_now() {
    return APP_NOW;
}

function status_badge($status) {
    $map = array(
        'delivered'         => array('#dcfce7','#166534'),
        'in transit'        => array('#dbeafe','#1e40af'),
        'delayed'           => array('#fef9c3','#854d0e'),
        'scheduled'         => array('#f1f5f9','#475569'),
        'active'            => array('#dcfce7','#166534'),
        'under maintenance' => array('#fef9c3','#854d0e'),
        'closed'            => array('#fee2e2','#991b1b'),
        'available'         => array('#dcfce7','#166534'),
        'in maintenance'    => array('#fef9c3','#854d0e'),
        'Open'              => array('#fee2e2','#991b1b'),
        'Under Review'      => array('#fef9c3','#854d0e'),
        'Resolved'          => array('#dcfce7','#166534'),
        'Seal Intact'       => array('#dcfce7','#166534'),
        'Packaging Damaged' => array('#fee2e2','#991b1b'),
        'refrigerated'      => array('#dbeafe','#1e40af'),
        'freezer'           => array('#ede9fe','#5b21b6'),
        'ambient'           => array('#f1f5f9','#475569'),
        'faulty'            => array('#fee2e2','#991b1b'),
        'inactive'          => array('#f1f5f9','#475569'),
        'Valid'             => array('#dcfce7','#166534'),
        'Missing'           => array('#fef9c3','#854d0e'),
        'Suspect'           => array('#fee2e2','#991b1b'),
    );
    $s = isset($map[$status]) ? $map[$status] : array('#f1f5f9','#475569');
    return "<span class='badge' style='background:" . $s[0] . ";color:" . $s[1] . "'>"
         . htmlspecialchars($status) . "</span>";
}

function fmt_sid($sid) {
    return 'SHP-' . ltrim(substr($sid, 3), '0');
}

function fmt_dt($dt) {
    if (!$dt) return '&mdash;';
    return date('M j, Y H:i', strtotime($dt));
}

function fmt_date($d) {
    if (!$d) return '&mdash;';
    return date('M j, Y', strtotime($d));
}
