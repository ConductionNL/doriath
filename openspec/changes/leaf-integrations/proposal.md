## Why

The fleet is adopting OpenRegister's integration leaves (ADR-019/ADR-022): apps declare `configuration.linkedTypes` on their register schemas and OR renders calendar/files/contacts/deck/… leaf widgets on the object detail pages. Every sibling app is expected to either adopt the leaves or document why not. Keepiq is the "why not" case, and the reasons deserve a spec rather than tribal knowledge — otherwise the next fleet sweep files "keepiq: 0 leaves" as a gap and someone builds one.

The blocking facts, verified in code:

- **Keepiq's data does not live in OpenRegister.** The register (`lib/Settings/keepiq_register.json`) holds a single placeholder `example` schema; secrets, folders, certificates, shares, and leases are app-owned tables (`lib/Db/*`, `keepiq_secrets` etc.). Leaves attach to OR objects via schema `linkedTypes` — there is structurally nothing for a leaf to attach to, and creating something (mirroring vault rows into OR objects) is exactly the move the architecture forbids.
- **The zero-knowledge boundary (ADR-003, encryption-suites spec).** Secret values (`key`, `login`, `additional_fields`) are ciphertext the server cannot decrypt; even the plaintext metadata (entry `name`, `url`, folder structure, expiry dates) is vault-structure information guarded by the vault's own access model (suites, shares, delegation). Every leaf ships data into another app's storage and ACL domain: a files leaf would put secret material or backups where NC file sharing governs them; a calendar leaf would replicate expiry metadata (entry names + dates — a map of what the organisation's credentials are and when they rot) into CalDAV, where calendar sharing, sync clients, and exports govern them; a deck/activity leaf would copy entry names onto boards/streams with their own sharing.
- **The candidate use cases are already served in-app.** Certificate and secret expiry has a scanning and notification pipeline (`ScanCertificateExpiryJob`, `CheckRootCertificateExpiry` at 90/30/7 days, `ScanExpiringSecretsJob` with reminder thresholds and overdue rotation flags), rotation follow-ups have the rotation-flag queue (`rotation-expiry-policies` spec), and the dashboard summarises both. A calendar or deck leaf would duplicate a working surface *and* leak metadata across the ACL boundary — the worst trade on both axes.

The honest change is therefore a documented decision: leaves stay out, stated as requirements with the rationale attached, plus the conditions any future exception must meet. This mirrors how the app already specs deliberate absences (e.g. "admin-initiated export is cryptographically impossible by design — worth stating", secret-export design).

## What Changes

- Add the **`integration-boundary`** capability: a spec that (1) forbids mirroring secret material *or vault-structure metadata* into OpenRegister objects or any leaf-backed store, (2) records that no `linkedTypes` is declared on any Keepiq schema and scopes out each considered leaf (files, calendar, deck, activity) with its specific refusal, and (3) defines the gate for future exceptions: metadata-only, owner-session-scoped, and specced through this capability — not added ad hoc.
- Add a regression assertion that `keepiq_register.json` (and any future `register.d/` fragment) declares no `linkedTypes` and no `mailObjectTemplate`, so a well-meaning fleet codemod cannot flip the decision silently.
- Document the decision in `docs/FEATURES.md` (one line in the security-model section) so the marketing surface and the spec agree.
- **No leaf, no UI, no schema change ships.** This change is deliberately spec + guard + docs.

## Capabilities

### New Capabilities
- `integration-boundary`: the standing decision that OR integration leaves stay out of Keepiq, the per-leaf refusals (files/calendar/deck/activity), the vault-metadata non-disclosure rule that drives them, and the conditions a future leaf adoption must meet.

### Modified Capabilities
_(none — no existing requirement changes behaviour; the encryption-suites and rotation-expiry-policies specs are referenced as the reasons, not modified)_

## Impact

- **Code**: none at runtime. One PHPUnit guard test asserting the register JSON carries no leaf configuration.
- **Docs**: `docs/FEATURES.md` gains the boundary statement.
- **Fleet**: sweeps that count leaf adoption get a spec to point at instead of an apparent gap; the register keeps its placeholder-only shape by assertion instead of by accident.
- **Future**: a change that wants a leaf (e.g. certificate-expiry calendar items) must modify this capability's requirements explicitly, satisfying the exception conditions — the decision can be revisited, but never bypassed.
