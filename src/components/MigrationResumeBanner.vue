<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Shell-level banner for an interrupted compromise-recovery migration.

  This is the surface for the case nothing else covers: the browser started a
  rotation and never finished it — a closed tab, a crash, a reload — so the
  migration row is still `in_progress`, the vault is still write-locked, and
  without this banner there is nothing anywhere in the UI that says so. The
  completion endpoint is never reached in that state, so the server's gate never
  gets a chance to explain itself either.

  Driven entirely by server state (GET /api/v1/migrations/status plus the derived
  remaining count), because a client-side count died with the tab that produced
  it.

  @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
-->
<template>
	<div
		v-if="migration !== null"
		class="keepiq-migration-banner"
		role="status"
		data-testid="migration-resume-banner">
		<div class="keepiq-migration-banner__message">
			<AlertOutline :size="20" />
			<span>
				<strong>{{ t('keepiq', 'Key rotation unfinished') }}</strong>
				{{ remainingLabel }}
				{{ t('keepiq', 'Your vault is read-only until it finishes.') }}
			</span>
		</div>

		<!-- Resume needs the OLD master password to unwrap the old private key.
		     The new one is already in the session, so it is not asked for. -->
		<form
			v-if="expanded && locked === false"
			class="keepiq-migration-banner__form"
			@submit.prevent="onResume">
			<NcPasswordField
				v-model="oldPassword"
				:label="t('keepiq', 'Your previous master password')"
				:disabled="busy" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="busy || oldPassword === ''">
				{{ busy ? t('keepiq', 'Resuming…') : t('keepiq', 'Resume now') }}
			</NcButton>
		</form>

		<p v-if="expanded && locked" class="keepiq-migration-banner__hint">
			{{ t('keepiq', 'Unlock your vault first, then resume.') }}
		</p>

		<!-- Abort is the non-destructive escape: it discards the unused new key
		     and returns the vault to the old key, which is still valid. The
		     server refuses if any secret has already been re-encrypted, so this
		     is only ever offered, never forced. -->
		<div v-if="expanded" class="keepiq-migration-banner__abort">
			<NcButton
				variant="tertiary"
				data-testid="migration-abort"
				:disabled="busy"
				@click="onAbort">
				{{
					busy
						? t('keepiq', 'Aborting…')
						: t('keepiq', 'Abort and keep my old key')
				}}
			</NcButton>
			<span class="keepiq-migration-banner__hint">
				{{
					t(
						'keepiq',
						'Discards the new key and unlocks your vault under the old one. Only possible while nothing has been re-encrypted yet.',
					)
				}}
			</span>
		</div>

		<p v-if="progressLabel" class="keepiq-migration-banner__hint">
			{{ progressLabel }}
		</p>

		<p v-if="error" class="keepiq-migration-banner__error">
			{{ error }}
		</p>

		<NcButton
			v-if="expanded === false"
			variant="secondary"
			data-testid="migration-resume-open"
			@click="expanded = true">
			{{ t('keepiq', 'Resume rotation') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcPasswordField } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'MigrationResumeBanner',
	components: { NcButton, NcPasswordField, AlertOutline },

	data() {
		return {
			oldPassword: '',
			busy: false,
			error: null,
			expanded: false,
		}
	},

	computed: {
		/**
		 * The in-progress migration, or null when there is nothing to resume.
		 *
		 * @return {object|null} The migration status record.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		migration() {
			return useEncryptionSuiteStore().migrationStatus
		},

		/**
		 * Whether the vault is locked, which resume requires it not to be.
		 *
		 * @return {boolean} True when locked.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		locked() {
			return useSessionStore().isLocked
		},

		/**
		 * How many records remain, phrased for a banner.
		 *
		 * Falls back to a countless sentence rather than showing "0 records" when
		 * the count could not be fetched — claiming zero would read as "finished".
		 *
		 * @return {string} The sentence fragment.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		remainingLabel() {
			const remaining = useEncryptionSuiteStore().migrationRemaining
			if (remaining === null) {
				return this.t(
					'keepiq',
					'Some secrets are still encrypted under your previous key.',
				)
			}

			return this.n(
				'keepiq',
				'%n secret is still encrypted under your previous key.',
				'%n secrets are still encrypted under your previous key.',
				remaining,
			)
		},

		/**
		 * Live progress while a resumed run is going.
		 *
		 * @return {string|null} The progress label, or null when idle.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		progressLabel() {
			const progress = useEncryptionSuiteStore().migrationProgress
			if (this.busy === false || progress === null || !progress.total) {
				return null
			}

			return this.t('keepiq', '{done} of {total} records re-encrypted', {
				done: progress.done,
				total: Math.max(progress.total, progress.done),
			})
		},
	},

	/**
	 * Ask the server whether a migration is parked, and how much is left.
	 *
	 * Both come from the server every time the shell mounts, because the whole
	 * point of this banner is the case where the client that started the
	 * migration is gone.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	async mounted() {
		const store = useEncryptionSuiteStore()

		// Arm the teardown. `registerLockReset` existed but nothing called it,
		// so src/migration/worker.js's claim — "the store terminates this
		// worker on vault lock, so no key reference survives a locked vault" —
		// was false: a migration worker holding the structured-cloned
		// oldPrivateKey / newPrivateKey / newPublicKey kept running after a
		// session timeout or an explicit "Lock vault". A defined-but-uncalled
		// teardown is identical to having none. This banner mounts with the
		// shell, which makes it the one place guaranteed to be present whenever
		// a migration could be running.
		store.registerLockReset()

		await store.fetchMigrationStatus()
		await store.fetchMigrationRemaining()
	},

	methods: {
		/**
		 * Resume the migration with the supplied previous master password.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
		 */
		async onResume() {
			this.busy = true
			this.error = null

			try {
				const store = useEncryptionSuiteStore()
				await store.resumeMigration(this.oldPassword)
				this.oldPassword = ''
				await store.fetchMigrationRemaining()

				// The store leaves the migration in place when the server wants a
				// loss acknowledged, so say where that decision now lives instead
				// of leaving an unexplained banner behind.
				if (store.migrationNeedsAcknowledgement === true) {
					this.error =
						store.migrationBlockedMessage
						|| this.t(
							'keepiq',
							'Some secrets could not be migrated. Open key rotation to decide what to do with them.',
						)
				}
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('keepiq', 'Could not resume the rotation.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Abort the migration, returning the vault to the old key.
		 *
		 * On a server refusal (records already moved) the message says so and
		 * the banner stays, pointing the user at resuming instead.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/harden-vault-key-material-guards/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
		 */
		async onAbort() {
			this.busy = true
			this.error = null

			try {
				await useEncryptionSuiteStore().abortMigration()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| this.t('keepiq', 'Could not abort the rotation.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
/*
 * Mirrors the offline banner's shell placement. Colours are theme variables
 * only: --color-warning is a background tint that inverts between themes, so it
 * is paired with --color-warning-text rather than a fixed foreground.
 */
.keepiq-migration-banner {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	background: var(--color-warning);
	color: var(--color-warning-text);
	border-bottom: 1px solid var(--color-warning-text);
}

.keepiq-migration-banner__message {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.keepiq-migration-banner__form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.5rem;
}

.keepiq-migration-banner__hint {
	margin: 0;
	font-size: 0.9em;
}

.keepiq-migration-banner__error {
	margin: 0;
	font-weight: 600;
	color: var(--color-error-text);
}
</style>
