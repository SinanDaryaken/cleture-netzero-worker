---
paths:
  - 'app/{Contracts/ProcessingTasks/Emission,Jobs/Emission,ProcessingTasks/Emission,ProcessingTasks/Definitions/Emission}/**'
---

# Retired emission transport

The project owner approved retirement on 2026-09-08 in the shared Atlas → Worker → Admin cleanup task. Atlas writes prepared source factors directly to Admin-owned incoming-factor tables. Do not restore package verification/staging, Admin validation clients or emission.candidate.ingest. Worker identity/tenant processing remains independent. No central task or data cleanup is implied by runtime retirement.
