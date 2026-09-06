/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the password-bearing classification heuristic
 * (src/health/classify.js). Runs in the node env. Locks the documented
 * examples from password-health design D1: PEM/SSH/long-base64/long-hex/
 * over-length values are key material (excluded from strength scoring);
 * human passwords are password-bearing.
 */

import { describe, expect, it } from 'vitest'
import { isKeyMaterial, isPasswordBearing } from '../../src/health/classify.js'

describe('classify: isKeyMaterial', () => {
	it('treats PEM private keys as key material', () => {
		expect(
			isKeyMaterial(
				'-----BEGIN PRIVATE KEY-----\nMIIEvable\n-----END PRIVATE KEY-----',
			),
		).toBe(true)
	})

	it('treats OpenSSH public keys as key material', () => {
		expect(isKeyMaterial('ssh-rsa AAAAB3NzaC1yc2EAAAADAQAB user@host')).toBe(
			true,
		)
		expect(isKeyMaterial('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 user@host')).toBe(
			true,
		)
	})

	it('treats a 64+ char hex blob as key material', () => {
		expect(isKeyMaterial('a'.repeat(64))).toBe(true)
		expect(isKeyMaterial('deadbeef'.repeat(8))).toBe(true)
	})

	it('treats a 64+ char base64 token as key material', () => {
		expect(isKeyMaterial('A1b2C3d4'.repeat(8) + '==')).toBe(true)
	})

	it('treats values over 72 chars as key material', () => {
		expect(isKeyMaterial('correct horse battery staple '.repeat(4))).toBe(true)
	})

	it('treats normal human passwords as NOT key material', () => {
		expect(isKeyMaterial('Summer2024!')).toBe(false)
		expect(isKeyMaterial('hunter2')).toBe(false)
		expect(isKeyMaterial('correct-horse-battery-staple')).toBe(false)
	})

	it('treats empty / non-string as key material (nothing to score)', () => {
		expect(isKeyMaterial('')).toBe(true)
		expect(isKeyMaterial(null)).toBe(true)
		expect(isKeyMaterial(undefined)).toBe(true)
	})

	it('does NOT misclassify a short hex-looking word with spaces', () => {
		// short and not a 64+ single token -> password-bearing
		expect(isKeyMaterial('cafe babe')).toBe(false)
	})
})

describe('classify: isPasswordBearing', () => {
	it('is the inverse of isKeyMaterial for non-empty strings', () => {
		expect(isPasswordBearing('Summer2024!')).toBe(true)
		expect(isPasswordBearing('-----BEGIN PRIVATE KEY-----')).toBe(false)
		expect(isPasswordBearing('')).toBe(false)
	})
})
