/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `useHealthStore` Pinia store (src/store/modules/health.js).
 * Runs in the jsdom env. Locks: the persistence-leak guard (no localStorage /
 * sessionStorage writes during analysis), `reset()` clears all derived state +
 * terminates the worker, and analysis aborts cleanly when the vault is locked.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useHealthStore } from '../../src/store/modules/health.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

// The engine decrypts via rsaDecrypt(session.cryptoKey); stub the crypto module
// so the store test exercises orchestration, not RSA.
vi.mock('../../src/crypto/index.js', () => ({
	rsaDecrypt: vi.fn(async (cipher) => cipher.replace('enc:', '')),
	importPublicKey: vi.fn(),
	rsaEncrypt: vi.fn(),
}))

describe('useHealthStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('analyses an unlocked vault into findings + summary + score', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }

		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				items: [
					{
						id: 'a',
						name: 'Weak',
						key: 'enc:password',
						blocked: false,
						keyUpdatedAt: new Date().toISOString(),
					},
					{ id: 'b', name: 'Dup1', key: 'enc:shared-val', blocked: false },
					{ id: 'c', name: 'Dup2', key: 'enc:shared-val', blocked: false },
				],
			},
		})

		const store = useHealthStore()
		await store.analyseVault({
			stalenessThreshold: 'never',
			breachEnabled: false,
		})

		expect(store.status).toBe('ready')
		expect(store.summary.reusedCount).toBe(2)
		expect(store.summary.weakCount).toBeGreaterThanOrEqual(1)
		expect(typeof store.score).toBe('number')
	})

	it('does NOT write any health data to localStorage or sessionStorage', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				items: [{ id: 'a', name: 'A', key: 'enc:password', blocked: false }],
			},
		})

		const localSpy = vi.spyOn(Storage.prototype, 'setItem')

		const store = useHealthStore()
		await store.analyseVault({
			stalenessThreshold: 'never',
			breachEnabled: false,
		})

		expect(localSpy).not.toHaveBeenCalled()
	})

	it('aborts and resets when the vault is locked', async () => {
		const session = useSessionStore()
		session.cryptoKey = null // locked
		const getSpy = vi.spyOn(axios, 'get')

		const store = useHealthStore()
		store.findings = [{ id: 'x', flags: ['weak'], score: 0 }]
		await store.analyseVault({ stalenessThreshold: 'never' })

		expect(getSpy).not.toHaveBeenCalled()
		expect(store.findings).toEqual([])
		expect(store.status).toBe('idle')
	})

	it('reset() clears all derived state and terminates the worker', () => {
		const store = useHealthStore()
		const terminate = vi.fn()
		store.findings = [{ id: 'a', flags: ['weak'], score: 1 }]
		store.summary = { weakCount: 1 }
		store.score = 40
		store.status = 'ready'
		store.worker = { terminate }

		store.reset()

		expect(store.findings).toEqual([])
		expect(store.summary).toBeNull()
		expect(store.score).toBeNull()
		expect(store.status).toBe('idle')
		expect(store.worker).toBeNull()
		expect(terminate).toHaveBeenCalledOnce()
	})

	it('locking the session resets the health store via the lock hook', () => {
		const store = useHealthStore()
		store.registerLockReset()
		store.findings = [{ id: 'a', flags: ['weak'], score: 0 }]
		store.status = 'ready'

		const session = useSessionStore()
		session.cryptoKey = { fake: true }
		session.lock()

		expect(store.findings).toEqual([])
		expect(store.status).toBe('idle')
	})

	it('excludes totp-typed secrets from analysis (add-totp-secrets D7)', async () => {
		const session = useSessionStore()
		session.cryptoKey = { fake: true }

		// Pre-populate the type store so loadDecryptedRows resolves the totp id
		// without hitting the mocked axios (which returns secret-shaped data).
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login' },
			{ id: 'type-totp', name: 'totp' },
		]

		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				items: [
					{
						id: 'a',
						name: 'Weak',
						typeId: 'type-login',
						key: 'enc:password',
						blocked: false,
					},
					{
						id: 't',
						name: 'Authenticator',
						typeId: 'type-totp',
						key: 'enc:otpauth://totp/x?secret=JBSWY3DP',
						blocked: false,
					},
				],
			},
		})

		const store = useHealthStore()
		await store.analyseVault({
			stalenessThreshold: 'never',
			breachEnabled: false,
		})

		// Only the login secret is analysed; the totp seed is never scored.
		expect(store.summary.analysedCount).toBe(1)
		expect(store.findings.some((f) => f.id === 't')).toBe(false)
	})
})
