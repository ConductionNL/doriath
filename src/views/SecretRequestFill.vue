<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Public secret-request fill-in page mounted at /share/request/:token.

  Phase 1 — fetch the request metadata via
  `useSecretRequestStore.fetchPublicRequest(token)`. If the request is
  expired / fulfilled / locked the page renders a status message and
  stops.

  Phase 2 — render an input per requested field. On submit the store
  encrypts each value client-side (RSA-OAEP-SHA256) and POSTs the
  blobs. Plaintext NEVER hits the network.

  @spec openspec/changes/implement-secret-requests/tasks.md#task-8.1
-->
<template>
	<div class="keepiq-secret-request-fill" data-testid="secret-request-fill">
		<header>
			<h1>{{ t('keepiq', 'Fill in secret') }}</h1>
		</header>

		<!--
		  Checked before anything else, because every path below encrypts in the
		  browser. Without WebCrypto the page previously died on
		  `crypto.subtle.importKey` with "Cannot read properties of undefined",
		  which tells an external recipient nothing and gives them no reason to
		  suspect the URL's scheme. They cannot fix the instance, but they CAN open
		  the https:// form of the same link — so say that.
		-->
		<div
			v-if="cryptoUnavailable"
			class="keepiq-secret-request-fill__error"
			data-testid="fill-insecure-context">
			<p>
				{{
					t(
						'keepiq',
						'This page needs a secure (https://) connection, because your value is encrypted in your browser before it is sent.',
					)
				}}
			</p>
			<p>
				{{
					t(
						'keepiq',
						'Open the same link with https:// at the start. If it still does not work, ask the person who sent it.',
					)
				}}
			</p>
		</div>

		<p
			v-else-if="store.loading && !store.publicRequest"
			class="keepiq-secret-request-fill__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<div
			v-else-if="loadError"
			class="keepiq-secret-request-fill__error"
			data-testid="fill-load-error">
			<p>{{ loadMessage }}</p>
		</div>

		<div
			v-else-if="store.publicRequest && unavailableMessage"
			class="keepiq-secret-request-fill__unavailable"
			data-testid="fill-unavailable">
			<p>{{ unavailableMessage }}</p>
		</div>

		<form
			v-else-if="
				store.publicRequest && store.publicRequest.status === 'pending'
			"
			class="keepiq-secret-request-fill__form"
			data-testid="fill-form"
			@submit.prevent="onSubmit">
			<label
				v-for="field in store.publicRequest.requested_fields"
				:key="field"
				class="keepiq-secret-request-fill__field">
				<span>{{ field }}</span>
				<input
					v-model="values[field]"
					:type="inputType(field)"
					autocomplete="off"
					required
					:data-testid="`fill-field-${field}`" />
			</label>

			<p
				v-if="submitError"
				class="keepiq-secret-request-fill__submit-error"
				data-testid="fill-submit-error">
				{{ submitError }}
			</p>

			<p
				v-if="submitted"
				class="keepiq-secret-request-fill__success"
				data-testid="fill-success">
				{{ t('keepiq', 'Thanks — the secret has been delivered.') }}
			</p>

			<button
				v-if="!submitted"
				type="submit"
				class="primary"
				data-testid="fill-submit"
				:disabled="busy">
				{{ busy ? t('keepiq', 'Encrypting…') : t('keepiq', 'Submit') }}
			</button>
		</form>
	</div>
</template>

<script>
import { useSecretRequestStore } from '../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestFill',
	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			values: {},
			busy: false,
			submitted: false,
			submitError: null,
			loadError: null,
			loadReason: null,
			store: useSecretRequestStore(),
		}
	},

	computed: {
		/**
		 * Whether the browser can encrypt at all.
		 *
		 * `crypto.subtle` exists only in a secure context, so plain http on any
		 * host other than localhost leaves it undefined. Measured: on
		 * http://nextcloud.local `isSecureContext` is false and `crypto.subtle` is
		 * undefined; over https both are fine.
		 *
		 * Both are checked rather than just one — a browser could in principle
		 * report a secure context without exposing SubtleCrypto, and the failure
		 * this prevents is a raw TypeError shown to a stranger.
		 *
		 * @return {boolean} True when the fill flow cannot possibly succeed.
		 *
		 * @spec exclude Environment precondition, not a spec'd requirement: the
		 *   specs describe what the fill flow does, and this is the case where the
		 *   browser cannot run it at all. Pinned by the view's own spec instead.
		 */
		cryptoUnavailable() {
			return (
				typeof window === 'undefined'
				|| window.isSecureContext === false
				|| typeof window.crypto?.subtle === 'undefined'
			)
		},

		/**
		 * What a recipient reads when the link cannot be filled.
		 *
		 * Prefers the server's machine-readable `reason` over its `message`, and
		 * that ordering is the whole point. The endpoint REFUSES a non-pending
		 * request, so the store rejects and `publicRequest` stays null — meaning
		 * `unavailableMessage` below never fires, and this branch is what the
		 * recipient actually sees. Measured on a real expired request before this
		 * existed: the page rendered "Request has expired", the PHP exception
		 * string, in English, to a recipient whose locale is one of 36.
		 *
		 * Falls back to the server message when the reason is `unknown` or absent
		 * (an older server, or a failure with no response at all) — an untranslated
		 * sentence beats a blank page.
		 *
		 * @return {string|null} The message to render, or null while none applies.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		loadMessage() {
			return this.messageForReason(this.loadReason) || this.loadError
		},

		/**
		 * The message for a request the server returned WITHOUT refusing.
		 *
		 * Retained for the case where a non-pending request is served with 200 —
		 * which the current endpoint never does. Kept rather than deleted because
		 * it costs one computed and it is the branch that would carry a status the
		 * server decides to describe instead of refuse.
		 *
		 * @return {string|null} The message to render, or null when none applies.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		unavailableMessage() {
			return this.messageForReason(this.store.publicRequest?.status)
		},
	},

	/**
	 * Resolve the token, and capture WHY if it cannot be filled.
	 *
	 * Both the reason and the message are kept. The reason is what the page can
	 * translate; the message is the fallback for a server that sent no reason, and
	 * for anything that failed before a response existed at all.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	async mounted() {
		this.loadError = null
		this.loadReason = null
		try {
			await this.store.fetchPublicRequest(this.token)
		} catch (e) {
			this.loadReason = e?.response?.data?.reason || null
			this.loadError =
				e?.response?.data?.message
				|| e?.message
				|| t('keepiq', 'Request not found')
		}
	},

	methods: {
		/**
		 * Translate a refusal reason into a sentence for the recipient.
		 *
		 * The reason slugs come from SecretRequestPolicy::REASONS. They are matched
		 * here rather than in two places so the refused case and the described case
		 * can never word the same condition differently.
		 *
		 * `unknown` deliberately returns null: the server could not explain the
		 * refusal, so inventing a confident sentence would be worse than showing
		 * whatever it did say.
		 *
		 * @param {string|null|undefined} reason The server's reason slug.
		 *
		 * @return {string|null} The translated sentence, or null when there is none.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		messageForReason(reason) {
			switch (reason) {
				case 'fulfilled':
					return t('keepiq', 'This request has already been fulfilled.')
				case 'declined':
					return t('keepiq', 'This request was declined.')
				case 'locked':
					return t(
						'keepiq',
						'This request is temporarily unavailable while a compromise recovery is in progress.',
					)
				case 'expired':
					return t('keepiq', 'This request has expired.')
				case 'not-found':
					return t('keepiq', 'This request could not be found.')
				default:
					return null
			}
		},

		inputType(field) {
			const name = String(field || '').toLowerCase()
			if (
				name.includes('password')
				|| name.includes('key')
				|| name.includes('secret')
			) {
				return 'password'
			}
			return 'text'
		},

		/**
		 * Encrypt the filled-in values under the requester's certificate in
		 * the browser and submit them against the request token.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 * @spec openspec/specs/secret-requests/spec.md#requirement-field-validation
		 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
		 */
		async onSubmit() {
			this.submitError = null
			this.busy = true
			try {
				await this.store.submitFill(this.token, this.values)
				this.submitted = true
			} catch (e) {
				this.submitError =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to submit')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-secret-request-fill {
	box-sizing: border-box;
	width: 100%;
	max-width: 480px;
	/* Centred by the public shell's flex wrapper (App.vue). */
	margin: auto;
	padding: 32px;
	border-radius: var(--border-radius-container, 16px);
	background-color: var(--color-main-background, #fff);
	box-shadow: 0 8px 40px rgba(0, 0, 0, 0.2);
}

.keepiq-secret-request-fill__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.keepiq-secret-request-fill__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.keepiq-secret-request-fill__submit-error {
	color: var(--color-error-text);
	font-size: 13px;
}

.keepiq-secret-request-fill__success {
	color: var(--color-success-text);
	font-weight: 600;
}

.primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 10px 20px;
	border-radius: var(--border-radius, 4px);
}
</style>
