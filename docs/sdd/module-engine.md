# Module Engine — SDD

> Single bootstrap authority for feature modules. Host lists only non-module providers; everything module-shaped is declared, not coded, in `config/tg_modules.php`.

## Model (verified 2026-09-17)

- **Registry:** `config/tg_modules.php` maps descriptor id → `TgModuleConfig` (enabled, provider, laravelProvider, seeders, commands, schedule, routes, httpRoutes, routeMiddleware, exceptionRenderables, frontendPages, pageGenerators, settingsScreens). Key MUST equal descriptor id. `strict=true` fails boot on invalid entry; default collects errors.
- **Boot takeover (phase 3):** engine registers each enabled module's Laravel provider in dependency order; `bootstrap/providers.php` holds only non-module packages.
- **Activation:** platform `enabled` ≠ per-bot enabled; `bot_module_activations` (driver `engine`) or legacy `tg_module_enablements` (driver `legacy`) chosen by `enablement_driver`; chat→bot→platform inheritance for enablement + settings.
- **Declarative surfaces:** routes (command RouteDeclaration table → flat registry, per-bot dispatch), commands, schedule (`TgModuleSchedule` + schedule-overrides), httpRoutes, middleware aliases, frontendPages, pageGenerators, settingsScreens (`SettingsScreenContribution` + `WebScreenBinding`).
- **Diagnostics:** `tg:modules:list|validate|diagnose`, drift checks `tg:modules:routes:*`.
- **Ui menu-dependency rule (2026-10-09):** registry build emits `MISSING_MENU_DEPENDENCY` when a Ui-capable descriptor omits `requiresModules menu` — descriptor id `menu` exempt (the boot warning mirrors the same exemption); stock config live probe: `tg:modules:validate` exit 0, no boot warning.

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

## DeclaredCommandInspector (W-D1, 2026-10-09)

- `tg:modules:validate` gained a duplicate-Telegram-command rule (Q5 edge 11): a static `DeclaredCommandInspector` alongside `CommandSignatureInspector`, wired in `TgModulesValidateCommand`'s rule-host region.
- Scope = cross-module duplicate declared `command` route keys at build time; the builder's same-`(type|key)` dedupe covers intra-declared rows, the inspector adds the cross-module flat-name view. Normalizes case, leading slash, `@bot` suffix, and the `command`/`telegram.command` alias pair. Error code `DUPLICATE_COMMAND_KEY`.
- Hard constraint: must start green on HEAD (K24 — day-one red in a blocking step is a wrong-reason red). Runtime flat-registry visibility (post-`support()` strings) stays out of scope — the flat `/report` dup was removed by B4b's `/netreport` rename, not by this rule.
- Evidence: `DeclaredCommandInspectorTest` (6) + `TgModulesValidateDuplicateCommandTest` (2); `php artisan tg:modules:validate` exit 0 on HEAD. `TgModulesDoctorCommand` untouched (2 phpstan-baseline entries).

## CallbackPrefixInspector (W-Q2, 2026-10-10)

- `tg:modules:validate` gained a callback-namespacing rule (Q2 option 2): `CallbackPrefixInspector` grammar over `RouteDeclaration('callback', …)` rows only — key must be `<module>:<action>`. Validation-only: no runtime change, no grammar migration.
- Grandfather list `GRANDFATHERED = ['wsi','wsa','sm','tv','tc','nt:','m:']` (7 legacy namespaces, owner/audit note cites each source constant; compliant baseline `antispam:captcha:`). Non-conforming declarations fail; grandfathered tokens pass; the list doubles as the migration worklist.
- Enforcement posture (Q7 option 2 + wiring (b)): shipped report-only first (findings in `$warnings`, exit path untouched), then **flipped to blocking 2026-10-11** — the warn-mode merge was observed green in CI, findings now enter `$errors` in `TgModulesValidateCommand` and the post-flip test carries the exit-1 body. Venue: W-D1's repaired `module-validation.yml` validate step (R3), never `bin/baseline-check`.
- "Blocking" = red job on path-matching runs only (repo rulesets `[]`, `main` unprotected); a required-check ruleset is the operational follow-up.
- Day-one green by construction: 0 `type:'callback'` rows exist today; runtime `support()` strings stay outside the rule's sight (no callback inventory). Proxy's emitted-but-unregistered `wizard:*` payloads are invisible to a declaration-scoped rule.
- Evidence: `CallbackPrefixInspectorTest` (4) + `TgModulesValidateCallbackPrefixTest` (2 passed, no skip); `php artisan tg:modules:validate` exit 0 on HEAD.

### Warn-mode semantics (red job ≠ merge gate) — SUPERSEDED by the 2026-10-11 flip

- Warn mode (pre-flip): `CALLBACK_PREFIX_INVALID` findings printed via the warn channel and never reached `$errors`, so `tg:modules:validate` kept exit 0; a red `module-validation.yml` job from this rule alone was advisory and must not have blocked merge.
- Flip precondition (first CI-green warn-mode run) was met 2026-10-11 (`Module Validation` green on `261db42`); flip mechanics executed same day: `$warnings` findings moved into `$errors` (`TgModulesValidateCommand`) and `TgModulesValidateCallbackPrefixTest::testNonConformingDeclarationFailsValidationAfterTheBlockingFlip` unskipped with the exit-1 body.
- After the flip the same red job is a genuine blocking signal; this note is historical.
