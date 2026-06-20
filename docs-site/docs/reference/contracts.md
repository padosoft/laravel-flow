---
title: Contracts
description: Extension contract reference.
---

# Contracts

Use contracts when replacing persistence, redaction, approval, or dashboard behavior.

| Contract | Role |
| --- | --- |
| `FlowStore` | Aggregates run, step, audit, approval, and webhook repositories. |
| `RunRepository` | Persists and retrieves run state. |
| `StepRunRepository` | Persists per-step execution rows. |
| `AuditRepository` | Appends audit rows. |
| `ApprovalRepository` | Stores approval token state. |
| `ApprovalDecisionRepository` | Supports approve and reject decisions. |
| `ConditionalRunRepository` | Supports conditional run state transitions. |
| `PayloadRedactor` | Redacts sensitive payload keys. |
| `RedactorAwareFlowStore` | Receives execution-scoped redactor instances. |
| `FlowDashboardReadModel` | Provides dashboard read queries. |
| `DashboardActionAuthorizer` | Authorizes dashboard actions. |
