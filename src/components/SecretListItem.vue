<template>
	<div
		class="secret-list-item"
		role="button"
		tabindex="0"
		:aria-label="t('keepiq', 'Open {name}', { name: secret.name })"
		:class="{ 'secret-list-item--blocked': secret.blocked }"
		@click="$emit('open', secret.id)"
		@keydown.enter="onRowActivate"
		@keydown.space.prevent="onRowActivate">
		<span class="secret-list-item__icon">
			<img
				v-if="faviconUrl && !faviconFailed"
				:src="faviconUrl"
				alt=""
				width="24"
				height="24"
				@error="faviconFailed = true" />
			<SecretTypeIcon v-else :typeId="secret.typeId" :size="24" />
		</span>

		<span class="secret-list-item__main">
			<span class="secret-list-item__name">
				{{ secret.name }}
				<StrengthBadge v-if="!secret.blocked" :secretId="secret.id" />
			</span>
			<span v-if="secret.url" class="secret-list-item__url">{{
				secret.url
			}}</span>
			<!--
			  Outstanding secret request (request-first-secret-requests). Without
			  this, a placeholder awaiting its first fill is indistinguishable from
			  a broken empty Secret — and placeholders are now the normal result of
			  asking someone for a credential. Never renders the fill token.
			-->
			<span
				v-if="requestState"
				class="secret-list-item__request"
				:data-testid="`secret-request-${requestState}`">
				<AccountQuestion :size="16" />
				{{
					requestState === 'awaiting-fill'
						? t('keepiq', 'Waiting for someone to fill this in')
						: t('keepiq', 'New values requested')
				}}
			</span>
			<span v-if="secret.tombstonedAt" class="secret-list-item__tombstone">
				{{ t('keepiq', 'Shared by a deleted account — no longer synced') }}
			</span>
			<!--
			  Driven by possiblyCompromisedAt on the secret payload, NOT by the
			  health pass: health analysis needs decrypted values, and a secret
			  that failed migration returns no ciphertext at all — so exactly the
			  rows that most need this warning would be the ones missing it. The
			  flag is plaintext metadata, so this renders on a locked vault too.
			  There is no dismiss affordance by design: it stays until the value
			  is actually replaced, which is what clears the flag server-side.
			-->
			<span
				v-if="secret.possiblyCompromisedAt"
				class="secret-list-item__compromised"
				data-testid="secret-possibly-compromised">
				<AlertOutline :size="16" />
				{{
					t(
						'keepiq',
						'Assume this value was exposed — change it at its source',
					)
				}}
			</span>
		</span>

		<!-- Which vault this secret lives in — only passed by cross-vault
		     hosts (the All-secrets root), where the row itself is the only
		     place that can say so (2026-09-03, Proton pattern). -->
		<VaultIndicator
			v-if="vault"
			class="secret-list-item__vault"
			:vault="vault"
			:data-testid="`secret-vault-dot-${secret.id}`" />

		<span v-if="secret.blocked" class="secret-list-item__blocked">
			<Lock :size="16" />
			{{ blockedLabel }}
		</span>

		<span
			v-else
			class="secret-list-item__actions"
			@click.stop
			@keydown.enter.stop
			@keydown.space.stop>
			<CopyButton
				:resolve="resolveKey"
				:label="t('keepiq', 'Copy password')"
				@copied="$emit('copied')" />
		</span>
	</div>
</template>

<script>
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import CopyButton from './CopyButton.vue'
import SecretTypeIcon from './SecretTypeIcon.vue'
import StrengthBadge from './StrengthBadge.vue'
import VaultIndicator from './VaultIndicator.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { resolveFaviconUrl } from '../utils/favicon.js'

/**
 * A single secret row: favicon (or type icon), name, url, and a copy button.
 * Copy triggers on-demand decryption of the key via the secret store.
 */
export default {
	name: 'SecretListItem',

	components: {
		AccountQuestion,
		Lock,
		AlertOutline,
		CopyButton,
		SecretTypeIcon,
		StrengthBadge,
		VaultIndicator,
	},

	props: {
		/** The secret (metadata + ciphertext, or blocked metadata). */
		secret: {
			type: Object,
			required: true,
		},

		/**
		 * Outstanding-request state for this secret, or null when there is none.
		 *
		 * `awaiting-fill` — the Secret holds no value yet, so it cannot be used.
		 * `re-request`    — it holds a value and new ones have been asked for, so
		 *                   it stays usable until they arrive.
		 *
		 * Deliberately a STATE, not the request object: a fill token in a list row
		 * travels into screenshots and over shoulders, and anyone entitled to it
		 * can get it from the request itself.
		 */
		requestState: {
			type: String,
			default: null,
		},

		/**
		 * The root VAULT this secret lives under, or null to show no vault
		 * indicator. Only cross-vault hosts pass it (the All-secrets root):
		 * inside a vault the location is the page itself, and a dot on every
		 * row would say nothing.
		 */
		vault: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			faviconFailed: false,
		}
	},

	computed: {
		faviconUrl() {
			return resolveFaviconUrl(this.secret.url)
		},

		/**
		 * Why this row is locked.
		 *
		 * "Suite revoked" was the only reason a row could be blocked, but a
		 * secret that compromise recovery could not carry across is blocked for a
		 * different reason and telling the user their suite was revoked would send
		 * them looking in the wrong place. The server sends the specific reason.
		 *
		 * @return {string} The label to show beside the lock icon.
		 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
		 */
		blockedLabel() {
			if (this.secret.unrecoverable === true) {
				return t('keepiq', 'Could not be migrated to your new key')
			}

			return t('keepiq', 'Locked — suite revoked')
		},
	},

	methods: {
		t,

		/**
		 * Open the secret when the row itself is activated via keyboard.
		 *
		 * Guards against keyboard activation of an inner control (e.g. the
		 * copy-password button) bubbling up and triggering row navigation:
		 * only the row element itself, when focused, opens the secret.
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 * @return {void}
		 */
		onRowActivate(event) {
			if (event.target !== event.currentTarget) {
				return
			}
			this.$emit('open', this.secret.id)
		},

		/**
		 * Resolve the decrypted key for the copy button.
		 *
		 * @return {Promise<string>}
		 */
		async resolveKey() {
			const secretStore = useSecretStore()
			const full = await secretStore.fetchSecret(this.secret.id)
			return full.key || ''
		},
	},
}
</script>

<style scoped>
.secret-list-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.secret-list-item:hover {
	background-color: var(--color-background-hover);
}

.secret-list-item:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	background-color: var(--color-background-hover);
}

.secret-list-item__main {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
}

.secret-list-item__name {
	font-weight: bold;
}

.secret-list-item__url {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.secret-list-item__blocked {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

/*
 * Deliberately louder than the muted url/tombstone lines beside it: this is the
 * one row-level signal telling the user a stored value is burned. --color-error-text
 * is the readable member of the error family in both themes.
 */
.secret-list-item__compromised {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 2px;
	font-weight: 600;
	color: var(--color-error-text);
}
</style>
