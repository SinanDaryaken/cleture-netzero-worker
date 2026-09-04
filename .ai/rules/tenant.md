---
paths:
  - 'database/migrations/tenant/**'
---

# Tenant

## Model tenant structure as an organizational-unit tree
Use `organizational_units` as the unbounded adjacency-list hierarchy. Nodes may remain unclassified; `mark_as_company` is allowed at any depth, while `mark_as_facility` is mutually exclusive with company and makes the node a leaf. Enforce facility leaf behavior and cycle prevention at the PostgreSQL schema level.

## Keep tenant login state out of users
Tenant users do not track email verification and do not persist Laravel remember-me tokens. Login, session, and access-token state belongs to the tenant-facing application and is kept in Redis rather than the tenant database.

## Declare self-reference primary keys before foreign keys
For a self-referencing tenant table, declare the ID column first and add `$table->primary('id')` explicitly before defining the self-referencing foreign key. Do not rely on a chained column `->primary()` modifier because Laravel may emit its implied primary-key command after the foreign-key command on PostgreSQL.

## Keep type classification independent from structural roles
Organization unit types are optional taxonomy records referenced by nullable `organizational_units.organization_unit_type_id`. Keep `mark_as_company` and `mark_as_facility` as independent structural flags; when both are false the unit is structurally Standard regardless of its optional type. Type deletion must be restricted while referenced.
