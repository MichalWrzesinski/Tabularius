# 0001 — Modular monolith on Symfony with Messenger on PostgreSQL

Date: 2026-11-21. Status: accepted.

## Context
The brief requires PHP/Symfony, PostgreSQL, a modular monolith with background jobs, durable jobs, an outbox and deduplication. It rules out microservices and a custom orchestrator. Expected load is low: one organization, one mailbox, a public demo with quotas.

## Decision
- One Symfony 7.4 LTS application (`api/`) with modules `Identity`, `Mail`, `Ticket`, `Interpretation`, `Report`, `File` and `Audit`, plus `Shared`.
- Modules call each other through public service interfaces only. A module never reads another module's tables.
- Background work runs on Symfony Messenger with the Doctrine transport in the same PostgreSQL database. A separate `worker` container runs from the same image, and Symfony Scheduler covers recurring jobs.
- The outbox is a table written in the same transaction as the ticket change and dispatched by the worker.
- No Redis/Valkey for now. Locks and rate limits also use PostgreSQL (Doctrine lock and storage adapters).

## Consequences
- One database to back up. Job state and business state commit atomically.
- Throughput is limited by PostgreSQL polling. That is enough for the expected load; add Valkey only on measured need.
- Every job must be idempotent per (ticket, plan version) or per message id, because Messenger retries.
