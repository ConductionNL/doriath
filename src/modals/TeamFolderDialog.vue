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
					  A picker with no free-text form, empty list included.
					  This is DELIBERATELY narrower than the server: membership
					  itself is not a file share, and assertMemberAddable asks
					  only that the user exists and is not the owner. The point
					  of the list is the narrowing — a member with no active
					  suite has no public key to encrypt their copies to, so
					  offering ids the instance's own sharee search does not
					  vouch for would defeat what the picker is for. On a
					  hardened instance (user enumeration off,
					  share-with-group-members-only) that set is small, and
					  small is the intent.

					  Both lists come from the SERVER's own directory, one
					  request per keystroke-burst, because neither is small
					  enough to hold locally: groups from the provisioning API,
					  users from Nextcloud's sharee search narrowed by keepiq's
					  shareability probe.
					-->
					<NcSelect
						class="team-folder-dialog__member-input"
						:modelValue="newMemberId === '' ? null : newMemberId"
						:options="memberCandidates"
						label="label"
						:reduce="(option) => option.id"
						:inputLabel="memberIdLabel"
						:loading="candidatesLoading"
						:disabled="busy"
						:error="candidatesError !== null"
						:helperText="candidatesError ?? ''"
						data-testid="team-folder-member-select"
						@update:modelValue="newMemberId = $event ?? ''"
						@search="onCandidateSearch" />
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
import { useGroupStore } from '../store/modules/group.js'
import { useShareStore } from '../store/modules/share.js'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

/**
 * How long a candidate search waits after the last keystroke.
 *
 * Every term is a real server round-trip — two of them for users, whose
 * shareability is probed after the sharee search — and the picker filters what
 * it already has in the meantime, so there is nothing to gain from querying
 * every character.
 *
 * @type {number}
 */
const CANDIDATE_SEARCH_DEBOUNCE_MS = 300

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
			/** Pending candidate search, so keystrokes coalesce into one call. */
			candidateSearchTimer: null,
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
		 * @spec exclude Presentation — a control label; the membership it
		 *   labels is specified on onAddMember().
		 */
		memberIdLabel() {
			return this.newMemberType === 'group'
				? this.t('keepiq', 'Group ID')
				: this.t('keepiq', 'User ID')
		},

		/**
		 * The members the control can offer, as `{ id, label }` options.
		 *
		 * The label is what a picker has to show and the id is what is
		 * submitted: on an LDAP or SSO instance a user id is a GUID, so a list
		 * of raw ids would be a list nobody can read. Groups have no display
		 * name and carry their id as the label.
		 *
		 * The two kinds come from different places because they ARE different:
		 * a user must hold an active encryption suite before a secret can be
		 * encrypted to them, so those are the sharee search narrowed by the
		 * shareability probe (share.searchShareableRecipients); a group holds
		 * no key of its own — its members are resolved and key-checked when
		 * the fan-out runs — so those are simply the server's groups.
		 *
		 * Existing members are removed: re-adding one is at best a no-op, and
		 * a list that offers it invites the attempt.
		 *
		 * @return {Array<{id: string, label: string}>} Selectable members.
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
				: useShareStore().shareableRecipients

			return candidates.filter((candidate) => !taken.has(candidate.id))
		},

		/**
		 * Whether a candidate lookup is in flight, so the picker can say so
		 * rather than looking momentarily empty.
		 *
		 * @return {boolean}
		 * @spec exclude Presentation — a spinner flag read off the stores that
		 *   own the lookups.
		 */
		candidatesLoading() {
			return this.newMemberType === 'group'
				? useGroupStore().loading
				: useShareStore().candidatesLoading
		},

		/**
		 * The text under the picker when the lookup itself failed, or `null`.
		 *
		 * An empty picker has three meanings — the directory said "nobody
		 * matches", the request 500'd, or the OCS call was refused — and the
		 * first is the only one the picker can say by itself. This is the
		 * quiet channel for the other two: the membership list on screen is
		 * still correct, so this must not become an error card that replaces
		 * it. The store's own message is not shown; it is untranslated and
		 * says nothing the user can act on.
		 *
		 * @return {string|null}
		 * @spec exclude Presentation — a helper line read off the stores that
		 *   own the lookups.
		 */
		candidatesError() {
			const failed =
				this.newMemberType === 'group'
					? useGroupStore().candidatesError
					: useShareStore().candidatesError

			return failed === null
				? null
				: this.t('keepiq', 'Could not reach the directory')
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
		 *
		 * @spec exclude Input hygiene — which pair may be added is specified
		 *   on onAddMember(), and the server validates it regardless.
		 */
		newMemberType() {
			this.newMemberId = ''
		},
	},

	/**
	 * Drop the pending candidate search, so one that lands after the dialog is
	 * gone cannot write into a store nothing is reading — or hold this
	 * component alive until it does.
	 *
	 * @spec exclude Lifecycle teardown — clears one timer; the search it would
	 *   have run is specified on onCandidateSearch().
	 */
	beforeUnmount() {
		clearTimeout(this.candidateSearchTimer)
	},

	methods: {
		/**
		 * Hand the dialog's own close back to the host that owns `open`.
		 *
		 * @param {boolean} value The requested open state.
		 *
		 * @spec exclude Presentation — re-emits one prop; what the dialog does
		 *   while open is specified on refresh().
		 */
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
			// not replace the membership list with an error — the stores record
			// it, and the picker's own helper line says so.
			useShareStore()
				.searchShareableRecipients()
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

		/**
		 * Share this folder as a team folder — the step that has to happen
		 * before there is any membership to manage.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 */
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
		 * Re-run the candidate search as the user types in the picker.
		 *
		 * Both directories page — the provisioning API by GROUP_PAGE_SIZE, the
		 * sharee search by the instance's own autocomplete limit — so on any
		 * sizeable instance the answer to "why is my colleague not in the
		 * list" has to be "keep typing" rather than "scroll". Which is also
		 * why this cannot be a local filter over one initial fetch.
		 *
		 * @param {string} term The current search term.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		onCandidateSearch(term) {
			clearTimeout(this.candidateSearchTimer)

			// vue-select clears its search text when an option is picked and
			// re-emits `search` with '', so without this the list resets to
			// page 1 about 300 ms after every member added.
			if (term === '') {
				return
			}

			const isGroup = this.newMemberType === 'group'

			this.candidateSearchTimer = setTimeout(() => {
				const search = isGroup
					? useGroupStore().fetchGroups(term)
					: useShareStore().searchShareableRecipients(term)
				search.catch(() => {})
			}, CANDIDATE_SEARCH_DEBOUNCE_MS)
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
		 *
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
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

		/**
		 * Encrypt and share the copies the reconcile pass found missing.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
		async onRunFanOut() {
			this.error = null
			try {
				await this.store.runFanOut(this.teamFolder.id)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * Stop sharing the folder, which revokes every derived copy with it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
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

.team-folder-dialog__member-input {
	flex: 1 1 10rem;
	min-width: 0;
}

/*
 * NcSelect carries its own bottom margin, so a bottom-aligned row puts the
 * pickers' control boxes one grid baseline above the row's edge. The button
 * takes the same offset, which is what actually lines the three bottoms up.
 */
.team-folder-dialog__add-button {
	flex: 0 0 auto;
	margin-block-end: var(--default-grid-baseline, 4px);
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
