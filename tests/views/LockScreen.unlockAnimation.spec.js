/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lock screen's unlock success signal: the padlock swaps to an OPEN
 * padlock and that swap gets a stage before the redirect fires.
 *
 * What is worth locking down here is the ORDERING, not the pixels. The
 * redirect unmounts this whole screen, so a `$router.push()` that runs
 * before the icon flips makes the animation unobservable no matter how
 * the keyframes are written — and that regression is invisible in a diff
 * (it is just two statements in the wrong order). So every assertion
 * below is of the form "the open lock is on screen AND the push has not
 * happened yet", followed by "the push does still happen".
 *
 * The hold has two phases — the keyframes, then a settle beat on the
 * finished open lock — and the settle is the one part where the timing
 * itself is the contract: it exists precisely so the redirect does NOT
 * fire the moment the animation ends, so one test drives fake timers
 * across that boundary rather than just waiting for the push.
 *
 * The reduced-motion case is the narrower contract: nothing animates, so
 * the animation is not waited for — but the hold does not go to zero,
 * because the polite live region rides on it too and the redirect unmounts
 * the region along with the screen. Less motion, not less time.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LockScreen from '../../src/views/LockScreen.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * The component's two hold phases, mirrored from UNLOCK_ANIMATION_MS and
 * UNLOCK_SETTLE_MS in LockScreen.vue. Only the settle test depends on the
 * exact numbers; everywhere else they just size the waitFor budget, which
 * has to clear their SUM — the 1s default does not.
 */
const ANIMATION_MS = 400
const SETTLE_MS = 500
const HOLD_BUDGET = { timeout: (ANIMATION_MS + SETTLE_MS) * 3 }

/**
 * Mirrors UNLOCK_ANNOUNCE_MS: the shorter hold the reduced-motion path
 * keeps so the polite live region is still on screen to be spoken.
 */
const ANNOUNCE_MS = 500

/** Mirrors LOCK_ERROR_MS: how long a rejected attempt stays red. */
const ERROR_MS = 1100
/** Budget for that flash to clear itself under real timers. */
const ERROR_BUDGET = { timeout: ERROR_MS * 3 }

const OPEN_LOCK = '[data-testid="lock-screen-icon-open"]'
const CLOSED_LOCK = '[data-testid="lock-screen-icon-closed"]'
/**
 * The closed padlock while it is flashing a rejection. The CLASS is the
 * assertion target rather than a colour: scoped SFC styles are not applied
 * in jsdom, so there is no computed red to read — what is testable, and
 * what actually regresses, is whether the state reaches the DOM at all.
 */
const REJECTED_LOCK = `${CLOSED_LOCK}.lock-screen__icon-rejected`
/** The two screen-reader live regions that mirror the icon's signals. */
const SR_STATUS = '.lock-screen__sr-live[role="status"]'
const SR_ALERT = '.lock-screen__sr-live[role="alert"]'
const SUBMIT = '[data-testid="unlock-with-password"]'

/**
 * Force the reduced-motion answer the component probes for.
 *
 * @param {boolean} matches Whether reduced motion is preferred.
 */
function stubMotionPreference(matches) {
	window.matchMedia = vi.fn(() => ({
		matches,
		media: '(prefers-reduced-motion: reduce)',
		addEventListener() {},
		removeEventListener() {},
	}))
}

/**
 * Put the stores in the "existing vault, ready to unlock" state that the
 * unlock form renders from, and hand back the doubles the tests drive.
 *
 * @param {object} [options] Setup switches.
 * @param {boolean} [options.passkeyOffered] Whether to offer passkey unlock.
 * @return {object} The stubbed stores.
 */
function arrangeUnlockableVault({ passkeyOffered = false } = {}) {
	const suiteStore = useEncryptionSuiteStore()
	// A resolved check with an ACTIVE suite is what moves the screen out of
	// its spinner and into the unlock form (never the setup form).
	suiteStore.currentSuite = { id: 7, status: 'active' }
	vi.spyOn(suiteStore, 'fetchSuite').mockResolvedValue(undefined)
	vi.spyOn(suiteStore, 'fetchMigrationStatus').mockResolvedValue(undefined)

	const sessionStore = useSessionStore()
	vi.spyOn(sessionStore, 'unlock').mockResolvedValue(undefined)

	const passkeyStore = usePasskeyStore()
	vi.spyOn(passkeyStore, 'isUnlockOffered').mockResolvedValue(passkeyOffered)
	vi.spyOn(passkeyStore, 'unlockWithPasskey').mockResolvedValue(undefined)

	const offlineStore = useOfflineStore()
	offlineStore.online = true

	return { suiteStore, sessionStore, passkeyStore, offlineStore }
}

/**
 * Mount the lock screen with a spying router, already past its suite check.
 *
 * @param {object} [route] Route stub overrides.
 * @return {Promise<object>} The wrapper plus the router push spy.
 */
async function mountUnlockForm(route = {}) {
	const push = vi.fn()
	const wrapper = mount(LockScreen, {
		global: {
			mocks: {
				$router: { push },
				$route: { query: {}, hash: '', ...route },
			},
		},
	})
	// created() awaits checkSuite(); until that settles only the spinner renders.
	await flush()
	return { wrapper, push }
}

/**
 * Type a master password and press Unlock.
 *
 * The nextTick is load-bearing: the submit is disabled while the field is
 * empty and Vue Test Utils will not dispatch a click on a disabled button,
 * so without the re-render the click is silently swallowed and the test
 * passes for the wrong reason.
 *
 * @param {object} wrapper The mounted lock screen.
 * @param {string} password The master password to submit.
 * @return {Promise<void>}
 */
async function submitPassword(wrapper, password) {
	wrapper.vm.masterPassword = password
	await wrapper.vm.$nextTick()
	await wrapper.find(SUBMIT).trigger('click')
	await flush()
}

/**
 * The same submit, for tests that have already switched to fake timers —
 * `flush()` would hang there, since its setTimeout only fires if the test
 * advances the clock. Advancing by 0 drains the awaited unlock chain
 * without moving any of the timed windows.
 *
 * @param {object} wrapper The mounted lock screen.
 * @param {string} password The master password to submit.
 * @return {Promise<void>}
 */
async function submitPasswordOnFakeTimers(wrapper, password) {
	wrapper.vm.masterPassword = password
	await wrapper.vm.$nextTick()
	wrapper.find(SUBMIT).trigger('click')
	await vi.advanceTimersByTimeAsync(0)
	await wrapper.vm.$nextTick()
}

describe('LockScreen unlock animation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		// The screen renders no form at all outside a secure context.
		window.isSecureContext = true
		stubMotionPreference(false)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('starts out showing the CLOSED padlock', async () => {
		arrangeUnlockableVault()
		const { wrapper } = await mountUnlockForm()

		expect(wrapper.find(CLOSED_LOCK).exists()).toBe(true)
		expect(wrapper.find(OPEN_LOCK).exists()).toBe(false)
	})

	it('shows the open padlock BEFORE it navigates to the return URL', async () => {
		arrangeUnlockableVault()
		const { wrapper, push } = await mountUnlockForm({
			query: { returnUrl: '/vault/7' },
		})

		await submitPassword(wrapper, 'correct horse battery staple')

		// The unlock has succeeded — the icon has swapped — and the redirect
		// is still pending, which is the whole point of the hold.
		expect(wrapper.find(OPEN_LOCK).exists()).toBe(true)
		expect(wrapper.find(CLOSED_LOCK).exists()).toBe(false)
		expect(push).not.toHaveBeenCalled()

		// ...and it is a hold, not a cancellation: the redirect still lands.
		await vi.waitFor(
			() => expect(push).toHaveBeenCalledWith('/vault/7'),
			HOLD_BUDGET,
		)
	})

	it('keeps holding after the animation ends, so the open lock settles', async () => {
		arrangeUnlockableVault()
		const { wrapper, push } = await mountUnlockForm({
			query: { returnUrl: '/vault/7' },
		})

		// Fake timers only from here: the mount above needs real ones to get
		// through created()'s awaited suite check.
		vi.useFakeTimers()
		try {
			wrapper.vm.masterPassword = 'correct horse battery staple'
			await wrapper.vm.$nextTick()
			wrapper.find(SUBMIT).trigger('click')
			// Let the awaited unlock resolve and the icon flip, without
			// letting any of the hold elapse.
			await vi.advanceTimersByTimeAsync(0)
			expect(wrapper.find(OPEN_LOCK).exists()).toBe(true)

			// The keyframes have finished here. Before the settle was added
			// this is exactly where the redirect fired — the "yanked away
			// mid-animation" feel this test exists to prevent.
			await vi.advanceTimersByTimeAsync(ANIMATION_MS)
			expect(push).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(SETTLE_MS)
			expect(push).toHaveBeenCalledWith('/vault/7')
		} finally {
			vi.useRealTimers()
		}
	})

	it('keeps the form inert while the animation holds the redirect', async () => {
		arrangeUnlockableVault()
		const { wrapper, push } = await mountUnlockForm()

		await submitPassword(wrapper, 'correct horse battery staple')

		// `loading` is only cleared after the hold, so the submit cannot be
		// pressed a second time into an already-unlocked vault.
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.find(SUBMIT).attributes('disabled')).toBeDefined()

		await vi.waitFor(() => expect(push).toHaveBeenCalled(), HOLD_BUDGET)
	})

	it('skips the animation under prefers-reduced-motion but still holds long enough to announce', async () => {
		// The regression this locks down: a reduced-motion path that returned
		// an already-resolved promise put `unlocked = true` and the redirect
		// in the same task, so the live region was torn down within a frame
		// or two of getting its text — nowhere near long enough to be spoken.
		// Reduced motion is a preference blind screen-reader users commonly
		// have on, so that dropped the announcement for exactly the viewer
		// the announcement exists for.
		stubMotionPreference(true)
		arrangeUnlockableVault()
		const { wrapper, push } = await mountUnlockForm({
			query: { returnUrl: '/vault/7' },
		})

		// Fake timers only from here: the mount above needs real ones to get
		// through created()'s awaited suite check.
		vi.useFakeTimers()
		try {
			wrapper.vm.masterPassword = 'correct horse battery staple'
			await wrapper.vm.$nextTick()
			wrapper.find(SUBMIT).trigger('click')
			await vi.advanceTimersByTimeAsync(0)

			// Success signal and announcement are both on screen, and the
			// redirect has NOT fired yet.
			expect(wrapper.find(OPEN_LOCK).exists()).toBe(true)
			expect(wrapper.find(SR_STATUS).text()).not.toBe('')
			expect(push).not.toHaveBeenCalled()

			// The hold is the announce beat alone — no animation waited for,
			// which is what keeps this path the faster of the two.
			expect(ANNOUNCE_MS).toBeLessThan(ANIMATION_MS + SETTLE_MS)
			await vi.advanceTimersByTimeAsync(ANNOUNCE_MS)
			expect(push).toHaveBeenCalledWith('/vault/7')
		} finally {
			vi.useRealTimers()
		}
	})

	it('leaves the closed padlock in place when the password is wrong', async () => {
		const { sessionStore } = arrangeUnlockableVault()
		sessionStore.unlock.mockRejectedValue(new Error('bad key'))
		const { wrapper, push } = await mountUnlockForm()

		await submitPassword(wrapper, 'wrong')

		// Asserting the error proves the attempt actually ran, so the
		// still-closed padlock below means "unlock failed" and not "the
		// click never reached the handler".
		expect(wrapper.vm.error).toBeTruthy()
		expect(wrapper.find(CLOSED_LOCK).exists()).toBe(true)
		expect(wrapper.find(OPEN_LOCK).exists()).toBe(false)
		expect(push).not.toHaveBeenCalled()
	})

	it('shakes the closed padlock red, then returns it to black', async () => {
		const { sessionStore } = arrangeUnlockableVault()
		sessionStore.unlock.mockRejectedValue(new Error('bad key'))
		const { wrapper } = await mountUnlockForm()

		await submitPassword(wrapper, 'wrong')
		await wrapper.vm.$nextTick()
		expect(wrapper.find(REJECTED_LOCK).exists()).toBe(true)

		// It is a flash, not a mode: the icon returns to black on its own
		// while the message the viewer reads stays on the field.
		await vi.waitFor(
			() => expect(wrapper.find(REJECTED_LOCK).exists()).toBe(false),
			ERROR_BUDGET,
		)
		expect(wrapper.vm.error).toBeTruthy()
	})

	it('re-arms the flash window for a SECOND wrong password', async () => {
		const { sessionStore } = arrangeUnlockableVault()
		sessionStore.unlock.mockRejectedValue(new Error('bad key'))
		const { wrapper } = await mountUnlockForm()

		vi.useFakeTimers()
		try {
			await submitPasswordOnFakeTimers(wrapper, 'wrong')
			expect(wrapper.find(REJECTED_LOCK).exists()).toBe(true)
			const firstLock = wrapper.find(REJECTED_LOCK).element

			// Nearly all of the first window has gone.
			await vi.advanceTimersByTimeAsync(ERROR_MS - 200)
			expect(wrapper.find(REJECTED_LOCK).exists()).toBe(true)

			// A second rejection has to start a WHOLE new window, not inherit
			// the tail of the first: 200ms of red is not a signal, and the
			// leftover timer would also clear the class mid-shake.
			await submitPasswordOnFakeTimers(wrapper, 'wrong again')
			await vi.advanceTimersByTimeAsync(300)
			expect(wrapper.find(REJECTED_LOCK).exists()).toBe(true)

			// ...and a FRESH element, which is the part the red alone does not
			// prove. A CSS animation restarts only when the browser sees the
			// computed animation-name change across a style recalculation, so
			// re-setting the class on the surviving element replays no shake
			// at all. jsdom runs no animations, so element identity is the
			// only handle the test has on that — and it is the right one: the
			// key bump is exactly what the fix turns on.
			expect(wrapper.find(REJECTED_LOCK).element).not.toBe(firstLock)

			await vi.advanceTimersByTimeAsync(ERROR_MS)
			expect(wrapper.find(REJECTED_LOCK).exists()).toBe(false)
		} finally {
			vi.useRealTimers()
		}
	})

	it('still reddens the padlock under prefers-reduced-motion', async () => {
		// The shake is suppressed in CSS there; the colour is not motion and
		// must survive, so the failure keeps a visual channel of its own.
		stubMotionPreference(true)
		const { sessionStore } = arrangeUnlockableVault()
		sessionStore.unlock.mockRejectedValue(new Error('bad key'))
		const { wrapper } = await mountUnlockForm()

		await submitPassword(wrapper, 'wrong')
		await wrapper.vm.$nextTick()

		expect(wrapper.find(REJECTED_LOCK).exists()).toBe(true)
	})

	it('announces a rejection to screen readers, not just in red', async () => {
		const { sessionStore } = arrangeUnlockableVault()
		sessionStore.unlock.mockRejectedValue(new Error('bad key'))
		const { wrapper } = await mountUnlockForm()

		// The regions exist from the first render — a live region inserted
		// along with its text is not reliably announced.
		expect(wrapper.find(SR_ALERT).exists()).toBe(true)
		expect(wrapper.find(SR_ALERT).text()).toBe('')

		await submitPassword(wrapper, 'wrong')

		// Assertive, and the same string the field shows, so the two
		// channels cannot drift apart.
		expect(wrapper.find(SR_ALERT).text()).toBe(wrapper.vm.error)
		expect(wrapper.find(SR_ALERT).text()).not.toBe('')
	})

	it('announces the unlock politely while the settle holds', async () => {
		arrangeUnlockableVault()
		const { wrapper } = await mountUnlockForm()

		await submitPassword(wrapper, 'correct horse battery staple')

		// Spoken during the hold: the redirect that unmounts this screen is
		// still pending, which is what gives the region time to be read.
		expect(wrapper.find(SR_STATUS).text()).not.toBe('')
		expect(wrapper.find(SR_ALERT).text()).toBe('')
	})

	it('never reddens the padlock on a SUCCESSFUL unlock', async () => {
		arrangeUnlockableVault()
		const { wrapper } = await mountUnlockForm()

		await submitPassword(wrapper, 'correct horse battery staple')
		await wrapper.vm.$nextTick()

		expect(wrapper.find(REJECTED_LOCK).exists()).toBe(false)
	})

	it('gives the passkey unlock the same open-padlock signal', async () => {
		arrangeUnlockableVault({ passkeyOffered: true })
		const { wrapper, push } = await mountUnlockForm({
			query: { returnUrl: '/vault/7' },
		})

		await wrapper.find('[data-testid="unlock-with-passkey"]').trigger('click')
		await flush()

		expect(wrapper.find(OPEN_LOCK).exists()).toBe(true)
		expect(push).not.toHaveBeenCalled()

		await vi.waitFor(
			() => expect(push).toHaveBeenCalledWith('/vault/7'),
			HOLD_BUDGET,
		)
	})
})
