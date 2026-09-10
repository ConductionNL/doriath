## ADDED Requirements

### Requirement: Suite Self-Service Operations Are Owner-Scoped

The suite self-service endpoints — viewing a suite (`show`), replacing its private-key envelope (`updatePrivateKey`), and revoking it (`revoke`) — MUST act only on a suite the calling session owns as a user: the suite's `owner_type` is `user` and its `owner_id` is the current user id. The system MUST refuse any other suite, whether it belongs to another user or to an application.

An application-owned suite MUST NOT be viewable, modifiable, or revocable through these self-service endpoints by any user, including an administrator. Application suites are managed through the application-lifecycle endpoints, and a destructive operation on one — revocation blocks every secret bound to it — MUST NOT be reachable from a user session that merely knows the suite id. The ownership check MUST express this as "the caller owns this suite", not as "the suite is not some *other* user's": a check written the second way silently admits every non-user suite, which is how an application suite becomes revocable by an unrelated session.

The refusal MUST NOT depend on how the session was authenticated; it is an authorization boundary over the suite's ownership, not a property of the login.

#### Scenario: A user cannot revoke another user's suite
@e2e exclude Controller-level authorization with no DOM surface; covered by PHPUnit EncryptionSuiteControllerTest.
- **GIVEN** an authenticated user
- **AND** an EncryptionSuite owned by a different user
- **WHEN** they request to revoke it by id
- **THEN** the system MUST refuse and MUST NOT call the revocation service

#### Scenario: A user cannot revoke an application's suite
@e2e exclude Controller-level authorization with no DOM surface; covered by PHPUnit EncryptionSuiteControllerTest.
- **GIVEN** an authenticated non-admin user with no relationship to an application
- **AND** an EncryptionSuite whose `owner_type` is `application`
- **WHEN** they request to revoke that suite by id
- **THEN** the system MUST refuse with an access-denied response
- **AND** MUST NOT call the revocation service
- **AND** the application's suite MUST remain `active`

#### Scenario: A user cannot overwrite an application suite's private-key envelope
@e2e exclude Controller-level authorization with no DOM surface; covered by PHPUnit EncryptionSuiteControllerTest.
- **GIVEN** an authenticated non-admin user
- **AND** an EncryptionSuite whose `owner_type` is `application`
- **WHEN** they submit a replacement private-key envelope for that suite by id
- **THEN** the system MUST refuse and MUST NOT persist any change to the suite

#### Scenario: A user manages their own suite normally
@e2e exclude Covered by PHPUnit EncryptionSuiteControllerTest happy-path cases.
- **GIVEN** an authenticated user
- **AND** an EncryptionSuite they own (`owner_type` `user`, `owner_id` the caller)
- **WHEN** they view, re-key, or revoke it
- **THEN** the system MUST allow the operation
