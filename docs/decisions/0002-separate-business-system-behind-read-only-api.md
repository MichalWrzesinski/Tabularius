# 0002 — Separate demo business system behind a read-only API

Date: 2026-11-21. Status: accepted.

## Context
The brief (§2, §2.1, §3) requires:
- business data outside the Tabularius database;
- a demo business system with its own database, migrations, permissions and seed;
- read-only access for Tabularius;
- replaceable adapters for a customer's API or approved views;
- no unnecessary services.

## Decision
- `business/` is a small Symfony app with its own PostgreSQL database `business`. It has two roles: `business_owner` for migrations and seed, and `business_reader` for the API runtime, with SELECT on approved reporting views only.
- It exposes `GET` endpoints for the profile's operations: sales lines, customers in a representative's scope, manufacturers and parts, and customer activity. Results are paginated and protected by a static bearer token from the environment. It never accepts SQL, table names or expressions.
- Tabularius reaches it only through an `IntegrationAdapter` chosen by the integration profile (`rest-json`). The adapter validates responses at the boundary and applies timeouts. A future `pg-views` adapter for customers without an API is possible but not built now.
- Security filters such as the representative's scope are always sent by Tabularius from trusted context, and the business API also checks that the requested customers belong to the given representative.
- Seed: deterministic (fixed random seed and data version) and refreshable relative to a reference date, so the demo stays useful over time. Future-dated records exist but are excluded by the period rules.

## Consequences
- One more container and database in Compose, started by the same `make up`.
- Report calculations happen in Tabularius (deterministic code) on data returned by the API. The business API returns lines and does not compute discounts.
- Integration failures, such as timeouts or 5xx responses, are visible and testable as „data-source error” (ticket T-1039 in the prototype).
