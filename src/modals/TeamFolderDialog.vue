<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Team-folder sharing dialog (team-folder-sharing §5.1/§5.2). Shares the
  selected folder as a team folder, manages its user/group membership,
  and runs the client fan-out with a cancellable progress bar. All
  encryption happens in the browser; the idempotent server upsert makes
  a cancelled run resumable via the "needs re-share" reconcile pass.

  @spec openspec/changes/team-folder-sharing/tasks.md#5.1
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Team folder sharing')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="team-folder-dialog" data-testid="team-folder-dialog">
			<NcNoteCard v-if="error" type="error" data-testid="team-folder-error">
				{{ error }}
			</NcNoteCard>

			<!-- Not shared yet: offer to share the folder. -->
			<div v-if="!teamFolder" class="team-folder-dialog__share">
				<p>
					{{
						t(
							'keepiq',
							'Share the folder "{name}" and every secret in it with your team. Members receive their own encrypted copies — no plaintext ever reaches the server.',
							{ name: folderName },
						)
					}}
				</p>
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="team-folder-share"
					@click="onShareFolder">
					{{ t('keepiq', 'Share this folder') }}
				</NcButton>
			</div>

			<!-- Shared: membership management + fan-out state. -->
			<div v-else class="team-folder-dialog__members">
				<h4>{{ t('keepiq', 'Members') }}</h4>
				<ul
					v-if="members.length"
					class="team-folder-dialog__member-list"
					data-testid="team-folder-members">
					<li
						v-for="member in members"
						:key="member.id"
						class="team-folder-dialog__member">
						<component
							:is="
								member.memberType === 'group'
									? 'AccountGroup'
									: 'Account'
							"
							:size="18" />
						<span class="team-folder-dialog__member-name">{{
							member.memberId
						}}</span>
						<!-- Permission grade (folder-permission-grades §4.1):
						     owner-only; a write member may edit folder secrets
						     and fan the change out to the whole team. -->
						<select
							class="team-folder-dialog__grade"
							:value="member.grade || 'read'"
							:disabled="busy"
							:data-testid="`team-folder-grade-${member.memberId}`"
							@change="onGradeChange(member, $event.target.value)">
							<option value="read">{{ t('keepiq', 'Read') }}</option>
							<option value="write">
								{{ t('keepiq', 'Write') }}
							</option>
						</select>
						<NcButton
							variant="tertiary"
							:aria-label="t('keepiq', 'Remove member')"
							:disabled="busy"
							:data-testid="`team-folder-remove-${member.memberId}`"
							@click="onRemoveMember(member)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-else class="team-folder-dialog__empty">
					{{ t('keepiq', 'No members yet — add a user or group below.') }}
				</p>

				<div class="team-folder-dialog__add">
					<NcSelect
						v-model="newMemberType"
						class="team-folder-dialog__member-type"
						:options="memberTypeOptions"
						:reduce="(opt) => opt.value"
						:inputLabel="t('keepiq', 'Member type')"
						:clearable="false" />
					<!--
					  Pick from a list when there IS one, ask for an id by hand
					  when there is not.

					  GROUPS come from the server's own provisioning API, so
					  that list is there today; the endpoint pages, hence the
					  search below rather than one big fetch.

					  USERS are the ones holding an active suite (a recipient
					  without one has no public key to encrypt a copy for), and
					  the suites endpoint cannot report anyone but the caller
					  yet — see encryptionSuite.fetchSuiteOwners(). That half
					  resolves to empty until it can, so the text field stays
					  and nobody is left with a dropdown that cannot be opened.
					-->
					<NcSelect
						v-if="memberCandidates.length > 0"
						class="team-folder-dialog__member-input"
						:modelValue="newMemberId === '' ? null : newMemberId"
						:options="memberCandidates"
						:inputLabel="memberIdLabel"
						:loading="candidatesLoading"
						:disabled="busy"
						data-testid="team-folder-member-select"
						@update:modelValue="newMemberId = $event ?? ''"
						@search="onCandidateSearch" />
					<label v-else class="team-folder-dialog__id-field">
						<span>{{ memberIdLabel }}</span>
						<input
							v-model.trim="newMemberId"
							type="text"
							autocomplete="off"
							data-testid="team-folder-member-id" />
					</label>
					<NcButton
						class="team-folder-dialog__add-button"
						variant="secondary"
						:disabled="busy || newMemberId === ''"
						data-testid="team-folder-add-member"
						@click="onAddMember">
						{{ t('keepiq', 'Add member') }}
					</NcButton>
				</div>

				<!-- Fan-out progress (§5.1): chunked, cancellable, resumable. -->
				<div
					v-if="fanOut.running || pendingCount > 0"
					class="team-folder-dialog__fanout">
					<NcNoteCard
						v-if="!fanOut.running && pendingCount > 0"
						type="warning"
						data-testid="team-folder-needs-reshare">
						{{
							n(
								'keepiq',
								'%n secret copy still needs to be encrypted and shared.',
								'%n secret copies still need to be encrypted and shared.',
								pendingCount,
							)
						}}
					</NcNoteCard>
					<NcProgressBar
						v-if="fanOut.running"
						:value="progressPercent"
						size="medium"
						data-testid="team-folder-progress" />
					<div class="team-folder-dialog__fanout-actions">
						<NcButton
							v-if="fanOut.running"
							variant="tertiary"
							data-testid="team-folder-cancel-fanout"
							@click="store.cancelFanOut()">
							{{ t('keepiq', 'Cancel') }}
						</NcButton>
						<NcButton
							v-else
							variant="primary"
							:disabled="busy"
							data-testid="team-folder-run-fanout"
							@click="onRunFanOut">
							{{ t('keepiq', 'Encrypt and share now') }}
						</NcButton>
					</div>
				</div>

				<div class="team-folder-dialog__danger">
					<NcButton
						variant="error"
						:disabled="busy"
						data-testid="team-folder-unshare"
						@click="onUnshare">
						{{ t('keepiq', 'Stop sharing this folder') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcProgressBar,
	NcSelect,
} from '@nextcloud/vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import { useGroupStore } from '../store/modules/group.js'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

/**
 * How long the group search waits after the last keystroke.
 *
 * The provisioning API is a real server round-trip per term, and the picker
 * filters what it already has locally in the meantime, so there is nothing to
 * gain from querying every character.
 *
 * @type {number}
 */
const GROUP_SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'TeamFolderDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcProgressBar,
		NcSelect,
		Account,
		AccountGroup,
		Close,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		folderId: {
			type: String,
			default: null,
		},

		folderName: {
			type: String,
			default: '',
		},
	},

	emits: ['update:open'],
	data() {
		return {
			busy: false,
			error: null,
			newMemberType: 'user',
			newMemberId: '',
			pendingCount: 0,
			/** Pending group search, so keystrokes coalesce into one call. */
			groupSearchTimer: null,
		}
	},

	computed: {
		store() {
			return useTeamFolderStore()
		},

		teamFolder() {
			return this.folderId ? this.store.byFolderId(this.folderId) : null
		},

		members() {
			return this.teamFolder?.members ?? []
		},

		fanOut() {
			return this.store.fanOut
		},

		progressPercent() {
			if (this.fanOut.total === 0) {
				return 0
			}
			return Math.round((this.fanOut.done / this.fanOut.total) * 100)
		},

		/**
		 * The two membership kinds a team folder accepts: a Nextcloud user or
		 * a Nextcloud group.
		 *
		 * @return {Array<{label: string, value: string}>}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		memberTypeOptions() {
			return [
				{ label: this.t('keepiq', 'User'), value: 'user' },
				{ label: this.t('keepiq', 'Group'), value: 'group' },
			]
		},

		/**
		 * The label for the member-id control, which names whichever kind of
		 * member the type selector is on.
		 *
		 * @return {string}
		 */
		memberIdLabel() {
			return this.newMemberType === 'group'
				? this.t('keepiq', 'Group ID')
				: this.t('keepiq', 'User ID')
		},

		/**
		 * The ids the member control can offer, or `[]` when none can be
		 * listed — which is what decides between the picker and the free-text
		 * field in the template.
		 *
		 * The two kinds come from different places because they ARE different:
		 * a user must hold an encryption suite before a secret can be
		 * encrypted to them, so those come from the suites table; a group
		 * holds no key of its own (its members are resolved and key-checked
		 * when the fan-out runs), so those are simply the server's groups.
		 *
		 * Existing members are removed: re-adding one is at best a no-op, and
		 * a list that offers it invites the attempt.
		 *
		 * @return {Array<string>} Selectable member ids.
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		memberCandidates() {
			const isGroup = this.newMemberType === 'group'
			const taken = new Set(
				this.members
					.filter((member) => (member.memberType === 'group') === isGroup)
					.map((member) => member.memberId),
			)
			const candidates = isGroup
				? useGroupStore().groups
				: useEncryptionSuiteStore().suiteOwners

			return candidates.filter((id) => !taken.has(id))
		},

		/**
		 * Whether a candidate lookup is in flight, so the picker can say so
		 * rather than looking momentarily empty.
		 *
		 * @return {boolean}
		 */
		candidatesLoading() {
			return this.newMemberType === 'group' ? useGroupStore().loading : false
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.error = null
				this.refresh()
			}
		},

		/**
		 * A user id is not a group id. Keeping the old value across a type
		 * switch offered to add "bob" as a group — accepted by the field,
		 * refused by the server, and confusing in between.
		 */
		newMemberType() {
			this.newMemberId = ''
		},
	},

	beforeUnmount() {
		// A search that lands after the dialog is gone would write into a
		// store nothing is reading, and hold this component alive until it did.
		clearTimeout(this.groupSearchTimer)
	},

	methods: {
		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},

		/**
		 * Refresh the team-folder list and the pending reconcile count.
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 */
		async refresh() {
			// Best-effort and deliberately not awaited into the error path: who
			// can be offered as a member is a convenience, while the team
			// folder itself is the dialog's subject. A failure in either must
			// not replace the membership list with an error.
			useEncryptionSuiteStore()
				.fetchSuiteOwners()
				.catch(() => {})
			useGroupStore()
				.fetchGroups()
				.catch(() => {})

			try {
				await this.store.fetchTeamFolders()
				if (this.teamFolder) {
					const state = await this.store.reconcile(this.teamFolder.id)
					this.pendingCount = (state.missing ?? []).length
				}
			} catch (e) {
				this.error =
					e?.message || this.t('keepiq', 'Failed to load team folder')
			}
		},

		async onShareFolder() {
			this.busy = true
			this.error = null
			try {
				await this.store.shareFolder(this.folderId)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Re-run the group search as the user types in the picker.
		 *
		 * The provisioning API pages (GROUP_PAGE_SIZE at a time), so on an
		 * instance with more groups than one page the answer to "why is my
		 * group not in the list" has to be "keep typing" rather than "scroll".
		 * Users need none of this: their candidates are a suite-table list the
		 * picker already filters locally.
		 *
		 * @param {string} term The current search term.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		onCandidateSearch(term) {
			if (this.newMemberType !== 'group') {
				return
			}

			clearTimeout(this.groupSearchTimer)
			this.groupSearchTimer = setTimeout(() => {
				useGroupStore()
					.fetchGroups(term)
					.catch(() => {})
			}, GROUP_SEARCH_DEBOUNCE_MS)
		},

		async onAddMember() {
			this.busy = true
			this.error = null
			try {
				await this.store.addMember(
					this.teamFolder.id,
					this.newMemberType,
					this.newMemberId,
				)
				this.newMemberId = ''
				await this.refresh()
				// New members mean new missing pairs — run the fan-out now.
				await this.onRunFanOut()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		async onRemoveMember(member) {
			this.busy = true
			this.error = null
			try {
				await this.store.removeMember(this.teamFolder.id, member.id)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Change a membership's permission grade (owner-only; no
		 * ciphertext is touched — folder-permission-grades §4.1).
		 *
		 * @param {object} member The membership row.
		 * @param {string} grade The new grade ('read'|'write').
		 * @return {Promise<void>}
		 */
		async onGradeChange(member, grade) {
			this.busy = true
			this.error = null
			try {
				await this.store.setMemberGrade(this.teamFolder.id, member.id, grade)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		async onRunFanOut() {
			this.error = null
			try {
				await this.store.runFanOut(this.teamFolder.id)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		async onUnshare() {
			this.busy = true
			this.error = null
			try {
				await this.store.unshareFolder(this.teamFolder.id)
				this.$emit('update:open', false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.team-folder-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0 12px;
}

.team-folder-dialog__member-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.team-folder-dialog__member {
	display: flex;
	align-items: center;
	gap: 8px;
}

.team-folder-dialog__member-name {
	flex: 1;
}

/*
 * One row: type, member, Add. It still WRAPS — below ~512px the dialog goes
 * full-width and there is no room for three — but it no longer wraps on a
 * desktop dialog, where it used to leave the button stranded on its own line
 * under two half-width pickers.
 */
.team-folder-dialog__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

/*
 * NcSelect ships `min-width: 260px`, so two of them could not share a 600px
 * dialog with a button. Neither holds anything long — "User"/"Group" and an
 * id — so the row's own flex sizing decides instead. The selector carries the
 * library's three classes because that is what its rule has, and a single
 * scoped class would lose the cascade to it.
 */
.team-folder-dialog__add :deep(.nc-select.v-select.select) {
	min-width: 0;
}

/* Wide enough for the floating label, which sits INSIDE the control (NcSelect
   passes inputLabel to the search field, not to an external label). */
.team-folder-dialog__member-type {
	flex: 0 0 10rem;
}

.team-folder-dialog__member-input,
.team-folder-dialog__id-field {
	flex: 1 1 10rem;
	min-width: 0;
}

.team-folder-dialog__id-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

/*
 * NcSelect carries its own bottom margin, so a bottom-aligned row puts the
 * pickers' control boxes one grid baseline above the row's edge. The button
 * and the plain-input fallback take the same offset, which is what actually
 * lines the three bottoms up.
 */
.team-folder-dialog__add-button,
.team-folder-dialog__id-field {
	margin-block-end: var(--default-grid-baseline, 4px);
}

.team-folder-dialog__add-button {
	flex: 0 0 auto;
}

.team-folder-dialog__id-field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.team-folder-dialog__fanout {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.team-folder-dialog__fanout-actions {
	display: flex;
	justify-content: flex-end;
}

.team-folder-dialog__danger {
	border-top: 1px solid var(--color-border, #ddd);
	padding-top: 12px;
	display: flex;
	justify-content: flex-end;
}

.team-folder-dialog__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
