# bagart/ask-client

Minimal deterministic execution engine: `execute(object $operation): ASKFuture`

## Usage

```php
use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\ASKContext;
use BAGArt\ASKClient\ASKFuture;

$client = new ASKClient(
    transport: ASKTransport::wrap(fn (object $op, ASKContext $ctx): ASKFuture =>
        ASKFuture::resolved($result),
    ),
);

$result = $client->execute($operation)->await();
```

## Future chain

```php
$result = $client
    ->execute($operation)
    ->then(fn ($x) => $x + 1)
    ->then(fn ($x) => $x * 2)
    ->await();

$recovered = $client
    ->execute($operation)
    ->recover(fn (Throwable $e) => [])
    ->await();
```

## Client with handlers

```php
$client = new ASKClient(
    transport: $transport,
    handlers: [new MyRetryHandler(), new MyLoggingHandler()],
);
```

## Handler contract

A handler is a single unified type (no separate middleware/stage layers):

```php
$handler = new class () implements ASKHandlerContract {
    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract {
        return $next($operation, $context);
    }
};
```

## Architecture

```
execute(operation)
    ↓
handler chain
    ↓
transport
    ↓
future
```

- **ASKClient** — entry point, `::create(...$handlers)` factory
- **ASKFuture** — lazy future with `then` / `catch` / `recover` / `finally` chain

- **ASKContext** — immutable context with `with()` / `without()` / `merge()`
- **ASKTransport** — `wrap(callable)` / `null()` / `execute()`
- **ASKNextHandler** — typed next-handler replacing `callable`
- **Contracts\*** — interfaces for all core components

## Benchmarks

### Localhost transport benchmark (2026-08-16)

Run via `./cmd/xhprof_bench_localhost_transport`. Each transport fires concurrent
HTTP POSTs at a localhost PHP server (50 ms delay per request): 200 requests per
(transport, mode, concurrency), no keep-alive (fresh connection per request),
concurrency sweep 8–200. `warm` = measured after warmup requests, `cold` = without.
Rows sorted by throughput; ★ marks the best mode per transport.

```text
transport      mode   conc    sent   err    req/s       avg       p50       p95       p99  elapsed
--------------------------------------------------------------------------------------------------
ask-socket     warm    200     200     0    518.7   311.5ms   376.0ms   380.5ms   380.9ms    0.39s  ★
ask-socket     cold    200     200     0    518.0   289.8ms   284.4ms   427.5ms   428.0ms    0.39s
curl-multi     warm    200     200     0    385.5   343.1ms   373.2ms   570.9ms   572.3ms    0.52s  ★
curl-multi     cold    200     200     0    340.6   368.1ms   378.6ms   623.0ms   624.3ms    0.59s
ask-socket     cold    128     200     0    315.0   233.9ms   218.6ms   479.3ms   479.8ms    0.63s
curl-multi     cold    128     200     0    297.1   242.3ms   220.7ms   466.9ms   468.2ms    0.67s
ask-socket     warm    128     200     0    284.6   234.7ms   230.6ms   530.6ms   578.5ms    0.70s
curl-multi     warm    128     200     0    282.2   245.3ms   227.2ms   416.0ms   417.4ms    0.71s
ask-socket     warm     64     200     0    256.8   155.6ms   164.9ms   266.0ms   316.8ms    0.78s
ask-socket     cold     64     200     0    256.6   161.9ms   166.9ms   268.1ms   365.5ms    0.78s
guzzle         cold    200     200     0    246.4   516.1ms   513.4ms   605.6ms   622.6ms    0.81s  ★
curl-multi     warm     64     200     0    231.3   184.7ms   164.0ms   310.5ms   311.1ms    0.86s
guzzle         warm    200     200     0    218.7   587.3ms   603.6ms   668.4ms   688.9ms    0.91s
ask-socket     warm     32     200     0    208.2   123.0ms   112.3ms   209.0ms   211.0ms    0.96s
curl-multi     cold     64     200     0    207.1   198.2ms   210.3ms   312.0ms   459.3ms    0.97s
guzzle         warm    128     200     0    196.7   361.7ms   358.9ms   509.1ms   543.8ms    1.02s
guzzle         cold    128     200     0    196.3   336.3ms   339.9ms   429.3ms   458.7ms    1.02s
ask-socket     cold     32     200     0    192.8   116.5ms   112.5ms   161.4ms   211.8ms    1.04s
curl-multi     cold     32     200     0    180.2   136.9ms   156.0ms   207.9ms   258.6ms    1.11s
guzzle         cold     64     200     0    177.7   231.3ms   228.2ms   301.5ms   322.1ms    1.13s
curl-multi     warm     32     200     0    172.9   142.4ms   155.7ms   255.3ms   256.1ms    1.16s
guzzle         warm     64     200     0    161.4   237.2ms   228.1ms   335.6ms   374.5ms    1.24s
guzzle         cold     32     200     0    149.4   157.4ms   154.3ms   225.0ms   295.6ms    1.34s
curl-multi     cold     16     200     0    141.7    92.7ms   103.9ms   154.5ms   205.2ms    1.41s
ask-socket     cold     16     200     0    141.3    86.4ms   104.0ms   111.4ms   156.6ms    1.42s
guzzle         warm     32     200     0    136.2   155.9ms   157.3ms   218.3ms   245.2ms    1.47s
curl-multi     warm     16     200     0    135.6    94.5ms   104.3ms   154.8ms   204.7ms    1.47s
ask-socket     warm     16     200     0    131.2    95.7ms   105.4ms   156.7ms   206.3ms    1.52s
guzzle         warm     16     200     0    118.2   104.5ms   109.6ms   130.4ms   159.4ms    1.69s
ask-socket     cold      8     200     0    109.3    64.6ms    56.6ms   104.3ms   106.4ms    1.83s
guzzle         cold     16     200     0    104.5   115.3ms   114.4ms   166.8ms   259.2ms    1.91s
curl-multi     warm      8     200     0    103.9    68.7ms    56.1ms   104.3ms   105.1ms    1.93s
curl-multi     cold      8     200     0     99.2    70.9ms    56.1ms   103.9ms   204.6ms    2.02s
ask-socket     warm      8     200     0     98.6    70.3ms    57.3ms   106.0ms   154.3ms    2.03s
guzzle         warm      8     200     0     91.5    74.7ms    68.4ms   112.2ms   116.2ms    2.19s
guzzle         cold      8     200     0     83.9    80.5ms    69.4ms   115.8ms   161.6ms    2.38s
```

Optimal concurrency (max req/s per transport):

- `ask-socket` — conc=200, **518.7 req/s** (p95=380.5ms, warm)
- `curl-multi` — conc=200, **385.5 req/s** (p95=570.9ms, warm)
- `guzzle` — conc=200, **246.4 req/s** (p95=605.6ms, cold)

Takeaways:

- `ask-socket` beats `curl-multi` by ~35% and `guzzle` by ~2.1× at conc=200, with the
  lowest p95 at peak load.
- All transports keep scaling up to conc=200 on localhost; zero errors across the sweep.
- warm vs cold makes little difference — per-connection setup cost dominates (no keep-alive).

### Transport benchmark (2026-08-17)

Run via `./cmd/xhprof_bench_transport`. Real-world end-to-end test: concurrent requests
to ~40 live currency API URLs over the internet. Ranks transports by wall time.

```text
Ranked by time (fastest → slowest):
  1. ask-socket     2.654s (29.0 rps, fairness 100/100, mem 16.0 MB, 6.0 MB Δ)
  2. guzzle         2.863s (26.9 rps, fairness 100/100, mem 18.0 MB, 8.0 MB Δ)
  3. curl-multi     3.772s (20.4 rps, fairness 98/100, mem 16.0 MB, 6.0 MB Δ)
```

Takeaways:

- `ask-socket` is fastest and fully fair (100/100), with the same 6.0 MB memory delta as `curl-multi`.
- `guzzle` is a close second (~8% slower) but uses 1.33× the memory of the other two.
- `curl-multi` is the slowest here (~42% behind `ask-socket`) and slightly unfair (98/100);
  wins on localhost are dominated by connection setup, which real-world latency hides.
