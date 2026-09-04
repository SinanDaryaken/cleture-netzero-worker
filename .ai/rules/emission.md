---
paths:
  - 'app/{Contracts/ProcessingTasks/Emission,Jobs/Emission,ProcessingTasks/Emission,ProcessingTasks/Definitions/Emission}/**'
---

# Emission

## Fail closed before candidate staging
For emission.candidate.ingest@v1, validate the exact Admin task pointer and stream the version-pinned S3-compatible artifact. Treat checksum, manifest, archive, member-digest, and schema defects as permanent; let object-storage transport failures use the existing retry lifecycle. Do not mark a package staged or invent canonical candidate writes until the Admin-owned validation/bulk-write contract is available; a fully verified artifact currently produces candidate_staging_contract_requires_decision.
