---
paths:
  - 'app/{Tenancy,Jobs/Tenancy}/**'
---

# Tenancy

## Keep tenant provisioning deterministic, secret-free, and safely scoped
Use the canonical tenant UUID as the tenant identity and derive the tenant database name deterministically on the server. Never persist database credentials in processing-task payloads or application tables. Keep the central task runtime role separate from the more privileged provisioner role. Long-lived workers must initialize tenant context within a try/finally-style boundary that always ends tenancy and restores central state.

## Keep tenant DDL on the provisioner role
Create and migrate tenant databases through the distinct provisioner connection; never make the runtime role the database owner. Grant the runtime role only the required DML/default privileges, then verify the applied migration state through the runtime tenant connection before marking the tenant ready.

## Upgrade existing tenant schemas through the provisioner
Existing tenant schema upgrades must use the DDL-capable provisioner connection, a tenant-scoped PostgreSQL advisory lock, the canonical migration set, runtime privilege repair, and runtime verification. Never run tenant DDL through the runtime role.
