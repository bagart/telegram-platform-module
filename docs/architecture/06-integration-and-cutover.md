# 06 — Integration with the Existing Platform & Cutover Plan

> Companion to the 10 canonical docs in this directory. This document grounds
> the module-engine architecture in the platform as it exists today
> (verified 2026-08-28) and defines the migration path.

---

## 1. Current state (inventory)

### 1.1 Module entry point — `TgModuleContract` (telegram-bot-lib)

`misc/BAGArt/telegram-bot-lib/src/Modules/TgModuleContract.php`:

```php
interface TgModuleContract
{
    /** Pure metadata: id, version, name, dependencies, capabilities. No side effects. */
    public static function descriptor(): TgModuleDescriptor;

    /** Declares components into registries exposed by $registrar. Once per boot. Idempotent. */
    public static function register(TgModuleRegistrar $registrar): void;
}
```

The contract is already engine-shaped: static entry point, pure descriptor,
declarative registration, no stateful module instance. **Decision: the engine
builds ON this contract, it does not replace it.** `TgModuleContract` remains
the module-author-facing API; the engine becomes the orchestrator behind
`TgModuleRegistrar`/`ModuleBootloader` (registry, activation, config, routing).

### 1.2 Current wiring (what the engine must absorb)

| Today | Where | Fate |
|---|---|---|
| `TgModulesDiscovery::discover()` scans local `modules/<Name>/config.php` | `config/telegram.php` `'modules'` | Replaced by engine discovery (41) + `config/tg_modules.php` policy (32) |
| `'modules_providers' => []` — module packages self-register provider classes | `config/telegram.php` | Superseded: modules listed in `tg_modules.php`; `bootstrap/providers.php` keeps only the engine provider (phase 3) |
| `'modules_seeders' => []` + `foreach` in `DatabaseSeeder` | `config/telegram.php`, `database/seeders/DatabaseSeeder.php` | Replaced by typed INSTALL/DEFAULT/DEMO seeders via engine (33 §4) |
| `'modules_frontend_pages'` + `modules:pages` generator | `config/telegram.php` | Replaced by frontend contributions (33 §4) |
| 11 explicit providers in `bootstrap/providers.php` (lib, basic, management, antispam, summarizer, nettools, stt, tts, mafia, menu, proxy-operations) | `bootstrap/providers.php` | Phase 3: only lib + engine + management remain; module providers are booted by the engine in dependency order |
| Webhooks: `POST /tg/`, `POST /tg/webhook/{bot_id}` → `TgWebhookController` | `routes/web.php` | Unchanged (transport layer); the engine is consulted only for the routing-table lookup |

### 1.3 Existing modules to migrate

antispam, summarizer, nettools, stt, tts, mafia, menu, proxy-operations,
(plus basic-lib as example module). All already implement `TgModuleContract`,
which makes migration per-module cheap: add a Definition + `tg_modules.php`
entry, move scattered self-registration into the Definition.

---

## 2. Target integration model

```
bootstrap/providers.php
    └── TelegramModuleEngineServiceProvider   ← the ONLY new required provider
            └── reads config/tg_modules.php    ← policy manifest (32)
            └── discovers module packages      ← via TgModuleContract::descriptor() (41)
            └── builds registry & routing table (PostgreSQL-backed) (12, 33)
            └── boots module providers in dependency order (08)
```

- Module Laravel ServiceProviders stop self-registering resources; they keep
  only container bindings. The engine drives registration via
  `TgModuleContract::register(TgModuleRegistrar)`.
- `telegram.modules_*` config keys are deleted once the engine owns their
  concern; `config/telegram.php` keeps only transport concerns.
- Per-bot activation (`bot_module_activations`, routing table) lives in
  PostgreSQL owned by the engine (decision 05).

---

## 3. Cutover phases (each phase ships independently, platform stays green)

### Phase 0 — Engine skeleton (no behavior change)
- Package layout per platform module rules (`misc/BAGArt/telegram-platform-module/`,
  dev-mode PSR-4 mapping, dual manifests, own Pest suite + host testsuite entry).
- Engine provider registered but boots as a no-op observer: it logs what it
  *would* take over. `cmd/deps/check` parity.

### Phase 1 — Registry & config (replace discovery + foreach seeders)
- `config/tg_modules.php` introduced; all 9 modules listed (enabled states
  copied from current behavior — nothing turns off).
- Engine takes over `'modules'` discovery and `'modules_seeders'`;
  legacy keys become aliases (deprecated, log on use), then are deleted.
- Acceptance: `tg:modules:validate` green; seeds produce identical rows;
  no module-specific code in host.

### Phase 2 — Activation & routing table
- `bot_module_activations` + routing table migrated to PostgreSQL; per-bot
  enable/disable via engine API; webhook/command dispatch consults the
  engine lookup (dumb proxy, decision 05).
- Existing implicit "enabled everywhere" behavior seeded as bot activations
  so runtime behavior is unchanged.

### Phase 3 — Bootstrap takeover
- Module providers removed from `bootstrap/providers.php`; engine boots them
  in dependency order (08). `telegram.modules_*` keys deleted.
- Acceptance: platform boots with zero module providers in the host;
  adding a module = composer require + `tg_modules.php` line (overview §77).

### Phase 4 — Contributions deepening (settings screens, access, audit)
- Per decisions 02/03/04 and the Management/menu task stubs. Each module
  adopts the descriptor/settings contribution at its own pace; modules with
  custom admin panels keep them until their screens migrate.

## 4. Rollback & safety

- Every phase is a separate PR-sized change; legacy aliases in phase 1 give
  an instant revert path (re-enable old config keys).
- Phase 2 seeds, never deletes: activation rows are additive; disabling the
  engine falls back to "all discovered modules active" (legacy semantics).
- No module data is touched by the cutover (invariant I-11: disable ≠ delete).

## 5. Explicitly out of scope for the cutover

- Rewriting module internals; UI redesigns; permission data migration
  (access module starts fresh — current ad-hoc `isAdmin()` gates are replaced
  per-module during phase 4 adoption, not by a data migration).
