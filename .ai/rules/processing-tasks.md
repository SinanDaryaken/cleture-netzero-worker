---
paths:
  - 'app/ProcessingTasks/**'
  - 'app/Jobs/ProcessProcessingTask.php'
  - 'config/processing_tasks.php'
  - 'routes/console.php'
---

# Processing Tasks

## Preserve the canonical processing-task lifecycle

The worker consumes the central `processing_tasks` table but never owns or runs its migrations. NetZeroAdmin is the sole owner of central migration files; tenant migration files remain Worker-owned.

- Producers insert supported, versioned tasks as `pending` with secret-free payloads.
- The scheduler atomically changes an eligible task to `queued`, assigns a new UUIDv7 `dispatch_token`, and inserts a Laravel database-queue job on the same central connection and transaction.
- The transport job carries only the processing-task ID and dispatch token. It verifies the current token before changing the task to `processing` and starting the lease.
- The task remains canonical until the domain work finishes. Delete it only after success; on retryable failure return it to `pending` with backoff; on permanent or exhausted failure write a safe `processing_task_failures` snapshot and delete the active task in one transaction.
- A stale transport job must not change task state when its dispatch token no longer matches.
- Unsupported task type and payload-version pairs stay pending and are not dispatched. Register new work through the configured contract definition and queue instead of adding type-specific branches to the dispatcher or executor.
