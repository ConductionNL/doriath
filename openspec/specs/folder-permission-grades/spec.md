# Folder Permission Grades Specification

**Status**: done

**OpenSpec changes:**
- `folder-permission-grades` (2026-07-17) — Read/write permission grades on team-folder membership: a `write` grade authorizes a non-owner to update a folder secret for all recipients via the existing client-side re-encrypt-for-all sync path, server-authorized on the grant; owner keeps membership management; grade changes and non-owner writes audited. Depends on `team-folder-sharing`.

## Purpose

@e2e exclude The permission-grade authorization contract is enforced server-side on the sync path and exercised through PHPUnit; the one browser-observable flow (write member edits, second recipient sees the new value) is covered by the change's Playwright e2e task, not by this evergreen spec.

`team-folder-sharing` makes every folder member read-only: a member holds a per-recipient RSA copy and can view it, but only the folder owner (or a delegate) can push a new value to the whole team. Real teams need shared **editable** credentials — rotating a shared service password or CI token — without funnelling every rotation through the owner.

This feature adds a permission grade (`read` default, or `write`) to each team-folder membership. A `write` grade authorizes the member to update a folder secret's value for all recipients. Because Keepiq is zero-knowledge (ADR-003), a `write` grade is **not** a shared key: the writer's browser re-encrypts the new value under every recipient's public certificate and pushes it through the existing sync path, and the server accepts the fan-out only because the writer holds a `write` grant. Envelope crypto is unchanged; the server holds zero plaintext. The owner alone manages membership and grades.

## Requirements

### Requirement: Team-folder membership carries a read or write grade
The system MUST record a `read` (default) or `write` grade on every team-folder membership. A `read` grade MUST grant exactly the access team-folder-sharing grants today. A `write` grade MUST additionally authorize value updates that propagate to all recipients. Only the folder owner MUST be able to set or change a grade.

#### Scenario: New membership defaults to read
- GIVEN an owner shares a folder without specifying a grade
- WHEN the membership is created
- THEN the system MUST set the grade to `read` and the member MUST NOT be able to push a value update to the team

#### Scenario: Non-owner cannot change a grade
- GIVEN a member of a shared folder who is not its owner
- WHEN they attempt to change any member's grade
- THEN the system MUST reject the request with a forbidden response

### Requirement: A write-grade member may update a folder secret for all recipients
The system MUST allow a `write`-grade member to update a folder secret such that the change propagates to every recipient. The value MUST be re-encrypted under each recipient's public certificate by the writer's client; the server MUST NOT decrypt, re-encrypt, or hold plaintext.

#### Scenario: Write-grade member rotates a shared credential
- GIVEN user W holds a `write` grade on a folder containing secret S shared to recipients R
- WHEN W submits an update for S with one re-encrypted blob per recipient
- THEN the system MUST accept the fan-out and update every recipient's copy without decrypting any blob

#### Scenario: Read-grade member cannot write
- GIVEN user V holds a `read` grade on a folder containing secret S
- WHEN V attempts a value update that would propagate to recipients
- THEN the system MUST reject the request and change no recipient's copy

### Requirement: Effective grade is the highest grade along the ancestor folder chain
The system MUST compute a member's effective grade for a secret as the highest grade (`write` outranks `read`) granted by any ancestor team folder of the secret's folder. A subfolder MAY raise the grade; it MUST NOT lower it below any ancestor's grade.

#### Scenario: Subfolder raises the effective grade
- GIVEN folder F grants member M `read`, and subfolder T of F grants M `write`
- WHEN the effective grade for a secret in T is resolved for M
- THEN it MUST be `write`

### Requirement: Grade changes and non-owner writes are audited
The system MUST dispatch a typed event when a grade changes and MUST attribute every non-owner write to the writer. Events MUST carry only identifiers — never key material or plaintext.

#### Scenario: Non-owner write attributed to the writer
- GIVEN a `write`-grade member W (not the owner) updates a folder secret
- WHEN the fan-out is accepted
- THEN a secret-updated audit event MUST be dispatched with the actor set to W

## User Stories

- As a team member trusted with a shared service account, I want to rotate its password so that my whole team gets the new value without waiting for the folder owner
- As a folder owner, I want to grant specific members write access while keeping others read-only, so that only trusted people can change shared credentials
- As a folder owner, I want to keep sole control of who is in the folder and at what grade, so that access management stays with me
- As a security officer, I want every shared-credential change attributed to the person who made it, so that rotations are traceable

## Acceptance Criteria

- [ ] Every team-folder membership carries a `read` (default) or `write` grade
- [ ] Only the folder owner can set or change a member's grade
- [ ] A `read` member behaves exactly as under team-folder-sharing (hold a copy, view only)
- [ ] A `write` member can update a folder secret's value; the change propagates to all recipients as per-recipient RSA copies re-encrypted in the writer's browser
- [ ] The server accepts a non-owner fan-out only when the writer holds a `write` grade on an ancestor team folder
- [ ] The server never decrypts, re-encrypts, or holds plaintext at any step
- [ ] A secret's effective grade is the highest grade any ancestor membership grants; subfolders may raise but not lower it
- [ ] Grade changes re-encrypt nothing
- [ ] Grade changes and non-owner writes are audited with identifiers only; non-owner writes are attributed to the writer

## Notes

- Depends on `team-folder-sharing` (owns the `keepiq_team_folder_members` table this feature adds a `grade` column to).
- Out of scope for v1: a `manage`/co-owner grade (membership management stays owner-only), per-field grades, and narrowing a subfolder's grade below an ancestor's.
- Related ADRs: ADR-001 (own tables), ADR-003 (encryption architecture — write-without-read, public certs server-visible, zero server-side plaintext).
