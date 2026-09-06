/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the card/identity payload model (card-identity-items
 * §7.2/§7.3/§7.5): payload round-trips, in-browser brand + last-4
 * derivation (never stored), Luhn/expiry hinting, and the import
 * mapper's Bitwarden card/identity routing.
 *
 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
 */

import { describe, expect, it } from 'vitest'
import {
	cardBrand,
	cardLast4,
	expiryFormatValid,
	luhnValid,
	parsePayload,
	serializeCard,
	serializeIdentity,
} from '../../src/cardIdentity/cardIdentity.js'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'

describe('card payload', () => {
	it('round-trips a card payload with number, CVV, and PIN intact', () => {
		const json = serializeCard({
			number: '4111 1111 1111 1111',
			expiry: '12/28',
			cvv: '123',
			pin: '4321',
			cardholder: 'R. van der Linde',
		})
		const payload = parsePayload(json)
		expect(payload.number).toBe('4111 1111 1111 1111')
		expect(payload.cvv).toBe('123')
		expect(payload.pin).toBe('4321')
		expect(payload.expiry).toBe('12/28')
		expect(payload.cardholder).toBe('R. van der Linde')
		// Derived facts are NOT part of the stored payload.
		expect(json).not.toContain('brand')
		expect(json).not.toContain('last4')
	})

	it('derives brand + last-4 from the number, never stores them', () => {
		expect(cardBrand('4111 1111 1111 1111')).toBe('Visa')
		expect(cardBrand('5555 5555 5555 4444')).toBe('Mastercard')
		expect(cardBrand('3782 822463 10005')).toBe('American Express')
		expect(cardLast4('4111 1111 1111 1111')).toBe('1111')
	})

	it('yields "Card" for an unknown prefix — never a fabricated brand', () => {
		expect(cardBrand('9999 8888 7777 6666')).toBe('Card')
		expect(cardBrand('')).toBe('Card')
	})

	it('Luhn + expiry hints are best-effort and format-only', () => {
		expect(luhnValid('4111111111111111')).toBe(true)
		expect(luhnValid('4111111111111112')).toBe(false)
		expect(expiryFormatValid('12/28')).toBe(true)
		expect(expiryFormatValid('13/28')).toBe(false)
	})
})

describe('identity payload', () => {
	it('round-trips an identity payload with the BSN intact', () => {
		const json = serializeIdentity({
			firstName: 'Test',
			lastName: 'Persoon',
			address: 'Stationsplein 1, Amsterdam',
			phone: '+31 6 12345678',
			email: 'test@example.nl',
			bsn: '999990019',
		})
		const payload = parsePayload(json)
		expect(payload.bsn).toBe('999990019')
		expect(payload.firstName).toBe('Test')
	})

	it('tolerates a legacy plain-string value by returning null', () => {
		expect(parsePayload('just-a-password')).toBeNull()
		expect(parsePayload('')).toBeNull()
	})
})

describe('import mapper: Bitwarden card/identity routing', () => {
	it('routes a Bitwarden card item into a card row with the payload in password', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{
						type: 3,
						name: 'My Visa',
						card: {
							cardholderName: 'R. Tester',
							brand: 'Visa',
							number: '4111111111111111',
							expMonth: '3',
							expYear: '2028',
							code: '123',
						},
					},
				],
			}),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('card')
		expect(rows[0].errors).toEqual([])
		const payload = parsePayload(rows[0].password)
		expect(payload.number).toBe('4111111111111111')
		expect(payload.expiry).toBe('03/28')
		expect(payload.cvv).toBe('123')
		// The Bitwarden brand field is deliberately dropped (derived on render).
		expect(rows[0].password).not.toContain('Visa')
	})

	it('routes a Bitwarden identity item into an identity row with ssn -> bsn', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{
						type: 4,
						name: 'My identity',
						identity: {
							firstName: 'Test',
							lastName: 'Persoon',
							ssn: '999990019',
							email: 't@example.nl',
							address1: 'Stationsplein 1',
							city: 'Amsterdam',
						},
					},
				],
			}),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('identity')
		expect(rows[0].errors).toEqual([])
		const payload = parsePayload(rows[0].password)
		expect(payload.bsn).toBe('999990019')
		expect(payload.address).toContain('Amsterdam')
	})

	it('rejects a card item without a number and an empty identity item', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{ type: 3, name: 'Empty card', card: {} },
					{ type: 4, name: 'Empty identity', identity: {} },
				],
			}),
		)
		expect(rows).toHaveLength(2)
		expect(rows[0].errors.length).toBeGreaterThan(0)
		expect(rows[1].errors.length).toBeGreaterThan(0)
	})
})
