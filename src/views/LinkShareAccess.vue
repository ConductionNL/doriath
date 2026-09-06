<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Public link-share access page mounted at /share/link/:token.

  Two-phase recipient flow:

   Phase 1 — fetch the public-share metadata (ciphertext blob + Argon2id
     salt) by token. Server has NO Nextcloud session. 404 is the generic
     not-found-or-expired response.

   Phase 2 — recipient types the access password; the page derives the
     AES-GCM key via Argon2id, decrypts the snapshot client-side, and
     POSTs a /confirm so the server can increment the usage count and
     auto-delete on cap.

  The blob is decrypted in the browser; the password NEVER reaches the
  server. A wrong password records a failed attempt via the `failed=1`
  re-fetch so the brute-force counter ticks.

  The revealed secret renders as labelled FIELD ROWS with a per-field
  copy button — the way the detail sidebar (and Proton Pass/Passwork)
  present a credential — not as a raw <dl>. Card/identity secrets store
  a JSON composite in `key` (card-identity-items D1/D2); it is parsed
  and rendered as its individual fields, and sensitive values are
  masked until the recipient reveals them.

  @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
  @spec openspec/changes/implement-link-sharing/tasks.md#task-9.1
-->
<template>
	<div class="keepiq-link-share-access" data-testid="link-share-access">
		<header class="keepiq-link-share-access__header">
			<h1>{{ t('keepiq', 'Shared secret') }}</h1>
		</header>

		<p v-if="loading && !share" class="keepiq-link-share-access__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<!-- Terminal not-found/expired state. Deliberately GENERIC, translated
		     copy rather than the raw (English) server message the view stores
		     in `loadError`: the controller answers one generic 404 for
		     not-found / expired / used-up by design, and a recipient gets a
		     recognisable error page (icon + heading + explanation) instead of
		     a bare red sentence. -->
		<div
			v-else-if="loadError"
			class="keepiq-link-share-access__not-found"
			data-testid="link-share-load-error">
			<div class="keepiq-link-share-access__not-found-icon">
				<LinkVariantOff :size="36" />
			</div>
			<h2>{{ t('keepiq', 'Link not found or expired') }}</h2>
			<p>
				{{
					t(
						'keepiq',
						'This share is not available. It may have expired or been used up.',
					)
				}}
			</p>
		</div>

		<form
			v-else-if="share && !snapshot"
			class="keepiq-link-share-access__form"
			data-testid="link-share-form"
			@submit.prevent="onUnlock">
			<p>
				{{
					t(
						'keepiq',
						'This link is protected with a password. Enter the password you received to view the secret.',
					)
				}}
			</p>

			<label>
				<span>{{ t('keepiq', 'Access password') }}</span>
				<input
					v-model="password"
					type="password"
					autocomplete="off"
					required
					data-testid="link-share-password" />
			</label>

			<p
				v-if="unlockError"
				class="keepiq-link-share-access__error"
				data-testid="link-share-unlock-error">
				{{ unlockError }}
			</p>

			<NcButton
				variant="primary"
				wide
				type="submit"
				:disabled="busy || !password"
				data-testid="link-share-submit">
				{{
					busy ? t('keepiq', 'Decrypting…') : t('keepiq', 'Reveal secret')
				}}
			</NcButton>
		</form>

		<div
			v-else-if="snapshot"
			class="keepiq-link-share-access__snapshot"
			data-testid="link-share-snapshot">
			<!-- Status banner at the TOP, where a notice about the whole card
			     belongs — tucked under the field list it read as an afterthought
			     glued to the card's edge. -->
			<NcNoteCard type="warning" class="keepiq-link-share-access__usage-note">
				{{
					t(
						'keepiq',
						'You have viewed this share. It will not be reachable again once the usage cap is reached.',
					)
				}}
			</NcNoteCard>
			<ul class="keepiq-link-share-access__fields">
				<li
					v-for="field in snapshotFields"
					:key="field.id"
					class="keepiq-link-share-access__field"
					:data-testid="`link-share-field-${field.id}`">
					<div class="keepiq-link-share-access__field-text">
						<span class="keepiq-link-share-access__field-label">{{
							field.label
						}}</span>
						<span
							class="keepiq-link-share-access__field-value"
							:class="{
								'keepiq-link-share-access__field-value--mono':
									field.secret,
							}"
							:data-testid="field.testid">
							{{
								field.secret && !revealed[field.id]
									? '••••••••••'
									: field.value
							}}
						</span>
					</div>
					<div class="keepiq-link-share-access__field-actions">
						<NcButton
							v-if="field.secret"
							variant="tertiary"
							:aria-label="
								revealed[field.id]
									? t('keepiq', 'Hide')
									: t('keepiq', 'Show')
							"
							:title="
								revealed[field.id]
									? t('keepiq', 'Hide')
									: t('keepiq', 'Show')
							"
							:data-testid="`link-share-toggle-${field.id}`"
							@click="toggleReveal(field.id)">
							<template #icon>
								<EyeOff v-if="revealed[field.id]" :size="20" />
								<Eye v-else :size="20" />
							</template>
						</NcButton>
						<CopyButton
							:value="field.value"
							:label="t('keepiq', 'Copy')" />
					</div>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import CopyButton from '../components/CopyButton.vue'
import { parsePayload } from '../cardIdentity/cardIdentity.js'
import { useLinkShareStore } from '../store/modules/linkShare.js'
import { objectToMembers } from '../utils/additionalFields.js'

/**
 * Composite members whose value stays masked until revealed. Everything a
 * shoulder-surfer can abuse directly; expiry/cardholder/address read like
 * ordinary metadata and stay visible so the row is recognisable.
 *
 * @type {string[]}
 */
const MASKED_COMPOSITE_FIELDS = ['number', 'cvv', 'pin', 'bsn']

export default {
	name: 'LinkShareAccess',

	components: {
		CopyButton,
		Eye,
		EyeOff,
		LinkVariantOff,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			token: '',
			share: null,
			snapshot: null,
			password: '',
			loading: false,
			busy: false,
			loadError: '',
			unlockError: '',
			priorFailure: false,
			/** @type {Object<string, boolean>} Per-field reveal state. */
			revealed: {},
		}
	},

	computed: {
		/**
		 * The decrypted snapshot as labelled display rows.
		 *
		 * A card/identity secret stores a JSON composite in `key`
		 * (card-identity-items D1/D2) — it is parsed into its individual
		 * fields so the recipient sees "Card number", "CVV" … rows instead of
		 * a raw JSON blob. A plain string `key` stays one "Secret value" row.
		 * Additional fields (an encrypted name→value blob) follow.
		 *
		 * @return {Array<{id: string, label: string, value: string, secret: boolean, testid: (string|undefined)}>} Display rows.
		 * @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
		 */
		snapshotFields() {
			if (!this.snapshot) {
				return []
			}

			const fields = []
			if (this.snapshot.name) {
				fields.push({
					id: 'name',
					label: this.t('keepiq', 'Name'),
					value: String(this.snapshot.name),
					secret: false,
				})
			}
			if (this.snapshot.login) {
				fields.push({
					id: 'login',
					label: this.t('keepiq', 'Login'),
					value: String(this.snapshot.login),
					secret: false,
				})
			}
			if (this.snapshot.url) {
				fields.push({
					id: 'url',
					label: this.t('keepiq', 'URL'),
					value: String(this.snapshot.url),
					secret: false,
				})
			}

			const composite = parsePayload(this.snapshot.key ?? '')
			if (composite !== null) {
				for (const [member, value] of Object.entries(composite)) {
					if (value === null || value === undefined || value === '') {
						continue
					}
					fields.push({
						id: `key-${member}`,
						label: this.compositeLabel(member),
						value: String(value),
						secret: MASKED_COMPOSITE_FIELDS.includes(member),
					})
				}
			} else if (this.snapshot.key) {
				fields.push({
					id: 'key',
					label: this.t('keepiq', 'Secret value'),
					value: String(this.snapshot.key),
					secret: true,
					testid: 'link-share-value',
				})
			}

			for (const member of objectToMembers(this.snapshot.additionalFields)) {
				fields.push({
					id: `extra-${member.name}`,
					label: member.name,
					value: member.value,
					// Additional fields live in the encrypted blob by design, so
					// they are treated as sensitive until the recipient reveals them.
					secret: true,
				})
			}

			return fields
		},
	},

	/**
	 * Resolve the share token from the route (or the URL path when no router
	 * is wired) and start the public Phase-1 fetch.
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
	 */
	mounted() {
		// The manifest router passes :token through props. When no
		// router is wired (component-test mount), we fall back to
		// reading the URL path so the spec can drive the flow.
		this.token = this.$route?.params?.token ?? this.tokenFromUrl()
		if (this.token) {
			this.loadShare()
		} else {
			this.loadError = this.t('keepiq', 'No share token in the URL.')
		}
	},

	methods: {
		tokenFromUrl() {
			const m = String(window?.location?.pathname ?? '').match(
				/share\/link\/([^/?#]+)/,
			)
			return m ? decodeURIComponent(m[1]) : ''
		},

		/**
		 * The translated label for a card/identity composite member.
		 *
		 * An unknown member falls back to its raw name rather than being
		 * dropped — the recipient was sent this data on purpose.
		 *
		 * @param {string} member The composite member name.
		 * @return {string} The display label.
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
		 */
		compositeLabel(member) {
			const labels = {
				number: this.t('keepiq', 'Card number'),
				expiry: this.t('keepiq', 'Expiry'),
				cvv: this.t('keepiq', 'CVV'),
				pin: this.t('keepiq', 'PIN'),
				cardholder: this.t('keepiq', 'Cardholder'),
				firstName: this.t('keepiq', 'First name'),
				lastName: this.t('keepiq', 'Last name'),
				address: this.t('keepiq', 'Address'),
				phone: this.t('keepiq', 'Phone'),
				email: this.t('keepiq', 'Email'),
				bsn: this.t('keepiq', 'BSN'),
			}
			return labels[member] ?? member
		},

		/**
		 * Toggle the reveal state of one masked field.
		 *
		 * @param {string} id The field row id.
		 * @return {void}
		 * @spec exclude Local view state flip — no requirement describes
		 *   per-field masking; the decrypt/confirm protocol is specified on
		 *   onUnlock().
		 */
		toggleReveal(id) {
			this.revealed = { ...this.revealed, [id]: !this.revealed[id] }
		},

		/**
		 * Phase 1: fetch the public share metadata and encrypted snapshot for
		 * the token. A 404 is the generic not-found-or-expired answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
		 */
		async loadShare() {
			this.loading = true
			this.loadError = ''
			try {
				const store = useLinkShareStore()
				this.share = await store.fetchPublicLinkShare(
					this.token,
					this.priorFailure,
				)
			} catch (e) {
				this.share = null
				this.loadError =
					e?.response?.data?.message
					?? this.t(
						'keepiq',
						'This share is not available. It may have expired or been used up.',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Phase 2: derive the Argon2id key from the supplied password,
		 * decrypt the snapshot in the browser, then confirm the view so the
		 * server can count (and auto-delete at) the usage cap.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-kdf-for-snapshot-encryption
		 * @spec openspec/specs/link-sharing/spec.md#requirement-brute-force-protection
		 * @spec openspec/specs/link-sharing/spec.md#requirement-auto-deletion
		 */
		async onUnlock() {
			if (!this.password || this.busy) {
				return
			}
			this.busy = true
			this.unlockError = ''

			const store = useLinkShareStore()
			try {
				this.snapshot = await store.decryptPublicSnapshot(
					this.share,
					this.password,
				)
				// Phase 2: confirm so the server can increment usage.
				await store.confirmPublicLinkShare(this.token)
			} catch (e) {
				// "Invalid password", NOT "does not match": nothing is being
				// compared against a second entry here — the recipient typed
				// one password and it failed to decrypt.
				this.unlockError = this.t(
					'keepiq',
					'Invalid password. Please try again.',
				)
				// Re-fetch with failed=1 so the brute-force counter ticks
				// server-side. We swallow re-fetch errors so a now-deleted
				// share simply surfaces as a load error on the next try.
				this.priorFailure = true
				try {
					await this.loadShare()
				} catch {}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-link-share-access {
	box-sizing: border-box;
	width: 100%;
	max-width: 480px;
	/* Centred by the public shell's flex wrapper (App.vue) — `auto` on both
	   axes keeps the card centred AND scrollable when taller than the
	   viewport. */
	margin: auto;
	padding: 32px;
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-container, 16px);
	box-shadow: 0 8px 40px rgba(0, 0, 0, 0.2);
}

.keepiq-link-share-access__header h1 {
	margin: 0 0 16px 0;
	font-size: 20px;
	font-weight: 600;
}

.keepiq-link-share-access__form label {
	display: block;
	margin: 12px 0;
}

.keepiq-link-share-access__form label span {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.keepiq-link-share-access__form input {
	box-sizing: border-box;
	display: block;
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
}

.keepiq-link-share-access__form input:focus-visible {
	border-color: var(--color-main-elevated, var(--color-primary-element, #21468b));
	outline: none;
}

.keepiq-link-share-access__form > .button-vue,
.keepiq-link-share-access__form > button {
	margin-top: 8px;
}

.keepiq-link-share-access__error {
	color: var(--color-error-text);
}

.keepiq-link-share-access__not-found {
	padding: 16px 0 8px;
	text-align: center;
}

.keepiq-link-share-access__not-found-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72px;
	height: 72px;
	margin: 0 auto 16px;
	border-radius: 50%;
	background: var(--color-background-dark, #ededed);
	background: color-mix(in srgb, var(--color-error, #d91f2d) 12%, transparent);
	color: var(--color-error, #d91f2d);
}

.keepiq-link-share-access__not-found h2 {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
}

.keepiq-link-share-access__not-found p {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

.keepiq-link-share-access__usage-note {
	margin: 0 0 16px 0;
}

.keepiq-link-share-access__fields {
	list-style: none;
	margin: 0;
	padding: 0;
}

.keepiq-link-share-access__field {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.keepiq-link-share-access__field:last-child {
	border-bottom: none;
}

.keepiq-link-share-access__field-text {
	min-width: 0;
}

.keepiq-link-share-access__field-label {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.keepiq-link-share-access__field-value {
	display: block;
	overflow-wrap: anywhere;
	color: var(--color-main-text, #222);
}

.keepiq-link-share-access__field-value--mono {
	font-family: var(--font-face-mono, monospace);
	font-size: 13px;
}

.keepiq-link-share-access__field-actions {
	display: flex;
	flex-shrink: 0;
	align-items: center;
}
</style>
