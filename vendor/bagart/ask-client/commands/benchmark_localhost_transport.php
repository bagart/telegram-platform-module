<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Transport\HttpTransportContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Transport\Adapters\AmpHttpTransportAdapter;
use BAGArt\ASKClient\Transport\Adapters\ASKSocketTransportAdapter;
use BAGArt\ASKClient\Transport\Adapters\CurlMultiTransportAdapter;
use BAGArt\ASKClient\Transport\Adapters\GuzzleTransportAdapter;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemon;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemonContext;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

require_once __DIR__.'/../../../../vendor/autoload.php';

// ── Options ──────────────────────────────────────────────────────────────────

$definedOptions = [
    'transport::',
    'host::',
    'concurrent::',
    'requests::',
    'runs::',
    'warmup::',
    'sleep::',
    'port::',
    'keep-alive::',
    'no-warm',
    'quick',
    'full',
    'help',
];

$options = getopt('', $definedOptions);

if (isset($options['help'])) {
    echo "Usage: php commands/benchmark_localhost_transport.php [options]

Localhost transport benchmark with a concurrency sweep.
Fires concurrent HTTP POST requests to tg-bench.php with a configurable delay,
comparing raw HTTP transport throughput across a range of concurrency levels
to find the sweet spot per transport. No pipeline, no queue, no rate limiter.

Each transport is measured at every level of --concurrent (a comma list, or a
single value), with --warmup requests fired first by default. Default sweep:
[8, 16, 32, 64, 128].

Options:
  --transport=<list>  Comma list of transports (default: all).
                      Known: ask-socket, curl-multi, guzzle, amphp.
  --host=<host>       Target host:port (e.g. 'localhost:8080' or 'docker-host:8080').
                      If omitted, starts a local PHP server automatically.
  --concurrent=<list> Comma-separated concurrency levels (default: 8,16,32,64,128,200).
                      A single number disables the sweep.
  --requests=<n>      Total requests per level per run (default: 200).
  --runs=<n>          Measurement runs per (transport, level), averaged (default: 3).
  --warmup=<n|pct>  Warmup requests before measurement. Integer = fixed count,
                      'auto' or omitted = 20%% of concurrency level. 0 disables.
  --sleep=<us>        Server sleep in microseconds (default: 50000 = 50ms).
  --port=<n>          Port for localhost server (default: 8080).
  --keep-alive=<mode> Keep-alive mode: 'yes' (reuse connections), 'no' (fresh per request, default),
                      'both' (run both). Shows comparison in the table.
  --no-warm           Skip the warm pass; measure cold only.
  --full              Full sweep: requests=500, keep-alive=both (old defaults).
  --quick             Smaller sweep [16,64] and fewer requests — for fast iteration.
  --help              This help.

Examples:
  php commands/benchmark_localhost_transport.php
  php commands/benchmark_localhost_transport.php --quick
  php commands/benchmark_localhost_transport.php --transport=ask-socket --concurrent=32
  php commands/benchmark_localhost_transport.php --host=localhost:8080 --sleep=200000
  php commands/benchmark_localhost_transport.php --keep-alive=no
  php commands/benchmark_localhost_transport.php --keep-alive=both
";
    exit(0);
}

$quick = isset($options['quick']);
$full = isset($options['full']);
$noWarm = isset($options['no-warm']);

$keepAliveRaw = strtolower((string)($options['keep-alive'] ?? ($full ? 'both' : 'no')));
$keepAliveModes = match ($keepAliveRaw) {
    'yes', 'true', '1', 'on' => ['yes'],
    'no', 'false', '0', 'off' => ['no'],
    default => ['yes', 'no'],
};

function parseConcurrentList(string $raw, bool $quick): array
{
    if ($raw === '') {
        return $quick ? [16, 64] : [8, 16, 32, 64, 128, 200];
    }

    $values = [];
    foreach (explode(',', $raw) as $part) {
        $v = (int)trim($part);
        if ($v >= 1) {
            $values[] = $v;
        }
    }

    return $values !== [] ? array_values(array_unique($values)) : [50];
}

$sweep = parseConcurrentList((string)($options['concurrent'] ?? ''), $quick);
sort($sweep);

$defaultRequests = $quick ? 100 : ($full ? 500 : 200);
$totalRequests = max(1, (int)($options['requests'] ?? $defaultRequests));
$runs = max(1, (int)($options['runs'] ?? 3));
$warmupRaw = $options['warmup'] ?? 'auto';
$warmupPct = match (true) {
    $warmupRaw === 'auto', $warmupRaw === null => true,
    is_numeric($warmupRaw) && (int)$warmupRaw >= 0 => false,
    default => true,
};
$warmupFixed = $warmupPct ? 0 : max(0, (int)$warmupRaw);
$sleep = max(0, (int)($options['sleep'] ?? 50_000));
$port = max(1024, (int)($options['port'] ?? 8080));

$transportFilterRaw = (string)($options['transport'] ?? '');
$customHost = (string)($options['host'] ?? '');

// ── Transport factories ──────────────────────────────────────────────────────

/**
 * Fresh transport factories for one concurrency level.
 * ask-socket's connection cap is set to $concurrent so it never artificially
 * throttles the batch — the bench is meant to compare raw transport throughput.
 *
 * When $keepAlive is false, the ask-socket connection pool is disabled so every
 * request opens a fresh TCP+TLS connection. For curl/guzzle transports, the
 * keep-alive is controlled at the request level via Connection: close header.
 *
 * @return array<string, callable(): HttpTransportContract>
 */
function transportFactories(int $concurrent, bool $keepAlive = true): array
{
    $socketConfig = new HttpsSocketClientConfig(
        maxConnectionsPerHost: $concurrent,
        maxIdlePerHost: $keepAlive ? $concurrent : 0,
        maxIdleTotal: $keepAlive ? $concurrent : 0,
        keepAlive: $keepAlive,
    );

    $factories = [
        ASKSocketTransportAdapter::TYPE => fn (): ASKSocketTransportAdapter => ASKSocketTransportAdapter::withConfig(
            $socketConfig
        ),
        CurlMultiTransportAdapter::TYPE => fn (): CurlMultiTransportAdapter => new CurlMultiTransportAdapter(),
        GuzzleTransportAdapter::TYPE => fn (): GuzzleTransportAdapter => new GuzzleTransportAdapter(),
    ];

    if (class_exists('\Amp\Http\Client\HttpClient')) {
        $factories[AmpHttpTransportAdapter::TYPE] = fn (): AmpHttpTransportAdapter => new AmpHttpTransportAdapter();
    }

    return $factories;
}

function parseTransportList(string $raw, array $known): array
{
    if ($raw === '') {
        return array_keys($known);
    }

    $selected = [];
    foreach (explode(',', $raw) as $part) {
        $t = trim($part);
        if ($t === '') {
            continue;
        }
        if (!isset($known[$t])) {
            fwrite(STDERR, "Unknown transport: {$t}. Known: ".implode(', ', array_keys($known))."\n");
            exit(2);
        }
        $selected[$t] = true;
    }

    return array_keys($selected);
}

$knownFactories = transportFactories(max($sweep));
$transports = parseTransportList($transportFilterRaw, $knownFactories);

// ── Resolve target URL ───────────────────────────────────────────────────────

$targetUrl = '';
$serverWorkers = min(max((int)max($sweep), 1), 64);

if ($customHost !== '') {
    $targetUrl = "http://{$customHost}/tg-bench.php";
} else {
    $targetUrl = "http://127.0.0.1:{$port}/tg-bench.php";
    $docRoot = dirname(__DIR__, 4).'/public';
    if ($docRoot !== false && is_dir($docRoot)) {
        $serverEnv = sprintf(
            'PHP_CLI_SERVER_WORKERS=%d XDEBUG_MODE=off',
            $serverWorkers,
        );
        $serverCmd = sprintf(
            '%s php -d xdebug.mode=off -S localhost:%d -t %s > /dev/null 2>&1 & echo $!',
            $serverEnv,
            $port,
            escapeshellarg($docRoot)
        );
        $pid = trim((string)shell_exec($serverCmd));
        if ($pid !== '' && is_numeric($pid)) {
            echo "  PHP server started (pid={$pid}) on localhost:{$port} with {$serverWorkers} workers\n";
            register_shutdown_function(function () use ($pid): void {
                shell_exec("kill {$pid} 2>/dev/null");
            });
            $ready = false;
            for ($i = 0; $i < 20; $i++) {
                $headers = @get_headers($targetUrl.'?sleep=0');
                if ($headers !== false && isset($headers[0]) && str_contains($headers[0], '200')) {
                    $ready = true;
                    break;
                }
                usleep(100_000);
            }
            if (!$ready) {
                fwrite(
                    STDERR,
                    "  WARNING: Local PHP server on {$targetUrl} did not respond within 2s. Continuing anyway...\n"
                );
            }
        } else {
            echo "  Could not auto-start PHP server. Ensure {$targetUrl} is reachable.\n";
        }
    } else {
        echo "  public/ dir not found. Ensure {$targetUrl} is reachable.\n";
    }
}

// ── Drain helper ─────────────────────────────────────────────────────────────

function drainTransport(HttpTransportContract $transport): void
{
    if (method_exists($transport, 'drain')) {
        $transport->drain();
    }
}

// ── Timer tickable ───────────────────────────────────────────────────────────

final class StopwatchTickable implements ASKTickableContract
{
    private readonly float $start;

    public function __construct(
        private readonly AsyncKernel $kernel,
        private readonly float $timeoutSec,
    ) {
        $this->start = microtime(true);
    }

    public function tick(int $systemPressure): void
    {
        if (microtime(true) - $this->start >= $this->timeoutSec) {
            $this->kernel->stop('timeout');
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function queueSize(): int
    {
        return 0;
    }
}

// ── Benchmark runner ─────────────────────────────────────────────────────────

function runBenchmarkPhase(
    HttpTransportContract $transport,
    string $targetUrl,
    int $concurrent,
    int $totalRequests,
    int $sleep = 500_000,
    float $timeoutSec = 120.0,
    bool $keepAlive = true,
): array {
    $logger = new ASKLogWrapper(minLevel: 'error');
    $kernel = new AsyncKernel($logger, shutdownTimeout: 30);
    $resolver = new ASKPromiseResolver();

    $kernel->addTickable($transport);
    $kernel->addTickable($resolver);

    $ok = 0;
    $errors = 0;
    $latencies = [];
    $remaining = $totalRequests;
    $url = $targetUrl.'?sleep='.$sleep;
    $body = json_encode(['data' => 'x'], JSON_THROW_ON_ERROR);

    // Timeout guard
    $kernel->addTickable(new StopwatchTickable($kernel, $timeoutSec));

    // ── Request-level keep-alive control ──
    // For ask-socket, keep-alive is controlled via HttpsSocketClientConfig.
    // For curl/guzzle, Connection: close tells HTTP/1.1 not to reuse the connection.
    $baseHeaders = ['Content-Type' => 'application/json'];
    if (!$keepAlive) {
        $baseHeaders['Connection'] = 'close';
    }

    // ── Producer daemon ──
    // Fires $concurrent requests at a time, waits for all to complete,
    // then fires the next batch until all requests are done.

    $ctx = new ASKFnDaemonContext(
        daemonName: 'bench',
        logger: $logger,
        payload: ['done' => false],
    );

    $daemonFn = function (ASKFnDaemonContext $context) use (
        $transport,
        $resolver,
        $url,
        $body,
        $concurrent,
        $kernel,
        $baseHeaders,
        &$ok,
        &$errors,
        &$latencies,
        &$remaining,
    ): void {
        while ($remaining > 0) {
            $batchSize = min($concurrent, $remaining);
            $promises = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $request = new ASKHttpRequest(
                    url: $url,
                    method: 'POST',
                    headers: $baseHeaders,
                    body: $body,
                    requestName: 'bench',
                );

                $t0 = microtime(true);
                $promises[] = [$transport->requestAsync($request), $t0];
            }

            $remaining -= $batchSize;

            foreach ($promises as [$promise, $t0]) {
                try {
                    $resolver->await($promise);
                    $latencies[] = microtime(true) - $t0;
                    $ok++;
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        $kernel->stop('all_requests_sent');
    };

    $kernel->addDaemon(
        new ASKFnDaemon(
            daemonContext: $ctx,
            fnProduce: $daemonFn,
            fnCanProduce: fn (ASKFnDaemonContext $context): bool => !($ctx->payload['done'] ?? false),
            fnShutdown: function (ASKFnDaemonContext $context, ?ASKShutdownContext $shutdownContext = null) use ($ctx
            ): bool {
                $ctx->payload['done'] = true;

                return true;
            },
        ),
        0,
    );

    $wallStart = microtime(true);
    $kernel->run();
    $wallTime = microtime(true) - $wallStart;

    return [$ok, $errors, $wallTime, $latencies];
}

// ── Statistics ───────────────────────────────────────────────────────────────

function percentile(array $sorted, float $pct): float
{
    $count = count($sorted);
    if ($count === 0) {
        return 0.0;
    }
    $idx = (int)ceil($pct / 100 * $count) - 1;
    $idx = max(0, min($count - 1, $idx));

    return $sorted[$idx];
}

function formatLatency(float $sec): string
{
    if ($sec < 0.001) {
        return round($sec * 1_000_000, 0)."\xc2\xb5s";
    }
    if ($sec < 1.0) {
        return number_format($sec * 1000, 1).'ms';
    }

    return number_format($sec, 2).'s';
}

function average(array $values): float
{
    $n = count($values);

    return $n > 0 ? array_sum($values) / $n : 0.0;
}

// ── Run transport ────────────────────────────────────────────────────────────

function runBenchmark(
    string $transport,
    string $mode,
    int $conc,
    callable $makeTransport,
    string $targetUrl,
    int $totalRequests,
    int $warmupReqs,
    int $runs,
    int $sleep = 500_000,
    bool $keepAlive = true,
): array {
    $client = $makeTransport();

    if ($warmupReqs > 0) {
        echo "            warmup {$warmupReqs} req... ";
        [$wOk, $wErr, $wTime] = runBenchmarkPhase(
            transport: $client,
            targetUrl: $targetUrl,
            concurrent: $conc,
            totalRequests: $warmupReqs,
            sleep: $sleep,
            keepAlive: $keepAlive,
        );
        drainTransport($client);
        echo "{$wOk} ok, {$wErr} err in ".number_format($wTime, 2)."s\n";
    }

    $allSent = [];
    $allElapsed = [];
    $allErrors = [];
    $allLatencies = [];

    for ($i = 0; $i < $runs; $i++) {
        [$s, $e, $t, $lats] = runBenchmarkPhase(
            transport: $client,
            targetUrl: $targetUrl,
            concurrent: $conc,
            totalRequests: $totalRequests,
            sleep: $sleep,
            keepAlive: $keepAlive,
        );
        $allSent[] = $s;
        $allElapsed[] = $t;
        $allErrors[] = $e;
        $allLatencies = array_merge($allLatencies, $lats);
        drainTransport($client);
        gc_collect_cycles();
    }

    $sentAvg = (int)round(average($allSent));
    $elapsedAvg = average($allElapsed);
    $errorsMax = $allErrors !== [] ? (int)max($allErrors) : 0;
    $throughput = $elapsedAvg > 0 ? $sentAvg / $elapsedAvg : 0.0;

    sort($allLatencies);
    $latCount = count($allLatencies);
    $p50 = $latCount > 0 ? percentile($allLatencies, 50) : 0.0;
    $p95 = $latCount > 0 ? percentile($allLatencies, 95) : 0.0;
    $p99 = $latCount > 0 ? percentile($allLatencies, 99) : 0.0;
    $maxLat = $latCount > 0 ? max($allLatencies) : 0.0;
    $avgLat = $latCount > 0 ? average($allLatencies) : 0.0;

    echo "              avg sent={$sentAvg}, errors={$errorsMax}, "
        .number_format($throughput, 1)." req/s, "
        ."p50=".formatLatency($p50)." p95=".formatLatency($p95)." p99=".formatLatency($p99)."\n";

    return [
        'transport' => $transport,
        'mode' => $mode,
        'conc' => $conc,
        'keepAlive' => $keepAlive,
        'sent' => $sentAvg,
        'errors' => $errorsMax,
        'elapsed' => $elapsedAvg,
        'throughput' => $throughput,
        'p50' => $p50,
        'p95' => $p95,
        'p99' => $p99,
        'max' => $maxLat,
        'avg' => $avgLat,
        'samples' => $latCount,
    ];
}

// ── Banner ───────────────────────────────────────────────────────────────────

$sweepLabel = count($sweep) > 1 ? 'sweep ['.implode(',', $sweep).']' : (string)$sweep[0];
$kaLabel = match ($keepAliveModes) {
    ['yes'] => 'yes',
    ['no'] => 'no',
    default => 'both',
};

echo "=== Localhost Transport Benchmark (sleep=".formatLatency($sleep / 1_000_000).") ===\n";
echo '    PHP: '.PHP_VERSION."\n";
echo "    Concurrency: {$sweepLabel}\n";
echo "    Server workers: {$serverWorkers}\n";
echo "    Requests/level: {$totalRequests} × {$runs} runs (averaged)\n";
$warmupLabel = $noWarm
    ? 'disabled (--no-warm)'
    : ($warmupPct ? '20% of concurrency' : "{$warmupFixed} req");
echo '    Warmup: '.$warmupLabel."\n";
echo "    Keep-alive: {$kaLabel}\n";
echo "    Target: {$targetUrl}\n";
echo "    Transports: ".implode(', ', $transports)."\n";

// ── ETA estimate ───────────────────────────────────────────────────────────────

$estPerLevel = static fn (int $conc): float => match (true) {
        $noWarm => 0.0,
        $warmupPct => max(1, (int)($conc * 0.2)) / max($conc, 1) * ($sleep / 1_000_000),
        default => $warmupFixed / max($conc, 1) * ($sleep / 1_000_000),
    } + $runs * (($totalRequests / max($conc, 1)) * ($sleep / 1_000_000));

$estPerTransport = 0.0;
foreach ($sweep as $conc) {
    $estPerTransport += $estPerLevel($conc);
}
$estPerTransport *= count($keepAliveModes) * count($noWarm ? ['cold'] : ['warm', 'cold']);
$estTotal = $estPerTransport * count($transports);

if ($estTotal > 60) {
    $estTotalMin = (int)ceil($estTotal / 60);
    echo "    Estimated total time: ~{$estTotalMin}m (sleep={$sleep}µs, {$totalRequests} req × {$runs}r)\n";
    if ($estTotalMin > 30) {
        echo "    Tip: use --quick for faster iteration or --sleep=100000 for quicker requests\n";
    }
}
echo "\n";

// ── Measurements ─────────────────────────────────────────────────────────────

$rows = [];

$emptyRow = static fn (string $transport, string $mode, int $conc, bool $keepAlive): array => [
    'transport' => $transport,
    'mode' => $mode,
    'conc' => $conc,
    'keepAlive' => $keepAlive,
    'sent' => 0,
    'errors' => 0,
    'elapsed' => 0.0,
    'throughput' => 0.0,
    'p50' => 0.0,
    'p95' => 0.0,
    'p99' => 0.0,
    'max' => 0.0,
    'avg' => 0.0,
    'samples' => 0,
];

$modes = $noWarm ? ['cold' => 0] : ['warm' => null, 'cold' => 0];

foreach ($sweep as $conc) {
    $concWarmup = $warmupPct ? (int)max(1, $conc * 0.2) : $warmupFixed;

    echo "  ══ conc={$conc} ══\n";

    foreach ($keepAliveModes as $kaMode) {
        $isKeepAlive = $kaMode === 'yes';
        $kaTag = $isKeepAlive ? 'ka' : 'no-ka';
        $levelFactories = transportFactories($conc, $isKeepAlive);

        foreach ($transports as $transport) {
            echo "  [{$transport}] conc={$conc} {$kaTag}\n";

            foreach ($modes as $mode => $warmupForMode) {
                $effectiveWarmup = ($mode === 'cold') ? 0 : $concWarmup;
                echo "      {$mode}:\n";

                try {
                    $rows[] = runBenchmark(
                        transport: $transport,
                        mode: $mode,
                        conc: $conc,
                        makeTransport: $levelFactories[$transport],
                        targetUrl: $targetUrl,
                        totalRequests: $totalRequests,
                        warmupReqs: $effectiveWarmup,
                        runs: $runs,
                        sleep: $sleep,
                        keepAlive: $isKeepAlive,
                    );
                } catch (Throwable $e) {
                    echo "      \x1b[31mERROR: {$e->getMessage()}\x1b[0m\n";
                    $rows[] = $emptyRow($transport, $mode, $conc, $isKeepAlive);
                }
            }
        }
    }
}

// ── Save results to JSON (when called under xhprof bench) ──────────────────────

$xhprofOutput = $_SERVER['XHPROF_OUTPUT'] ?? '';
if ($xhprofOutput !== '' && is_dir($xhprofOutput)) {
    $meta = [
        'sweep' => $sweep,
        'requests' => $totalRequests,
        'runs' => $runs,
        'warmupPct' => $warmupPct,
        'warmupFixed' => $warmupFixed,
        'sleep' => $sleep,
        'noWarm' => $noWarm,
        'php' => PHP_VERSION,
        'timestamp' => time(),
    ];
    file_put_contents($xhprofOutput.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

    foreach ($rows as $row) {
        $slug = $row['transport'].'_'
            .($row['keepAlive'] ? 'ka' : 'no-ka').'_'
            .$row['mode'].'_c'.$row['conc'];
        $subDir = $xhprofOutput.'/'.$row['transport'];
        if (!is_dir($subDir) && !mkdir($subDir, 0777, true) && !is_dir($subDir)) {
            continue;
        }
        file_put_contents(
            $subDir.'/'.$slug.'.json',
            json_encode($row, JSON_PRETTY_PRINT),
        );
    }
}

// ── Final comparison table ───────────────────────────────────────────────────

require __DIR__.'/includes/benchmark_localhost_transport/result.php';
render_benchmark_results($rows, $sweep, $totalRequests, $runs, $warmupPct, $warmupFixed, $sleep, $noWarm);

echo "\nDone.\n";
