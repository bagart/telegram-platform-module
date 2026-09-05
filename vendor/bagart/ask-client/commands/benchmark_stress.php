<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Transport\HttpTransportContract;
use BAGArt\ASKClient\Dto\ASKHttpRequest;
use BAGArt\ASKClient\SocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Transport\Adapters\ASKSocketTransportAdapter;
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
    'scenario::',
    'concurrent::',
    'requests::',
    'runs::',
    'warmup::',
    'sleep::',
    'port::',
    'keep-alive::',
    'no-warm',
    'quick',
    'help',
];

$options = getopt('', $definedOptions);

if (isset($options['help'])) {
    echo "Usage: php commands/benchmark_stress.php [options]

Stress benchmark for HTTP transports. Tests scenarios where transports differ
most in performance: multi-host DNS, high concurrency, large payloads, etc.

Scenarios:
  multi-host     30+ unique domains, no keep-alive (DNS+TLS overhead)
  high-conc      128 concurrent connections to single host (fd_set stress)
  large-body     100KB response body (parser overhead)
  connection-churn  Mixed keep-alive/no-keepalive across 5 hosts
  all            Run all scenarios (default)

Options:
  --transport=<list>  Comma list (default: all). Known: ask-socket, curl-multi, guzzle.
  --scenario=<list>   Comma list (default: all).
  --concurrent=<n>    Override concurrency per scenario (default: scenario-specific).
  --requests=<n>      Total requests per scenario (default: 300).
  --runs=<n>          Measurement runs, averaged (default: 3).
  --warmup=<n|pct>    Warmup requests (default: 20% of concurrency).
  --sleep=<us>        Server sleep in microseconds (default: 100000 = 100ms).
  --port=<n>          Port for localhost server (default: 8080).
  --keep-alive=<mode> 'yes', 'no', 'both' (default: both for scenarios that apply).
  --no-warm           Skip warm pass.
  --quick             Fewer requests/runs for fast iteration.
  --help              This help.
";
    exit(0);
}

$quick = isset($options['quick']);
$noWarm = isset($options['no-warm']);

$keepAliveRaw = strtolower((string)($options['keep-alive'] ?? 'both'));
$keepAliveModes = match ($keepAliveRaw) {
    'yes', 'true', '1', 'on' => ['yes'],
    'no', 'false', '0', 'off' => ['no'],
    default => ['yes', 'no'],
};

$defaultRequests = $quick ? 50 : 300;
$totalRequests = max(1, (int)($options['requests'] ?? $defaultRequests));
$runs = max(1, (int)($options['runs'] ?? ($quick ? 1 : 3)));
$warmupRaw = $options['warmup'] ?? 'auto';
$warmupPct = match (true) {
    $warmupRaw === 'auto', $warmupRaw === null => true,
    is_numeric($warmupRaw) && (int)$warmupRaw >= 0 => false,
    default => true,
};
$warmupFixed = $warmupPct ? 0 : max(0, (int)$warmupRaw);
$sleep = max(0, (int)($options['sleep'] ?? 100_000));
$port = max(1024, (int)($options['port'] ?? 8080));
$transportFilterRaw = (string)($options['transport'] ?? '');
$scenarioFilterRaw = (string)($options['scenario'] ?? 'all');

// ── Multi-host URL set (real currency API domains) ───────────────────────────

$MULTI_HOST_URLS = [
    'https://open.er-api.com/v6/latest/EUR',
    'https://www.cbr-xml-daily.ru/daily_json.js',
    'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json',
    'https://economia.awesomeapi.com.br/json/last/EUR-USD',
    'https://api.coinbase.com/v2/prices/EUR-USD/spot',
    'https://api.coinbase.com/v2/exchange-rates?currency=EUR',
    'https://api.frankfurter.dev/v1/latest?from=EUR&to=USD',
    'https://api.nbp.pl/api/exchangerates/tables/A/?format=json',
    'https://www.bankofcanada.ca/valet/observations/FXUSDCAD,FXEURCAD/json?recent=1',
    'https://api.datero.ro/v1/fx-rates?currency=EUR,USD',
    'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/EUR/USD',
    'https://query1.finance.yahoo.com/v8/finance/chart/EURUSD=X',
    'https://latest.currency-api.pages.dev/v1/currencies/eur.json',
    'https://api.frankfurter.app/latest?from=EUR&to=USD',
    'https://api.exchangerate.host/latest?base=EUR&symbols=USD',
    'https://api.frankfurter.dev/v1/latest?base=EUR&symbols=USD',
    'https://api.coinbase.com/v2/prices/ETH-USD/spot',
    'https://api.binance.com/api/v3/ticker/price?symbol=EURUSDT',
    'https://api.kraken.com/0/public/Ticker?pair=ZEURZUSD',
    'https://api.kucoin.com/api/v1/market/orderbook/level1?symbol=EUR-USDT',
    'https://api.bybit.com/v5/market/tickers?category=spot&symbol=EURUSDT',
    'https://api.coingecko.com/api/v3/simple/price?ids=eur&vs_currencies=usd',
    'https://www.floatrates.com/daily/eur.json',
    'https://api.exchangerate-api.com/v4/latest/EUR',
    'https://api.fxratesapi.com/latest?base=EUR&currencies=USD',
    'https://api.vatcomply.com/rates?base=EUR',
    'https://api.exchangerate.fun/latest?base=EUR',
    'https://cdn.moneyconvert.net/api/latest.json',
    'https://api.frankfurter.dev/v2/rate/EUR/USD',
    'https://api.exchangerate-api.com/v4/latest/USD',
    'https://www.floatrates.com/daily/usd.json',
    'https://api.coinbase.com/v2/exchange-rates?currency=USD',
    'https://api.binance.com/api/v3/ticker/price?symbol=EURUSDC',
    'https://api.mexc.com/api/v3/ticker/price?symbol=EURUSDT',
    'https://api.kraken.com/0/public/Ticker?pair=EURUSD',
    'https://api.hnb.hr/tecajn-eur/v3?valuta=USD',
    'https://api.bcb.gov.br/dados/serie/bcdata.sgs.10813/dados/ultimos/1?formato=json',
    'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?format=jsondata',
    'https://api.db.nomics.world/v22/series/ECB/EXR/D.USD.EUR.SP00.A?observations=1',
    'https://api.worldtradingdata.com/api/v1/forex?symbol=EUR/USD',
];

// ── Scenario definitions ─────────────────────────────────────────────────────

$SCENARIOS = [
    'multi-host' => [
        'label' => 'Multi-host no-keepalive (DNS+TLS stress)',
        'description' => '40 unique real-world domains, keepAlive=false. Each request = fresh DNS+TCP+TLS.',
        'concurrency' => 16,
        'sleep' => 0,
        'keepAlive' => false,
        'urls' => true, // use MULTI_HOST_URLS (external)
        'noLocalServer' => true,
    ],
    'high-conc' => [
        'label' => 'High concurrency single-host (fd_set stress)',
        'description' => '128 concurrent connections to same host. Tests stream_select scalability.',
        'concurrency' => 128,
        'sleep' => 50_000,
        'keepAlive' => true,
        'urls' => false,
    ],
    'large-body' => [
        'label' => 'Large response body (parser overhead)',
        'description' => '100KB response body. Tests fread/parse throughput.',
        'concurrency' => 32,
        'sleep' => 10_000,
        'keepAlive' => true,
        'urls' => false,
        'extraParams' => 'body_size=100000',
    ],
    'connection-churn' => [
        'label' => 'Connection churn (mixed keep-alive)',
        'description' => '5 hosts × 20 requests, mixed keep-alive. Tests pool management.',
        'concurrency' => 32,
        'sleep' => 50_000,
        'keepAlive' => 'mixed',
        'urls' => false,
    ],
];

// ── Transport factories ──────────────────────────────────────────────────────

function makeTransportFactory(string $type, int $concurrent, bool $keepAlive): callable
{
    if ($type === 'ask-socket') {
        return function () use ($concurrent, $keepAlive): ASKSocketTransportAdapter {
            $config = new HttpsSocketClientConfig(
                maxConnectionsPerHost: $concurrent,
                maxIdlePerHost: $keepAlive ? $concurrent : 0,
                maxIdleTotal: $keepAlive ? $concurrent : 0,
                keepAlive: $keepAlive,
            );
            return ASKSocketTransportAdapter::withConfig($config);
        };
    }

    if ($type === 'curl-multi') {
        return function (): \BAGArt\ASKClient\Transport\Adapters\CurlMultiTransportAdapter {
            return new \BAGArt\ASKClient\Transport\Adapters\CurlMultiTransportAdapter();
        };
    }

    if ($type === 'guzzle') {
        return function (): \BAGArt\ASKClient\Transport\Adapters\GuzzleTransportAdapter {
            return new \BAGArt\ASKClient\Transport\Adapters\GuzzleTransportAdapter();
        };
    }

    throw new \RuntimeException("Unknown transport: {$type}");
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
    array $urls,
    int $concurrent,
    int $totalRequests,
    int $sleep = 100_000,
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
    $body = json_encode(['data' => 'x'], JSON_THROW_ON_ERROR);

    $kernel->addTickable(new StopwatchTickable($kernel, $timeoutSec));

    $baseHeaders = ['Content-Type' => 'application/json'];
    if (!$keepAlive) {
        $baseHeaders['Connection'] = 'close';
    }

    $urlCount = count($urls);

    $ctx = new ASKFnDaemonContext(
        daemonName: 'bench',
        logger: $logger,
        payload: ['done' => false],
    );

    $daemonFn = function (ASKFnDaemonContext $context) use (
        $transport,
        $resolver,
        $urls,
        $body,
        $concurrent,
        $kernel,
        $baseHeaders,
        &$ok,
        &$errors,
        &$latencies,
        &$remaining,
        $urlCount,
    ): void {
        $urlIdx = 0;
        while ($remaining > 0) {
            $batchSize = min($concurrent, $remaining);
            $promises = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $url = $urls[$urlIdx % $urlCount];
                $urlIdx++;

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

// ── Run a single scenario ────────────────────────────────────────────────────

function runScenario(
    string $scenarioKey,
    array $scenario,
    array $transports,
    int $port,
    int $totalRequests,
    int $runs,
    int $warmupFixed,
    bool $warmupPct,
    array $keepAliveModes,
): array {
    $concOverride = null;
    $concurrent = $scenario['concurrency'];
    $sleep = $scenario['sleep'];
    $keepAlive = $scenario['keepAlive'];
    $useMultiHost = $scenario['urls'] ?? false;
    $extraParams = $scenario['extraParams'] ?? '';

    echo "\n";
    echo "┌─ {$scenario['label']}\n";
    echo "│  {$scenario['description']}\n";
    echo "│  Concurrency: {$concurrent}, Sleep: ".formatLatency($sleep / 1_000_000).", KeepAlive: ".(is_string(
            $keepAlive
        ) ? $keepAlive : ($keepAlive ? 'yes' : 'no'))."\n";
    echo "└─────────────────────────────────────────\n\n";

    // Build URL list for this scenario
    if ($useMultiHost) {
        global $MULTI_HOST_URLS;
        $noLocalServer = $scenario['noLocalServer'] ?? false;
        if ($noLocalServer) {
            // External URLs — use as-is
            $urls = $MULTI_HOST_URLS;
        } else {
            // Local URLs — replace port placeholder
            $urls = array_map(
                fn ($u) => str_replace('{PORT}', (string)$port, $u),
                $MULTI_HOST_URLS,
            );
        }
    } else {
        $baseParams = "sleep={$sleep}";
        if ($extraParams !== '') {
            $baseParams .= "&{$extraParams}";
        }
        $urls = ["http://127.0.0.1:{$port}/tg-bench.php?{$baseParams}"];
    }

    // Determine which keep-alive modes to test for this scenario
    if ($keepAlive === 'mixed') {
        $kaModes = ['yes', 'no'];
    } elseif (is_bool($keepAlive)) {
        $kaModes = [$keepAlive ? 'yes' : 'no'];
    } else {
        $kaModes = $keepAliveModes;
    }

    $rows = [];

    foreach ($transports as $transportType) {
        foreach ($kaModes as $kaMode) {
            $isKeepAlive = $kaMode === 'yes';
            $kaTag = $isKeepAlive ? 'ka' : 'no-ka';

            echo "  [{$transportType}] {$kaTag}\n";

            try {
                $makeTransport = makeTransportFactory($transportType, $concurrent, $isKeepAlive);
                $client = $makeTransport();
            } catch (\Throwable $e) {
                echo "    SKIP: {$e->getMessage()}\n";
                continue;
            }

            $effectiveWarmup = $warmupPct ? max(1, (int)($concurrent * 0.2)) : $warmupFixed;

            // Warmup
            if ($effectiveWarmup > 0) {
                echo "    warmup {$effectiveWarmup} req... ";
                [$wOk, $wErr, $wTime] = runBenchmarkPhase(
                    transport: $client,
                    urls: $urls,
                    concurrent: $concurrent,
                    totalRequests: $effectiveWarmup,
                    sleep: $sleep,
                    keepAlive: $isKeepAlive,
                );
                drainTransport($client);
                echo "{$wOk} ok, {$wErr} err in ".number_format($wTime, 2)."s\n";
            }

            // Measurement runs
            $allSent = [];
            $allElapsed = [];
            $allErrors = [];
            $allLatencies = [];

            for ($i = 0; $i < $runs; $i++) {
                [$s, $e, $t, $lats] = runBenchmarkPhase(
                    transport: $client,
                    urls: $urls,
                    concurrent: $concurrent,
                    totalRequests: $totalRequests,
                    sleep: $sleep,
                    keepAlive: $isKeepAlive,
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
            $avgLat = $latCount > 0 ? average($allLatencies) : 0.0;

            echo "    sent={$sentAvg}, err={$errorsMax}, "
                .number_format($throughput, 1)." req/s, "
                ."p50=".formatLatency($p50)." p95=".formatLatency($p95)."\n";

            $rows[] = [
                'scenario' => $scenarioKey,
                'transport' => $transportType,
                'keepAlive' => $isKeepAlive,
                'conc' => $concurrent,
                'sent' => $sentAvg,
                'errors' => $errorsMax,
                'elapsed' => $elapsedAvg,
                'throughput' => $throughput,
                'p50' => $p50,
                'p95' => $p95,
                'p99' => $p99,
                'avg' => $avgLat,
                'samples' => $latCount,
            ];
        }
    }

    return $rows;
}

// ── Parse transport list ─────────────────────────────────────────────────────

$knownTypes = [];
$knownTypes['ask-socket'] = true;
if (function_exists('curl_multi_init')) {
    $knownTypes['curl-multi'] = true;
}
if (class_exists('GuzzleHttp\Client')) {
    $knownTypes['guzzle'] = true;
}

function parseList(string $raw, array $known): array
{
    if ($raw === '') {
        return array_keys($known);
    }
    $selected = [];
    foreach (explode(',', $raw) as $part) {
        $t = trim($part);
        if ($t !== '' && isset($known[$t])) {
            $selected[$t] = true;
        }
    }
    return array_keys($selected);
}

$transports = parseList($transportFilterRaw, $knownTypes);
$scenarioKeys = $scenarioFilterRaw === 'all'
    ? array_keys($SCENARIOS)
    : parseList($scenarioFilterRaw, $SCENARIOS);

// ── Start server (only if local scenarios exist) ─────────────────────────────

$needsLocalServer = false;
foreach ($scenarioKeys as $key) {
    if (isset($SCENARIOS[$key]) && !($SCENARIOS[$key]['noLocalServer'] ?? false)) {
        $needsLocalServer = true;
        break;
    }
}

$targetUrl = "http://127.0.0.1:{$port}/tg-bench.php";
$serverWorkers = 64;

if ($needsLocalServer) {
    $docRoot = dirname(__DIR__, 4).'/public';
    if (is_dir($docRoot)) {
        $serverEnv = "PHP_CLI_SERVER_WORKERS={$serverWorkers}";
        $serverCmd = "{$serverEnv} php -S localhost:{$port} -t ".escapeshellarg($docRoot).' > /dev/null 2>&1 & echo $!';
        $pid = trim((string)shell_exec($serverCmd));
        if ($pid !== '' && is_numeric($pid)) {
            echo "PHP server started (pid={$pid}) on localhost:{$port} with {$serverWorkers} workers\n";
            register_shutdown_function(function () use ($pid): void {
                shell_exec("kill {$pid} 2>/dev/null");
            });
            // Wait for server to be ready
            for ($i = 0; $i < 20; $i++) {
                $headers = @get_headers($targetUrl.'?sleep=0');
                if ($headers !== false && isset($headers[0]) && str_contains($headers[0], '200')) {
                    break;
                }
                usleep(100_000);
            }
        }
    }
}

// ── Banner ───────────────────────────────────────────────────────────────────

function bench_rpad(string $text, int $width): string
{
    return $text.str_repeat(' ', max(0, $width - strlen($text)));
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║              STRESS TRANSPORT BENCHMARK                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  ".bench_rpad('PHP: '.PHP_VERSION, 60)."║\n";
echo "║  ".bench_rpad('Transports: '.implode(', ', $transports), 60)."║\n";
echo "║  ".bench_rpad('Scenarios: '.implode(', ', $scenarioKeys), 60)."║\n";
echo "║  ".bench_rpad("Requests: {$totalRequests} × {$runs}, Warmup: ".($warmupPct ? '20%' : $warmupFixed), 60)."║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

// ── Run scenarios ────────────────────────────────────────────────────────────

$allRows = [];

foreach ($scenarioKeys as $key) {
    if (!isset($SCENARIOS[$key])) {
        fwrite(STDERR, "Unknown scenario: {$key}\n");
        continue;
    }
    $rows = runScenario(
        scenarioKey: $key,
        scenario: $SCENARIOS[$key],
        transports: $transports,
        port: $port,
        totalRequests: $totalRequests,
        runs: $runs,
        warmupFixed: $warmupFixed,
        warmupPct: $warmupPct,
        keepAliveModes: $keepAliveModes,
    );
    $allRows = array_merge($allRows, $rows);
}

// ── Summary table ────────────────────────────────────────────────────────────

echo "\n\n";
echo "╔══════════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                              SUMMARY                                              ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════════════╝\n\n";

// Group by scenario
$byScenario = [];
foreach ($allRows as $r) {
    $byScenario[$r['scenario']][] = $r;
}

foreach ($byScenario as $scenarioKey => $rows) {
    $scenario = $SCENARIOS[$scenarioKey];
    echo "── {$scenario['label']} ──\n";

    usort($rows, fn ($a, $b) => $b['throughput'] <=> $a['throughput']);

    $header = sprintf(
        "%-14s %-5s %5s %7s %5s %8s %9s %9s %9s",
        'transport',
        'ka',
        'conc',
        'sent',
        'err',
        'req/s',
        'p50',
        'p95',
        'p99'
    );
    echo $header."\n";
    echo str_repeat('─', strlen($header))."\n";

    $bestThroughput = $rows !== [] ? $rows[0]['throughput'] : 0;

    foreach ($rows as $r) {
        $kaStr = $r['keepAlive'] ? 'yes' : 'no';
        $isWinner = $r['throughput'] === $bestThroughput && $bestThroughput > 0;
        $star = $isWinner ? ' ★' : '';

        echo sprintf(
            "%-14s %-5s %5d %7d %5d %8.1f %9s %9s %9s%s\n",
            $r['transport'],
            $kaStr,
            $r['conc'],
            $r['sent'],
            $r['errors'],
            $r['throughput'],
            formatLatency($r['p50']),
            formatLatency($r['p95']),
            formatLatency($r['p99']),
            $star,
        );
    }
    echo "\n";
}

// ── Cross-scenario ranking ───────────────────────────────────────────────────

echo "══ CROSS-SCENARIO RANKING ══\n\n";

$transportScores = [];
foreach ($allRows as $r) {
    $key = $r['transport'];
    if (!isset($transportScores[$key])) {
        $transportScores[$key] = ['totalThroughput' => 0, 'scenarios' => 0, 'wins' => 0];
    }
    $transportScores[$key]['totalThroughput'] += $r['throughput'];
    $transportScores[$key]['scenarios']++;
}

// Count wins per scenario
foreach ($byScenario as $rows) {
    usort($rows, fn ($a, $b) => $b['throughput'] <=> $a['throughput']);
    if ($rows !== []) {
        $winner = $rows[0]['transport'];
        $transportScores[$winner]['wins']++;
    }
}

uasort($transportScores, fn ($a, $b) => $b['totalThroughput'] <=> $a['totalThroughput']);

echo sprintf(
    "%-14s %6s %10s %8s\n",
    'transport',
    'wins',
    'total r/s',
    'avg r/s'
);
echo str_repeat('─', 42)."\n";

foreach ($transportScores as $type => $score) {
    $avg = $score['scenarios'] > 0 ? $score['totalThroughput'] / $score['scenarios'] : 0;
    echo sprintf(
        "%-14s %6d %10.1f %8.1f\n",
        $type,
        $score['wins'],
        $score['totalThroughput'],
        $avg,
    );
}

echo "\nDone.\n";
