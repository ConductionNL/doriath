<template>
	<div class="compromise-recovery-form">
		<h2>{{ t('keepiq', 'Compromise recovery') }}</h2>

		<!-- Surface 1 of 3: before confirm. Shown in every phase, because
		     regaining access is never the same thing as being safe. -->
		<NcNoteCard type="warning">
			<p>
				{{
					t(
						'keepiq',
						'Every value stored in this vault must be assumed to have been exposed and must be changed at its source.',
					)
				}}
			</p>
			<p>
				{{
					t(
						'keepiq',
						'Rotating your key restores access to your secrets so you can go and change those values in an orderly fashion. It does not make the old values safe.',
					)
				}}
			</p>
		</NcNoteCard>

		<template v-if="phase === 'idle'">
			<NcPasswordField
				v-model="oldPassword"
				:label="t('keepiq', 'Old (compromised) password')"
				:disabled="loading" />

			<NcPasswordField
				v-model="newPassword"
				:label="t('keepiq', 'New password')"
				:disabled="loading" />

			<PasswordStrengthMeter
				v-if="newPassword"
				:password="newPassword"
				@strengthChange="onStrengthChange" />

			<NcPasswordField
				v-model="confirmPassword"
				:label="t('keepiq', 'Confirm new password')"
				:disabled="loading" />
		</template>

		<!-- Surface 2 of 3: during. Driven by the worker, counted across all
		     stores, and never reporting completion before the migration has
		     actually terminated. -->
		<div v-if="progress !== null" class="compromise-recovery-form__progress">
			<NcProgressBar :value="progressPercent" size="medium" />
			<p class="compromise-recovery-form__progress-label">
				{{ progressLabel }}
			</p>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- The server refuses to finalise while records remain that would lose
		     access. Name them, then let the user decide. -->
		<template v-if="needsAcknowledgement">
			<NcNoteCard type="error">
				<p>
					{{
						n(
							'keepiq',
							'%n secret could not be decrypted with your old key, so it did not migrate.',
							'%n secrets could not be decrypted with your old key, so they did not migrate.',
							lossCount,
						)
					}}
				</p>
				<p>
					{{
						t(
							'keepiq',
							'Finishing the rotation locks the old key, so these secrets can no longer be opened. Their stored data is kept, but you will need to set their values again at the source.',
						)
					}}
				</p>
			</NcNoteCard>

			<p
				v-if="lossListTruncated"
				class="compromise-recovery-form__list-detail">
				{{
					t('keepiq', 'Showing {shown} of {total}.', {
						shown: unrecoverable.length,
						total: lossCount,
					})
				}}
			</p>

			<ul class="compromise-recovery-form__list">
				<li v-for="item in unrecoverable" :key="item.id">
					<!--
						Falls back to the record id, and names the store. Version
						and attachment-grant failures carry no secret name, so
						this list rendered blank rows directly above a "losing
						access to N secrets" button — for exactly the record
						types the accounting blockers were about.
					-->
					<span class="compromise-recovery-form__list-name">{{
						describeRecord(item)
					}}</span>
					<span class="compromise-recovery-form__list-detail">{{
						item.error
					}}</span>
				</li>
			</ul>

			<!-- Finishing carries a proof over the old key. When the run was
			     resumed (the old password is not retained) re-ask for it. -->
			<NcPasswordField
				v-if="needsReauth"
				v-model="oldPassword"
				:label="
					t('keepiq', 'Re-enter your previous master password to finish')
				"
				:disabled="loading" />

			<div class="compromise-recovery-form__actions">
				<NcButton :disabled="loading" @click="handleRetry">
					{{ t('keepiq', 'Try these again') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="loading || (needsReauth && oldPassword === '')"
					@click="handleAcceptLosses">
					{{
						n(
							'keepiq',
							'Finish anyway, losing access to %n secret',
							'Finish anyway, losing access to %n secrets',
							lossCount,
						)
					}}
				</NcButton>
			</div>
		</template>

		<!-- Surface 3 of 3: after. Counts and the failure list, and deliberately
		     no wording that implies the vault is now secure. -->
		<template v-if="phase === 'terminal'">
			<NcNoteCard type="info">
				<p>
					{{
						n(
							'keepiq',
							'Key rotation finished. %n secret was re-encrypted under your new key.',
							'Key rotation finished. %n secrets were re-encrypted under your new key.',
							result.migrated,
						)
					}}
				</p>
				<p v-if="result.droppedVersions > 0">
					{{
						n(
							'keepiq',
							'%n older version was dropped because only recent history can be carried across.',
							'%n older versions were dropped because only recent history can be carried across.',
							result.droppedVersions,
						)
					}}
				</p>
				<p>
					{{
						t(
							'keepiq',
							'The migrated values are still to be considered exposed. Change each of them at its source.',
						)
					}}
				</p>
			</NcNoteCard>

			<template v-if="result.failures.length > 0">
				<NcNoteCard type="warning">
					{{
						n(
							'keepiq',
							'%n secret did not migrate.',
							'%n secrets did not migrate.',
							result.failures.length,
						)
					}}
				</NcNoteCard>
				<ul class="compromise-recovery-form__list">
					<li v-for="item in result.failures" :key="item.store + item.id">
						<span class="compromise-recovery-form__list-name">{{
							item.name || item.id
						}}</span>
						<span class="compromise-recovery-form__list-detail">{{
							item.error
						}}</span>
					</li>
				</ul>
			</template>

			<!-- Emergency contacts that could not be re-enveloped (the grantee
			     had no reachable certificate) were invalidated by the completion
			     sweep. Name them so the owner re-establishes exactly those; a
			     rotation where every contact migrated shows nothing here. -->
			<template v-if="residualContacts.length > 0">
				<NcNoteCard
					type="warning"
					data-testid="compromise-recovery-residual">
					{{
						n(
							'keepiq',
							'Emergency access for %n contact could not be carried across and was removed. Re-establish it so they can still recover your vault.',
							'Emergency access for %n contacts could not be carried across and was removed. Re-establish them so they can still recover your vault.',
							residualContacts.length,
						)
					}}
				</NcNoteCard>
				<ul class="compromise-recovery-form__list">
					<li
						v-for="grantee in residualContacts"
						:key="grantee"
						data-testid="compromise-recovery-residual-item">
						<span class="compromise-recovery-form__list-name">{{
							grantee
						}}</span>
					</li>
				</ul>
			</template>
		</template>

		<NcButton
			v-if="phase === 'idle'"
			variant="error"
			:disabled="!canSubmit || loading"
			@click="handleSubmit">
			{{
				loading
					? t('keepiq', 'Rotating keys…')
					: t('keepiq', 'Start key rotation')
			}}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField, NcProgressBar } from '@nextcloud/vue'
import PasswordStrengthMeter from './PasswordStrengthMeter.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'

export default {
	name: 'CompromiseRecoveryForm',
	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcProgressBar,
		PasswordStrengthMeter,
	},

	data() {
		return {
			oldPassword: '',
			newPassword: '',
			confirmPassword: '',
			strengthValid: false,
			loading: false,
			error: null,
			/** @type {'idle'|'running'|'terminal'} */
			phase: 'idle',
			/** @type {object|null} The outcome once the run has terminated. */
			result: null,
			/** @type {string|null} Retained so a retry can resume without re-asking. */
			activeOldPassword: null,
			/** @type {boolean} Show the re-auth field when completion needs a fresh proof. */
			needsReauth: false,
		}
	},

	computed: {
		/**
		 * Emergency contacts that could not be carried across the rotation and
		 * were invalidated — the owner is prompted to re-establish exactly these.
		 * Empty (so the block is hidden) when every contact migrated, on a resumed
		 * run that cannot re-envelope, or before a run has terminated.
		 *
		 * @return {string[]} The residual grantee ids.
		 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		residualContacts() {
			return this.result?.residualContacts ?? []
		},

		/**
		 * Gate the compromise-recovery submit on matching, strength-valid input.
		 *
		 * @return {boolean} True when recovery may be submitted.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
		 */
		canSubmit() {
			return (
				this.oldPassword
				&& this.newPassword
				&& this.confirmPassword
				&& this.newPassword === this.confirmPassword
				&& this.strengthValid
			)
		},

		/**
		 * How many records will actually lose access.
		 *
		 * The server's number when it has stated one, because the failure LIST
		 * it returns is capped for display: on a run where the new key material
		 * is wrong — exactly the run that produces thousands of failures — the
		 * list stops at the cap while the real loss is larger. Showing the list
		 * length would under-report the loss the user is being asked to accept.
		 *
		 * @return {number} The count to show and to acknowledge.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		lossCount() {
			return (
				useEncryptionSuiteStore().migrationRequiredAcknowledgement
				?? this.unrecoverable.length
			)
		},

		/**
		 * Whether the named list is shorter than the real loss.
		 *
		 * @return {boolean} True when records are lost but unnamed.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		lossListTruncated() {
			return this.lossCount > this.unrecoverable.length
		},

		/**
		 * Live progress across all stores, straight from the store.
		 *
		 * @return {object|null} The progress record, or null when not running.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
		 */
		progress() {
			return useEncryptionSuiteStore().migrationProgress
		},

		/**
		 * Progress as a percentage for the bar.
		 *
		 * @return {number} 0-100.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
		 */
		progressPercent() {
			const p = this.progress
			if (p === null || !p.total) {
				return 0
			}
			return Math.min(100, Math.round((p.done / p.total) * 100))
		},

		/**
		 * `n of m` across all stores. Never says "complete" — the terminal note
		 * card is the only thing allowed to report an outcome.
		 *
		 * @return {string} The progress label.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
		 */
		progressLabel() {
			const p = this.progress
			if (p === null) {
				return ''
			}
			if (p.phase === 'starting') {
				return this.t('keepiq', 'Preparing…')
			}
			// this.t rather than the bare global: the component's own translation
			// function is what handles placeholder interpolation.
			return this.t('keepiq', '{done} of {total} records re-encrypted', {
				done: p.done,
				total: Math.max(p.total, p.done),
			})
		},

		/**
		 * Whether the server is waiting for the user to accept a loss.
		 *
		 * @return {boolean} True when an acknowledgement is required.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		needsAcknowledgement() {
			return useEncryptionSuiteStore().migrationNeedsAcknowledgement
		},

		/**
		 * The secrets that would lose access if the user finishes anyway.
		 *
		 * @return {Array<object>} The failure list.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		unrecoverable() {
			const store = useEncryptionSuiteStore()
			return store.migrationFailures.length > 0
				? store.migrationFailures
				: store.migrationUnrecoverable
		},
	},

	methods: {
		/**
		 * Track password-strength validity from the strength meter.
		 *
		 * The new master password is held to the same floor as any other: this
		 * is what keeps the submit button disabled until the meter reports the
		 * floor is met. A rotation is not a licence to set a weak key.
		 *
		 * @param {object} root0 Strength event.
		 * @param {boolean} root0.isValid Whether the new password meets the floor.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-master-password-strength
		 */
		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},

		/**
		 * Initiate compromise recovery: full key rotation plus the secret
		 * migration from the old (leaked) master password to the new one.
		 *
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
		 */
		async handleSubmit() {
			this.loading = true
			this.error = null
			this.phase = 'running'
			this.activeOldPassword = this.oldPassword

			try {
				const store = useEncryptionSuiteStore()
				const outcome = await store.initiateCompromiseRecovery(
					this.oldPassword,
					this.newPassword,
				)

				this.result = outcome
				// Only terminal once the server actually accepted completion. When
				// an acknowledgement is pending the migration is still in progress,
				// so the dialog must not present an outcome yet.
				this.phase = store.migrationNeedsAcknowledgement
					? 'running'
					: 'terminal'
				this.oldPassword = ''
				this.newPassword = ''
				this.confirmPassword = ''
			} catch (e) {
				this.error = this.describe(e)
				this.phase = 'idle'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Retry the records that did not make it, without starting a new rotation.
		 *
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		async handleRetry() {
			if (this.activeOldPassword === null) {
				this.error = this.t(
					'keepiq',
					'Re-enter your old password to retry the remaining secrets.',
				)
				this.phase = 'idle'
				return
			}

			this.loading = true
			this.error = null

			try {
				const store = useEncryptionSuiteStore()
				const outcome = await store.resumeMigration(this.activeOldPassword)
				this.result = outcome
				this.phase = store.migrationNeedsAcknowledgement
					? 'running'
					: 'terminal'
			} catch (e) {
				this.error = this.describe(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A human label for one failed record.
		 *
		 * A secret head carries its name; a version or attachment grant does
		 * not, so it is identified by store plus record id instead of
		 * rendering an empty bullet.
		 *
		 * @param {{name: string|null, id: string, store: string|undefined}} item The failure.
		 * @return {string} A non-empty label.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		describeRecord(item) {
			if (item.name) {
				return item.name
			}

			const labels = {
				secrets: t('keepiq', 'Secret'),
				versions: t('keepiq', 'Version history entry'),
				attachmentGrants: t('keepiq', 'Attachment key'),
			}

			const kind = labels[item.store] ?? t('keepiq', 'Record')

			return `${kind} ${item.id}`
		},

		/**
		 * Finish the rotation, accepting that the listed secrets lose access.
		 *
		 * Locks the old key. Only reachable from an explicit click on a button
		 * that names how many secrets are affected.
		 *
		 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
		 */
		async handleAcceptLosses() {
			this.loading = true
			this.error = null

			try {
				const store = useEncryptionSuiteStore()
				// The server's own number, not this list's length. The list
				// holds one entry per failed RECORD across every pass; the
				// server counts distinct records currently failed and compares
				// with a strict `===`. Sending the list length made every click
				// refused and left the vault write-locked with no way out.
				//
				// Completion carries a vault-key proof over the OLD key. The old
				// password is retained from the run when it started here; on a
				// resumed run it is not, so the field below is re-shown.
				await store.acceptMigrationLosses(
					store.migrationStatus?.id,
					this.activeOldPassword || this.oldPassword,
				)
				this.needsReauth = false
				this.result = {
					...(this.result ?? { migrated: 0, droppedVersions: 0 }),
					failures: this.unrecoverable,
				}
				this.phase = 'terminal'
			} catch (e) {
				// A guard refusal (or a missing password) means: re-enter and
				// retry, not a dead end. Surface the password field.
				if (
					e?.code === 'key_proof_required'
					|| e?.response?.data?.error === 'key_proof_required'
				) {
					this.needsReauth = true
				}
				this.error = this.describe(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Turn an error into something a user can act on.
		 *
		 * @param {Error} e The caught error.
		 * @return {string} The message to display.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
		 */
		describe(e) {
			return (
				e?.response?.data?.message
				|| e?.message
				|| this.t('keepiq', 'Failed to start recovery')
			)
		},
	},
}
</script>

<style scoped lang="scss">
/* Colours come from Nextcloud theme variables only: this dialog is shown in
   both light and dark themes and hardcoded values do not adapt. */
.compromise-recovery-form {
	&__progress {
		margin: 1rem 0;
	}

	&__progress-label {
		margin-top: 0.25rem;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		margin: 0.5rem 0 1rem;
		padding: 0;
		list-style: none;
		max-height: 12rem;
		overflow-y: auto;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 8px);

		li {
			display: flex;
			flex-direction: column;
			gap: 0.125rem;
			padding: 0.5rem 0.75rem;
			border-bottom: 1px solid var(--color-border);

			&:last-child {
				border-bottom: none;
			}
		}
	}

	&__list-name {
		font-weight: bold;
		color: var(--color-main-text);
	}

	&__list-detail {
		font-size: 0.85em;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem;
		margin-bottom: 1rem;
	}
}
</style>
