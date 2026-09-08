# User Sharing Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-user-sharing` (2026-03-31) — Full implementation: user/group sharing, sync-on-update, notifications, share requests, delegation

## Purpose

@e2e exclude No user-sharing UI is built in v0.1; all sharing scenarios require the encrypted secret CRUD surface (itself unbuilt) — covered by integration tests, not Playwright UI flows.

A user can share a secret with another Nextcloud user or with a Nextcloud user group. Because encryption is asymmetric, sharing works by creating an encrypted copy of the secret using the recipient's public certificate. Both the original and all shared copies stay in sync: when either party updates the secret, the change is written back to all copies.

Group sharing expands statically to individual shares at share time. When new members join a group, the owner is notified and asked to approve adding them. When members leave a group, their group-derived shares are automatically revoked. Direct shares are always independent of group membership.

## Data Model

### SecretShare (user-to-user)

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `source_secret_id` | FK | No | The original secret being shared |
| `target_user_id` | string | No | Nextcloud user ID of the recipient |
| `secret_id` | FK | No | The encrypted copy in the recipient's vault |
| `group_share_id` | FK | No | Nullable — set if this share was created from a GroupShare; null for direct shares |
| `created_at` | datetime | No | |

The recipient's copy is itself a full `Secret` row, encrypted with the recipient's EncryptionSuite. The `SecretShare` links the two.

A `group_share_id` allows the system to identify which shares originated from a group share, enabling automatic revocation when a user leaves the group and targeted notifications when a new member joins.

### GroupShare

Tracks that a secret has been shared with a Nextcloud user group. One record per secret-group combination.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being shared with the group |
| `group_id` | string | No | Nextcloud group ID |
| `created_by` | string | No | Nextcloud user ID of the owner who created the share |
| `created_at` | datetime | No | |

### SecretDelegation

Tracks ownership delegation of a secret. Multiple active delegations per secret are allowed; all delegates hold co-owner rights simultaneously.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being delegated |
| `original_owner_id` | string | No | Nextcloud user ID of the original owner |
| `delegated_to` | string | No | Nextcloud user ID of the delegate |
| `delegated_at` | datetime | No | When delegation was created |
| `initiated_by` | string | No | User ID of who created the delegation (may differ from original_owner_id for admin power grabs) |
| `is_permanent` | bool | No | false = temporary (owner can reclaim); true = permanent (owner's suite was revoked/deleted) |
| `made_permanent_at` | datetime | No | Null while temporary |

## Requirements

### Requirement: Share a Secret
The system MUST allow a user to share a secret they own with another Nextcloud user who has an active EncryptionSuite.

#### Scenario: Share with valid recipient
- GIVEN user A owns a secret and user B has an active EncryptionSuite
- WHEN user A shares the secret with user B
- THEN the system MUST create an encrypted copy of the secret in user B's vault using user B's public certificate
- AND create a SecretShare linking the original to the copy

#### Scenario: Share with user without EncryptionSuite
- GIVEN user B has never opened Keepiq and has no EncryptionSuite
- WHEN user A attempts to share a secret with user B
- THEN the system MUST return an error indicating the recipient has no encryption suite

### Requirement: Recipient Shareability Lookup
Sharing requires the recipient's public certificate, so a client MUST be able to
learn, before it attempts a share, which of a set of candidate users can receive
one. The system MUST expose a lookup taking a caller-supplied list of user ids
and returning, for each, whether that user has an active EncryptionSuite and if
so their certificate.

The lookup MUST be a probe, never an enumeration: it MUST answer only about ids
the caller names, and the system MUST NOT expose any endpoint listing the users
who hold a suite. Certificates are public keys and safe to distribute, but the
set of users holding one is a membership disclosure gated by no sharing
permission.

A user who does not exist and a user with no active EncryptionSuite MUST be
reported identically, so the lookup cannot be used as a user-existence oracle.

Where a user has more than one active suite — which is normal for the duration
of a compromise-recovery migration — the lookup MUST return the NEWEST, matching
single-recipient resolution. Returning the older one would hand back the
certificate the owner is migrating away from, producing a copy the recipient
cannot open.

The number of ids accepted in one request MUST be bounded, and a request
exceeding the bound MUST be refused rather than truncated.

#### Scenario: Mixed candidates answered in one request
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- GIVEN user B has an active EncryptionSuite and user C does not
- WHEN a client looks up both B and C
- THEN the response MUST report B as shareable with their certificate
- AND report C as not shareable, without a certificate

#### Scenario: Unknown user is indistinguishable from one without a suite
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- GIVEN no user "nobody" exists and user C exists without an EncryptionSuite
- WHEN a client looks up both
- THEN both MUST be reported not shareable with the same reason

#### Scenario: Newest suite wins during a migration
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by EncryptionSuiteMapperTest.
- GIVEN user B has two active EncryptionSuites from an in-flight compromise recovery
- WHEN a client looks up B
- THEN the certificate returned MUST be that of the most recently created suite

#### Scenario: Oversized request refused
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- WHEN a client submits more ids than the bound allows
- THEN the request MUST be refused, and no lookup performed

### Requirement: Sync on Update
When either party updates a shared secret, the change MUST be propagated to all copies.

#### Scenario: Owner updates shared secret
- GIVEN a secret is shared with one or more users
- WHEN the owner updates the secret's value
- THEN the system MUST re-encrypt the updated value for each recipient and write it to their copy

#### Scenario: Recipient updates shared secret
- GIVEN a secret has been shared with user B
- WHEN user B updates their copy of the secret
- THEN the system MUST re-encrypt the updated value for the original owner and all other recipients

### Requirement: Share with Group (Static Expansion)
The system MUST allow a secret owner to share a secret with a Nextcloud user group. At the moment of sharing, the system MUST create an individual SecretShare (with `group_share_id` set) for each current member of the group who has an active EncryptionSuite. A GroupShare record MUST be created to track the relationship.

Members without an active EncryptionSuite are skipped at expansion time. No error is returned for skipped members.

#### Scenario: Share with group
- GIVEN a user owns a secret and a Nextcloud group G has N members with active EncryptionSuites
- WHEN the owner shares the secret with group G
- THEN N SecretShares MUST be created (one per eligible member), each with `group_share_id` referencing the GroupShare
- AND a GroupShare record MUST be created for the secret-group pair

### Requirement: New Group Member — Owner Notification
When a new user joins a Nextcloud group that has a GroupShare for one or more secrets, the secret owner MUST be notified for each affected secret and asked to approve adding the new member.

This follows the same pattern as the share request mechanism: the owner approves or denies; the system acts on their decision.

Notification content:
- Body: "User *{X}* joined group *{G}* — share *{secret}* with them?"
- Actions: Approve / Deny

On approval: a new SecretShare is created for X (with `group_share_id` set), and X is notified of the new share.
On denial: no share is created. The GroupShare remains active for future members.

#### Scenario: New member joins group
- GIVEN group G has a GroupShare for a secret owned by A
- WHEN user X joins group G
- THEN A MUST receive a Nextcloud notification for each affected secret asking to approve the share
- AND on approval, a SecretShare MUST be created for X referencing the GroupShare
- AND X MUST receive a notification that the secret was shared with them

### Requirement: Member Leaves Group — Auto-Revocation
When a user leaves a Nextcloud group, all SecretShares for that user originating from a GroupShare for that group MUST be automatically revoked (deleted), along with the encrypted copies in the user's vault.

Direct shares (where `group_share_id` is null) are unaffected — leaving a group does not remove access granted independently of the group.

#### Scenario: Member leaves group
- GIVEN user Y has a SecretShare for secret S with `group_share_id` referencing GroupShare for group G
- WHEN Y leaves group G
- THEN Y's SecretShare and encrypted copy MUST be automatically deleted
- AND if Y also has a direct SecretShare for S (group_share_id = null), that share MUST remain intact

### Requirement: Revoke Group Share
The secret owner MUST be able to revoke a GroupShare. On revocation, all SecretShares originating from that GroupShare MUST be cascade-deleted.

#### Scenario: Owner revokes group share
- GIVEN a GroupShare exists for group G and secret S, with M derived SecretShares
- WHEN the owner revokes the GroupShare
- THEN all M SecretShares with that `group_share_id` MUST be deleted
- AND the GroupShare record MUST be removed
- AND direct shares for the same secret (group_share_id = null) are unaffected

### Requirement: Notification on Share Received
When a secret is shared with a user, the recipient MUST receive a Nextcloud notification.

Notification content:
- Body: "*{User A}* shared a secret with you"
- Action link: opens the shared secret in the recipient's vault

#### Scenario: Secret shared with user
- GIVEN user A shares a secret with user B
- WHEN the share is created
- THEN B MUST receive a Nextcloud notification

### Requirement: Share Request (Recipient-Initiated)
Recipients MUST NOT be able to share a secret directly with a third party. Instead, a recipient MAY submit a share request to the original owner, asking them to share the secret with another Nextcloud user.

The share request flow:
1. Recipient B submits a request: "Please share *{secret}* with user C"
2. The original owner A receives a Nextcloud notification
3. A approves → the system creates a new `SecretShare` directly from A to C (identical to A having shared it themselves)
4. A denies → the request is dropped
5. B receives a Nextcloud notification of the outcome (accepted or denied) — no further detail

This keeps all shares flat (A→B, A→C, A→D, …), preserves owner control, and avoids sync and revocation complexity from re-share trees.

#### Scenario: Recipient requests a share
- GIVEN user B holds a shared copy of A's secret
- WHEN B submits a share request for user C
- THEN a Nextcloud notification MUST be sent to A
- AND B MUST receive a notification when A approves or denies

#### Scenario: Owner approves share request
- GIVEN A receives a share request from B for user C
- WHEN A approves it
- THEN the system MUST create a SecretShare from A to C as if A initiated it directly

#### Scenario: Owner denies share request
- GIVEN A receives a share request from B for user C
- WHEN A denies it
- THEN no share is created and B is notified of the denial

### Requirement: Share Visibility
Only the original owner of a secret MUST be able to see the full list of users the secret is shared with. Recipients MUST NOT see who else has access.

#### Scenario: Owner views share list
- GIVEN user A owns a secret shared with B and C
- WHEN A views the secret
- THEN A MUST see the full list of recipients

#### Scenario: Recipient views shared secret
- GIVEN user B holds a shared copy of A's secret
- WHEN B views the secret
- THEN B MUST NOT see the list of other recipients

### Requirement: EncryptionSuite Revocation — Share Cleanup
When a recipient's EncryptionSuite is **revoked** (deliberate decommissioning), all SecretShare records targeting that suite MUST be automatically cascade-deleted, including the encrypted copies in the recipient's vault. The original owner's copy is unaffected.

#### Scenario: Recipient's suite is revoked
- GIVEN user B holds shared copies of one or more secrets
- WHEN B's EncryptionSuite is revoked
- THEN all SecretShare records where B is the recipient MUST be deleted
- AND all encrypted copies in B's vault originating from a share MUST be deleted
- AND the original secrets owned by their respective owners remain intact

### Requirement: EncryptionSuite Compromise — Shared Copy Migration and Owner Notification
When a recipient's EncryptionSuite is **replaced due to compromise**, the suite migration process (see encryption-suites spec) MUST cover all `Secret` rows encrypted with the old suite — including shared copies held by the recipient. Those copies MUST be re-encrypted with the new suite and flagged `possibly_compromised_at` as part of the standard migration.

The additional responsibility of User Sharing is: when a shared copy is flagged `possibly_compromised_at` during migration, the **original owner of the secret MUST be notified** that the secret may have been compromised and its value should be replaced.

When the owner replaces the secret value, sync-on-update (see Requirement: Sync on Update) propagates the new value to all copies, including the migrated copy in the recipient's new suite. Updating the value MUST unset `possibly_compromised_at` on all copies.

#### Scenario: Shared copy flagged during migration
- GIVEN user B holds a shared copy of a secret owned by A
- WHEN B's EncryptionSuite is replaced due to compromise and the copy is migrated
- THEN the copy MUST be flagged `possibly_compromised_at` (per encryption-suites migration)
- AND A MUST receive a Nextcloud notification: "A secret you shared may have been compromised — please replace its value"

#### Scenario: Owner replaces possibly-compromised secret value
- GIVEN A's secret (and its shared copies) is flagged `possibly_compromised_at`
- WHEN A updates the secret value
- THEN sync-on-update MUST propagate the new value to all copies
- AND `possibly_compromised_at` MUST be unset on the original and all copies

### Requirement: Ownership Delegation
The system MUST allow a secret's ownership to be delegated to one or more other users, granting them co-owner rights (share management, full recipient visibility, value updates). Multiple active delegations for the same secret MUST be allowed simultaneously.

**Who can create a delegation:**
- **Admin power grab**: a vault administrator can create a delegation for any secret that has already been shared with them. No owner consent is required.
- **User self-delegation**: the secret owner can create a delegation for their own secret to any user who already holds a share of that secret.

In both cases the delegatee MUST already hold a share — the delegation promotes their existing copy to co-owner status.

**Mechanics:** the delegate's copy is promoted to co-owner status; the original owner's copy is demoted to a regular share for the duration of the delegation. The existing sync-on-update mechanism ensures all copies remain consistent regardless of which co-owner updates the value.

#### Scenario: Admin creates delegation (power grab)
- GIVEN a vault administrator holds a share of secret S (owned by A)
- WHEN the admin creates a delegation for S
- THEN a SecretDelegation record MUST be created with `is_permanent = false`
- AND the admin gains co-owner rights: share management, full recipient visibility, value updates

#### Scenario: Owner self-delegates
- GIVEN user A owns secret S and user B holds a share of S
- WHEN A creates a delegation to B
- THEN a SecretDelegation record MUST be created with `is_permanent = false`
- AND B gains co-owner rights for S

#### Scenario: Delegatee does not hold a share
- GIVEN user C does not hold a share of secret S
- WHEN a delegation to C is attempted
- THEN the system MUST return an error — delegation requires a pre-existing share

### Requirement: Reclaim Delegation
The original owner MUST be able to reclaim ownership at any time while delegations are temporary, revoking all active delegations simultaneously.

#### Scenario: Owner reclaims
- GIVEN secret S has one or more active temporary delegations
- WHEN the original owner reclaims
- THEN ALL active SecretDelegation records for S MUST be removed
- AND all delegates are demoted back to regular share recipients
- AND the original owner is sole owner again

### Requirement: Permanent Transfer on Suite Revocation
When the original owner's EncryptionSuite is revoked or deleted, all temporary delegations for their secrets MUST automatically become permanent.

#### Scenario: Original owner's suite revoked or deleted
- GIVEN secret S has one or more active temporary delegations (is_permanent = false)
- WHEN the original owner's EncryptionSuite is revoked or deleted
- THEN all SecretDelegation records for S MUST have `is_permanent` set to true and `made_permanent_at` set to now
- AND the original owner's (now inaccessible) copy MUST be deleted
- AND the delegates retain co-owner rights permanently — reclaim is no longer possible

### Requirement: Revoke Share
The system MUST allow the original owner to revoke a share, removing the recipient's copy.

#### Scenario: Revoke share
- GIVEN a share exists between user A and user B
- WHEN user A revokes the share
- THEN the recipient's Secret copy MUST be deleted
- AND the SecretShare record MUST be removed

## User Stories

- As a user, I want to share a password with a colleague so that we both have access to a shared account
- As a user, I want changes I make to a shared secret to be visible to my colleagues immediately
- As a user, I want to revoke a share when a colleague leaves the team
- As a user, I want to share a secret with an entire team group so that all current members get access at once
- As a user, I want to be notified when a new member joins a group I shared a secret with, so that I can decide whether to extend access
- As a user, I want shares derived from a group to be automatically cleaned up when a member leaves the group
- As an administrator, I want to take over management of a secret shared with me so that I can handle sharing when the owner is unavailable
- As a user, I want to delegate ownership of my secret to a trusted colleague so they can manage sharing on my behalf
- As a user, I want to reclaim sole ownership of my secret when I return, revoking all delegations at once

## Acceptance Criteria

- [ ] A secret can be shared with any Nextcloud user who has an active EncryptionSuite
- [ ] The recipient receives an encrypted copy in their own vault
- [ ] The copy is encrypted with the recipient's public certificate — the sender cannot read it after sharing
- [ ] The recipient receives a Nextcloud notification when a secret is shared with them
- [ ] Updates by either party propagate to all copies of the shared secret
- [ ] The original owner can revoke a share at any time
- [ ] Revoking a share deletes the recipient's copy
- [ ] Sharing fails with a clear error if the recipient has no EncryptionSuite
- [ ] Revoking a recipient's EncryptionSuite cascade-deletes all their SecretShare records and encrypted copies
- [ ] When a shared copy is flagged `possibly_compromised_at` during suite migration, the original owner receives a Nextcloud notification
- [ ] When the owner updates a possibly-compromised secret, sync-on-update propagates the new value to all copies and unsets the flag
- [ ] Recipients cannot share directly — they can only submit a share request to the original owner
- [ ] The original owner receives a Nextcloud notification for incoming share requests
- [ ] Approving a share request creates a new direct share from owner to the requested user
- [ ] The requester (B) is notified of the outcome (accepted or denied), with no further detail
- [ ] Only the original owner can see the full list of recipients; recipients cannot see who else has access
- [ ] A secret can be shared with a Nextcloud user group, creating individual SecretShares for all current eligible members
- [ ] A GroupShare record tracks each secret-group relationship
- [ ] Each group-derived SecretShare references its GroupShare via `group_share_id`
- [ ] When a new user joins a group, the secret owner receives a notification and must approve before the share is created
- [ ] When a user leaves a group, all their group-derived SecretShares are automatically revoked
- [ ] Direct shares (group_share_id = null) are unaffected by group membership changes
- [ ] The owner can revoke a GroupShare, cascade-deleting all derived SecretShares
- [ ] A vault administrator can create a delegation for any secret already shared with them (no owner consent required)
- [ ] A secret owner can create a delegation for their own secret to any user who already holds a share
- [ ] Delegation fails if the delegatee does not already hold a share
- [ ] Multiple active delegations per secret are allowed; all delegates hold co-owner rights simultaneously
- [ ] Delegates can manage shares, see the full recipient list, and update values
- [ ] The original owner can reclaim at any time, revoking all temporary delegations simultaneously
- [ ] When the original owner's EncryptionSuite is revoked or deleted, all temporary delegations automatically become permanent
- [ ] On permanent transfer, the original owner's inaccessible copy is deleted and reclaim is no longer possible

## Notes

- The sync-on-update requirement means that updating a widely-shared secret triggers multiple re-encryption operations. For the initial implementation, this can be synchronous. Async fanout can be explored if performance becomes an issue.
- **Future consideration (flagged for later):** delegation currently requires the delegatee to already hold a share, due to the cryptographic constraint that no one can decrypt a secret they were never given a copy of. This means an admin can only take over a secret if it was proactively shared with them. A team policy should ensure that secrets vital to operations are always shared with a designated admin. Whether a mechanism to enforce this policy (e.g. mandatory admin share on creation) should be added to Keepiq is left for a future exploration session.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
