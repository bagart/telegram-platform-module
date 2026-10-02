# Module Engine — SDD

> Single bootstrap authority for feature modules. Host lists only non-module providers; everything module-shaped is declared, not coded, in `config/tg_modules.php`.

## Model (verified 2026-09-17)

- **Registry:** `config/tg_modules.php` maps descriptor id → `TgModuleConfig` (enabled, provider, laravelProvider, seeders, commands, schedule, routes, httpRoutes, routeMiddleware, exceptionRenderables, frontendPages, pageGenerators, settingsScreens). Key MUST equal descriptor id. `strict=true` fails boot on invalid entry; default collects errors.
- **Boot takeover (phase 3):** engine registers each enabled module's Laravel provider in dependency order; `bootstrap/providers.php` holds only non-module packages.
- **Activation:** platform `enabled` ≠ per-bot enabled; `bot_module_activations` (driver `engine`) or legacy `tg_module_enablements` (driver `legacy`) chosen by `enablement_driver`; chat→bot→platform inheritance for enablement + settings.
- **Declarative surfaces:** routes (command RouteDeclaration table → flat registry, per-bot dispatch), commands, schedule (`TgModuleSchedule` + schedule-overrides), httpRoutes, middleware aliases, frontendPages, pageGenerators, settingsScreens (`SettingsScreenContribution` + `WebScreenBinding`).
- **Diagnostics:** `tg:modules:list|validate|diagnose`, drift checks `tg:modules:routes:*`.

## Rules

No secrets in tg_modules.php. Module identity lives in descriptor(), policy lives in config. Adding a module = config entry + PSR-4 + prod manifest entry, not provider edits.

## Prod-Mode Path Resolution (2026-09-20)

Modules declare `sourcePath` (nullable string) on `TgModuleConfig`/`TgModuleDefinition`. `ModuleRegistryBuilder::resolvePaths()` resolves relative `httpRoutes`/`frontendPages` against `sourcePath` when available. Absolute paths pass through (backward compatible dev mode). Three modules configured: antispam, menu, proxy. 11 new tests in `ModuleSourcePathResolutionTest`.

## Engine Settings Adapter (2026-09-20)

`EngineSettingsAdapter` implements `ModuleSettingsContract`, reads from `bot_module_activations` via `DatabaseSettingsStorage`. Registered when `enablement_driver='engine'`; legacy `TgModuleEnablementService` bridge skipped in that mode. Chat-level scoping still blocks full retirement of `tg_module_enablements`.

## ModuleActivationWriterContract (2026-09-23)

- **New interface** `Activation\ModuleActivationWriterContract::setEnabled(string $moduleId, ?string $botId, bool $enabled, ?actorId = null, ?actorType = null)` — mockable write-side seam for engine activation.
- **`ModuleActivationService` implements it** — `setEnabled` delegates to existing `enable()`/`disable()`; null `botId` is a no-op.
- **Consumer** — menu `EngineSettingsWriter` type-hints the contract (not the final service) and forwards actor fields; enables unit tests without mocking final classes.
- **Rule:** write-side engine activation goes through the contract; read-side stays on `ModuleActivationReader`.
