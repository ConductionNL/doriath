# Team Folder Sharing Specification

**Status**: done

**OpenSpec changes:**
- [team-folder-sharing](../../changes/team-folder-sharing/)

## Purpose

Keepiq can share individual secrets with users and groups, but a team needs to share a whole **folder** (a "team vault") so that every secret inside it — and any added later — is automatically accessible to the team. Because Keepiq's sharing model is per-recipient RSA re-encrypted copies (ADR-003), a team folder is defined as a **membership set** whose changes drive fan-out of the existing per-secret share operation, not a shared symmetric key. Membership propagates with Nextcloud group membership (join → owner-approved add; leave → auto-revoke), and an admin offboarding action cleanly removes a leaver and transfers the secrets they owned. This closes Keepiq's biggest team-collaboration gap versus Bitwarden collections, 1Password shared vaults, Passbolt/Teampass folders, and the long-standing Nextcloud Passwords "share folder / share to group" requests.

## Requirements

### Requirement: Share a folder as a team folder
The system MUST allow a folder's owner — and only the owner — to share an owned folder with Nextcloud users and groups, without creating any shared symmetric key. Access MUST be granted by fanning out per-recipient RSA-encrypted copies of the contained secrets.

#### Scenario: Owner shares a folder
- GIVEN a user owns a folder containing N secrets
- WHEN the owner shares it with users and groups totalling R eligible recipients
- THEN the system MUST create a team-folder record and up to N×R per-recipient encrypted copies, each linked via `team_folder_id`
- AND no shared symmetric folder key MUST be created

#### Scenario: Non-owner cannot manage the folder
- GIVEN a recipient of a shared folder who is not its owner
- WHEN they attempt to change its membership
- THEN the system MUST reject the request with a 403/forbidden response

### Requirement: Inherited access on add, revoked on removal
The system MUST auto-share a secret to a team folder's current recipients when it is placed in the folder and MUST auto-revoke the folder-derived copies when it is removed or deleted. Fan-out writes MUST be idempotent.

#### Scenario: Add fans out, remove revokes only derived copies
- GIVEN a secret in a shared folder that also has an independent direct share
- WHEN the owner moves the secret out of the folder
- THEN all `team_folder_id`-derived copies MUST be revoked and the independent direct share MUST remain intact

### Requirement: Nested subfolder inheritance
The system MUST share a secret in a subfolder to the union of every ancestor team folder's recipient set. A subfolder MAY widen membership but MUST NOT narrow it below any ancestor's set in v1.

#### Scenario: Subfolder secret inherits ancestor members
- GIVEN a shared folder F with members M and a subfolder S of F
- WHEN a secret is added to S
- THEN the secret MUST be shared to all members M of F

### Requirement: Membership propagation with group membership
The system MUST propagate group membership changes to team-folder-derived shares: a user joining a member group MUST require owner approval before inheriting; a user leaving MUST have their team-folder-derived shares auto-revoked.

#### Scenario: Departing member auto-loses access
- GIVEN user Y holds team-folder-derived shares via membership in group G
- WHEN Y leaves group G
- THEN all of Y's `team_folder_id`-derived shares for that folder MUST be automatically revoked

### Requirement: Admin offboarding
The system MUST provide a single admin action that, given a leaving user and a successor, revokes the leaver's team-folder-derived shares and transfers the team secrets they owned to the successor via the existing permanent-delegation mechanism.

#### Scenario: Offboarding revokes and transfers
- GIVEN a leaving user U who holds derived shares and owns team secrets, and successor S
- WHEN an administrator runs the offboarding action
- THEN every derived share held by U MUST be revoked
- AND each team secret owned by U MUST be transferred to S via a permanent delegation

### Requirement: Auditable without exposing secret material
The system MUST dispatch typed events for share, unshare, member add, member remove, and offboarding carrying only identifiers — never key material or secret plaintext.

#### Scenario: Fan-out event carries no key material
- GIVEN an owner shares a folder
- WHEN the fan-out completes
- THEN the dispatched event MUST carry only folder id, owner id, member ids, and timestamps

## User Stories

- As a team lead, I want to share a whole folder with my team so that everyone gets access to all its credentials at once
- As a team lead, I want a secret I add to a shared folder to become available to the team automatically, without re-sharing it manually
- As a team lead, I want to share a folder with a Nextcloud group so that access tracks group membership
- As an owner, I want a new group member's access to require my approval, so I stay in control of who joins
- As an owner, I want a departing group member's inherited access to be cleaned up automatically
- As an administrator, I want a single offboarding action that removes a leaver's access and hands their owned team secrets to a successor

## Acceptance Criteria

- [ ] Only the folder owner can share a folder or change its membership
- [ ] Sharing a folder fans out per-recipient RSA copies of every contained secret — no shared symmetric key
- [ ] Adding a secret to a shared folder auto-shares it; removing it auto-revokes the derived copies only
- [ ] Secrets in subfolders inherit the union of ancestor memberships (widen-only in v1)
- [ ] Joining a member group requires owner approval before inheriting; leaving auto-revokes derived shares
- [ ] Group members without an active EncryptionSuite are skipped silently
- [ ] Fan-out server writes are idempotent; a partial fan-out self-heals on next folder open
- [ ] The admin offboarding action revokes a leaver's inherited access and transfers their owned team secrets via permanent delegation
- [ ] Non-owners cannot manage folders; non-admins cannot offboard (403)
- [ ] Team-folder events are audited with identifiers only — never key material
- [ ] The server never holds plaintext secret material at any step

## Notes

- Introduces `keepiq_team_folders` and `keepiq_team_folder_members` tables plus a nullable `team_folder_id` provenance column on the existing share table (parallel to `group_share_id`).
- Reuses `ShareService::createBatchShares`/`revokeShare`/`syncUpdate`, `GroupShareService` group-expansion/propagation, and `DelegationService::createDelegation`/`makePermanent` — no new crypto.
- Related ADRs: ADR-001 (own tables), ADR-003 (per-recipient RSA, write-without-read, zero-knowledge).
- Out of scope for v1: shared symmetric folder key, folder co-ownership, per-secret read/write permission grades, subfolder membership narrowing.
