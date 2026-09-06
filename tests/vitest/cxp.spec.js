/**
 * @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
 *
 * CXP transport: request → seal → open round-trip, and the fail-fast negative
 * cases (version mismatch, suite downgrade, misdirected envelope rejected before
 * a decrypt attempt).
 */
import { describe, expect, it } from 'vitest'
import {
	createImportRequest,
	CXP_VERSION,
	openEnvelope,
	sealForRequest,
} from '../../src/crypto/cxp.js'

const enc = new TextEncoder()
const dec = new TextDecoder()

function cxfBytes() {
	return enc.encode(
		JSON.stringify({
			version: { major: 1, minor: 0 },
			accounts: [{ items: [] }],
		}),
	)
}

describe('CXP transport', () => {
	it('round-trips a CXF payload through request → seal → open', async () => {
		const { request, session } = await createImportRequest()
		expect(request.v).toBe(CXP_VERSION)
		expect(request.requestedFormat).toBe('CXF')
		expect(typeof request.requesterPublicKey).toBe('string')

		const payload = cxfBytes()
		const envelope = await sealForRequest(request, payload)
		expect(envelope.v).toBe(CXP_VERSION)
		expect(envelope.suite).toEqual({ kem: 0x0020, kdf: 0x0001, aead: 0x0002 })

		const opened = await openEnvelope(session, envelope)
		expect(dec.decode(opened)).toBe(dec.decode(payload))
	})

	it('rejects an unsupported request version at seal time', async () => {
		const { request } = await createImportRequest()
		await expect(
			sealForRequest({ ...request, v: 'keepiq-cxp-v99' }, cxfBytes()),
		).rejects.toThrow(/version/)
	})

	it('rejects an unsupported envelope version at open time (fail fast)', async () => {
		const { request, session } = await createImportRequest()
		const envelope = await sealForRequest(request, cxfBytes())
		await expect(
			openEnvelope(session, { ...envelope, v: 'keepiq-cxp-v99' }),
		).rejects.toThrow(/version/)
	})

	it('refuses to open on an HPKE suite downgrade', async () => {
		const { request, session } = await createImportRequest()
		const envelope = await sealForRequest(request, cxfBytes())
		await expect(
			openEnvelope(session, {
				...envelope,
				suite: { kem: 0x0020, kdf: 0x0001, aead: 0x0001 },
			}),
		).rejects.toThrow(/suite/)
	})

	it('rejects a misdirected envelope before a decrypt attempt (nonce binding)', async () => {
		const a = await createImportRequest()
		const b = await createImportRequest()
		// Seal for A's request, then try to open with B's session.
		const envelopeForA = await sealForRequest(a.request, cxfBytes())
		await expect(openEnvelope(b.session, envelopeForA)).rejects.toThrow(
			/misdirected|fingerprint/,
		)
	})

	it('binds the envelope nonce to the request', async () => {
		const { request, session } = await createImportRequest()
		const envelope = await sealForRequest(request, cxfBytes())
		expect(envelope.nonce).toBe(request.nonce)
		// Tamper the nonce → rejected before decrypt.
		await expect(
			openEnvelope(session, {
				...envelope,
				nonce: 'AAAAAAAAAAAAAAAAAAAAAA==',
			}),
		).rejects.toThrow(/nonce/)
	})
})
