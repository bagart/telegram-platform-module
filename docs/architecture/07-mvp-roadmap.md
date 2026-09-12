# 07 — MVP Roadmap & Package Skeleton

> Turns the 10 canonical architecture docs into an ordered, reviewable
> implementation sequence. Reasonable-sufficiency rule: each phase is the
> smallest slice that delivers user-visible value and is fully tested.
> Phase ↔ architecture-doc mapping is given so no doc is orphaned.

> **Status (2026-08-28): phases 0–6 implemented.**
> - Phase 0/1: package skeleton, registry, `tg:modules:list|validate`,
>   boot takeover (lib steps aside when `config('tg_modules')` present),
>   declarative seeders takeover of `DatabaseSeeder` (legacy
>   `telegram.modules*` keys consumed as deprecated aliases).
> - Phase 2: `src/Activation` (idempotent lifecycle, optimistic locking,
>   structured blockers), `src/Tenancy` (ScopeLevel, BotContext),
>   `src/Routing` (RouteResolver contract + PG resolver), migration
>   `src/Persistence/2026_08_27_000001_create_bot_module_tables.php`
>   (bot_module_activations + bot_module_routes). Negative tenant-scoping
>   covered by tests.
> - Phase 3: bootstrap takeover — `ProviderSequence` registers each
>   enabled module's `laravelProvider` (TgModuleConfig) in dependency
>   order; `bootstrap/providers.php` shrinks to lib + engine + basic-lib +
>   management + proxy-operations; architectural test guards it.
> - Phase 4: `src/Capabilities` (exclusive-capability conflicts,
>   static/effective graphs, structured BlockReason, reduced mode).
> - Phase 5: separate packages `telegram-platform-access`
>   (BAGArt\TelegramBotAccess) and `telegram-platform-audit`
>   (BAGArt\TelegramBotAudit) — pure-domain scaffolds, wired dev-mode.
> - Phase 6: `tg:modules:diagnose [--bot=] [--format=json]` +
>   `src/Diagnostics` (pure presenter, EngineMetrics sink, unavailable
>   degradation). Deferred: route-table writers beyond the declarative
>   sync, descriptor `requiresCapabilities`.
>
> **Follow-up round (2026-08-28, post-MVP):**
> - Control path CLI: `tg:modules:enable|disable <bot> <module> [--revision=]`
>   (exit 5 on blocker/conflict) and `tg:modules:routes:sync <bot>`.
> - `RouteTableSync` writer: materializes declarative
>   `TgModuleConfig::$routes` (`RouteDeclaration`) into `bot_module_routes`
>   per active bot, idempotent, removes stale rows of no-longer-active modules.
> - Dispatch integration: `EngineModuleEnablement` implements the lib
>   `ModuleEnablementContract` (memoized, `refresh()` per bot/chat);
>   `tg_modules.enablement_driver` selects 'legacy' (management service,
>   default) or 'engine' — the dumb-proxy decision is now a config flip.
> - Semantics fix: disabling a default-enabled module with no binding now
>   persists an explicit DISABLED row (overrides the descriptor default);
>   default-disabled modules stay no-op (doc 40 §15).
> - Antispam seeder migrated to the declarative `TgModuleConfig::$seeders`
>   (legacy `telegram.modules_seeders` push removed from its provider).
>
> **Legacy source retirement (2026-08-29):**
> - `config/telegram.php` no longer defines `modules`, `modules_providers`
>   or `modules_seeders`; `App\Config\TgModulesDiscovery` deleted; the
>   Example fixture module is declared in `config/tg_modules.php`. Module
>   providers no longer self-register into the legacy keys — the deprecated
>   alias consumption in the engine/lib stays for external consumers only.
> - `EngineMetrics` is wired: activation denials/conflicts counters
>   (ModuleActivationService) and routing lookup latency (PgRouteResolver);
>   surfaced via `tg:modules:diagnose`.
> - First real declarative routes: menu module contributes
>   `RouteDeclaration('command', '/menu')` and the live control path
>   (enable → routes:sync → diagnose) is verified against Postgres.
> - Note: the menu module provider was rebuilt after an accidental
>   truncation; tgapp route loading, §27.8 throttle buckets and the §13.4
>   429 envelope renderable were re-derived from the module's own tests
>   (menu suite 295 green). Diff it carefully during review.
>
> **Deferred-items closure (2026-08-30):**
> - `TgModuleDescriptor::$requiresCapabilities` (lib, list<string>, mandatory
>   requirements) added; `EffectiveDependencyResolver` merges them with the
>   explicit resolver input (which keeps optional requirements) — the input
>   map is no longer the only source of capability requirements.
> - Drift detection shipped: `RouteTableSync::diffForBot()` +
>   `tg:modules:routes:check <bot>` (exit 5 on drift; sync repairs).
> - Config publisher: `vendor:publish --tag=tg-modules-config` ships the
>   package stub (host config is canonical and skipped on publish).
>
> **Artisan command passthrough (2026-08-30):**
> - `TgModuleConfig`/`TgModuleDefinition` gain `commands`
>   (list<class-string<Command>>): declarative Artisan command declarations,
>   registered by the engine for platform-enabled modules (replaces
>   provider-level `->commands()` pushes; tts + summarizer migrated).
> - Builder validates each class (exists, extends Illuminate\Console\Command;
>   COMMAND_MISSING / COMMAND_NOT_COMMAND, module skipped like provider
>   errors); `CommandSignatureInspector` detects duplicate `$signature`s
>   across enabled modules (COMMAND_SIGNATURE_COLLISION: strict boot fails,
>   degraded logs, `tg:modules:validate` exits non-zero).
> - `tg:modules:list` and `tg:modules:diagnose` show a Commands column.
>
> **Declarative host-integration contributions (2026-08-31):**
> - `TgModuleConfig` gains `schedule` (TgModuleSchedule entries),
>   `httpRoutes`, `routeMiddleware`, `exceptionRenderables`,
>   `frontendPages`, `pageGenerators` — declarative replacements for the
>   legacy per-module Config::set side-channels (telegram.modules_schedule,
>   modules_frontend_pages, modules_page_generators) and the providers' own
>   loadRoutesFrom() / aliasMiddleware() / renderable() registrations.
> - Engine wiring: `registerDeclarations()` loads route files, aliases
>   middleware, registers container-resolved renderable classes; the
>   engine is the SOLE producer of the legacy interchange keys
>   (modules_frontend_pages / modules_page_generators) consumed by the
>   host `modules:pages` and menu `menu:pages` shims.
> - `Schedule\ModuleScheduleRegistrar` registers module cron entries with
>   schedule-overrides.php user overrides; a static `enabled=false` entry
>   is not scheduled at all (registry rebuilds on process restart).
>   Supersedes App\Console\ModuleTaskScheduler (kept as deprecated alias).
> - All six in-tree modules migrated (antispam, mafia, menu, stt,
>   summarizer, tts): no provider-side side-channel pushes remain.
> - Open caveat: `httpRoutes`/`frontendPages` are absolute host paths —
>   prod-mode (vendor-installed modules) path resolution needs a
>   descriptor-provided source before prod rollout.
>
> **Dispatch integration (2026-08-27):**
> - Live command dispatch now consumes the routing table: lib gains
>   `CommandRouteContract` (`Contracts/Modules/`); `RegisteredUpdateProcessorSelector`
>   consults it (container-bound, same guarded pattern as enablement)
>   before the flat command registry; an unusable route (missing class,
>   processor `support()` rejecting the DTO) falls back transparently.
> - Engine side: `Routing\CommandRouteLookup` (memoized per bot, command
>   => processor from `payload.processor`; collisions resolve by
>   priority desc then module id asc; `refresh(?botId)` invalidates).
> - Host config declares routes for every command module (antispam owns
>   `/report`; nettools' report probe stays flat-registry-only).
> - Fixed `tg:modules:doctor` to accept the descriptor contract shape
>   `requiresModules: id => constraint` (map) alongside the legacy list
>   form, and to treat the `*` constraint as always satisfied.
> Remaining known follow-up (post-MVP, by design): flip
> `enablement_driver` to 'engine' — a product decision because it switches
> the enablement source of truth (engine activation rows ignore legacy
> per-bot disables until rows are migrated).

---

## 1. Package skeleton

```
misc/BAGArt/telegram-platform-module/
├── composer.json / composer.prod.json     # dual manifests (platform rule)
├── phpunit.xml.dist + composer test       # own Pest suite
├── src/
│   ├── TelegramModuleEngineServiceProvider.php
│   ├── Definition/     # TgModuleDefinition DTOs, compatibility (02)
│   ├── Config/         # TelegramModuleConfig, platform/bot/chat DTOs (32)
│   ├── Registry/       # discovery, registry builder, validation (41, 12)
│   ├── Activation/     # lifecycle state machine, provisioning (40)
│   ├── Routing/        # routing table + RouteResolver (12, 33)
│   ├── Tenancy/        # scope model, contexts, snapshots (38)
│   ├── Capabilities/   # capability registry, dependency graphs (16)
│   ├── Persistence/    # migrations for engine-owned tables (PG)
│   └── Console/        # tg:modules:validate|list|diagnose (12, 33)
├── config/tg_modules.php                 # published stub
└── tests/Pest.php
```

- Host wiring: PSR-4 dev mapping + path repo; prod overlay in
  `composer.prod.json`; provider in `bootstrap/providers.php` (phase 0 only —
  removed again in phase 3 when the engine boots module providers itself).
- Regenerate baselines after any composer change
  (`tools/baseline/composer-policy.php --check=scripts --update`,
  `tools/baseline/manifest.php --generate`).

---

## 2. Phases

### Phase 0 — Skeleton + no-op boot (≈1 PR)
- Package + provider + config publisher + `tg:modules:list` printing a
  hard-coded empty set. No behavior change.
- **Test budget**: provider boots, config publishes, command exits 0.
- Docs used: this file.

### Phase 1 — Registry, Definition, config (value: kill the foreach)
- `TgModuleDefinition` DTO + discovery via `TgModuleContract::descriptor()`
  (41); `config/tg_modules.php` policy + strict mode (32); registry builder
  with duplicate-ID/dependency validation (12); take over
  `telegram.modules` + `modules_seeders` with deprecated aliases (06 §3).
- **Tests**: duplicate module ID rejected; unknown config entry rejected in
  strict mode; definition with side effects rejected; seed parity with
  legacy foreach (identical rows); seeder executed exactly once (idempotency).
- Docs: 02, 32, 41, 12 (registry part), 06.

### Phase 2 — Tenancy: bot activation + routing table (value: per-bot skills)
- `bot_module_activations` migration + lifecycle ops enable/disable with
  validation, idempotency, optimistic locking (40); scope model +
  `BotContext` (38); routing table in PG + `RouteResolver` strict contract
  (33, 12); webhook/command path consults the resolver (dumb proxy).
- **Tests**: enable/disable idempotent; disabled module absent from routing
  table; required-dependency blocked enable returns structured reason;
  **negative tenant scoping** (bot A activation invisible to bot B —
  platform-mandatory); concurrent enable via expectedRevision →
  CONCURRENT_MODIFICATION; legacy "enabled everywhere" seed parity.
- Docs: 38, 40, 33 (routing), 12.

### Phase 3 — Bootstrap takeover (value: add-a-module = 1 config line)
- Engine boots module providers in dependency order (08); host
  `bootstrap/providers.php` shrinks to lib + engine + management;
  `telegram.modules_*` keys deleted (06 §3).
- **Tests**: boot order respects dependency graph; broken OPTIONAL module →
  boot survives, module INVALID; broken REQUIRED_PLATFORM → boot fails;
  architectural test "host contains no module class references".
- Docs: 08, 41, 06.

### Phase 4 — Capabilities & dependencies (value: safe composition)
- Capability registry, three typed dependency contracts, static/effective
  graphs, provider binding, "blocked because" reasons (16).
- **Tests**: two modules claiming one exclusive capability → registration
  error; capability availability computed per-bot (not stored); optional
  capability missing → module runs in reduced mode.
- Docs: 16, 12.

### Phase 5 — Ecosystem contracts (separate modules, own repos)
- Per recorded decisions — each is an independent module, NOT engine code:
  - `telegram-platform-access` (task 02): AccessControlContract, ChatRole,
    Grant, AccessDecision; menu task 08-roles-grants resolves overlap here.
  - `telegram-platform-audit` (task 04): AuditSink append-only.
  - Settings screens (task 03): engine-side descriptor contract + storage;
    generic renderers in Management (web) and Menu (telegram, task 25 stub —
    requires menu RFC extension first).
- **Tests**: per module, negative tenant scoping included.
- Docs: 02 (definitions), 33 (contributions), platform repo docs/.

### Phase 6 — Diagnostics & hardening
- `tg:modules:validate|list|diagnose|bot <id>` full surface, JSON output,
  fail-fast vs degraded reporting (32); engine observability (activation
  denial counter, lookup latency); drift detection replaces reconcile ideas.
- Docs: 12, 32, 33 (diagnostics), EXTRACTED-IDEAS.

---

## 3. Cross-phase rules

- `composer test` (module suite + host testsuite chain) mandatory before
  delivery; Pint `--dirty` on every PHP-touching PR.
- Every phase ends green on `cmd/dev/check`; no phase ships with disabled
  controls.
- LF endings; English artifacts; docs updated in the same PR as behavior.
- Anything not in phases 0–6 does not exist yet — resist adding it
  (karpathy rule: no speculative machinery).
