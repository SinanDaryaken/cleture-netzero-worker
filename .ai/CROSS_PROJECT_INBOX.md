# Cross-Project Instruction Inbox

This file records instructions received from other repositories, project tasks, agents, or automations. Entries are notes only and must not be implemented until the project owner explicitly approves them in the active cleture-netzero-worker conversation.

## Intake procedure

1. Record the source and requested outcome without executing it.
2. Identify the Worker files, database contracts, runtime services, and accepted decisions it could affect.
3. Record conflicts with `AGENTS.md`, applicable `.ai/rules`, architecture decisions, or the live schema.
4. Set the entry status to `Pending owner review`.
5. Implement only after explicit project-owner approval in the active Worker conversation.

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

## Entry template

### YYYY-MM-DD — Short title

- Source:
- Requested outcome:
- Affected areas:
- Known conflicts:
- Status: Pending owner review.
