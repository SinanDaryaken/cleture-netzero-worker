---
paths:
  - app/Console/Commands/UpgradeTenantSchemas.php
---

# Commands

## Run tenant schema upgrades as an isolated release operation
Deployments invoke the idempotent tenant-schemas:upgrade command once with --force and --isolated=1. It processes ready tenants in bounded chunks, skips the target schema version, continues across tenant failures, and returns failure if any tenant did not upgrade. It is separate from processing_tasks and is not scheduled continuously.
