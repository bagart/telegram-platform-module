<?php

declare(strict_types=1);

function render_benchmark_results(
    array $results,
    array $retries,
    array $risks,
    int $runs,
): void {
    echo "=== ASKClient Async Transport Benchmark (CONCURRENT) ===\n";
    echo '    php: '.PHP_VERSION."\n";
    echo "    all sources fetched simultaneously, runs: {$runs} (median)\n";
    echo "    primary: ↓time (faster better) ↑rps (bigger better) ↑score\n\n";

    $header = sprintf(
        "%-14s %9s %9s %6s %7s %7s %5s",
        'transport',
        'time↓',
        'rps↑',
        'ok',
        'fail',
        'memΔ',
        'score',
    );
    echo $header."\n";
    echo str_repeat('-', strlen($header))."\n";

    foreach ($results as $transport => $r) {
        if ($r['error'] !== null) {
            echo sprintf("%-14s ERROR: %s\n", $transport, substr((string)$r['error'], 0, 50));
            continue;
        }
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).'M' : '—';
        $errStr = $r['fail'] > 0 ? (string)$r['fail'] : '—';
        echo sprintf(
            "%-14s %9.3f %9.2f %6d %7s %7s %5d\n",
            $transport,
            $r['concurrent_time'],
            (float)($r['rps'] ?? 0.0),
            $r['ok'],
            $errStr,
            $deltaFmt,
            $r['fairness'],
        );
    }

    if ($retries !== []) {
        echo "\nRetries:\n";
        foreach ($retries as $name) {
            echo "  {$name}: worker failed on first attempt, retried successfully\n";
        }
    }

    if ($risks !== []) {
        echo "\nRISKS — URLs with >50% time difference across transports:\n";
        echo "  (may indicate external issues — consider removing from benchmark)\n\n";
        foreach ($risks as $i => $risk) {
            echo sprintf("  %d. %s\n", $i + 1, $risk['name']);
            echo "     url: {$risk['url']}\n";
            echo "     max/min ratio: ".number_format($risk['ratio'], 2)."x\n";
            foreach ($risk['times'] as $tName => $tVal) {
                echo sprintf("       %-14s %s\n", $tName, number_format($tVal, 4).'s');
            }
            echo "\n";
        }
    }

    echo "\nRanked by time (fastest → slowest):\n";
    $ranked = array_filter($results, static fn ($r) => $r['error'] === null);
    uasort($ranked, static fn ($a, $b) => $a['concurrent_time'] <=> $b['concurrent_time']);
    $rank = 0;
    foreach ($ranked as $transport => $r) {
        $rank++;
        $mem = $r['peak_memory'] ?? 0;
        $memFmt = $mem > 0 ? number_format($mem / 1024 / 1024, 1).' MB' : '—';
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).' MB Δ' : '';
        $errNote = $r['fail'] > 0 ? " ({$r['fail']} errors)" : '';
        echo sprintf(
            "  %d. %-14s %.3fs (%.1f rps, fairness %d/100, mem %s%s)%s\n",
            $rank,
            $transport,
            $r['concurrent_time'],
            (float)($r['rps'] ?? 0.0),
            $r['fairness'],
            $memFmt,
            $deltaFmt !== '' ? ', '.$deltaFmt : '',
            $errNote,
        );
    }

    // Per-phase metrics (P3.2): only transports exposing a MetricsCollector (ask-socket) have these.
    // Surfaces pool reuse ratio, TLS handshake cost, and connection lifecycle. Empty for others.
    $hasMetrics = false;
    foreach ($results as $r) {
        if ($r['error'] === null && !empty($r['metrics'])) {
            $hasMetrics = true;
            break;
        }
    }
    if ($hasMetrics) {
        echo "\nPer-phase metrics (client-side, where available):\n";
        foreach ($results as $transport => $r) {
            if ($r['error'] !== null || empty($r['metrics'])) {
                continue;
            }
            $m = $r['metrics'];
            $reuse = isset($m['pool.reuse_ratio']) ? number_format((float)$m['pool.reuse_ratio'] * 100, 1).'%' : '—';
            $tlsAvg = isset($m['tls.handshake.duration_avg_ms']) ? number_format(
                    (float)$m['tls.handshake.duration_avg_ms'],
                    2
                ).' ms' : '—';
            $tlsCount = (int)($m['tls.handshake.count'] ?? 0);
            $tlsFail = (int)($m['tls.handshake.failed'] ?? 0);
            $created = (int)($m['connections.created'] ?? 0);
            $closed = (int)($m['connections.closed'] ?? 0);
            $reqAvg = isset($m['connections.requests_avg']) ? number_format(
                (float)$m['connections.requests_avg'],
                2
            ) : '—';
            $dnsAvg = isset($m['dns.resolve_time_avg_ms']) ? number_format(
                    (float)$m['dns.resolve_time_avg_ms'],
                    2
                ).' ms' : '—';
            $dnsCount = (int)($m['dns.lookup_count'] ?? 0);
            echo "  {$transport}:\n";
            echo "    pool reuse:       {$reuse} of requests\n";
            echo "    DNS lookups:      {$dnsCount} (avg {$dnsAvg})\n";
            echo "    TLS handshakes:   {$tlsCount} (avg {$tlsAvg}, {$tlsFail} failed)\n";
            if (!empty($m['tls.versions'])) {
                $verStr = implode(', ', array_map(
                    static fn ($v, $c) => "{$v}×{$c}",
                    array_keys($m['tls.versions']),
                    array_values($m['tls.versions']),
                ));
                echo "    TLS versions:     {$verStr}\n";
            }
            echo "    connections:      {$created} created, {$closed} closed, {$reqAvg} req/conn avg\n";

            render_percentiles($m);
        }
    }

    render_phase_breakdowns($results);
}

/**
 * Print p50/p95/p99 for each request phase from the MetricsCollector snapshot.
 */
function render_percentiles(array $m): void
{
    $phases = ['dns', 'tcp', 'tls', 'write', 'ttfb', 'read', 'total'];
    $rows = [];
    foreach ($phases as $p) {
        $p50 = $m["phase.{$p}.p50_ms"] ?? null;
        $p95 = $m["phase.{$p}.p95_ms"] ?? null;
        $p99 = $m["phase.{$p}.p99_ms"] ?? null;
        if ($p50 === null && $p95 === null && $p99 === null) {
            continue;
        }
        $rows[] = sprintf(
            '    %-8s p50 %6.0fms  p95 %6.0fms  p99 %6.0fms',
            strtoupper($p),
            (float)($p50 ?? 0),
            (float)($p95 ?? 0),
            (float)($p99 ?? 0),
        );
    }
    if ($rows !== []) {
        echo "    phase percentiles:\n";
        foreach ($rows as $row) {
            echo "{$row}\n";
        }
    }
}

/**
 * Print per-URL phase breakdown (DNS/TCP/TLS/write/TTFB/read/total) for transports
 * that collected RequestTiming data (ask-socket only). Skipped silently otherwise.
 */
function render_phase_breakdowns(array $results): void
{
    foreach ($results as $transport => $r) {
        $phaseByUrl = $r['phase_by_url'] ?? [];
        if ($phaseByUrl === []) {
            continue;
        }

        echo "\nPhase breakdown — {$transport} (per-URL averages):\n";
        echo sprintf(
            "  %-22s %7s %7s %7s %7s %8s %7s %8s\n",
            'host',
            'DNS',
            'TCP',
            'TLS',
            'write',
            'TTFB',
            'read',
            'total',
        );
        echo '  '.str_repeat('-', 84)."\n";

        $acc = ['dns' => 0.0, 'tcp' => 0.0, 'tls' => 0.0, 'write' => 0.0, 'ttfb' => 0.0, 'read' => 0.0, 'total' => 0.0];
        $count = 0;

        foreach ($phaseByUrl as $url => $samples) {
            $host = parse_url((string)$url, PHP_URL_HOST) ?: $url;
            $host = substr((string)$host, 0, 22);

            $sums = [
                'dns' => 0.0,
                'tcp' => 0.0,
                'tls' => 0.0,
                'write' => 0.0,
                'ttfb' => 0.0,
                'read' => 0.0,
                'total' => 0.0
            ];
            foreach ($samples as $d) {
                foreach ($sums as $k => $_) {
                    $sums[$k] += (float)($d[$k] ?? 0.0);
                }
            }
            $n = count($samples);
            foreach ($sums as $k => $v) {
                $sums[$k] = $n > 0 ? $v / $n : 0.0;
                $acc[$k] += $sums[$k];
            }
            $count++;

            echo sprintf(
                "  %-22s %5.0fms %5.0fms %5.0fms %5.0fms %6.0fms %5.0fms %6.0fms\n",
                $host,
                $sums['dns'],
                $sums['tcp'],
                $sums['tls'],
                $sums['write'],
                $sums['ttfb'],
                $sums['read'],
                $sums['total'],
            );
        }

        if ($count > 0) {
            foreach ($acc as $k => $v) {
                $acc[$k] = $v / $count;
            }
            echo '  '.str_repeat('-', 84)."\n";
            echo sprintf(
                "  %-22s %5.0fms %5.0fms %5.0fms %5.0fms %6.0fms %5.0fms %6.0fms\n",
                "AVG ({$count} urls)",
                $acc['dns'],
                $acc['tcp'],
                $acc['tls'],
                $acc['write'],
                $acc['ttfb'],
                $acc['read'],
                $acc['total'],
            );
        }
    }
}

// CLI entry point (when run directly, not included)
if (PHP_SAPI === 'cli' && (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1) === [])) {
    $storageDir = $argv[1] ?? null;
    if ($storageDir === null) {
        fwrite(STDERR, "Usage: php result.php <storage-dir>\n");
        exit(1);
    }

    $metaFile = $storageDir.'/meta.json';
    $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
    $runs = (int)($meta['runs'] ?? 1);

    $results = [];
    $retries = [];

    $dir = new DirectoryIterator($storageDir);
    foreach ($dir as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }
        $t = $entry->getFilename();
        $jsonFile = $entry->getPathname().'/result.json';
        if (!file_exists($jsonFile)) {
            continue;
        }

        $output = trim((string)file_get_contents($jsonFile));
        $workerResult = json_decode($output, true);
        if (!is_array($workerResult) || !isset($workerResult['concurrent_time'])) {
            $results[$t] = ['error' => 'Invalid JSON in '.$jsonFile];
            continue;
        }

        $results[$t] = [
            'concurrent_time' => $workerResult['concurrent_time'],
            'rps' => $workerResult['rps'] ?? 0.0,
            'ok' => $workerResult['ok'] ?? 0,
            'fail' => $workerResult['fail'] ?? 0,
            'total' => $workerResult['total'] ?? 0,
            'wall' => 0.0,
            'peak_memory' => $workerResult['peak_memory'] ?? 0,
            'peak_memory_delta' => $workerResult['peak_memory_delta'] ?? null,
            'baseline_memory' => $workerResult['baseline_memory'] ?? null,
            'fairness' => $workerResult['fairness'] ?? 0,
            'cv' => $workerResult['cv'] ?? 0.0,
            'per_url' => $workerResult['per_url'] ?? [],
            'metrics' => $workerResult['metrics'] ?? [],
            'phase_by_url' => $workerResult['phase_by_url'] ?? [],
            'stderr' => '',
            'error' => null,
        ];
        if ($workerResult['retried'] ?? false) {
            $retries[] = $t;
        }
    }

    if ($results === []) {
        fwrite(STDERR, "No valid result.json files found in {$storageDir}\n");
        exit(1);
    }

    $urlData = [];
    foreach ($results as $transport => $r) {
        if ($r['error'] !== null) {
            continue;
        }
        foreach ($r['per_url'] as $entry) {
            $key = $entry['url'];
            if (!isset($urlData[$key])) {
                $urlData[$key] = ['name' => $entry['name'], 'url' => $entry['url'], 'times' => []];
            }
            $urlData[$key]['times'][$transport] = $entry['avg_time'];
        }
    }

    $risks = [];
    foreach ($urlData as $key => $data) {
        $times = $data['times'];
        if (count($times) < 2) {
            continue;
        }
        $max = max($times);
        $min = min($times);
        if ($min > 0 && ($max / $min) > 1.5) {
            $risks[] = [
                'name' => $data['name'],
                'url' => $data['url'],
                'times' => $times,
                'ratio' => $max / $min,
                'max_transport' => array_search($max, $times, true),
                'min_transport' => array_search($min, $times, true),
            ];
        }
    }
    usort($risks, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);
    $risks = array_slice($risks, 0, 5);

    // Exclude risky URLs from all transports' aggregate scoring
    $riskyKeys = [];
    foreach ($risks as $risk) {
        $riskyKeys[$risk['url']] = true;
    }

    if ($riskyKeys !== []) {
        foreach ($results as $transport => &$r) {
            if ($r['error'] !== null) {
                continue;
            }
            $filtered = array_values(
                array_filter(
                    $r['per_url'],
                    static fn ($entry) => !isset($riskyKeys[$entry['url']]),
                )
            );
            $r['per_url'] = $filtered;

            $okSum = 0;
            $totalSamples = 0;
            $weightedSum = 0.0;
            foreach ($filtered as $entry) {
                $s = $entry['samples'];
                $okSum += $s;
                $totalSamples += $s;
                $weightedSum += $entry['avg_time'] * $s;
            }
            $r['ok'] = $okSum;
            $r['total'] = $okSum + $r['fail'];
            $r['rps'] = $r['concurrent_time'] > 0 ? round($r['total'] / $r['concurrent_time'], 2) : 0.0;

            if ($totalSamples > 0 && $weightedSum > 0) {
                $mean = $weightedSum / $totalSamples;
                $varSum = 0.0;
                $maxTime = 0.0;
                foreach ($filtered as $entry) {
                    $diff = $entry['avg_time'] - $mean;
                    $varSum += $entry['samples'] * $diff * $diff;
                    if ($entry['avg_time'] > $maxTime) {
                        $maxTime = $entry['avg_time'];
                    }
                }
                $stddev = sqrt($varSum / $totalSamples);
                $cv = $maxTime > 0 ? $stddev / $maxTime : 0.0;
                $r['cv'] = round($cv, 4);
                $successRate = $r['total'] > 0 ? $okSum / $r['total'] : 0.0;
                $consistencyPts = max(0, 50 * (1 - $cv / 1.0));
                $reliabilityPts = $successRate * 50;
                $r['fairness'] = (int)round($consistencyPts + $reliabilityPts);
            }
        }
        unset($r);
    }

    render_benchmark_results($results, $retries, $risks, $runs);
    echo "\nDone.\n";
}
