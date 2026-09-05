# Module Engine Architecture — Consolidated Plan

Result of the 2026-08-27/28 review: the original 101-file ChatGPT plan was
deduplicated (6 rewrite passes over ~7 topics), off-topic material removed,
and every recorded decision lives in `docs/tasks/module-engine/` (platform repo).

## Document map (10 canonical docs, optimized 2026-08-28)

| File | Topic | Absorbed |
|---|---|---|
| `00-overview.md` | Master overview: goals, model, invariants | 01 |
| `02.md` | Domain model + Definition DTO & contracts | 42 |
| `08.md` | Laravel bootstrap & resource registration | — |
| `12.md` | Registry, resolver API & runtime contracts | — |
| `16.md` | Capabilities, dependencies & provider binding | 30, 36, 48, 55, 59 |
| `32.md` | Configuration model, `config/tg_modules.php` | 04, 10, 15, 19, 22, 27, 46 |
| `33.md` | Contributions & resources: taxonomy, scopes, registry, lifecycle | 13, 26, 29, 50, 56, 61 |
| `38.md` | Tenancy & runtime context: scopes, bindings, snapshots | 24, 31, 35, 39, 43, 47, 51 |
| `40.md` | Lifecycle: state machine & operations | 07–21, 25, 28, 34, 37, 44, 49, 53 |
| `41.md` | Discovery, manifest, registration | 05, 18, 23, 54 |
| `06-integration-and-cutover.md` | Existing-platform integration (`TgModuleContract` inventory, wiring absorption) & 5-phase cutover | — |
| `07-mvp-roadmap.md` | Package skeleton, phased implementation plan with test budgets | — |
| `EXTRACTED-IDEAS.md` | One-line ideas salvaged from deleted off-topic files | — |

## Binding decisions (see `docs/tasks/module-engine/` in the platform repo)

1. **01 — no reconciliation engine**: apply-on-activation + idempotency +
   computed effective state; drift is diagnostics' job.
2. **02 — access control is a separate module** (`telegram-bot-lib-access`):
   engine only contributes permission definitions.
3. **03 — settings screens as contributions**: PHP-DTO descriptor default +
   custom screen escape hatch; web rendering in Management, Telegram rendering
   in Menu; settings scopes platform → bot → chat.
4. **04 — audit is a separate module** (`telegram-platform-audit`); modules
   never talk to each other; libs talk via composer dependencies.
5. **05 — registry & routing table in PostgreSQL**; engine is a dumb
   dispatcher proxy in the request path.

## Engine boundaries (invariants added during review)

- Engine knows **no Telegram transport** (no HTTP, no Bot API calls). The only
  sanctioned Telegram knowledge is the typed external-key vocabulary
  (`telegram.command|callback|http.route|menu_item|webhook.endpoint`) needed
  for dispatch-key collision detection.
- Modules never communicate with each other; they are independent and
  non-hierarchical.
- PostgreSQL is the single source of truth for registry/activations/routing;
  any cache is derived, never a second truth.
