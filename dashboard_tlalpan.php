<?php
// dashboard_tlalpan.php
// Dashboard (Dark/Neon) — BIOMAUSSAN TLALPAN
require_once __DIR__ . '/db.php';

// ===============================
// Config
// ===============================
$biller = 'BIOMAUSSAN TLALPAN';
$goalMonth = 630000; // Meta mensual (MXN)
$topNProducts = 8;   // top N para tabla/gráfica
$topNDisplace = 5;   // top N para desplazamiento (series)

// Rango de fechas (mes actual)
$tz = new DateTimeZone('America/Mexico_City');
$now = new DateTime('now', $tz);

$startCur = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
$endCur = (clone $startCur)->modify('+1 month');

$startPrev = (clone $startCur)->modify('-1 month');
$endPrev = (clone $startCur);

// Helpers
function pesos($n): string { return '$' . number_format((float)$n, 0, '.', ',') . ' MXN'; }
function num($n, $dec=0): string { return number_format((float)$n, $dec, '.', ','); }

function fetchAll(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}
function fetchOne(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch();
  return $row ? $row : [];
}

// Date params
$paramsCur = [
  ':biller' => $biller,
  ':start'  => $startCur->format('Y-m-d H:i:s'),
  ':end'    => $endCur->format('Y-m-d H:i:s'),
];

// ===============================
// Queries
// ===============================

// Totales del mes actual
$curTotals = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_sales,
    COUNT(*) AS tx_count
  FROM sma_sales
  WHERE biller = :biller
    AND date >= :start AND date < :end
", $paramsCur);

$totalSalesCur = (float)($curTotals['total_sales'] ?? 0);
$txCountCur    = (int)($curTotals['tx_count'] ?? 0);

// Totales del mes anterior
$prevTotals = fetchOne($pdo, "
  SELECT
    COALESCE(SUM(grand_total),0) AS total_sales,
    COUNT(*) AS tx_count
  FROM sma_sales
  WHERE biller = :biller
    AND date >= :start AND date < :end
", [
  ':biller' => $biller,
  ':start'  => $startPrev->format('Y-m-d H:i:s'),
  ':end'    => $endPrev->format('Y-m-d H:i:s'),
]);

$totalSalesPrev = (float)($prevTotals['total_sales'] ?? 0);
$txCountPrev    = (int)($prevTotals['tx_count'] ?? 0);

// Ventas por día (mes actual)
$salesByDay = fetchAll($pdo, "
  SELECT
    DATE(date) AS day,
    COALESCE(SUM(grand_total),0) AS total_sales,
    COUNT(*) AS tx_count
  FROM sma_sales
  WHERE biller = :biller
    AND date >= :start AND date < :end
  GROUP BY DATE(date)
  ORDER BY DATE(date) ASC
", $paramsCur);

// Unidades por producto (mes actual)
$productUnits = fetchAll($pdo, "
  SELECT
    si.product_name,
    COALESCE(SUM(si.quantity),0) AS units,
    COUNT(DISTINCT si.sale_id) AS tx_with_product
  FROM sma_sale_items si
  INNER JOIN sma_sales s ON s.id = si.sale_id
  WHERE s.biller = :biller
    AND s.date >= :start AND s.date < :end
  GROUP BY si.product_name
  ORDER BY units DESC, si.product_name ASC
  LIMIT {$topNProducts}
", $paramsCur);

// Total de unidades del mes (para promedio por transacción)
$unitsRow = fetchOne($pdo, "
  SELECT COALESCE(SUM(si.quantity),0) AS total_units
  FROM sma_sale_items si
  INNER JOIN sma_sales s ON s.id = si.sale_id
  WHERE s.biller = :biller
    AND s.date >= :start AND s.date < :end
", $paramsCur);
$totalUnitsCur = (float)($unitsRow['total_units'] ?? 0);

// Comparativo por semanas (mes actual)
$weeksCur = fetchAll($pdo, "
  SELECT
    YEARWEEK(date, 3) AS yw,
    MIN(DATE(date)) AS min_day,
    MAX(DATE(date)) AS max_day,
    COALESCE(SUM(grand_total),0) AS total_sales,
    COUNT(*) AS tx_count
  FROM sma_sales
  WHERE biller = :biller
    AND date >= :start AND date < :end
  GROUP BY YEARWEEK(date, 3)
  ORDER BY MIN(DATE(date)) ASC
", $paramsCur);

// ===============================
// Métricas e insights
// ===============================
$progress = ($goalMonth > 0) ? ($totalSalesCur / $goalMonth) : 0;
$progressPct = max(0, min(1, $progress));
$missing = max(0, $goalMonth - $totalSalesCur);

$avgUnitsPerTx = ($txCountCur > 0) ? ($totalUnitsCur / $txCountCur) : 0;
$avgTicket = ($txCountCur > 0) ? ($totalSalesCur / $txCountCur) : 0;

$deltaMonth = ($totalSalesPrev != 0) ? (($totalSalesCur - $totalSalesPrev) / $totalSalesPrev) : null;

// Días del mes / proyección
$daysInMonth = (int)$startCur->format('t');
$today = new DateTime($now->format('Y-m-d 00:00:00'), $tz);
$lastDayCur = (clone $endCur)->modify('-1 day');
$effectiveToday = ($today > $lastDayCur) ? $lastDayCur : $today;

$daysElapsed = (int)$startCur->diff($effectiveToday)->days + 1; // incluye hoy
$daysElapsed = max(1, min($daysInMonth, $daysElapsed));

$remainingDays = max(0, $daysInMonth - $daysElapsed);
$runRateDaily = ($daysElapsed > 0) ? ($totalSalesCur / $daysElapsed) : 0;
$projectedMonth = $runRateDaily * $daysInMonth;

$requiredDailyToGoal = ($remainingDays > 0) ? ($missing / $remainingDays) : ($missing > 0 ? $missing : 0);

// Insight: mejor día, promedio diario, fines de semana vs semana
$bestDay = null; $bestDaySales = -1;
$totalSalesDays = 0; $countDaysWithSales = 0;
$weekendSales = 0; $weekdaySales = 0;
$weekendTx = 0; $weekdayTx = 0;

foreach ($salesByDay as $r) {
  $d = $r['day'];
  $v = (float)$r['total_sales'];
  $t = (int)$r['tx_count'];

  if ($v > $bestDaySales) { $bestDaySales = $v; $bestDay = $d; }
  $totalSalesDays += $v;
  $countDaysWithSales += 1;

  $dt = new DateTime($d, $tz);
  $dow = (int)$dt->format('N'); // 6,7 = fin
  if ($dow >= 6) { $weekendSales += $v; $weekendTx += $t; }
  else { $weekdaySales += $v; $weekdayTx += $t; }
}

$avgDailySales = ($daysElapsed > 0) ? ($totalSalesCur / $daysElapsed) : 0;
$avgDailyTx = ($daysElapsed > 0) ? ($txCountCur / $daysElapsed) : 0;

$topProduct = $productUnits[0]['product_name'] ?? null;
$topProductUnits = isset($productUnits[0]) ? (float)$productUnits[0]['units'] : 0;
$topProductShare = ($totalUnitsCur > 0) ? ($topProductUnits / $totalUnitsCur) : 0;

// ===============================
// Series para charts (relleno por día)
// ===============================
$labelsDays = [];
$dailySalesMap = [];
$dailyTxMap = [];
foreach ($salesByDay as $r) {
  $dailySalesMap[$r['day']] = (float)$r['total_sales'];
  $dailyTxMap[$r['day']] = (int)$r['tx_count'];
}

$cumActual = [];
$dailySalesSeries = [];
$dailyTxSeries = [];
$cum = 0;

for ($i=1; $i<=$daysInMonth; $i++) {
  $dayStr = $startCur->format('Y-m-') . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
  $labelsDays[] = $dayStr;
  $v = $dailySalesMap[$dayStr] ?? 0.0;
  $t = $dailyTxMap[$dayStr] ?? 0;
  $dailySalesSeries[] = $v;
  $dailyTxSeries[] = $t;
  $cum += $v;
  $cumActual[] = $cum;
}

// Proyección acumulada (run-rate lineal)
$cumProj = [];
for ($i=1; $i<=$daysInMonth; $i++) {
  $cumProj[] = $runRateDaily * $i;
}

// Línea de meta (acumulada lineal)
$cumGoal = [];
for ($i=1; $i<=$daysInMonth; $i++) {
  $cumGoal[] = ($goalMonth / $daysInMonth) * $i;
}

// ===============================
// Desplazamiento por producto (top N series)
// ===============================
$topNames = array_slice(array_map(fn($r) => $r['product_name'], $productUnits), 0, $topNDisplace);
$displaceSeries = []; // product => [day => units]
if (count($topNames) > 0) {
  $in = [];
  $inParams = $paramsCur;
  foreach ($topNames as $i => $name) {
    $key = ":p{$i}";
    $in[] = $key;
    $inParams[$key] = $name;
    $displaceSeries[$name] = [];
  }

  $sqlDisp = "
    SELECT
      DATE(s.date) AS day,
      si.product_name,
      COALESCE(SUM(si.quantity),0) AS units
    FROM sma_sale_items si
    INNER JOIN sma_sales s ON s.id = si.sale_id
    WHERE s.biller = :biller
      AND s.date >= :start AND s.date < :end
      AND si.product_name IN (" . implode(',', $in) . ")
    GROUP BY DATE(s.date), si.product_name
    ORDER BY DATE(s.date) ASC
  ";

  $rowsDisp = fetchAll($pdo, $sqlDisp, $inParams);
  foreach ($rowsDisp as $r) {
    $pn = $r['product_name'];
    $day = $r['day'];
    $u = (float)$r['units'];
    if (!isset($displaceSeries[$pn])) $displaceSeries[$pn] = [];
    $displaceSeries[$pn][$day] = $u;
  }
}

// Convertir a datasets listos para Chart.js (rellenando días)
$dispDatasets = [];
foreach ($displaceSeries as $pn => $map) {
  $arr = [];
  for ($i=1; $i<=$daysInMonth; $i++) {
    $dayStr = $startCur->format('Y-m-') . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $arr[] = $map[$dayStr] ?? 0.0;
  }
  $dispDatasets[] = ['label' => $pn, 'data' => $arr];
}

// ===============================
// Preparar datasets para gráficas
// ===============================
$chartProductLabels = array_map(fn($r) => $r['product_name'], $productUnits);
$chartProductUnits  = array_map(fn($r) => (float)$r['units'], $productUnits);

$chartWeekLabels = [];
$chartWeekTotals = [];
foreach ($weeksCur as $idx => $w) {
  $label = "S" . ($idx+1) . " (" . $w['min_day'] . "–" . $w['max_day'] . ")";
  $chartWeekLabels[] = $label;
  $chartWeekTotals[] = (float)$w['total_sales'];
}

$chartMonthLabels = [$startPrev->format('M Y'), $startCur->format('M Y')];
$chartMonthTotals = [$totalSalesPrev, $totalSalesCur];

?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard Ventas | BIOMAUSSAN TLALPAN</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    :root{
      --bg:#07070a;
      --panel:#0b0c12;
      --panel2:#0e101a;
      --line:rgba(255,255,255,.10);
      --text:#eef2ff;
      --muted:rgba(238,242,255,.65);

      --neonA:#22d3ee; /* cyan */
      --neonB:#a78bfa; /* violet */
      --neonC:#34d399; /* green */
      --neonD:#fb7185; /* pink */
      --neonE:#fbbf24; /* amber */
      --shadow: 0 18px 40px rgba(0,0,0,.45);
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
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
      padding:22px 18px 10px;
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
      font-size:18px;
      font-weight:800;
      letter-spacing:.3px;
    }
    .sub{
      color:var(--muted);
      font-size:12.5px;
      margin-top:6px;
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
    }
    .tag strong{ color:var(--text); font-weight:700; }

    .grid{
      max-width:1220px;
      margin:0 auto;
      padding:12px 18px 26px;
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap:12px;
    }

    .card{
      background:
        linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:16px;
      padding:14px;
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .card::before{
      content:"";
      position:absolute;
      inset:-2px;
      background: radial-gradient(500px 120px at 0% 0%, rgba(34,211,238,.12), transparent 60%),
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
    .muted{ color:var(--muted); }
    .right{ text-align:right; }

    .span-12{ grid-column: span 12; }
    .span-8{ grid-column: span 8; }
    .span-7{ grid-column: span 7; }
    .span-6{ grid-column: span 6; }
    .span-5{ grid-column: span 5; }
    .span-4{ grid-column: span 4; }

    .kpis{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:10px;
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
    }

    .progressWrap{
      margin-top:12px;
      border:1px solid var(--line);
      border-radius:999px;
      overflow:hidden;
      height:12px;
      background: rgba(255,255,255,.04);
    }
    .progressBar{
      height:100%;
      width:0%;
      background: linear-gradient(90deg, var(--neonA), var(--neonB), var(--neonC));
      box-shadow: 0 0 16px rgba(34,211,238,.30);
    }

    .twoCol{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:12px;
      align-items:stretch;
    }

    .tableScroll{
      max-height:370px;
      overflow:auto;
      border-radius:14px;
      border:1px solid var(--line);
      background: rgba(0,0,0,.16);
    }
    table{
      width:100%;
      border-collapse:collapse;
    }
    th, td{
      padding:10px 10px;
      border-bottom:1px solid rgba(255,255,255,.07);
      font-size:12.5px;
      text-align:left;
      vertical-align:top;
      white-space:nowrap;
    }
    th{
      position:sticky;
      top:0;
      z-index:2;
      background: rgba(15, 17, 28, .92);
      backdrop-filter: blur(8px);
      color:rgba(238,242,255,.88);
      font-weight:800;
      letter-spacing:.2px;
    }
    tr:last-child td{ border-bottom:none; }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border:1px solid var(--line);
      border-radius:999px;
      background: rgba(0,0,0,.18);
      color:rgba(238,242,255,.85);
      font-size:12px;
    }

    .insights{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap:10px;
    }
    .insight{
      border:1px solid var(--line);
      border-radius:14px;
      padding:12px;
      background: rgba(0,0,0,.18);
    }
    .insight .t{
      font-size:11.5px;
      color:var(--muted);
      letter-spacing:.2px;
      margin-bottom:6px;
    }
    .insight .v{
      font-size:14px;
      font-weight:800;
      color:rgba(238,242,255,.92);
    }
    .note{
      font-size:12px;
      color:var(--muted);
      margin-top:10px;
      line-height:1.35;
    }

    .chartBox{
      padding:6px 0 0;
    }
    canvas{ width:100% !important; height:360px !important; }

    @media (max-width: 980px){
      .kpis{ grid-template-columns: repeat(2, 1fr); }
      .twoCol{ grid-template-columns:1fr; }
      .insights{ grid-template-columns:1fr; }
      .span-8,.span-7,.span-6,.span-5,.span-4{ grid-column: span 12; }
      canvas{ height:290px !important; }
    }
  </style>
</head>

<body>
<header>
  <div class="titleRow">
    <div>
      <h1>Dashboard Ventas — <?= htmlspecialchars($biller) ?></h1>
      <div class="sub">
        Mes actual: <strong><?= htmlspecialchars($startCur->format('F Y')) ?></strong> |
        Días transcurridos: <strong><?= num($daysElapsed) ?></strong>/<?= num($daysInMonth) ?> |
        Actualizado: <strong><?= htmlspecialchars($now->format('Y-m-d H:i')) ?></strong>
      </div>
    </div>
    <div class="tag">
      <span style="width:8px;height:8px;border-radius:999px;background:var(--neonA);box-shadow:0 0 12px rgba(34,211,238,.45)"></span>
      Meta: <strong><?= pesos($goalMonth) ?></strong>
    </div>
  </div>
</header>

<div class="grid">

  <!-- KPIs + Meta -->
  <section class="card span-12">
    <h2>Metas + KPIs clave</h2>

    <div class="kpis">
      <div class="kpi">
        <div class="label">Ventas mes actual</div>
        <div class="value"><?= pesos($totalSalesCur) ?></div>
        <div class="hint muted">Transacciones: <strong><?= num($txCountCur) ?></strong> · Promedio/día: <strong><?= pesos($avgDailySales) ?></strong></div>
      </div>
      <div class="kpi">
        <div class="label">Avance vs meta</div>
        <div class="value"><?= num($progressPct*100, 1) ?>%</div>
        <div class="hint muted">Faltante: <strong><?= pesos($missing) ?></strong></div>
      </div>
      <div class="kpi">
        <div class="label">Ticket promedio</div>
        <div class="value"><?= pesos($avgTicket) ?></div>
        <div class="hint muted">Promedio por venta realizada</div>
      </div>
      <div class="kpi">
        <div class="label">Unidades promedio / transacción</div>
        <div class="value"><?= num($avgUnitsPerTx, 2) ?></div>
        <div class="hint muted">Unidades mes: <strong><?= num($totalUnitsCur) ?></strong></div>
      </div>
    </div>

    <div class="progressWrap" aria-label="Barra de avance">
      <div class="progressBar" id="progressBar"></div>
    </div>

    <div style="margin-top:12px;" class="insights">
      <div class="insight">
        <div class="t">Mejor día del mes</div>
        <div class="v"><?= $bestDay ? htmlspecialchars($bestDay) . " · " . pesos($bestDaySales) : "Sin datos" ?></div>
      </div>
      <div class="insight">
        <div class="t">Top producto (unidades)</div>
        <div class="v"><?= $topProduct ? htmlspecialchars($topProduct) . " · " . num($topProductUnits) . " u (" . num($topProductShare*100,1) . "% del total)" : "Sin datos" ?></div>
      </div>
      <div class="insight">
        <div class="t">Run-rate / Proyección</div>
        <div class="v">
          <?= pesos($runRateDaily) ?>/día · Proyección: <strong><?= pesos($projectedMonth) ?></strong>
          <?php if ($goalMonth > 0): ?>
            (<?= num(($projectedMonth/$goalMonth)*100,1) ?>% de meta)
          <?php endif; ?>
        </div>
        <div class="note">Para alcanzar la meta: <strong><?= pesos($requiredDailyToGoal) ?></strong> por día (restan <?= num($remainingDays) ?> días).</div>
      </div>
    </div>

    <div class="twoCol" style="margin-top:14px;">
      <div class="card" style="border:none; padding:0; box-shadow:none; background:transparent;">
        <h2 style="margin:0 0 10px;">Avance (monto) — Ventas vs Faltante</h2>
        <div class="chartBox"><canvas id="goalDoughnut"></canvas></div>
      </div>

      <div class="card" style="border:none; padding:0; box-shadow:none; background:transparent;">
        <h2 style="margin:0 0 10px;">Comparativo mes actual vs mes anterior</h2>
        <div class="chartBox"><canvas id="monthCompare"></canvas></div>
        <div class="note">
          <?php if ($deltaMonth === null): ?>
            Mes anterior: <?= pesos($totalSalesPrev) ?> (sin base para % cambio).
          <?php else: ?>
            Cambio vs mes anterior: <strong><?= num($deltaMonth*100, 1) ?>%</strong>.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Proyección acumulada -->
  <section class="card span-12">
    <h2>Proyección acumulada del mes (Actual vs Proyección vs Meta)</h2>
    <div class="chartBox"><canvas id="projectionChart"></canvas></div>
    <div class="note">
      Proyección basada en el run-rate del mes (<?= pesos($runRateDaily) ?>/día) con <?= num($daysElapsed) ?> días transcurridos.
    </div>
  </section>

  <!-- Ventas por producto -->
  <section class="card span-7">
    <h2>Ventas por producto (unidades) — Mes actual (Top <?= num($topNProducts) ?>)</h2>
    <div class="tableScroll">
      <table>
        <thead>
          <tr>
            <th>Producto</th>
            <th class="right">Unidades</th>
            <th class="right">Transacciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($productUnits) === 0): ?>
            <tr><td colspan="3" class="muted">Sin registros para el mes actual.</td></tr>
          <?php else: foreach ($productUnits as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['product_name']) ?></td>
              <td class="right"><?= num($r['units']) ?></td>
              <td class="right"><?= num($r['tx_with_product']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="note">
      * Con (product_name + quantity) se reportan <strong>unidades vendidas</strong>. Si existe subtotal/precio en items, lo cambio a <strong>ventas por producto en $</strong>.
    </div>
  </section>

  <section class="card span-5">
    <h2>Gráfica — Unidades por producto</h2>
    <div class="chartBox"><canvas id="productUnitsChart"></canvas></div>
  </section>

  <!-- Desplazamiento por producto -->
  <section class="card span-12">
    <h2>Desplazamiento por producto (unidades por día) — Top <?= num($topNDisplace) ?></h2>
    <div class="chartBox"><canvas id="displacementChart"></canvas></div>
    <div class="note">
      * Útil para ver qué producto “se mueve” en qué días. Tip: puedes apagar/encender series desde la leyenda.
    </div>
  </section>

  <!-- Ventas por día -->
  <section class="card span-7">
    <h2>Ventas por día — Mes actual</h2>
    <div class="tableScroll">
      <table>
        <thead>
          <tr>
            <th>Día</th>
            <th class="right">Ventas</th>
            <th class="right">Transacciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($salesByDay) === 0): ?>
            <tr><td colspan="3" class="muted">Sin registros para el mes actual.</td></tr>
          <?php else: foreach ($salesByDay as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['day']) ?></td>
              <td class="right"><?= pesos($r['total_sales']) ?></td>
              <td class="right"><?= num($r['tx_count']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="note">
      Promedio de transacciones por día: <strong><?= num($avgDailyTx, 2) ?></strong>.  
      Ventas fines de semana: <strong><?= pesos($weekendSales) ?></strong> · Entre semana: <strong><?= pesos($weekdaySales) ?></strong>.
    </div>
  </section>

  <section class="card span-5">
    <h2>Gráfica — Ventas por día</h2>
    <div class="chartBox"><canvas id="salesByDayChart"></canvas></div>
  </section>

  <!-- Comparativo por semanas -->
  <section class="card span-12">
    <h2>Comparativo entre semanas — Mes actual</h2>

    <div class="twoCol">
      <div class="tableScroll">
        <table>
          <thead>
            <tr>
              <th>Semana</th>
              <th class="right">Ventas</th>
              <th class="right">Transacciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($weeksCur) === 0): ?>
              <tr><td colspan="3" class="muted">Sin registros para el mes actual.</td></tr>
            <?php else: foreach ($weeksCur as $idx => $w): ?>
              <tr>
                <td><?= "Semana " . ($idx+1) . " (" . htmlspecialchars($w['min_day']) . " a " . htmlspecialchars($w['max_day']) . ")" ?></td>
                <td class="right"><?= pesos($w['total_sales']) ?></td>
                <td class="right"><?= num($w['tx_count']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div>
        <div class="chartBox"><canvas id="weeksChart"></canvas></div>
        <div class="note">* Se agrupa por semana ISO dentro del mes (filtrado al mes actual).</div>
      </div>
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
  Chart.defaults.plugins.tooltip.displayColors = true;
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

  // Gradientes neon (verde/azul) — scriptable para crisp/HD
  const neon = {
    green: '#34d399',
    green2:'#10b981',
    cyan:  '#22d3ee',
    blue:  '#3b82f6',
    blue2: '#60a5fa',
    ink:   'rgba(255,255,255,.12)'
  };

  function lineGradient(ctx, chartArea, a=neon.green, b=neon.cyan){
    const g = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
    g.addColorStop(0, a);
    g.addColorStop(1, b);
    return g;
  }
  function fillGradient(ctx, chartArea, a='rgba(34,211,238,.22)', b='rgba(52,211,153,.02)'){
    const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    g.addColorStop(0, a);
    g.addColorStop(1, b);
    return g;
  }
  function barGradient(ctx, chartArea, a=neon.cyan, b=neon.green){
    const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    g.addColorStop(0, a);
    g.addColorStop(1, b);
    return g;
  }

  // Progress bar
  const pct = <?= json_encode($progressPct * 100) ?>;
  document.getElementById('progressBar').style.width = Math.min(100, Math.max(0, pct)) + '%';

  // ===== Goal doughnut
  const totalSalesCur = <?= json_encode($totalSalesCur) ?>;
  const missing = <?= json_encode($missing) ?>;

  new Chart(document.getElementById('goalDoughnut'), {
    type: 'doughnut',
    data: {
      labels: ['Ventas', 'Faltante'],
      datasets: [{
        data: [totalSalesCur, missing],
        backgroundColor: [neon.green, 'rgba(255,255,255,.08)'],
        borderWidth: 0,
        hoverOffset: 6
      }]
    },
    options: {
      responsive: true,
      cutout: '70%',
      layout: { padding: 6 },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${fmtMoney(ctx.raw)}` } }
      }
    }
  });

  // ===== Month compare
  const monthLabels = <?= json_encode($chartMonthLabels, JSON_UNESCAPED_UNICODE) ?>;
  const monthTotals = <?= json_encode($chartMonthTotals) ?>;

  new Chart(document.getElementById('monthCompare'), {
    type: 'bar',
    data: {
      labels: monthLabels,
      datasets: [{
        label: 'Ventas (MXN)',
        data: monthTotals,
        borderWidth: 0,
        borderRadius: 10,
        barThickness: 42,
        backgroundColor: (ctx) => {
          const {chart} = ctx;
          const {ctx: c, chartArea} = chart;
          if (!chartArea) return neon.cyan;
          return barGradient(c, chartArea, neon.blue, neon.cyan);
        }
      }]
    },
    options: {
      responsive: true,
      layout: { padding: 6 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.raw)}` } }
      },
      scales: {
        x: { grid: { display: false } },
        y: {
          beginAtZero: true,
          ticks: { callback: (v) => fmtMoney(v) },
          grid: { color: 'rgba(255,255,255,.08)' }
        }
      }
    }
  });

  // ===== Projection chart (cumulative)
  const labelsDays = <?= json_encode($labelsDays, JSON_UNESCAPED_UNICODE) ?>;
  const cumActual = <?= json_encode($cumActual) ?>;
  const cumProj   = <?= json_encode($cumProj) ?>;
  const cumGoal   = <?= json_encode($cumGoal) ?>;

  new Chart(document.getElementById('projectionChart'), {
    type: 'line',
    data: {
      labels: labelsDays,
      datasets: [
        {
          label: 'Acumulado real',
          data: cumActual,
          tension: 0.25,
          fill: true,
          borderColor: (ctx) => {
            const {chart} = ctx;
            const {ctx: c, chartArea} = chart;
            if (!chartArea) return neon.green;
            return lineGradient(c, chartArea, neon.green, neon.cyan);
          },
          backgroundColor: (ctx) => {
            const {chart} = ctx;
            const {ctx: c, chartArea} = chart;
            if (!chartArea) return 'rgba(52,211,153,.14)';
            return fillGradient(c, chartArea, 'rgba(34,211,238,.18)', 'rgba(52,211,153,.02)');
          }
        },
        {
          label: 'Proyección (run-rate)',
          data: cumProj,
          borderDash: [6,6],
          tension: 0.25,
          borderColor: (ctx) => {
            const {chart} = ctx;
            const {ctx: c, chartArea} = chart;
            if (!chartArea) return neon.blue;
            return lineGradient(c, chartArea, neon.blue, neon.cyan);
          }
        },
        {
          label: 'Meta (acumulada)',
          data: cumGoal,
          borderDash: [2,6],
          tension: 0.25,
          borderColor: 'rgba(255,255,255,.28)'
        }
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
        y: {
          beginAtZero: true,
          ticks: { callback: (v) => fmtMoney(v) },
          grid: { color: 'rgba(255,255,255,.08)' }
        }
      }
    }
  });

  // ===== Product units chart
  const prodLabels = <?= json_encode($chartProductLabels, JSON_UNESCAPED_UNICODE) ?>;
  const prodUnits  = <?= json_encode($chartProductUnits) ?>;

  new Chart(document.getElementById('productUnitsChart'), {
    type: 'bar',
    data: {
      labels: prodLabels,
      datasets: [{
        label: 'Unidades',
        data: prodUnits,
        borderWidth: 0,
        borderRadius: 10,
        backgroundColor: (ctx) => {
          const {chart} = ctx;
          const {ctx: c, chartArea} = chart;
          if (!chartArea) return neon.green;
          return barGradient(c, chartArea, neon.green, neon.cyan);
        }
      }]
    },
    options: {
      responsive: true,
      layout: { padding: 6 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}` } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 70, minRotation: 20 } },
        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.08)' } }
      }
    }
  });

  // ===== Displacement chart (units per day by product)
  const dispDatasetsRaw = <?= json_encode($dispDatasets, JSON_UNESCAPED_UNICODE) ?>;
  const palette = [neon.green, neon.cyan, neon.blue, neon.green2, neon.blue2];
  const dispDatasets = dispDatasetsRaw.map((ds, i) => ({
    ...ds,
    type: 'line',
    tension: 0.25,
    borderWidth: 2,
    pointRadius: 0,
    pointHoverRadius: 4,
    borderColor: (ctx) => {
      const {chart} = ctx;
      const {ctx: c, chartArea} = chart;
      const a = palette[i % palette.length];
      const b = palette[(i+1) % palette.length];
      if (!chartArea) return a;
      return lineGradient(c, chartArea, a, b);
    }
  }));

  new Chart(document.getElementById('displacementChart'), {
    type: 'line',
    data: {
      labels: labelsDays,
      datasets: dispDatasets
    },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw} u` } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.08)' } }
      }
    }
  });

  // ===== Sales by day chart
  const dailySales = <?= json_encode($dailySalesSeries) ?>;

  new Chart(document.getElementById('salesByDayChart'), {
    type: 'line',
    data: {
      labels: labelsDays,
      datasets: [{
        label: 'Ventas (MXN)',
        data: dailySales,
        tension: 0.25,
        borderWidth: 2,
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
          return fillGradient(c, chartArea, 'rgba(34,211,238,.16)', 'rgba(34,211,238,.02)');
        }
      }]
    },
    options: {
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.raw)}` } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: {
          beginAtZero: true,
          ticks: { callback: (v) => fmtMoney(v) },
          grid: { color: 'rgba(255,255,255,.08)' }
        }
      }
    }
  });

  // ===== Weeks chart
  const weekLabels = <?= json_encode($chartWeekLabels, JSON_UNESCAPED_UNICODE) ?>;
  const weekTotals = <?= json_encode($chartWeekTotals) ?>;

  new Chart(document.getElementById('weeksChart'), {
    type: 'bar',
    data: {
      labels: weekLabels,
      datasets: [{
        label: 'Ventas (MXN)',
        data: weekTotals,
        borderWidth: 0,
        borderRadius: 10,
        backgroundColor: (ctx) => {
          const {chart} = ctx;
          const {ctx: c, chartArea} = chart;
          if (!chartArea) return neon.blue;
          return barGradient(c, chartArea, neon.cyan, neon.blue);
        }
      }]
    },
    options: {
      responsive: true,
      layout: { padding: 6 },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.raw)}` } }
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { callback: (v) => fmtMoney(v) }, grid: { color: 'rgba(255,255,255,.08)' } }
      }
    }
  });
</script>

</body>
</html>
