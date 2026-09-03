---
paths:
  - 'app/{Identity,Jobs/Identity,Mail/Identity}/**'
---

# Identity

## Keep identity tokens out of durable payloads and logs
Identity processing-task payload version 1 carries only `organizationUserId`. Localized version 2 carries exactly `organizationUserId` and a validated `tr` or `en` locale. Domain mail jobs may carry the same non-secret locale, while the transport job continues to carry only the processing-task ID and dispatch token. Generate verification and reset tokens inside the domain job, persist only hashes, send mail synchronously inside the job, and never include plaintext tokens, passwords, links, email addresses, or other secrets in processing payloads, failure snapshots, job parameters, or logs.
