# Cross-Project Instruction Inbox

This file records the review and implementation status of instructions received from other repositories, project tasks, agents, or automations. Every entry remains subject to the cross-project review procedure in `AGENTS.md`.

## Intake procedure

1. Record the source and requested outcome before making changes.
2. Identify the Worker files, database contracts, runtime services, and accepted decisions it could affect.
3. Compare the request with `AGENTS.md`, applicable `.ai/rules`, architecture decisions, installed package versions, the current implementation, and the live schema where relevant.
4. Record the reviewed scope, compatibility conclusion, implementation state, and any conflicts or missing information.
5. Implement compatible, sufficiently specified requests and verify them normally. Leave incompatible or incomplete requests pending with explicit acceptance conditions.
6. Re-evaluate revised requests from the beginning.

## Entries

### 2026-09-03 — MyCleture localized identity-mail contract

- Source: cleture-netzero-my project task `01a06613-d2e2-7671-ab57-f224b3ace399`.
- Requested outcome: Accept identity-mail payload version 2 with `{organizationUserId, locale}`, preserve version 1 fallback behavior, and add Turkish/English mail rendering.
- Affected areas: processing-task contract registry and definitions, identity jobs, mail templates/translations, and processing/job/mail tests.
- Known conflicts: The original request arrived while canonical lifecycle remediation was in progress. The owner has now explicitly approved a backward-compatible implementation: v1 remains ID-only with Turkish fallback, while v2 carries exactly `{organizationUserId, locale}` and keeps the canonical lifecycle.
- Current state: The project owner approved Worker locale-v2 development in the active Worker conversation while MyCleture producer work proceeds separately. Worker now supports v1 ID-only tasks with the Turkish fallback and v2 tasks carrying exactly `{organizationUserId, locale}` for `tr` or `en`. The newly added or changed contract, localization, failure-snapshot, and locale-isolation tests pass with 11 scenarios and 38 assertions. MyCleture must not deploy v2 production until the updated Worker is deployed and restarted.
- Status: Approved, implemented, and focused verification passed.

### 2026-09-03 — MyCleture identity-mail Worker instruction

- Source: cleture-netzero-my project task / delegated Worker work.
- Requested outcome: Add processing-task consumption and identity verification/password-reset mail jobs to the Worker.
- Affected areas: processing-task lifecycle, database queue handoff, retry/failure ownership, mail jobs, scheduler and runtime services.
- Conflict found: The resulting direct `pending -> processing -> queue -> delete` handoff conflicts with NetZeroAdmin ADR-045's `pending -> queued -> processing` lifecycle, dispatch-token fencing, and canonical domain retry ownership.
- Current state: The instruction was implemented before this quarantine procedure existed. Its reusable mail and runtime parts require review; its processing-task lifecycle must not be treated as the Worker baseline.
- Status: Pending owner review and remediation decision.

### 2026-09-03 — Password-recovery security follow-up

- Source: Active cleture-netzero-my security-remediation task requested by the project owner.
- Requested outcome: Before creating a password-reset token, enforce the configured broker cooldown with `recentlyCreatedToken`; add a supported, secret-free `organization-user.password-changed` processing-task contract and localized confirmation mail after a successful reset.
- Affected areas: `app/Jobs/Identity/SendOrganizationUserPasswordReset.php`, identity-mail contract definitions and registry, a new password-changed job/mailable/templates, and their job/mail/processing-task tests.
- Known conflicts: The existing identity rule still applies: tokens and reset links must never enter durable task payloads, failure snapshots, or logs. NetZeroAdmin has no persistent Organization User locale field, so per-user Turkish/English selection cannot be added without separately approving a locale source or contract change; the current Turkish fallback remains authoritative.
- Current state: The project owner approved this entry in the active Worker conversation. Cooldown enforcement and the v1 password-changed Worker contract are implemented. Empty safe failure payloads are stored as JSON objects, the PHPUnit result-cache permission issue is resolved, the focused suite passes with 34 tests and 173 assertions, and the full suite passes with 36 tests and 175 assertions. The current Turkish fallback remains in place; per-user locale selection still belongs to the separately pending locale-contract decision.
- Status: Approved by project owner, implemented, and verified. MyCleture may implement its producer change after the updated Worker is deployed.

### 2026-09-04 — stancl/tenancy dependency installation

- Source: Delegated Worker task from cleture-netzero task `01a06b1f-a669-7283-99c8-d1d3b0405ad9`, with explicit project-owner approval to add the dependency in this repository.
- Requested outcome: Verify and pin a stable `stancl/tenancy` release compatible with the Worker's PHP and Laravel versions, install it through the configured Herd Lite platform runner, and verify package discovery without provisioning tenants.
- Reviewed scope: `AGENTS.md`, `.ai/rules`, the existing processing-task and tenant-ID references, installed PHP/Laravel/package versions, Composer dependency resolution, official package metadata/documentation, `composer.json`, `composer.lock`, and Laravel package discovery.
- Compatibility conclusion: `stancl/tenancy` v3.10.1 supports PHP `^8.0` and Illuminate `^10|^11|^12|^13`; it is compatible with PHP 8.5.0 and Laravel 13.30.1. No authoritative Worker rule conflicts with dependency-only installation.
- Current state: `stancl/tenancy` is pinned to `3.10.1`; its three new transitive packages are locked and installed; platform requirements, lock installability, package discovery, Artisan command registration, and security audit passed. The durable provisioning boundaries were recorded in `.ai/rules/tenancy.md`.
- Explicit exclusions: No tenancy install scaffold was published; no migration, tenant model/domain query, tenant database create/delete, tenant migration, seed/backfill, role/grant, processing-task lifecycle change, credential persistence, or database write was performed.
- Status: Approved by project owner, implemented, and focused verification passed. Provisioning integration remains a separate future Worker task.

### 2026-09-04 — Organization-owned tenant provisioning

- Source: Active cleture-netzero-my conversation, explicitly approved by the project owner after the stancl/tenancy dependency installation.
- Requested outcome: Consume secret-free `tenant.provision` v1 tasks created atomically with organization-owned tenants; provision a deterministic PostgreSQL database without Redis or dirty flags; expose readiness through central tenant state.
- Affected areas: Processing-task contract registry and lifecycle hooks, tenant model/context, provisioner/runtime database connections, tenant migrations, and provisioning tests.
- Compatibility conclusion: The request preserves the canonical pending/queued/processing task lifecycle, UUID tenant identity, database-queue transport, safe failure snapshots, deterministic database naming, and separate provisioner/runtime roles. The obsolete Customer registry is not part of the contract.
- Current state: Worker integration is implemented with `pending -> provisioning -> ready/failed` tenant transitions. Recoverable task failures retain `provisioning`; terminal and expired failures set non-ready tenants to `failed` and keep `active=false`. Provisioning migrations run through the distinct DDL-capable provisioner connection; the applied migration ledger must exactly match the repository's canonical tenant migration set. The runtime role receives DML/default privileges without database ownership, and readiness is verified through a bootstrapped runtime tenant connection that always restores central context. The first canonical tenant schema contains local `users` and an unbounded `organizational_units` tree; the legacy core's separate company/facility tables and triple pivot were reviewed but not copied. Tenant users do not persist email-verification or Laravel remember-me state; the tenant-facing application owns Redis-backed login, session, and access-token state. Existing ready tenants are upgraded separately from `processing_tasks` by the idempotent `tenant-schemas:upgrade` release command. The command selects outdated tenants in bounded chunks, uses global command isolation and per-tenant PostgreSQL advisory locks, runs pending migrations through the provisioner role, repairs runtime grants, verifies the canonical ledger through the runtime connection, updates only successfully verified `schema_version` values, continues across tenant failures, and returns a failing exit code when any tenant fails. Empty failure payloads remain JSON objects. The first real tenant was provisioned and verified through the runtime role. The command tests now execute before their side-effect assertions, and the complete Worker suite passes with 81 tests and 369 assertions.
- Status: Approved by project owner, implemented, runtime-verified, and automated verification passed.

### 2026-09-04 — Tenant provisioning queue runtime follow-up

- Source: Active cleture-netzero-my analysis, explicitly approved by the project owner in the current Worker conversation.
- Requested outcome: Make the platform queue worker consume both `identity-mails` and `tenant-provisioning`, raise its worker timeout to 300 seconds while remaining below the 330-second database queue retry window, recreate only the queue service, and verify the existing provisioning request without creating another request.
- Reviewed scope: Worker processing-task routing, queue retry configuration, transport job timeout, existing processing-task tests, the platform Worker Compose service, platform decision D-018, and the current runtime state of the existing tenant task.
- Compatibility conclusion: `tenant.provision` is already routed to `tenant-provisioning`; the transport job already has a 300-second timeout and `failOnTimeout=true`; 300 seconds remains below `retry_after=330`. The project owner's active instruction explicitly supersedes D-018's previous identity-mail-only queue scope.
- Current state: The Compose command and focused timeout/migration-order regression tests are updated, and the Compose schema is valid. Separate non-superuser `tenant_provisioner` and `tenant_runtime` PostgreSQL roles were configured with secret-backed Worker credentials; only the provisioner can create databases. Runtime verification exposed a Laravel Blueprint command-order defect in the self-referencing organizational-unit migration, so its primary key is now declared explicitly before the foreign key. The existing task then completed through the configured queue: the tenant is `ready`, active, and has a verified schema version; both the central task row and tenant-provisioning transport row were removed. The queue remains running, and no new provisioning request was created. The complete Worker suite passes with 81 tests and 369 assertions.
- Status: Approved, implemented, runtime-verified with the existing provisioning request, and automated verification passed.

### 2026-09-04 — Organization unit types tenant schema

- Source: Active cleture-netzero-my task delegated by the project owner, followed by an explicit instruction to make Worker the sole owner of the migration and remove the source migration from MyCleture.
- Requested outcome: Add a tenant-only `organization_unit_types` table and a nullable, indexed, delete-restricted type reference on `organizational_units`; preserve the existing company/facility structural flags and upgrade every existing ready tenant without data loss.
- Reviewed scope: Worker tenant migration ownership and ordering rules, the canonical migration path, schema-version calculation, isolated tenant upgrade command, source schema proposal, installed Laravel/PostgreSQL capabilities, and the live central/tenant schema.
- Compatibility conclusion: The change is compatible with the unbounded organizational-unit tree and does not alter central schema ownership. A nullable foreign key preserves existing rows. The Worker version adapts the proposal to canonical UUIDv7, normalized-name, and non-negative sort-order constraints, and remains separate from the company/facility structural flags.
- Current state: Worker now owns the Artisan-generated canonical migration. It creates UUIDv7-backed `organization_unit_types` with unique normalized names, active state, non-negative sort order, and timezone timestamps; it adds a nullable indexed UUID foreign key with restricted updates/deletes without changing the company/facility flags. The focused PostgreSQL migration-definition test passes with 2 tests and 14 assertions. The isolated release command upgraded tenant `01a06bc0-326a-700a-a2d7-ec6065b8f6d6`, preserving all 5 existing organizational-unit rows, and runtime/schema verification passed. The central database has neither the tenant table nor the tenant column. A second release-command run reported 0 upgrades required and 1 tenant already current.
- Status: Approved, implemented, focused verification passed, and every existing ready tenant was upgraded successfully.

### 2026-09-04 — Atlas–ADEME candidate-package ingest v1

- Source: NetZeroAdmin task `01a06b1f-a669-7283-99c8-d1d3b0405ad9`, delegated to this Worker with explicit project-owner approval for the first ingest slice and the S3 Flysystem adapter.
- Requested outcome: Register and consume the exact secret-free `emission.candidate.ingest@v1` task; stream the version-pinned S3-compatible artifact; fail closed on size, media type, checksum, manifest, archive, member digest, and NDJSON envelope defects while preserving the canonical processing-task claim, lease, retry, failure-snapshot, and dispatch-fencing lifecycle.
- Reviewed scope: Worker processing-task contracts and lifecycle, applicable shared rules, installed Laravel/Flysystem versions, Admin INT-001 task payload and central package/member schema, Admin manifest and candidate-staging contracts, and local object-storage/deployment boundaries. Live central-schema inspection was unavailable because the Boost process could not resolve the configured PostgreSQL host, so the accepted Admin migrations, models, actions, and tests were used as the schema authority.
- Compatibility conclusion: The v1 payload and read-only artifact verification fit the current Worker architecture without a migration. Laravel Filesystem provides a provider-neutral S3-compatible boundary for local MinIO and non-local Huawei OBS. Object-store transport failures remain retryable; immutable contract and integrity defects use the existing permanent-failure taxonomy. Credentials, buckets, policies, and runtime services remain deployment-owned.
- Current state: `league/flysystem-aws-s3-v3` is installed; `emission.candidate.ingest@v1` is registered on its own queue; storage endpoints and credentials are environment-driven; object reads are streamed and object-version pinned when supplied; the registered package/manifest/member envelope, archive allow-list, compressed and expanded sizes, artifact/member hashes, record counts, and bounded JSON-object lines are verified. Tests cover verification, checksum/manifest/member/schema failures, missing and transient object storage, unsupported versions, queue routing, stale dispatch fencing, and both versioned and unversioned storage reads. The complete Worker suite passes with 81 tests and 369 assertions.
- Explicit boundary: Admin's complete candidate validation and canonical bulk-write contract is not present in Worker and no additional ownership or dependency was approved. A fully verified artifact is therefore archived with the safe permanent code `candidate_staging_contract_requires_decision`, while the package remains `received`; Worker does not falsely mark it `staged` or invent canonical writes.
- Status: Approved and implemented through verified artifact staging boundary; automated verification passed. Deployment still must provide read-only object-store configuration and subscribe the runtime worker to `emission-candidate-ingest`.

### 2026-09-05 — Atlas main 35b82db / Admin V2 durable staging coordination

- Source: Atlas main commit `35b82db` follow-up delegated from project task `01a06d80-9ff9-76e0-987b-438ea57810bb` at the project owner's explicit request for record-only intake.
- Related entry: `2026-09-04 — Atlas–ADEME candidate-package ingest v1` above. That completed v1 verification boundary must not be reimplemented or treated as authorization for a v1 downgrade.
- Pending dependency: Atlas will develop independent validation and package orchestration after `35b82db`. Worker coordination requires the Admin V2 candidate-intake contract, a compatible durable staging writer, and idempotent full-snapshot staging proof.
- Required contract coverage: Define the entity, findings, and source-diff staging behavior; define the policy for a zero-record relationships member; and preserve explicit retry behavior for transient failures plus permanent handling for validation failures.
- Ownership boundary: Candidate acceptance is not publication. The canonical publish decision remains Admin-owned.
- Delivery gate: Atlas delivery must remain closed until Admin V2 intake and Worker durable staging are verified together. No V1 downgrade is permitted.
- Current state: Dependency recorded only. Worker development, tests, deployment, and rollout have not started.
- Status: Pending (BEKLEYEN).

## Entry template

### YYYY-MM-DD — Short title

- Source:
- Requested outcome:
- Affected areas:
- Known conflicts:
- Status: Pending owner review.
