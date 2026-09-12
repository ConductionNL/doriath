# SIEM Audit Export Specification

**Status**: done

**OpenSpec changes:** [siem-audit-export](../../changes/archive/2026-07-18-siem-audit-export/)

## Purpose

NIS2 / Cyberbeveiligingswet requires logging/monitoring integration and incident-detection capability, and every enterprise vault competitor ships SIEM export (Keeper, 1Password, Bitwarden) while every self-hosted OSS vault lacks it. This feature streams Keepiq's existing sanitized audit events to an external SIEM through admin-configured syslog (RFC 5424, TCP/TLS) and webhook (HMAC-signed HTTPS POST) sinks — with per-category filtering, reliable background delivery (exponential-backoff retry, dead-letter with an admin notification, drop-oldest backpressure with a counter), per-sink observability, and a test-fire action — carrying identifiers only, never key material, per the audit trail's no-secret-material guarantee.

## Requirements

### Requirement: Admin-configured syslog and webhook sinks
The system MUST let administrators configure `syslog` (RFC 5424, TCP/TLS) and `webhook` (HMAC-signed HTTPS POST) sinks, each independently enable/disable-able, restricted to administrators; a disabled sink MUST receive no forwarded event.

#### Scenario: Admin creates a webhook sink
- GIVEN an administrator on the SIEM settings panel
- WHEN they create an enabled webhook sink with an HTTPS endpoint and an HMAC shared secret
- THEN the sink MUST be stored and eligible for delivery, and the HMAC secret MUST NOT be returned in any read of the sink

### Requirement: Forwarded payload carries no secret material
The system MUST forward only the existing sanitized audit-entry shape (identifiers plus whitelisted metadata) and MUST NOT include any secret value, login, password, additional field, or ciphertext in any payload or sink state.

#### Scenario: Payload is a subset of the sanitized audit entry
- GIVEN an enabled sink
- WHEN an audit event is forwarded
- THEN the payload MUST contain only the audit entry's identifiers and whitelisted metadata, with no key material

### Requirement: Reliable background delivery
The system MUST deliver asynchronously via a durable per-sink queue drained by a background job, retry failures with exponential backoff, dead-letter (and retain) events past the retry ceiling, raise an administrator notification on dead-lettering, and MUST NOT let an enqueue or delivery failure roll back the audited operation.

#### Scenario: Failed delivery is retried then dead-lettered
- GIVEN a sink whose endpoint is unreachable
- WHEN the delivery job runs repeatedly past the retry ceiling for an event
- THEN the event MUST be dead-lettered and retained, and an administrator notification MUST be raised

### Requirement: Backpressure and observability
The system MUST enforce a per-sink queue cap with drop-oldest eviction and a dropped-events counter, MUST expose per-sink delivery state, and MUST provide a test-fire action reporting the transport outcome.

#### Scenario: Queue at cap drops the oldest event
- GIVEN a sink at its queue cap with a failing endpoint
- WHEN a new event is enqueued
- THEN the oldest pending event MUST be evicted, the dropped-events counter MUST increase, and the queue MUST NOT exceed the cap

## User Stories

- As a security team, I want Keepiq audit events in our SIEM so that we can detect and investigate incidents centrally
- As an administrator, I want a test-fire button and per-sink state so that I can validate and monitor a sink without waiting for real events
- As a CISO, I want failed deliveries dead-lettered and alerted rather than silently dropped so that our compliance log stays trustworthy

## Acceptance Criteria

- [ ] Syslog and webhook sinks are admin-configurable, enable/disable-able, admin-only
- [ ] Forwarded payload is a strict subset of the sanitized audit entry; no key material leaves
- [ ] Category filter excludes non-matching events at enqueue time
- [ ] Enqueue/delivery failures never roll back the audited operation
- [ ] Retry with exponential backoff; dead-letter after the ceiling (retained); admin notification raised
- [ ] Per-sink queue cap enforces drop-oldest with a counter; queue never exceeds the cap
- [ ] Per-sink delivery state exposed; test-fire reports the transport outcome
- [ ] HMAC secret encrypted at rest, write-only, never echoed
- [ ] Sink lifecycle emits audit events carrying only identifiers, never the HMAC secret

## Notes

- Own tables per ADR-001 (`keepiq_siem_sinks`, `keepiq_siem_queue`); no OpenRegister.
- Relays `secret-audit-trail`'s sanitized events and inherits its no-secret-material whitelist (`lib/Event/Audit/AuditEventTypes.php`); adds no new data path into the secrets.
- Background delivery mirrors the existing `TimedJob` pattern (`lib/BackgroundJob/PurgeAuditLogJob.php`); dead-letter alert uses the unconditional admin notification subject pattern (`app_pending`).
- Related specs: secret-audit-trail (event source + sanitization), admin-settings (panel conventions).
