<?php

declare(strict_types=1);

/**
 * Result renderer for the localhost transport benchmark (concurrency sweep).
 *
 * Each result row carries its own transport / mode / concurrency / keepAlive,
 * so the table can show the throughput curve across the sweep and highlight
 * the sweet spot per transport, with and without HTTP keep-alive.
 *
 * Row shape:
 *   transport, mode ('warm'|'cold'), conc, keepAlive (bool),
 *   sent, errors, elapsed, throughput, p50, p95, p99, max, avg, samples
 *
 * Called from benchmark_localhost_transport.php:
 *   require __DIR__.'/includes/benchmark_localhost_transport/result.php';
 *   render_benchmark_results($rows, $sweep, $totalRequests, $runs, $warmupPct, $warmupFixed, $sleep, $noWarm);
 */

function localhost_bench_format_latency(float $sec): string
{
    if ($sec < 0.001) {
        return round($sec * 1_000_000, 0)."\xc2\xb5s";
    }
    if ($sec < 1.0) {
        return number_format($sec * 1000, 1).'ms';
    }

    return number_format($sec, 2).'s';
}

/**
 * Generate a self-contained HTML report and return the path to the saved file.
 *
 * @param  array<int, array{
 *     transport:string, mode:string, conc:int, keepAlive:bool,
 *     sent:int, errors:int, elapsed:float, throughput:float,
 *     p50:float, p95:float, p99:float, max:float, avg:float, samples:int
 * }>  $rows
 * @param  array<int, int>  $sweep
 * @return string Path to the saved HTML file
 */
function render_benchmark_results_html(
    array $rows,
    array $sweep,
    int $totalRequests,
    int $runs,
    bool $warmupPct,
    int $warmupFixed,
    int $sleep,
    bool $noWarm = false,
    string $outputDir = '',
): string {
    $isSweep = count($sweep) > 1;
    $concLabel = $isSweep ? '['.implode(', ', $sweep).']' : (string)$sweep[0];
    $sleepLabel = localhost_bench_format_latency($sleep / 1_000_000);

    $phpVersion = PHP_VERSION;

    $warmupLabel = $noWarm
        ? 'disabled'
        : ($warmupPct ? '20% of concurrency' : "{$warmupFixed} req");

    // Compute optimal (max throughput) per transport
    $byTransport = [];
    foreach ($rows as $r) {
        $byTransport[$r['transport']][] = $r;
    }
    $optimal = [];
    foreach ($byTransport as $t => $group) {
        $best = null;
        foreach ($group as $r) {
            if ($r['samples'] <= 0) {
                continue;
            }
            if ($best === null
                || $r['throughput'] > $best['throughput']
                || ($r['throughput'] === $best['throughput'] && $r['conc'] < $best['conc'])
            ) {
                $best = $r;
            }
        }
        $optimal[$t] = $best;
    }

    $hasKaColumn = false;
    $kaValues = [];
    foreach ($rows as $r) {
        $kaValues[] = $r['keepAlive'] ?? true;
    }
    if (count(array_unique($kaValues)) > 1) {
        $hasKaColumn = true;
    }

    // Sort all rows by throughput desc (for the main table)
    $sorted = $rows;
    usort($sorted, static fn ($a, $b) => $b['throughput'] <=> $a['throughput']);

    // Build rows HTML
    $rowsHtml = '';
    foreach ($sorted as $r) {
        $has = $r['samples'] > 0;
        $bestRow = $optimal[$r['transport']] ?? null;
        $isBest = $bestRow !== null
            && $r['conc'] === $bestRow['conc']
            && $r['mode'] === $bestRow['mode']
            && ($r['keepAlive'] ?? true) === ($bestRow['keepAlive'] ?? true);

        $trClass = $isBest ? ' class="best"' : '';
        $star = $isBest ? '<span class="star">★</span>' : '';
        $kaStr = ($r['keepAlive'] ?? true) ? 'yes' : 'no';

        $kaCell = $hasKaColumn
            ? '<td>'.htmlspecialchars($kaStr).'</td>'
            : '';

        $rowsHtml .= '<tr'.$trClass.'>'
            .'<td>'.htmlspecialchars($r['transport']).$star.'</td>'
            .$kaCell
            .'<td>'.htmlspecialchars($r['mode']).'</td>'
            .'<td class="num">'.$r['conc'].'</td>'
            .'<td class="num">'.$r['sent'].'</td>'
            .'<td class="num">'.$r['errors'].'</td>'
            .'<td class="num throughput">'.number_format($r['throughput'], 1).'</td>'
            .'<td class="num">'.($has ? htmlspecialchars(localhost_bench_format_latency($r['avg'])) : '&mdash;').'</td>'
            .'<td class="num">'.($has ? htmlspecialchars(localhost_bench_format_latency($r['p50'])) : '&mdash;').'</td>'
            .'<td class="num">'.($has ? htmlspecialchars(localhost_bench_format_latency($r['p95'])) : '&mdash;').'</td>'
            .'<td class="num">'.($has ? htmlspecialchars(localhost_bench_format_latency($r['p99'])) : '&mdash;').'</td>'
            .'<td class="num">'.number_format($r['elapsed'], 2).'s</td>'
            .'</tr>';
    }

    $kaHeader = $hasKaColumn ? '<th>KA</th>' : '';
    $kaSort = $hasKaColumn ? '3' : '';

    // Optimal summary
    $optimalHtml = '';
    foreach (array_keys($byTransport) as $t) {
        $best = $optimal[$t];
        if ($best === null) {
            $optimalHtml .= '<tr><td>'.htmlspecialchars($t).'</td><td colspan="5">no data</td></tr>';

            continue;
        }
        $kaTag = ($best['keepAlive'] ?? true) ? 'ka' : 'no-ka';
        $optimalHtml .= '<tr>'
            .'<td>'.htmlspecialchars($t).'</td>'
            .'<td class="num">'.$best['conc'].'</td>'
            .'<td class="num throughput">'.number_format($best['throughput'], 1).'</td>'
            .'<td>'.htmlspecialchars(localhost_bench_format_latency($best['p95'])).'</td>'
            .'<td>'.htmlspecialchars($best['mode']).'</td>'
            .'<td>'.htmlspecialchars($kaTag).'</td>'
            .'</tr>';
    }

    $timestamp = date('Y-m-d H:i:s');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transport Benchmark — {$sleepLabel}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif;background:#0d1117;color:#e6edf3;padding:20px}
h1{font-size:18px;margin-bottom:4px;color:#f0f6fc}
h2{font-size:15px;margin:16px 0 8px;color:#f0f6fc}
.meta{font-size:12px;color:#8b949e;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:12px;font-family:"SF Mono","JetBrains Mono","Consolas",monospace;margin-bottom:24px}
th,td{padding:6px 10px;text-align:right;border-bottom:1px solid #21262d;white-space:nowrap}
th{background:#161b22;color:#8b949e;font-weight:600;cursor:pointer;position:sticky;top:0;z-index:1;user-select:none}
td:first-child,th:first-child{text-align:left}
tr:hover td{background:#161b22}
tr.best td{background:#1c2128;border-left:2px solid #1f6feb}
.star{color:#f0c846;margin-left:6px}
.num{text-align:right}
.throughput{font-weight:bold;color:#58a6ff}
a{color:#58a6ff;text-decoration:none}
a:hover{text-decoration:underline}
.legend{font-size:11px;color:#8b949e;margin-bottom:16px}
</style>
</head>
<body>

<h1>Localhost Transport Benchmark</h1>
<div class="meta">
    Sleep: {$sleepLabel} &middot; PHP: {$phpVersion} &middot; Concurrency: {$concLabel}<br>
    Requests/level: {$totalRequests} &times; {$runs} runs &middot;
    Warmup: {$warmupLabel} &middot; Generated: {$timestamp}
</div>

<h2>All results (sorted by req/s &darr;)</h2>
<table id="main-table">
<thead><tr>
    <th>Transport</th>
    {$kaHeader}
    <th>Mode</th>
    <th>Conc</th>
    <th>Sent</th>
    <th>Err</th>
    <th data-type="num" data-default="desc">req/s</th>
    <th data-type="text" data-default="asc">Avg</th>
    <th data-type="text" data-default="asc">p50</th>
    <th data-type="text" data-default="asc">p95</th>
    <th data-type="text" data-default="asc">p99</th>
    <th data-type="num" data-default="asc">Elapsed</th>
</tr></thead>
<tbody>
{$rowsHtml}
</tbody>
</table>

<h2>Optimal concurrency per transport</h2>
<table>
<thead><tr>
    <th>Transport</th>
    <th>Conc</th>
    <th>req/s</th>
    <th>p95</th>
    <th>Mode</th>
    <th>KA</th>
</tr></thead>
<tbody>
{$optimalHtml}
</tbody>
</table>

<div class="legend">★ = best throughput for this transport &middot; Click column headers to sort</div>

<script>
(function(){
    var table = document.getElementById('main-table');
    var ths = table.querySelectorAll('th[data-type]');
    var dir = {};
    ths.forEach(function(th, idx) {
        var col = idx + 1;
        var type = th.dataset.type;
        dir[col] = th.dataset.default || 'asc';
        th.addEventListener('click', function() {
            dir[col] = dir[col] === 'asc' ? 'desc' : 'asc';
            var rows = Array.from(table.querySelectorAll('tbody tr'));
            rows.sort(function(a, b) {
                var aVal = a.children[col] ? a.children[col].textContent.trim() : '';
                var bVal = b.children[col] ? b.children[col].textContent.trim() : '';
                if (type === 'num') {
                    aVal = parseFloat(aVal) || 0;
                    bVal = parseFloat(bVal) || 0;
                } else {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }
                var cmp = aVal < bVal ? -1 : (aVal > bVal ? 1 : 0);
                return dir[col] === 'asc' ? cmp : -cmp;
            });
            var tbody = table.querySelector('tbody');
            rows.forEach(function(r) { tbody.appendChild(r); });
        });
    });
})();
</script>
</body>
</html>
HTML;

    if ($outputDir !== '' && is_dir($outputDir)) {
        $path = $outputDir.'/benchmark-result.html';
        file_put_contents($path, $html);

        return $path;
    }

    echo $html;

    return '';
}

/**
 * @param  array<int, array{
 *     transport:string, mode:string, conc:int, keepAlive:bool,
 *     sent:int, errors:int, elapsed:float, throughput:float,
 *     p50:float, p95:float, p99:float, max:float, avg:float, samples:int
 * }>  $rows
 * @param  array<int, int>  $sweep
 */
function render_benchmark_results(
    array $rows,
    array $sweep,
    int $totalRequests,
    int $runs,
    bool $warmupPct,
    int $warmupFixed,
    int $sleep,
    bool $noWarm = false,
): void {
    $isSweep = count($sweep) > 1;
    $concLabel = $isSweep ? 'sweep ['.implode(',', $sweep).']' : (string)$sweep[0];

    $warmupLabel = $noWarm
        ? 'disabled (--no-warm)'
        : ($warmupPct ? '20% of concurrency' : "{$warmupFixed} req");

    echo "=== Localhost Transport Benchmark (sleep="
        .localhost_bench_format_latency($sleep / 1_000_000).") ===\n";
    echo '    PHP: '.PHP_VERSION."\n";
    echo "    Concurrency: {$concLabel}\n";
    echo "    Requests/level: {$totalRequests} × {$runs} runs (averaged)\n";
    echo '    Warmup: '.$warmupLabel."\n\n";

    // Group rows by transport (preserve first-seen order), compute the optimal
    // level per transport = max throughput, ties broken toward lower concurrency.
    // When both keep-alive variants exist, optimal is computed per transport+ka.
    $byTransport = [];
    foreach ($rows as $r) {
        $byTransport[$r['transport']][] = $r;
    }

    $optimal = [];
    foreach ($byTransport as $t => $group) {
        $best = null;
        foreach ($group as $r) {
            if ($r['samples'] <= 0) {
                continue;
            }
            $better = $best === null
                || $r['throughput'] > $best['throughput']
                || ($r['throughput'] === $best['throughput'] && $r['conc'] < $best['conc']);
            if ($better) {
                $best = $r;
            }
        }
        $optimal[$t] = $best;
    }

    // Detect whether keep-alive column is meaningful (both variants present)
    $hasKaColumn = false;
    $kaValues = [];
    foreach ($rows as $r) {
        $kaValues[] = $r['keepAlive'] ?? true;
    }
    if (count(array_unique($kaValues)) > 1) {
        $hasKaColumn = true;
    }

    $header = $hasKaColumn
        ? sprintf(
            "%-14s %-3s %-5s %5s %7s %5s %8s %9s %9s %9s %9s %8s",
            'transport',
            'ka',
            'mode',
            'conc',
            'sent',
            'err',
            'req/s',
            'avg',
            'p50',
            'p95',
            'p99',
            'elapsed',
        )
        : sprintf(
            "%-14s %-5s %5s %7s %5s %8s %9s %9s %9s %9s %8s",
            'transport',
            'mode',
            'conc',
            'sent',
            'err',
            'req/s',
            'avg',
            'p50',
            'p95',
            'p99',
            'elapsed',
        );
    echo $header."\n";
    echo str_repeat('-', strlen($header))."\n";

    $allRows = [];
    foreach ($byTransport as $t => $group) {
        foreach ($group as $r) {
            $allRows[] = $r;
        }
    }
    usort($allRows, static fn ($a, $b) => $b['throughput'] <=> $a['throughput']);

    foreach ($allRows as $r) {
        $has = $r['samples'] > 0;
        $bestRow = $optimal[$r['transport']] ?? null;
        $isBest = $bestRow !== null
            && $r['conc'] === $bestRow['conc']
            && $r['mode'] === $bestRow['mode']
            && ($r['keepAlive'] ?? true) === ($bestRow['keepAlive'] ?? true);

        $kaStr = ($r['keepAlive'] ?? true) ? 'yes' : 'no';

        $line = $hasKaColumn
            ? sprintf(
                "%-14s %-3s %-5s %5d %7d %5d %8.1f %9s %9s %9s %9s %7.2fs",
                $r['transport'],
                $kaStr,
                $r['mode'],
                $r['conc'],
                (int)round($r['sent']),
                $r['errors'],
                $r['throughput'],
                $has ? localhost_bench_format_latency($r['avg']) : '-',
                $has ? localhost_bench_format_latency($r['p50']) : '-',
                $has ? localhost_bench_format_latency($r['p95']) : '-',
                $has ? localhost_bench_format_latency($r['p99']) : '-',
                $r['elapsed'],
            )
            : sprintf(
                "%-14s %-5s %5d %7d %5d %8.1f %9s %9s %9s %9s %7.2fs",
                $r['transport'],
                $r['mode'],
                $r['conc'],
                (int)round($r['sent']),
                $r['errors'],
                $r['throughput'],
                $has ? localhost_bench_format_latency($r['avg']) : '-',
                $has ? localhost_bench_format_latency($r['p50']) : '-',
                $has ? localhost_bench_format_latency($r['p95']) : '-',
                $has ? localhost_bench_format_latency($r['p99']) : '-',
                $r['elapsed'],
            );

        if ($isBest) {
            echo "{$line}  ★\n";
        } else {
            echo $line."\n";
        }
    }

    echo "\nOptimal concurrency (max req/s per transport):\n";
    $optimalSorted = $optimal;
    uasort($optimalSorted, static fn ($a, $b) => ($b['throughput'] ?? 0) <=> ($a['throughput'] ?? 0));
    foreach (array_keys($optimalSorted) as $t) {
        $best = $optimal[$t];
        if ($best === null) {
            echo "  {$t}: (no successful measurements)\n";

            continue;
        }
        $kaTag = ($best['keepAlive'] ?? true) ? 'ka' : 'no-ka';
        echo sprintf(
            "  %-14s conc=%-4d %6.1f req/s  (p95=%s, %s, %s)\n",
            $t,
            $best['conc'],
            $best['throughput'],
            localhost_bench_format_latency($best['p95']),
            $best['mode'],
            $kaTag,
        );
    }
}

// CLI entry point (when run directly, not included)
if (PHP_SAPI === 'cli' && debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1) === []) {
    $storageDir = $argv[1] ?? null;
    if ($storageDir === null) {
        fwrite(STDERR, "Usage: php result.php <storage-dir>\n");

        exit(1);
    }

    $metaFile = $storageDir.'/meta.json';
    $meta = file_exists($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : [];

    $sweep = isset($meta['sweep']) && is_array($meta['sweep'])
        ? array_map('intval', $meta['sweep'])
        : [50];
    $totalRequests = (int)($meta['requests'] ?? 500);
    $runs = (int)($meta['runs'] ?? 3);
    $warmupPct = (bool)($meta['warmupPct'] ?? true);
    $warmupFixed = (int)($meta['warmupFixed'] ?? 0);
    $sleep = (int)($meta['sleep'] ?? 500_000);
    $noWarm = (bool)($meta['noWarm'] ?? false);

    $rows = [];
    $dirIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($dirIt as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
            continue;
        }
        $basename = $file->getBasename('.json');
        if (in_array($basename, ['meta'], true)) {
            continue;
        }

        $row = json_decode((string)file_get_contents($file->getPathname()), true);
        if (!is_array($row) || !isset($row['transport'], $row['throughput'])) {
            continue;
        }

        if (!isset($row['keepAlive'])) {
            $row['keepAlive'] = true;
        }

        $rows[] = $row;
    }

    if ($rows === []) {
        fwrite(STDERR, "No valid result JSON files found in {$storageDir}\n");

        exit(1);
    }

    $htmlPath = render_benchmark_results_html(
        $rows,
        $sweep,
        $totalRequests,
        $runs,
        $warmupPct,
        $warmupFixed,
        $sleep,
        $noWarm,
        $storageDir,
    );
    if ($htmlPath !== '') {
        echo "HTML report: $htmlPath\n";
    }

    echo "\n";
    render_benchmark_results($rows, $sweep, $totalRequests, $runs, $warmupPct, $warmupFixed, $sleep, $noWarm);
}
