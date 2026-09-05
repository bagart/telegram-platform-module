# Extracted Ideas from Off-Topic Plan Files

> One-line ideas salvaged from the off-topic files (64–100) that were removed from the
> Module Engine architecture plan. These are platform-level or cross-cutting insights
> worth keeping, even though the files themselves did not belong to the engine plan.

## Security & Trust

- Treat even authenticated-API responses (Telegram payloads included) as untrusted input; validate by schema before use. (77, 81, 100)
- Treat config/module provenance as untrusted input validated by schema; fail on unknown keys in production. (81, 83)
- Module-contributed routes should inherit a mandatory platform middleware/policy stack instead of defining their own — prevents modules bypassing platform auth. (94)
- Fail-closed security policy objects with per-decision audit records → an "activation guard" that logs every activation denial. (81)
- "Internal ≠ trusted": service-to-service (engine↔module runtime API) calls still need authorization at the boundary. (86)
- Correlation-ID propagation through per-bot request context. (81)

## Lifecycle & Operations

- Distinguish "operation status" from "resource/module state" — an operation can time out while the resource is fine. (64)
- Journal the intent durably *before* performing an irreversible action (write-ahead pattern) — applies to uninstall/migration steps. (64, 68)
- Persisted desired state is intent, not a command — after deploy/crash, run idempotent reconciliation rather than trusting a stored "running" flag. (44/53)
- A broken optional module must be state INVALID without failing platform boot; only explicitly allowlisted REQUIRED_PLATFORM modules may fail boot. (54)
- On uninstall, tombstone module-owned data instead of hard delete, then reconcile in a sweep; don't auto-delete orphans (may be evidence). (84)
- Deterministic resource IDs + generation counters + fencing tokens prevent stale delayed workers resurrecting removed resources. (60)

## Configuration

- Per-key override strategies (REPLACE/MERGE/APPEND) instead of one global merge rule. (57)
- Patch semantics must distinguish omitted / null / empty values; validate patches atomically. (57)
- Optimistic concurrency on config edits: expected_revision check so one operator doesn't overwrite another's change. (57, 83)
- Immutable ConfigurationSnapshot for in-flight jobs so a config change mid-flight doesn't tear state. (57)
- Config change audit trail as a first-class feature, not an afterthought. (83)

## Jobs & Queues

- Tag every queued job with its owning module so uninstall can cancel/quarantine that module's pending jobs. (93)
- Per-tenant (per-bot) queue fairness quota so one bot/module cannot starve the worker pool. (65, 93)

## Observability & Audit

- Audit events for enable/disable/uninstall as an immutable append-only event history, not mutable rows. (89)
- Separate audit/evidence tables from operational state tables. (78)
- Goal: every lifecycle operation traceable from request to outcome without enabling debug mode. (82)
- Engine CLI should emit versioned, machine-readable JSON alongside text output for tooling. (86)

## Data & Errors

- Strict DTO hygiene at boundaries: no raw arrays, tolerate unknown fields for forward compatibility. (100)
- A shared failure taxonomy (retryable / non-retryable / fatal) that module exceptions must map into, to make engine diagnostics meaningful. (98)
- Registry state changes as transactional state machines with invariant checks. (92)

## Distributed Coordination (reference for async kernel)

- Clear conceptual distinction: lease vs lock vs fencing token. (69)
- Per-execution scoped service container (fresh Laravel scope per work item) is a clean worker pattern. (66)
- Weighted-fair scheduling with per-tenant and per-module buckets. (65)
- Single choke point for external side effects: all Telegram API mutations by modules should pass through one audited boundary. (67)

## From Kept-but-Duplicated Files (unique deltas that must survive any dedup)

- Separate ResourceIdentity from external BindingKey (e.g. `telegram.command:/start`) so duplicate external dispatch keys are detected even when resource IDs differ; global middleware is a privileged resource type requiring explicit capability. (56)
- Three dependency types must never be conflated: module dependency / capability dependency / infrastructure requirement; one static graph + per-scope effective graph instead of per-bot graphs. (55)
- Discovery registry (all installed modules) vs per-bot active runtime registry are different things; disabled modules register no runtime handlers. (47)
- Path-traversal protection for manifest-declared resource paths. (54)
- Config file cannot swap classes/entry points — config is a security boundary, not a code loader. (19)
- Capability availability is computed per-bot, not stored per-bot. (30/36)
