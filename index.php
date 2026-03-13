
<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
// dashboard_tlalpan.php
// Dashboard (Dark/Neon) — Gym 222 (Recepción + Cocina) + Subscriptions (WP) + Stock (lista fija)
// Requiere: db.php que exponga $pdo (PDO conectado)

require_once __DIR__ . '/db.php';

// ===============================
// Config
// ===============================
$tz  = new DateTimeZone('America/Mexico_City');
$now = new DateTime('now', $tz);

$billerRecepcion = 'Gym 222';
$billerCocina    = 'Gym 222 Cocina';

// Fechas base
$today      = new DateTime($now->format('Y-m-d 00:00:00'), $tz);
$tomorrow   = (clone $today)->modify('+1 day');
$yesterday  = (clone $today)->modify('-1 day');

$year  = (int)$now->format('Y');
$month = (int)$now->format('n');

// Regla especial:
// - Si el mes actual ES FEBRERO: contar desde Feb 10
// - Si NO: contar mes completo (día 1)
if ($month === 2) {
  $startMonth = new DateTime("{$year}-02-10 00:00:00", $tz);
} else {
  $startMonth = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
}

$startPreviousMonth = (clone $now)->modify('first day of last month')->setTime(0, 0, 0);
$endPreviousMonth   = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
$previousMonth      = (int)$startPreviousMonth->format('n');

// Regla consistente para comparativo:
// - Si el mes anterior fue FEBRERO, se cuenta desde el día 10
// - En otros meses, desde el día 1
if ($previousMonth === 2) {
  $startPreviousMonth = new DateTime($startPreviousMonth->format('Y-02-10 00:00:00'), $tz);
}

// fin dinámico (incluye hoy)
$endLive = clone $tomorrow;

// ===============================
// Helpers
// ===============================
function pesos($n): string { return '$' . number_format((float)$n, 0, '.', ',') . ' MXN'; }
function num($n, $dec=0): string { return number_format((float)$n, $dec, '.', ','); }

function fetchAll(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetchOne(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? $row : [];
}

// ===============================
// WooCommerce Subscriptions (desde WordPress vía REST)
// ===============================
function gym_fetch_wp_sub_counts(string $baseUrl, int $soon_days = 30): array {
  $url = rtrim($baseUrl, '/') . '/wp-json/gym/v1/sub-counts?soon_days=' . max(0, $soon_days) . '&t=' . time();

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'Cache-Control: no-cache',
    ],
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $err || $code < 200 || $code >= 300) {
    return ['ok'=>false, 'error'=>$err ?: ("HTTP ".$code), 'raw'=>$body];
  }

  $json = json_decode($body, true);
  if (!is_array($json) || empty($json['ok'])) {
    return ['ok'=>false, 'error'=>'Respuesta inválida', 'raw'=>$body];
  }

  $totals = $json['totals'] ?? [];
  $break  = $json['breakdown'] ?? [];

  $activos = (int)($totals['activos_vigentes'] ?? ($totals['activos_raw'] ?? 0));
$on_hold = (int)($break['on_hold'] ?? 0);
$expired = (int)($break['expired'] ?? 0);
$cancel  = (int)($break['cancelled'] ?? 0);
$pending = (int)($break['pending_cancel'] ?? 0);
$venc_en_activo = (int)($totals['vencidos_en_activo'] ?? 0);

$no_activos = $on_hold + $expired + $cancel + $pending + $venc_en_activo;


  return [
    'ok' => true,
    'activos' => $activos,
    'no_activos' => $no_activos,
  ];
}

// Traer datos desde tu WordPress
$wpSubs = gym_fetch_wp_sub_counts('https://222gym.com.mx', 30);
$subsActivos   = ($wpSubs['ok'] ?? false) ? (int)$wpSubs['activos'] : 0;
$subsNoActivos = ($wpSubs['ok'] ?? false) ? (int)$wpSubs['no_activos'] : 0;

// ===============================
// Totales del mes actual (month-to-date)
// ===============================
$paramsMonth = [
  ':start' => $startMonth->format('Y-m-d H:i:s'),
  ':end'   => $endLive->format('Y-m-d H:i:s'),
  ':b0'    => $billerRecepcion,
  ':b1'    => $billerCocina,
];

$totalsMonth = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_all,
    COALESCE(SUM(total_discount),0) AS total_discount_all,
    COALESCE(SUM(CASE WHEN biller = :b0 THEN grand_total ELSE 0 END),0) AS total_recepcion,
    COALESCE(SUM(CASE WHEN biller = :b1 THEN grand_total ELSE 0 END),0) AS total_cocina,
    COUNT(*) AS tx_all
  FROM sma_sales
  WHERE biller IN (:b0,:b1)
    AND date >= :start AND date < :end
", $paramsMonth);

$totalMesAll       = (float)($totalsMonth['total_all'] ?? 0);
$descuentosMesAll  = (float)($totalsMonth['total_discount_all'] ?? 0);
$totalMesRecepcion = (float)($totalsMonth['total_recepcion'] ?? 0);
$totalMesCocina    = (float)($totalsMonth['total_cocina'] ?? 0);
$txMesAll          = (int)($totalsMonth['tx_all'] ?? 0);
$ticketPromMes     = ($txMesAll > 0) ? ($totalMesAll / $txMesAll) : 0;

$gastosMes = fetchOne($pdo, "
  SELECT COALESCE(SUM(amount),0) AS total_expenses
  FROM sma_expenses
  WHERE date >= :start AND date < :end
", [
  ':start' => $startMonth->format('Y-m-d H:i:s'),
  ':end'   => $endLive->format('Y-m-d H:i:s'),
]);
$gastosMesAll = (float)($gastosMes['total_expenses'] ?? 0);

$paramsPreviousMonth = [
  ':start' => $startPreviousMonth->format('Y-m-d H:i:s'),
  ':end'   => $endPreviousMonth->format('Y-m-d H:i:s'),
  ':b0'    => $billerRecepcion,
  ':b1'    => $billerCocina,
];

$totalsPreviousMonth = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_all,
    COALESCE(SUM(total_discount),0) AS total_discount_all,
    COALESCE(SUM(CASE WHEN biller = :b0 THEN grand_total ELSE 0 END),0) AS total_recepcion,
    COALESCE(SUM(CASE WHEN biller = :b1 THEN grand_total ELSE 0 END),0) AS total_cocina,
    COUNT(*) AS tx_all
  FROM sma_sales
  WHERE biller IN (:b0,:b1)
    AND date >= :start AND date < :end
", $paramsPreviousMonth);

$totalMesAnteriorAll       = (float)($totalsPreviousMonth['total_all'] ?? 0);
$descuentosMesAnteriorAll  = (float)($totalsPreviousMonth['total_discount_all'] ?? 0);
$totalMesAnteriorRecepcion = (float)($totalsPreviousMonth['total_recepcion'] ?? 0);
$totalMesAnteriorCocina    = (float)($totalsPreviousMonth['total_cocina'] ?? 0);
$txMesAnteriorAll          = (int)($totalsPreviousMonth['tx_all'] ?? 0);

$gastosMesAnterior = fetchOne($pdo, "
  SELECT COALESCE(SUM(amount),0) AS total_expenses
  FROM sma_expenses
  WHERE date >= :start AND date < :end
", [
  ':start' => $startPreviousMonth->format('Y-m-d H:i:s'),
  ':end'   => $endPreviousMonth->format('Y-m-d H:i:s'),
]);
$gastosMesAnteriorAll = (float)($gastosMesAnterior['total_expenses'] ?? 0);

// ===============================
// Ventas HOY y AYER (totales del día)
// ===============================
$paramsHoy = [
  ':start' => $today->format('Y-m-d H:i:s'),
  ':end'   => $tomorrow->format('Y-m-d H:i:s'),
  ':b0'    => $billerRecepcion,
  ':b1'    => $billerCocina,
];
$todayTotals = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_all,
    COALESCE(SUM(total_discount),0) AS total_discount_all,
    COALESCE(SUM(CASE WHEN biller = :b0 THEN grand_total ELSE 0 END),0) AS total_recepcion,
    COALESCE(SUM(CASE WHEN biller = :b1 THEN grand_total ELSE 0 END),0) AS total_cocina,
    COUNT(*) AS tx_all
  FROM sma_sales
  WHERE biller IN (:b0,:b1)
    AND date >= :start AND date < :end
", $paramsHoy);

$ventasHoyAll       = (float)($todayTotals['total_all'] ?? 0);
$descuentosHoyAll   = (float)($todayTotals['total_discount_all'] ?? 0);
$ventasHoyRecepcion = (float)($todayTotals['total_recepcion'] ?? 0);
$ventasHoyCocina    = (float)($todayTotals['total_cocina'] ?? 0);
$txHoy              = (int)($todayTotals['tx_all'] ?? 0);

$gastosHoy = fetchOne($pdo, "
  SELECT COALESCE(SUM(amount),0) AS total_expenses
  FROM sma_expenses
  WHERE date >= :start AND date < :end
", [
  ':start' => $today->format('Y-m-d H:i:s'),
  ':end'   => $tomorrow->format('Y-m-d H:i:s'),
]);
$gastosHoyAll = (float)($gastosHoy['total_expenses'] ?? 0);

$paramsAyer = [
  ':start' => $yesterday->format('Y-m-d H:i:s'),
  ':end'   => $today->format('Y-m-d H:i:s'),
  ':b0'    => $billerRecepcion,
  ':b1'    => $billerCocina,
];
$yesterdayTotals = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_all,
    COALESCE(SUM(total_discount),0) AS total_discount_all,
    COALESCE(SUM(CASE WHEN biller = :b0 THEN grand_total ELSE 0 END),0) AS total_recepcion,
    COALESCE(SUM(CASE WHEN biller = :b1 THEN grand_total ELSE 0 END),0) AS total_cocina,
    COUNT(*) AS tx_all
  FROM sma_sales
  WHERE biller IN (:b0,:b1)
    AND date >= :start AND date < :end
", $paramsAyer);

$ventasAyerAll       = (float)($yesterdayTotals['total_all'] ?? 0);
$descuentosAyerAll   = (float)($yesterdayTotals['total_discount_all'] ?? 0);
$ventasAyerRecepcion = (float)($yesterdayTotals['total_recepcion'] ?? 0);
$ventasAyerCocina    = (float)($yesterdayTotals['total_cocina'] ?? 0);
$txAyer              = (int)($yesterdayTotals['tx_all'] ?? 0);

$gastosAyer = fetchOne($pdo, "
  SELECT COALESCE(SUM(amount),0) AS total_expenses
  FROM sma_expenses
  WHERE date >= :start AND date < :end
", [
  ':start' => $yesterday->format('Y-m-d H:i:s'),
  ':end'   => $today->format('Y-m-d H:i:s'),
]);
$gastosAyerAll = (float)($gastosAyer['total_expenses'] ?? 0);

// ===============================
// Ventas por día (mes actual, inicio según regla)
// ===============================
$salesByDay = fetchAll($pdo, "
  SELECT
    DATE(date) AS day,
    COALESCE(SUM(CASE WHEN biller = :b0 THEN grand_total ELSE 0 END),0) AS recepcion_sales,
    COALESCE(SUM(CASE WHEN biller = :b1 THEN grand_total ELSE 0 END),0) AS cocina_sales,
    COALESCE(SUM(grand_total),0) AS total_sales,
    COALESCE(SUM(total_discount),0) AS total_discounts,
    COUNT(*) AS tx_all
  FROM sma_sales
  WHERE biller IN (:b0,:b1)
    AND date >= :start AND date < :end
  GROUP BY DATE(date)
  ORDER BY DATE(date) ASC
", $paramsMonth);

// Mapas diarios
$mapRecepcion = [];
$mapCocina = [];
$mapTotal  = [];
$mapDiscounts = [];
$mapTx     = [];

foreach ($salesByDay as $r) {
  $d = $r['day'];
  $mapRecepcion[$d] = (float)$r['recepcion_sales'];
  $mapCocina[$d]    = (float)$r['cocina_sales'];
  $mapTotal[$d]     = (float)$r['total_sales'];
  $mapDiscounts[$d] = (float)$r['total_discounts'];
  $mapTx[$d]        = (int)$r['tx_all'];
}

$expensesByDay = fetchAll($pdo, "
  SELECT
    DATE(date) AS day,
    COALESCE(SUM(amount),0) AS total_expenses
  FROM sma_expenses
  WHERE date >= :start AND date < :end
  GROUP BY DATE(date)
  ORDER BY DATE(date) ASC
", [
  ':start' => $startMonth->format('Y-m-d H:i:s'),
  ':end'   => $endLive->format('Y-m-d H:i:s'),
]);

$mapExpenses = [];
foreach ($expensesByDay as $r) {
  $mapExpenses[$r['day']] = (float)$r['total_expenses'];
}

// Series (gráfica en orden ascendente)
$labelsDays   = [];
$seriesTotal  = [];
$seriesRecep  = [];
$seriesCocina = [];

$cursor = clone $startMonth;
while ($cursor <= $today) {
  $dayStr = $cursor->format('Y-m-d');
  $labelsDays[]   = $dayStr;
  $seriesTotal[]  = $mapTotal[$dayStr] ?? 0.0;
  $seriesRecep[]  = $mapRecepcion[$dayStr] ?? 0.0;
  $seriesCocina[] = $mapCocina[$dayStr] ?? 0.0;
  $cursor->modify('+1 day');
}

$periodLabelStart = $startMonth->format('Y-m-d');
$periodLabelEnd   = $today->format('Y-m-d');

// ===============================
// STOCK: lista fija (SIN "Ropa 200")
// ===============================
$stockNames = [
  'Agua 1L',
  'Agua 1.5 L',
  'C4',
  'Volt',
  'Halls',
  'Getorade',
  'Electrolit',
  'Montser Energy Negro',
  'Galleta Lenny & Larrys',
  'Hangryboy',
  'Protein Crisp',
  'Bucal',
  'Ghost',
  'Deli Barras',
  'Ryse',
  'Agua Evian',
  'Agua Fiji',
];

// WHERE (LOWER(p.name) LIKE :n0 OR ... )
$whereParts = [];
$paramsStock = [];
foreach ($stockNames as $i => $nm) {
  $k = ":n{$i}";
  $whereParts[] = "LOWER(p.name) LIKE {$k}";
  $paramsStock[$k] = '%' . mb_strtolower(trim($nm), 'UTF-8') . '%';
}

$stockRows = [];
if (count($whereParts) > 0) {
  $stockRows = fetchAll($pdo, "
    SELECT
      p.name,
      COALESCE(p.quantity,0) AS stock
    FROM sma_products p
    WHERE (" . implode(" OR ", $whereParts) . ")
    ORDER BY p.name ASC
  ", $paramsStock);
}

// Orden sugerido (igual al listado)
$rank = [];
foreach ($stockNames as $i => $nm) $rank[mb_strtolower($nm,'UTF-8')] = $i;

usort($stockRows, function($a,$b) use ($rank){
  $an = mb_strtolower($a['name'] ?? '', 'UTF-8');
  $bn = mb_strtolower($b['name'] ?? '', 'UTF-8');
  $ai = 9999; $bi = 9999;
  foreach ($rank as $needle => $pos) {
    if ($ai === 9999 && strpos($an, $needle) !== false) $ai = $pos;
    if ($bi === 9999 && strpos($bn, $needle) !== false) $bi = $pos;
  }
  if ($ai === $bi) return strcmp($an,$bn);
  return $ai <=> $bi;
});

?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="refresh" content="60">
  <title>Dashboard Ventas | Gym 222</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    :root{
      --bg:#07070a;
      --line:rgba(255,255,255,.10);
      --text:#eef2ff;
      --muted:rgba(238,242,255,.65);

      --neonCyan:#22d3ee;
      --neonViolet:#a78bfa;
      --neonGreen:#34d399;
      --neonRed:#fb7185;

      --shadow: 0 18px 40px rgba(0,0,0,.45);
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
      background:
        radial-gradient(1000px 600px at 15% -10%, rgba(34,211,238,.10), transparent 60%),
        radial-gradient(900px 600px at 85% -20%, rgba(167,139,250,.10), transparent 65%),
        radial-gradient(900px 700px at 50% 115%, rgba(52,211,153,.08), transparent 60%),
        var(--bg);
      color:var(--text);
    }

    header{
      max-width:1220px;
      margin:0 auto;
      padding:18px 14px 8px;
    }

    .titleRow{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:flex-end;
      justify-content:space-between;
    }

    h1{
      margin:0;
      font-size:16px;
      font-weight:900;
      letter-spacing:.3px;
    }

    .sub{
      color:var(--muted);
      font-size:12.5px;
      margin-top:6px;
      line-height:1.35;
    }

    .tag{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border:1px solid var(--line);
      border-radius:999px;
      background: rgba(255,255,255,.03);
      backdrop-filter: blur(8px);
      font-size:12px;
      color:var(--muted);
      white-space:nowrap;
    }
    .tag strong{ color:var(--text); font-weight:800; }

    .grid{
      max-width:1220px;
      margin:0 auto;
      padding:10px 14px 22px;
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap:10px;
    }

    .card{
      background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:16px;
      padding:12px;
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }

    .card::before{
      content:"";
      position:absolute;
      inset:-2px;
      background:
        radial-gradient(500px 120px at 0% 0%, rgba(34,211,238,.12), transparent 60%),
        radial-gradient(500px 120px at 100% 0%, rgba(167,139,250,.10), transparent 55%);
      pointer-events:none;
      opacity:.9;
    }
    .card > *{ position:relative; z-index:1; }

    .card h2{
      margin:0 0 10px;
      font-size:13px;
      color:rgba(238,242,255,.92);
      letter-spacing:.25px;
    }

    .span-12{ grid-column: span 12; }

    .kpis{
      display:grid;
      gap:10px;
    }
    .kpis2{ grid-template-columns: repeat(2, 1fr); }
    .kpis3{ grid-template-columns: repeat(3, 1fr); }

    /* Caja llamativa */
    .kpiHot{
      border-radius:16px;
      padding:14px;
      border:1px solid rgba(255,255,255,.14);
      background:
        radial-gradient(700px 260px at 0% 0%, rgba(34,211,238,.22), rgba(0,0,0,.20) 55%),
        radial-gradient(700px 260px at 100% 0%, rgba(52,211,153,.18), rgba(0,0,0,.12) 60%),
        rgba(0,0,0,.18);
      box-shadow: 0 0 0 1px rgba(34,211,238,.10), 0 22px 50px rgba(0,0,0,.55);
      position:relative;
      overflow:hidden;
    }
    .kpiHot::after{
      content:"";
      position:absolute;
      inset:-2px;
      background: linear-gradient(90deg, rgba(34,211,238,.35), rgba(167,139,250,.22), rgba(52,211,153,.35));
      opacity:.18;
      filter: blur(14px);
      pointer-events:none;
    }
    .kpiHot > *{ position:relative; z-index:1; }

    .kpiHot .label{
      font-size:11px;
      color:rgba(238,242,255,.70);
      letter-spacing:.22px;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .kpiHot .value{
      font-size:22px;
      font-weight:1000;
      letter-spacing:.2px;
      margin-top:6px;
    }
    .kpiHot .subline{
      margin-top:6px;
      font-size:12px;
      color:rgba(238,242,255,.78);
      line-height:1.25;
    }
    .kpiHot .metaLine,
    .kpi .metaLine{
      margin-top:6px;
      font-size:11px;
      color:rgba(238,242,255,.60);
      line-height:1.25;
    }

    /* DOTS (verde / rojo) */
    .dot{
      width:10px;
      height:10px;
      border-radius:999px;
      display:inline-block;
      flex:0 0 10px;
    }
    .dot-green{
      background: var(--neonGreen);
      box-shadow: 0 0 12px rgba(52,211,153,.55);
    }
    .dot-red{
      background: var(--neonRed);
      box-shadow: 0 0 12px rgba(251,113,133,.55);
    }

    .kpi{
      border:1px solid var(--line);
      border-radius:14px;
      padding:12px;
      background: rgba(0,0,0,.18);
    }
    .kpi .label{
      color:var(--muted);
      font-size:11.5px;
      margin-bottom:6px;
      letter-spacing:.2px;
    }
    .kpi .value{
      font-size:18px;
      font-weight:900;
      letter-spacing:.2px;
    }
    .kpi .hint{
      margin-top:6px;
      font-size:12px;
      color:rgba(238,242,255,.75);
      line-height:1.25;
    }

    .tableScroll{
      max-height:360px;
      overflow:auto;
      border-radius:14px;
      border:1px solid var(--line);
      background: rgba(0,0,0,.16);
    }

    table{ width:100%; border-collapse:collapse; }
    th, td{
      padding:9px 8px;
      border-bottom:1px solid rgba(255,255,255,.07);
      font-size:12px;
      white-space:nowrap;
    }
    th{
      position:sticky;
      top:0;
      z-index:2;
      background: rgba(15, 17, 28, .92);
      backdrop-filter: blur(8px);
      color:rgba(238,242,255,.88);
      font-weight:900;
      letter-spacing:.2px;
      text-align:left;
    }
    .right{ text-align:right; }
    tr:last-child td{ border-bottom:none; }

    .chartBox{ padding:6px 0 0; }
    canvas{ width:100% !important; height:280px !important; }

    .note{
      font-size:12px;
      color:var(--muted);
      margin-top:10px;
      line-height:1.35;
    }

    @media (max-width: 980px){
      .grid{ padding:10px 12px 22px; gap:10px; }
      .card{ padding:12px; border-radius:14px; }
      .kpis2, .kpis3{ grid-template-columns: 1fr; }
      canvas{ height:240px !important; }
      .tableScroll{ max-height:320px; }
    }
  </style>
</head>

<body>
<header>
  <div class="titleRow">
    <div>
      <h1>Dashboard Ventas — Gym 222</h1>
      <div class="sub">
        <strong>Total del mes</strong> (<?= htmlspecialchars($periodLabelStart) ?> → <?= htmlspecialchars($periodLabelEnd) ?>)
        <br>
        Actualizado: <strong><?= htmlspecialchars($now->format('Y-m-d H:i')) ?></strong> (auto cada 60s)
      </div>
    </div>
    <div class="tag">
      <span style="width:8px;height:8px;border-radius:999px;background:var(--neonCyan);box-shadow:0 0 12px rgba(34,211,238,.45)"></span>
      Billers: <strong><?= htmlspecialchars($billerRecepcion) ?></strong> + <strong><?= htmlspecialchars($billerCocina) ?></strong>
    </div>
  </div>
</header>

<div class="grid">

  <!-- TOP: SUBSCRIPTIONS + HOY/AYER + TOTAL MES -->
  <section class="card span-12">
    <h2>Total del mes (mes actual)</h2>

    <!-- SUSCRIPCIONES (ARRIBA DE HOY/AYER) -->
    <div class="kpis kpis2" style="margin-bottom:10px;">
      <div class="kpiHot">
        <div class="label"><span class="dot dot-green"></span>SUSCRIPTORES ACTIVOS</div>
        <div class="value"><?= num($subsActivos) ?></div>
        <div class="subline">Vigentes (Subscriptions)</div>
      </div>

      <div class="kpiHot">
        <div class="label"><span class="dot dot-red"></span>NO ACTIVOS</div>
        <div class="value"><?= num($subsNoActivos) ?></div>
        <div class="subline">On-hold + Cancelados</div>
      </div>
    </div>

    <?php if (isset($wpSubs) && is_array($wpSubs) && empty($wpSubs['ok'])): ?>
      <div class="note" style="margin-top:-4px;">
        * No se pudo leer Subscriptions desde WordPress (se muestran 0).
      </div>
    <?php endif; ?>

    <!-- VENTAS HOY / AYER -->
    <div class="kpis kpis2" style="margin-bottom:10px;">
      <div class="kpiHot">
        <div class="label">VENTAS HOY</div>
        <div class="value"><?= pesos($ventasHoyAll) ?></div>
        <div class="subline">
          Recepción: <strong><?= pesos($ventasHoyRecepcion) ?></strong> · Cocina: <strong><?= pesos($ventasHoyCocina) ?></strong><br>
          Tx: <strong><?= num($txHoy) ?></strong>
        </div>
        <div class="metaLine">Descuentos: <strong><?= pesos($descuentosHoyAll) ?></strong> · Gastos: <strong><?= pesos($gastosHoyAll) ?></strong></div>
      </div>

      <div class="kpiHot">
        <div class="label">VENTAS AYER</div>
        <div class="value"><?= pesos($ventasAyerAll) ?></div>
        <div class="subline">
          Recepción: <strong><?= pesos($ventasAyerRecepcion) ?></strong> · Cocina: <strong><?= pesos($ventasAyerCocina) ?></strong><br>
          Tx: <strong><?= num($txAyer) ?></strong>
        </div>
        <div class="metaLine">Descuentos: <strong><?= pesos($descuentosAyerAll) ?></strong> · Gastos: <strong><?= pesos($gastosAyerAll) ?></strong></div>
      </div>
    </div>

    <!-- TOTAL MES + MES ANTERIOR -->
    <div class="kpis kpis2">
      <div class="kpi">
        <div class="label">Total mes (Recepción + Cocina)</div>
        <div class="value"><?= pesos($totalMesAll) ?></div>
        <div class="hint">Tx: <strong><?= num($txMesAll) ?></strong> · Ticket prom: <strong><?= pesos($ticketPromMes) ?></strong></div>
        <div class="metaLine">Descuentos: <strong><?= pesos($descuentosMesAll) ?></strong> · Gastos: <strong><?= pesos($gastosMesAll) ?></strong></div>
      </div>
      <div class="kpi">
        <div class="label">Mes anterior (Recepción + Cocina)</div>
        <div class="value"><?= pesos($totalMesAnteriorAll) ?></div>
        <div class="hint">Tx: <strong><?= num($txMesAnteriorAll) ?></strong> · Recepción: <strong><?= pesos($totalMesAnteriorRecepcion) ?></strong> · Cocina: <strong><?= pesos($totalMesAnteriorCocina) ?></strong></div>
        <div class="metaLine">Descuentos: <strong><?= pesos($descuentosMesAnteriorAll) ?></strong> · Gastos: <strong><?= pesos($gastosMesAnteriorAll) ?></strong></div>
      </div>
    </div>

    <div class="chartBox" style="margin-top:10px;">
      <canvas id="salesChart"></canvas>
    </div>

    <div class="note">
      * Si el mes actual es <strong>febrero</strong>, el “Total del mes” cuenta desde <strong>Feb 10</strong>. En los demás meses cuenta desde el <strong>día 1</strong>.
    </div>
  </section>

  <!-- TABLA VENTAS POR DÍA (más reciente primero) -->
  <section class="card span-12">
    <h2>Ventas por día (mes actual) — Más reciente primero</h2>

    <div class="tableScroll">
      <table>
        <thead>
          <tr>
            <th>Día</th>
            <th class="right">Recepción</th>
            <th class="right">Cocina</th>
            <th class="right">Total</th>
            <th class="right">Descuentos</th>
            <th class="right">Gastos</th>
            <th class="right">Tx</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $cursor2 = clone $today;
            while ($cursor2 >= $startMonth):
              $d = $cursor2->format('Y-m-d');
              $r = $mapRecepcion[$d] ?? 0.0;
              $c = $mapCocina[$d] ?? 0.0;
              $t = $mapTotal[$d] ?? 0.0;
              $ds = $mapDiscounts[$d] ?? 0.0;
              $ge = $mapExpenses[$d] ?? 0.0;
              $x = $mapTx[$d] ?? 0;
          ?>
            <tr>
              <td><?= htmlspecialchars($d) ?></td>
              <td class="right"><?= pesos($r) ?></td>
              <td class="right"><?= pesos($c) ?></td>
              <td class="right"><strong><?= pesos($t) ?></strong></td>
              <td class="right"><?= pesos($ds) ?></td>
              <td class="right"><?= pesos($ge) ?></td>
              <td class="right"><?= num($x) ?></td>
            </tr>
          <?php
              $cursor2->modify('-1 day');
            endwhile;
          ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- STOCK (solo producto y stock) -->
  <section class="card span-12">
    <h2>Stock actual — Productos (lista fija)</h2>

    <div class="tableScroll">
      <table>
        <thead>
          <tr>
            <th>Producto</th>
            <th class="right">Stock</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($stockRows) === 0): ?>
            <tr><td colspan="2" class="note">No se encontraron productos con esos nombres (revisa cómo están escritos en tu sistema).</td></tr>
          <?php else: foreach ($stockRows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
              <td class="right"><strong><?= num($r['stock'] ?? 0) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<script>
  // ===== Chart.js global styling (Dark/Neon)
  Chart.defaults.color = 'rgba(238,242,255,.85)';
  Chart.defaults.borderColor = 'rgba(255,255,255,.12)';
  Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
  Chart.defaults.plugins.legend.labels.boxWidth = 10;
  Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(9,10,16,.92)';
  Chart.defaults.plugins.tooltip.borderColor = 'rgba(255,255,255,.12)';
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.titleColor = 'rgba(238,242,255,.95)';
  Chart.defaults.plugins.tooltip.bodyColor = 'rgba(238,242,255,.88)';
  Chart.defaults.elements.line.borderWidth = 2;
  Chart.defaults.elements.point.radius = 0;
  Chart.defaults.elements.point.hoverRadius = 4;
  Chart.defaults.devicePixelRatio = Math.max(window.devicePixelRatio || 1, 2);
  Chart.defaults.maintainAspectRatio = false;

  const fmtMoney = (v) => {
    try {
      return new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN', maximumFractionDigits:0 }).format(v);
    } catch(e) {
      return '$' + (Math.round(v)).toLocaleString('es-MX') + ' MXN';
    }
  };

  const neon = { cyan:'#22d3ee', green:'#34d399', violet:'#a78bfa' };

  function lineGradient(ctx, chartArea, a, b){
    const g = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
    g.addColorStop(0, a);
    g.addColorStop(1, b);
    return g;
  }
  function fillGradient(ctx, chartArea, a, b){
    const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    g.addColorStop(0, a);
    g.addColorStop(1, b);
    return g;
  }

  const labelsDays   = <?= json_encode($labelsDays, JSON_UNESCAPED_UNICODE) ?>;
  const seriesTotal  = <?= json_encode($seriesTotal) ?>;
  const seriesRecep  = <?= json_encode($seriesRecep) ?>;
  const seriesCocina = <?= json_encode($seriesCocina) ?>;

  new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
      labels: labelsDays,
      datasets: [
        {
          label: 'Total',
          data: seriesTotal,
          tension: 0.25,
          fill: true,
          borderColor: (ctx) => {
            const {chart} = ctx;
            const {ctx: c, chartArea} = chart;
            if (!chartArea) return neon.cyan;
            return lineGradient(c, chartArea, neon.cyan, neon.green);
          },
          backgroundColor: (ctx) => {
            const {chart} = ctx;
            const {ctx: c, chartArea} = chart;
            if (!chartArea) return 'rgba(34,211,238,.12)';
            return fillGradient(c, chartArea, 'rgba(34,211,238,.18)', 'rgba(52,211,153,.02)');
          }
        },
        { label: 'Recepción', data: seriesRecep, tension: 0.25, borderDash: [6,6], borderColor: neon.green },
        { label: 'Cocina',    data: seriesCocina, tension: 0.25, borderDash: [2,6], borderColor: neon.violet },
      ]
    },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.raw)}` } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
        y: { beginAtZero: true, ticks: { callback: (v) => fmtMoney(v) }, grid: { color: 'rgba(255,255,255,.08)' } }
      }
    }
  });
</script>

</body>
</html>
```
