/**
 * @spec openspec/changes/extension-totp-autofill/specs/extension-totp-autofill/spec.md
 *
 * The extension TOTP service reuses the web app's RFC 6238 generator verbatim
 * (extension-totp-autofill §1.1/§6.1). Anchored to the RFC 6238 Appendix B test
 * vector (SHA1 seed "12345678901234567890", T=59s → 6-digit 287082), and the
 * honest invalid-seed contract (never a fabricated code).
 */
import { describe, expect, it } from 'vitest'
import { computeTotp } from '../../browser-extension/src/lib/totp-service.js'

// base32 of ASCII "12345678901234567890" (the RFC 6238 SHA1 seed)
const RFC_SEED =
	'otpauth://totp/rfc?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&algorithm=SHA1&digits=6&period=30'

describe('extension TOTP service', () => {
	it('matches the RFC 6238 vector at T=59s (6-digit)', async () => {
		const res = await computeTotp(RFC_SEED, 59000)
		expect(res.valid).toBe(true)
		expect(res.code).toBe('287082')
		expect(res.secondsRemaining).toBe(1) // 30 - (59 % 30)
	})

	it('rolls to a different code in the next window', async () => {
		const a = await computeTotp(RFC_SEED, 59000)
		const b = await computeTotp(RFC_SEED, 90000) // counter 3
		expect(a.code).not.toBe(b.code)
	})

	it('returns the honest invalid state for an unparseable seed (never a code)', async () => {
		const res = await computeTotp('this is not a valid otp seed !@#', 59000)
		expect(res.valid).toBe(false)
		expect(res.code).toBeUndefined()
	})

	it('rejects an empty seed', async () => {
		const res = await computeTotp('', 59000)
		expect(res.valid).toBe(false)
	})
})
