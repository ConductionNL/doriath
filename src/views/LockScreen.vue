<template>
	<div class="lock-screen">
		<div class="lock-screen__card">
			<!--
			  SYSTEMIC error banners sit above icon and title, matching the
			  Nextcloud login screen (its session-error banner tops the card).
			  Field-level mistakes (wrong password) render inline under the
			  input instead — also the login-screen pattern.
			-->
			<NcNoteCard v-if="!isSecureContext" type="error">
				{{
					t(
						'keepiq',
						'Keepiq requires a secure connection (HTTPS) to function. Please access this instance over HTTPS.',
					)
				}}
			</NcNoteCard>
			<!--
			  Suite check failed while online: fail closed. Showing the setup
			  form here would offer to create a NEW suite to a user who most
			  likely already has one (the check failed, so we cannot know).
			  Offline is the exception: the fetch always fails without a
			  network, but the unlock form is safe — offline unlock only
			  decrypts the local snapshot and can never create anything
			  server-side.
			-->
			<NcNoteCard v-else-if="checkFailed && offlineStore.online" type="error">
				{{
					t(
						'keepiq',
						'Could not determine whether your vault is already set up. To protect your existing vault, setup and unlock are unavailable until this check succeeds.',
					)
				}}
			</NcNoteCard>

			<!--
			  The icon is the unlock's own success signal: a successful unlock
			  swaps it for an OPEN lock and animates that swap in before the
			  redirect fires (the unlock handlers hold navigation for
			  UNLOCK_HOLD_MS — the animation plus a settle beat). Without the
			  hold the open lock would render for at most one frame and the
			  screen would just blink away.

			  A REJECTED password is the mirror image: the lock stays closed
			  and shakes red for LOCK_ERROR_MS before returning to black.
			  Both are second channels only — the padlock's shape says
			  locked-vs-open, and the field's inline error text says why —
			  so neither colour is load-bearing (WCAG 1.4.1).
			-->
			<!--
			  The screen-reader channel for everything the icon says in
			  colour and motion (WCAG 4.1.3). Red, green and the shake reach
			  nobody using a screen reader, and the field's helperText is
			  wired by aria-describedby — announced when the FIELD has focus,
			  which after clicking Unlock it does not: focus is on the button,
			  so a rejection could pass in complete silence.

			  Both regions are rendered UNCONDITIONALLY and only their text
			  changes. A live region inserted together with its message is
			  not reliably announced — the container has to be in the
			  accessibility tree before the text lands in it.

			  Two regions, because the two kinds of news deserve different
			  urgency: the unlock's success is polite (role="status"), a
			  rejected credential interrupts (role="alert"). The navigation
			  hold is what gives the polite one time to be spoken before the
			  redirect tears the screen down — which is why reduced motion
			  drops the ANIMATION but keeps a beat of that hold: reduced
			  motion means less movement, not less time, and it is a
			  preference screen-reader users commonly have on.

			  The suite check is deliberately not announced here: its text
			  would be in the region on the very first render, which is the
			  one case a live region does not announce, and the spinner's
			  own visible label already carries it (see `liveStatus`).
			-->
			<p class="lock-screen__sr-live" role="status">{{ liveStatus }}</p>
			<p class="lock-screen__sr-live" role="alert">{{ liveAlert }}</p>

			<div class="lock-screen__icon">
				<LockOpenVariantIcon
					v-if="unlocked"
					class="lock-screen__icon-open"
					:size="48"
					data-testid="lock-screen-icon-open" />
				<!--
				  The key is load-bearing, not bookkeeping: a CSS animation
				  bound to a class only restarts when the browser sees the
				  computed animation-name change ACROSS a style
				  recalculation. Toggling the class off and on can never
				  produce that within one task, so a repeat rejection would
				  redden without shaking. Bumping the key hands Vue a fresh
				  element instead, and a fresh element always animates.
				-->
				<LockIcon
					v-else
					:key="rejectionSeq"
					:class="{ 'lock-screen__icon-rejected': unlockRejected }"
					:size="48"
					data-testid="lock-screen-icon-closed" />
			</div>
			<!--
			  No title while setup-vs-unlock is unknown — pending, or failed
			  while online (the error card explains itself). Either title
			  would be a claim we cannot back yet: "Unlock Keepiq" reads
			  wrong to a user who has never set a password. Failed-while-
			  offline keeps the title: that state renders the unlock form.
			-->
			<h1
				v-if="!checking && !(checkFailed && offlineStore.online)"
				class="lock-screen__title">
				{{
					isFirstSetup
						? t('keepiq', 'Set up your master password')
						: t('keepiq', 'Unlock Keepiq')
				}}
			</h1>

			<!-- Insecure context: banner above says it all, no form renders. -->
			<template v-if="!isSecureContext" />

			<!--
			  Suite check in flight. The setup-vs-unlock decision MUST wait for
			  the server answer: `currentSuite` starts null, so rendering on it
			  immediately flashes the "set up your master password" form at
			  every reload — a surface that can POST a brand-new suite — before
			  flipping to the unlock form. Nothing but a spinner renders until
			  the check settles.
			-->
			<template v-else-if="checking">
				<div class="lock-screen__checking">
					<NcLoadingIcon :size="32" />
					<p>{{ t('keepiq', 'Checking your vault…') }}</p>
				</div>
			</template>

			<!-- Failed while online: banner above explains, only retry here. -->
			<template v-else-if="checkFailed && offlineStore.online">
				<NcButton variant="primary" :wide="true" @click="checkSuite">
					{{ t('keepiq', 'Try again') }}
				</NcButton>
			</template>

			<template v-else>
				<!--
				  Migration paused. The copy here used to say "enter your master
				  password to resume", which conflated two different passwords:
				  unlocking uses the CURRENT one and does not resume anything,
				  while resuming needs the PREVIOUS one. Unlocking is step one;
				  MigrationResumeBanner then asks for the old password and does
				  the actual resuming.
				-->
				<NcNoteCard v-if="hasPausedMigration" type="warning">
					{{
						t(
							'keepiq',
							'Key rotation is unfinished and your vault is read-only. Unlock to continue — you will then be asked for your previous master password to resume it.',
						)
					}}
				</NcNoteCard>

				<!--
				  First-time setup mode.

				  autocomplete carries the programmatic purpose WCAG 1.3.5
				  asks for, and it is `new-password` on BOTH fields here
				  because this credential is being minted: that keeps the
				  browser from autofilling the account password and lets a
				  manager offer to generate and store one, which is where a
				  vault master password belongs — nobody can recover it for
				  the user afterwards.
				-->
				<template v-if="isFirstSetup">
					<NcPasswordField
						v-model="masterPassword"
						:label="t('keepiq', 'Master password')"
						:disabled="loading"
						autocomplete="new-password"
						@blur="revealMismatch"
						@keyup.enter="handleSetup" />
					<PasswordStrengthMeter
						v-if="masterPassword"
						:password="masterPassword"
						@strengthChange="onStrengthChange" />
					<!--
					  Live mismatch feedback and setup failures render inline
					  under the field, matching the Nextcloud login screen's
					  wrong-password feedback (red outline + red helper text).
					  The inline text is the ACCESSIBLE channel: NcInputField
					  wires helperText to the input via aria-describedby, so
					  screen readers announce it — the button tooltip below
					  never reaches them (a disabled button is not focusable).
					-->
					<NcPasswordField
						v-model="confirmPassword"
						class="lock-screen__confirm"
						:label="t('keepiq', 'Confirm master password')"
						:disabled="loading"
						:error="showMismatch || !!error"
						:helperText="confirmHelperText"
						autocomplete="new-password"
						@blur="revealMismatch"
						@keyup.enter="handleSetup" />
					<!--
					  Tooltip is progressive enhancement for mouse users
					  hovering the disabled button; it sits on a wrapper span
					  because browsers don't reliably fire hover on disabled
					  buttons. Never the only channel (WCAG 1.4.13) — the
					  helper text above carries the same message persistently.
					-->
					<span
						class="lock-screen__submit"
						:title="
							showMismatch
								? t(
										'keepiq',
										'The password does not match. Please try again.',
									)
								: null
						">
						<NcButton
							variant="primary"
							:disabled="!canSubmitSetup || loading"
							:wide="true"
							@click="handleSetup">
							{{
								loading
									? t('keepiq', 'Setting up…')
									: t('keepiq', 'Set up vault')
							}}
						</NcButton>
					</span>
				</template>

				<!-- Normal unlock mode -->
				<template v-else>
					<NcButton
						v-if="passkeyOffered"
						variant="primary"
						:disabled="loading"
						:wide="true"
						data-testid="unlock-with-passkey"
						@click="handlePasskeyUnlock">
						<template #icon>
							<KeyIcon :size="20" />
						</template>
						{{
							loading
								? t('keepiq', 'Unlocking…')
								: t('keepiq', 'Unlock with passkey')
						}}
					</NcButton>

					<!--
					  Wrong-password (and passkey-fallback) errors render
					  inline under the field, matching the Nextcloud login
					  screen's wrong-password feedback (red outline + red
					  helper text) instead of a banner.

					  `current-password` is the programmatic purpose here
					  (WCAG 1.3.5) — unlike setup, this field asks for a
					  credential the user already holds, so a password
					  manager should fill it.
					-->
					<NcPasswordField
						v-model="masterPassword"
						:label="t('keepiq', 'Master password')"
						:disabled="loading"
						:error="!!error"
						:helperText="error || ''"
						autocomplete="current-password"
						@keyup.enter="handleUnlock" />
					<NcButton
						:variant="passkeyOffered ? 'secondary' : 'primary'"
						:disabled="!masterPassword || loading"
						:wide="true"
						data-testid="unlock-with-password"
						@click="handleUnlock">
						{{
							loading
								? t('keepiq', 'Unlocking…')
								: t('keepiq', 'Unlock')
						}}
					</NcButton>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import LockOpenVariantIcon from 'vue-material-design-icons/LockOpenVariant.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { usePasskeyStore } from '../store/modules/passkey.js'
import { useSessionStore } from '../store/modules/session.js'

/**
 * How long the open-lock swap takes to play, in milliseconds. Matches the
 * `lock-screen-unlock-in` keyframe duration in this component's
 * stylesheet — change both together, or navigation cuts the animation off.
 */
const UNLOCK_ANIMATION_MS = 400

/**
 * How long the open lock then simply sits there, in milliseconds, before
 * the redirect fires. Navigating the instant the keyframes end reads as
 * the screen being yanked away mid-gesture: the eye needs a beat on the
 * settled state to register "unlocked" as the outcome, rather than as a
 * flicker on the way out. Half a second is that beat — long enough to
 * land, short enough that the vault still feels immediate.
 */
const UNLOCK_SETTLE_MS = 500

/**
 * Total navigation hold when motion is allowed: the animation, then its
 * settle. Reduced motion holds UNLOCK_ANNOUNCE_MS instead.
 */
const UNLOCK_HOLD_MS = UNLOCK_ANIMATION_MS + UNLOCK_SETTLE_MS

/**
 * The navigation hold under prefers-reduced-motion, in milliseconds.
 *
 * Not zero, which is what this used to be: the padlock is not the only
 * channel the unlock speaks through. The polite live region needs the
 * screen to still exist when the screen reader gets to it, and the
 * redirect unmounts the screen — so with no hold at all, the one viewer
 * who cannot see the open padlock is also the one who never hears about
 * it. Reduced motion means less MOVEMENT, not less time, and it is a
 * preference blind screen-reader users commonly have on.
 *
 * Still the faster path of the two: this is the settle beat on its own,
 * without the animation in front of it.
 */
const UNLOCK_ANNOUNCE_MS = 500

/**
 * How long the closed lock stays red after a rejected password, in
 * milliseconds, before it returns to black. Long enough to be noticed by
 * someone whose eyes were on the field rather than the icon, short enough
 * that it has cleared before a second attempt is typed — a red lock still
 * sitting there over a fresh attempt would be reporting the wrong state.
 *
 * The shake plays INSIDE this window (see the stylesheet) and is motion,
 * so it is suppressed under prefers-reduced-motion; the red is not motion
 * and stays. Nothing waits for this window: unlike the unlock hold, the
 * screen is not going anywhere, so the timer is a visual reset only.
 */
const LOCK_ERROR_MS = 1100

export default {
	name: 'LockScreen',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		LockIcon,
		LockOpenVariantIcon,
		KeyIcon,
		PasswordStrengthMeter,
	},

	data() {
		return {
			masterPassword: '',
			confirmPassword: '',
			loading: false,
			error: null,
			strengthValid: false,
			passkeyOffered: false,
			/**
			 * Suite-check state machine: 'pending' (spinner, no form),
			 * 'resolved' (server answered — setup or unlock is now KNOWN),
			 * 'failed' (fetch rejected — fail closed, never show setup).
			 */
			suiteCheck: 'pending',
			/**
			 * Whether the user is "done typing" in the setup fields: set by
			 * a pause (debounce) or by leaving a field, cleared on every
			 * keystroke. Gates the mismatch message so it never flashes
			 * mid-word.
			 */
			mismatchSettled: false,
			/** @type {number|null} Debounce timer for mismatchSettled. */
			mismatchTimer: null,
			/**
			 * Whether the vault has just been unlocked — drives the open-lock
			 * icon and its animation. Purely visual: the authoritative locked
			 * state lives in the session store, and this screen is on its way
			 * out the moment this flips.
			 */
			unlocked: false,
			/** @type {number|null} Timer holding navigation for the unlock animation and its settle. */
			unlockTimer: null,
			/**
			 * Whether the last attempt was REJECTED — drives the shake and
			 * the red lock. Separate from `error`, which stays on screen as
			 * text until the next attempt: this flag is the momentary flash,
			 * so it clears itself after LOCK_ERROR_MS while the message the
			 * viewer actually reads stays put.
			 */
			unlockRejected: false,
			/** @type {number|null} Timer returning the rejected lock to black. */
			rejectedTimer: null,
			/**
			 * Rejection counter, used as the closed padlock's vnode key so
			 * every rejection renders a FRESH element. That is what makes
			 * the shake replay: see the key's own comment in the template.
			 */
			rejectionSeq: 0,
		}
	},

	computed: {
		/**
		 * Polite screen-reader narration of the one state that shows itself
		 * as nothing but an icon: the unlock's own success. Empty the rest
		 * of the time, so the region announces on change instead of
		 * re-reading itself.
		 *
		 * The suite check is deliberately NOT in here. `suiteCheck` starts
		 * 'pending', so a `checking` branch would have its text sitting in
		 * the region on the very FIRST render — precisely the
		 * region-and-message-arrive-together case that is not reliably
		 * announced — and would then go empty when the check settled,
		 * announcing nothing at all. All it actually did was put a second
		 * copy of the spinner's own visible "Checking your vault…" into the
		 * accessibility tree, for a screen reader browsing the page to read
		 * twice.
		 *
		 * @return {string} The status to announce, or '' for silence.
		 * @spec exclude Accessibility mirror of existing visual state — announces what the open-lock icon already shows; introduces no state of its own.
		 */
		liveStatus() {
			return this.unlocked
				? t('keepiq', 'Vault unlocked. Opening your vault…')
				: ''
		},

		/**
		 * Assertive narration of a rejected attempt. Deliberately the SAME
		 * string the field shows, so the two channels cannot drift; the
		 * systemic banners are not repeated here, because NcNoteCard already
		 * renders `type="error"` as role="alert" itself.
		 *
		 * @return {string} The alert to announce, or '' for silence.
		 * @spec exclude Accessibility mirror of existing visual state — re-announces the field's own error text; introduces no state of its own.
		 */
		liveAlert() {
			return this.error || ''
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia offline store with no domain logic.
		 */
		offlineStore() {
			return useOfflineStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia session store with no domain logic.
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia encryption-suite store with no domain logic.
		 */
		suiteStore() {
			return useEncryptionSuiteStore()
		},

		/**
		 * First-time setup ONLY when the server has positively confirmed no
		 * active suite exists. While the check is pending or failed this is
		 * false, so the create-a-new-suite surface can never render on a
		 * guess — reloading used to flash it before the fetch resolved.
		 *
		 * @return {boolean} True when the server confirmed there is no suite.
		 */
		isFirstSetup() {
			return this.suiteCheck === 'resolved' && !this.suiteStore.currentSuite
		},

		/**
		 * @return {boolean} True while the suite check is in flight.
		 * @spec exclude Suite-check state-machine accessor — presentation gating only; the fail-closed behaviour is specified on checkSuite.
		 */
		checking() {
			return this.suiteCheck === 'pending'
		},

		/**
		 * @return {boolean} True when the suite check failed.
		 * @spec exclude Suite-check state-machine accessor — presentation gating only; the fail-closed behaviour is specified on checkSuite.
		 */
		checkFailed() {
			return this.suiteCheck === 'failed'
		},

		hasPausedMigration() {
			return this.suiteStore.migrationStatus?.status === 'in_progress'
		},

		isSecureContext() {
			return window.isSecureContext
		},

		/**
		 * Raw mismatch between the two setup fields — only once both are
		 * filled, so the user isn't scolded while still typing the first one.
		 *
		 * @return {boolean} True when both fields are filled and differ.
		 * @spec exclude Inline form-feedback condition — pure presentation; no requirement prescribes the mismatch hint, and submission is guarded by canSubmitSetup.
		 */
		passwordsMismatch() {
			return (
				!!this.masterPassword
				&& !!this.confirmPassword
				&& this.masterPassword !== this.confirmPassword
			)
		},

		/**
		 * Whether the mismatch message may be SHOWN: the mismatch is real
		 * AND the user is done typing (paused or left the field). Keystrokes
		 * hide it again instantly, so it never nags mid-correction.
		 *
		 * @return {boolean} True when the mismatch feedback should render.
		 * @spec exclude Inline form-feedback timing — pure presentation; no requirement prescribes when the mismatch hint appears.
		 */
		showMismatch() {
			return this.mismatchSettled && this.passwordsMismatch
		},

		/**
		 * Helper text under the confirm field: the settled mismatch
		 * explanation, then a server-side setup error. Matching passwords
		 * deliberately get NO feedback of their own — the submit button
		 * enabling is the success signal.
		 *
		 * @return {string} The helper text ('' when there is nothing to say).
		 * @spec exclude Inline form-feedback copy selection — pure presentation over states specified elsewhere (checkSuite, handleSetup).
		 */
		confirmHelperText() {
			if (this.showMismatch) {
				return t('keepiq', 'The password does not match. Please try again.')
			}
			return this.error || ''
		},

		/**
		 * Gate the first-time setup submit on matching, strength-valid passwords.
		 *
		 * @return {boolean} True when setup may be submitted.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		canSubmitSetup() {
			return (
				this.masterPassword
				&& this.confirmPassword
				&& this.masterPassword === this.confirmPassword
				&& this.strengthValid
			)
		},
	},

	watch: {
		/** Every keystroke restarts the "done typing" debounce. */
		masterPassword: 'restartMismatchDebounce',
		confirmPassword: 'restartMismatchDebounce',
	},

	/**
	 * Load the user's suite and migration status to decide between the
	 * unlock, first-setup, and paused-migration screens.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	async created() {
		await this.checkSuite()
	},

	/**
	 * Cancel every pending timer so a teardown mid-animation cannot fire a
	 * callback against a destroyed component. The unlock timer is the one
	 * that matters: its resolve drives `$router.push`, so leaving it armed
	 * would navigate after the screen is gone.
	 *
	 * @spec exclude Lifecycle teardown — clears timers only; the behaviour each timer drives is specified on the method that arms it.
	 */
	beforeUnmount() {
		clearTimeout(this.mismatchTimer)
		clearTimeout(this.unlockTimer)
		clearTimeout(this.rejectedTimer)
	},

	methods: {
		/**
		 * Hide the mismatch message while typing and re-arm the pause timer:
		 * one second of idle counts as "done typing". Leaving a field
		 * (revealMismatch) short-circuits the wait.
		 *
		 * @spec exclude Feedback debounce timing — pure presentation; no requirement prescribes the typing-pause interval.
		 */
		restartMismatchDebounce() {
			this.mismatchSettled = false
			clearTimeout(this.mismatchTimer)
			this.mismatchTimer = setTimeout(() => {
				this.mismatchSettled = true
			}, 1000)
		},

		/**
		 * Blur = done typing: reveal the mismatch feedback immediately
		 * instead of waiting out the pause timer.
		 *
		 * @spec exclude Feedback reveal-on-blur — pure presentation; the late-validation pattern is a UX choice, not a specified requirement.
		 */
		revealMismatch() {
			clearTimeout(this.mismatchTimer)
			this.mismatchSettled = true
		},

		/**
		 * Resolve the setup-vs-unlock decision from the server, driving the
		 * `suiteCheck` state machine. Extracted from `created()` so the
		 * failed state's "Try again" button can re-run it.
		 *
		 * Only `fetchSuite()` gates the decision. The migration-status and
		 * passkey-offer lookups are best-effort extras: a failure there
		 * degrades a banner or hides the passkey button, and must not push
		 * an answered setup-vs-unlock decision back into the failed state.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
		 */
		async checkSuite() {
			this.suiteCheck = 'pending'
			try {
				await this.suiteStore.fetchSuite()
				this.suiteCheck = 'resolved'
			} catch {
				this.suiteCheck = 'failed'
				return
			}
			try {
				await this.suiteStore.fetchMigrationStatus()
				// Offer passkey unlock only when WebAuthn is present AND the
				// caller has an active enrolled passkey (feature-detected,
				// never assumed).
				if (this.offlineStore.online) {
					this.passkeyOffered = await usePasskeyStore().isUnlockOffered()
				}
			} catch {
				// Best-effort extras — the unlock/setup form still renders.
			}
		},

		/**
		 * Unlock the vault with a passkey (passkey-vault-login §4.1). On any
		 * failure, fall back to the master-password field without leaving the
		 * lock screen — the master password always works.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-master-password-remains-the-canonical-fallback
		 */
		async handlePasskeyUnlock() {
			this.loading = true
			this.error = null
			try {
				await usePasskeyStore().unlockWithPasskey()
				const returnUrl = this.$route.query.returnUrl || '/'
				await this.playUnlockAnimation()
				this.$router.push(returnUrl)
			} catch (e) {
				this.error =
					e?.message
					|| t(
						'keepiq',
						'Passkey unlock failed — use your master password',
					)
				// Same signal for a refused passkey: the lock is still shut,
				// and the fallback is the same password field below it.
				this.flashUnlockRejected()
			} finally {
				this.loading = false
			}
		},

		/**
		 * Derive the AES key from the master password, unlock the vault,
		 * and redirect to the return URL (or root).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleUnlock() {
			this.loading = true
			this.error = null

			try {
				if (this.offlineStore.online) {
					await this.sessionStore.unlock(this.masterPassword)
				} else {
					// Offline unlock from the cached snapshot — no server request;
					// the master password never leaves the browser (offline §4.1).
					await this.offlineStore.unlockOffline(this.masterPassword)
				}
				const returnUrl = this.$route.query.returnUrl || '/'
				await this.playUnlockAnimation()
				this.$router.push(returnUrl)
			} catch (e) {
				// When an online unlock fails on a network error, fall back to the
				// offline snapshot (covers "online but server unreachable").
				if (this.offlineStore.online && this.isNetworkError(e)) {
					try {
						await this.offlineStore.unlockOffline(this.masterPassword)
						const returnUrl = this.$route.query.returnUrl || '/'
						await this.playUnlockAnimation()
						this.$router.push(returnUrl)
						return
					} catch {
						// fall through to the generic error below
					}
				}
				this.error = t(
					'keepiq',
					'Wrong master password or decryption failed',
				)
				this.flashUnlockRejected()
			} finally {
				this.loading = false
				this.masterPassword = ''
			}
		},

		/**
		 * Show the open lock and give the swap a stage: flip the icon, then
		 * resolve once the animation has had UNLOCK_ANIMATION_MS to play AND
		 * the settled open lock has held for UNLOCK_SETTLE_MS on top of it.
		 * Every unlock path awaits this immediately before `$router.push`,
		 * because the redirect unmounts this screen — pushing first would
		 * make the icon swap unobservable, and pushing at the keyframes'
		 * end makes it read as a flicker.
		 *
		 * Under prefers-reduced-motion the animation is dropped and the hold
		 * shrinks to UNLOCK_ANNOUNCE_MS — but it does not vanish. The hold
		 * carries the polite live region as well as the keyframes, and the
		 * redirect unmounts the screen out from under both; a zero hold
		 * there would leave exactly the viewer who cannot see the padlock
		 * with no signal at all. So reduced motion still unlocks faster
		 * than the animated path, just not instantly.
		 *
		 * @return {Promise<void>} Resolves when the redirect may proceed.
		 * @spec exclude Presentation-only success signal — flips the icon flag and waits out its CSS animation; no requirement prescribes the unlock's visuals.
		 */
		playUnlockAnimation() {
			this.unlocked = true
			const hold = this.prefersReducedMotion()
				? UNLOCK_ANNOUNCE_MS
				: UNLOCK_HOLD_MS
			return new Promise((resolve) => {
				this.unlockTimer = setTimeout(resolve, hold)
			})
		},

		/**
		 * Flash the closed lock red (and shake it, motion permitting) to
		 * mark a rejected attempt, then reset it after LOCK_ERROR_MS.
		 *
		 * Bumping `rejectionSeq` is what makes a second wrong password shake
		 * again, and it replaces an earlier false-then-`$nextTick`-then-true
		 * dance that did not work. That dance fixed the wrong problem: Vue
		 * would indeed coalesce the two writes inside one tick, but
		 * `$nextTick` is a MICROTASK, so the class came off and went back on
		 * within the same task with no style recalculation between them —
		 * and a CSS animation restarts only when the computed
		 * `animation-name` changes across one. The browser saw no change and
		 * replayed nothing, so a rejection landing inside the still-open
		 * window re-extended the red without shaking.
		 *
		 * A new vnode key sidesteps the restart rule entirely: Vue mounts a
		 * fresh element, and an element that enters with the class already
		 * on it animates unconditionally. Setting the flag synchronously
		 * also keeps the timer inside `beforeUnmount`'s reach — the
		 * `$nextTick` callback could arm it after teardown had already run.
		 *
		 * @spec exclude Presentation-only failure signal — flashes a class for LOCK_ERROR_MS; the rejection itself is reported by the field's error text, which the unlock handlers set.
		 */
		flashUnlockRejected() {
			clearTimeout(this.rejectedTimer)
			this.rejectionSeq++
			this.unlockRejected = true
			this.rejectedTimer = setTimeout(() => {
				this.unlockRejected = false
			}, LOCK_ERROR_MS)
		},

		/**
		 * Whether the viewer has asked their OS for reduced motion. Guarded
		 * against a missing `matchMedia` so the unlock still completes in
		 * environments that do not implement it (jsdom in some setups,
		 * embedded webviews) — an unavailable answer means "no preference",
		 * which is the same default the CSS media query applies.
		 *
		 * @return {boolean} True when reduced motion is preferred.
		 * @spec exclude Environment probe for the motion preference — no domain logic; the accessibility requirement is met by the CSS media query it mirrors.
		 */
		prefersReducedMotion() {
			return !!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
		},

		/**
		 * Whether an unlock error is a network failure (server unreachable)
		 * rather than a wrong password — used to trigger the offline fallback.
		 *
		 * @param {Error} e The unlock error.
		 * @return {boolean}
		 */
		isNetworkError(e) {
			return (
				!!e
				&& (e.message === 'Network Error'
					|| e.code === 'ERR_NETWORK'
					|| (e.request && !e.response))
			)
		},

		/**
		 * First-time suite setup: create the encryption suite from the
		 * new master password and navigate into the vault.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleSetup() {
			if (!this.canSubmitSetup) return

			this.loading = true
			this.error = null

			try {
				await this.suiteStore.createSuite(this.masterPassword)
				this.$router.push('/')
			} catch (e) {
				this.error = e.message || t('keepiq', 'Setup failed')
			} finally {
				this.loading = false
				this.masterPassword = ''
				this.confirmPassword = ''
			}
		},

		/**
		 * Track password-strength validity emitted by the strength meter.
		 *
		 * @param {object} root0 Strength event.
		 * @param {boolean} root0.isValid Whether the password meets the floor.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},
	},
}
</script>

<style scoped>
.lock-screen {
	/*
	 * Fixed overlay, not an in-column page. This component renders inside
	 * CnAppRoot's content area, so as a normal page the app sidebar stays
	 * visible and clickable beside it. The unlock/setup prompt must be the
	 * only interactive surface, so cover the whole viewport below the
	 * Nextcloud global header (which stays reachable — locking the vault
	 * must not trap the user inside Keepiq). z-index sits above
	 * NcAppNavigation (~1800) but below the header (2000) and modals.
	 */
	position: fixed;
	top: var(--header-height, 50px);
	inset-inline: 0;
	bottom: 0;
	z-index: 1999;
	display: flex;
	/*
	 * Centering: the card uses `margin: auto` instead of justify/align
	 * center — identical result, but when the card is TALLER than a small
	 * viewport, flex-centering clips its top unreachably while margin:auto
	 * degrades to a scrollable top-aligned card.
	 *
	 * The overlay's box starts BELOW the global header, so its geometric
	 * center sits half a header too low on every screen size; the extra
	 * bottom padding re-centers the card on the true viewport
	 * (header-height / 2 each side of `auto`) plus a 3vh optical lift —
	 * dialogs read as centered when slightly above the geometric middle.
	 */
	overflow-y: auto;
	padding: 1rem;
	padding-bottom: calc(1rem + var(--header-height, 50px) + 6vh);
	/*
	 * Login-page look, not app-content look: the instance's primary color
	 * with the admin-themed background image on top (the same tokens the
	 * Nextcloud login screen uses). Falls back to the stock Nextcloud
	 * blue when theming variables are absent.
	 */
	background-color: var(--color-primary, #0082c9);
	background-image: var(--image-background, var(--gradient-primary-background));
	background-size: cover;
	background-position: center;
}

.lock-screen__card {
	margin: auto;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 2rem;
	/* 400px matches Nextcloud's own login-form card width. */
	max-width: 400px;
	width: 100%;
	/* Theme shadow token, so dark mode gets its calibrated shadow. */
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.lock-screen__icon {
	text-align: center;
}

/*
 * Screen-reader-only live regions: present in the layout at all times but
 * never visible, same clip pattern as Nextcloud's hidden-visually. NOT
 * `display: none` or `visibility: hidden` — either one takes the element
 * out of the accessibility tree, and a live region that is not in the tree
 * announces nothing at all.
 */
.lock-screen__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

/*
 * The unlock's success signal. The open shackle says "it worked" on its
 * own, so the state still reads correctly with the animation suppressed
 * below; the pop-and-settle only carries the "it just opened" timing.
 * Green is the SECOND channel, never the only one: the shape change from
 * closed to open padlock carries the state on its own (WCAG 1.4.1), so a
 * viewer who cannot separate green from black loses nothing. It uses
 * --color-success-text rather than --color-success because Nextcloud tunes
 * that token per theme for contrast against --color-main-background — the
 * card's background — which keeps the icon past the 3:1 that WCAG 1.4.11
 * asks of a graphical object carrying meaning, in light AND dark themes.
 * Duration must stay in step with
 * UNLOCK_ANIMATION_MS in the script above — the navigation hold is that
 * duration plus UNLOCK_SETTLE_MS, so the settled lock is what the viewer
 * is left looking at rather than a pop cut off by the redirect.
 */
.lock-screen__icon-open {
	/*
	 * inline-block, because the icon's root is a span and transforms do
	 * not apply to inline boxes — without this the keyframes below are
	 * silently dropped.
	 */
	display: inline-block;
	color: var(--color-success-text, #286c39);
	animation: lock-screen-unlock-in 0.4s ease-out;
}

@keyframes lock-screen-unlock-in {
	from {
		opacity: 0;
		transform: scale(0.7) rotate(-10deg);
	}

	60% {
		opacity: 1;
		transform: scale(1.12) rotate(3deg);
	}

	to {
		opacity: 1;
		transform: scale(1) rotate(0);
	}
}

/*
 * The rejected-attempt signal, and the mirror of the success one above:
 * red instead of green, a head-shake instead of a pop, and the padlock
 * staying SHUT is the channel that does not depend on colour at all
 * (WCAG 1.4.1) — backed by the field's inline error text, which is what
 * a screen reader reads. --color-error-text is the theme-tuned token, so
 * the icon clears 3:1 against the card in light and dark alike
 * (WCAG 1.4.11); the red is held by the class for LOCK_ERROR_MS rather
 * than by the keyframes, so it survives the shake and outlasts it.
 *
 * A head-shake is not a flash: one 0.4s pass at 6 movements is far below
 * the three-per-second threshold of WCAG 2.3.1, and nothing here loops.
 */
.lock-screen__icon-rejected {
	/* inline-block for the same reason as the open lock above: the icon's
	 * root is a span, and transforms do not apply to inline boxes. */
	display: inline-block;
	color: var(--color-error-text, #c20505);
	animation: lock-screen-reject-shake 0.4s ease-in-out;
}

/*
 * Small amplitude on purpose: 4px is a "no" gesture, not a jolt. The
 * outer steps are half-size so the shake eases out of its own accord
 * rather than stopping dead on a peak.
 */
@keyframes lock-screen-reject-shake {
	0%,
	100% {
		transform: translateX(0);
	}

	20%,
	60% {
		transform: translateX(-4px);
	}

	40%,
	80% {
		transform: translateX(4px);
	}
}

/* Block, so the tooltip wrapper doesn't shrink the wide submit button. */
.lock-screen__submit {
	display: block;
}

/*
 * Reserve the helper-text line up front: without this, the mismatch
 * message appearing grows the field and shoves the submit button down —
 * the "button moves" jolt. One reserved line keeps the card geometry
 * fixed whether feedback is showing or not.
 */
.lock-screen__confirm {
	min-height: calc(var(--default-clickable-area, 44px) + 2em);
}

/* Let the error state fade in rather than snap. */
.lock-screen__confirm :deep(.input-field__input) {
	transition: border-color 0.2s ease-out;
}

.lock-screen__confirm :deep(.input-field__helper-text-message) {
	animation: lock-screen-feedback-in 0.25s ease-out;
}

@keyframes lock-screen-feedback-in {
	from {
		opacity: 0;
	}

	to {
		opacity: 1;
	}
}

/* Vestibular safety (gate-45): no motion for users who asked for none. */
@media (prefers-reduced-motion: reduce) {
	/*
	 * The open-lock glyph stays — it is the state, not motion — but the
	 * scale/rotate pop goes. playUnlockAnimation() shortens its wait to
	 * match (UNLOCK_ANNOUNCE_MS instead of the full hold): nothing is
	 * waiting on keyframes that never run, but the polite live region
	 * still needs the screen to outlive its own announcement.
	 */
	.lock-screen__icon-open {
		animation: none;
	}

	/*
	 * Same split for the rejection: the red stays (it is the state, and it
	 * lives on the class, not in these keyframes), the shake goes. A shake
	 * is exactly the horizontal motion vestibular disorders react to, so
	 * this one is not negotiable.
	 */
	.lock-screen__icon-rejected {
		animation: none;
	}

	.lock-screen__confirm :deep(.input-field__input) {
		transition: none;
	}

	.lock-screen__confirm :deep(.input-field__helper-text-message) {
		animation: none;
	}
}

.lock-screen__checking {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	padding: 1rem 0;
}

.lock-screen__title {
	text-align: center;
	font-size: 1.25rem;
	margin: 0;
}
</style>
