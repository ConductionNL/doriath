/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for the minimal client-side X.509 parser
 * (certificate-lifecycle §5.2) against a static self-signed fixture.
 */
import { describe, expect, it } from 'vitest'
import { parseCertificatePem } from '../../src/certificates/x509.js'

// Static self-signed RSA-2048 fixture: CN=x509-fixture.doriath.test,
// The CN keeps the old name after the doriath -> keepiq rename because it is
// baked into the SIGNED DER of the PEM below — editing the string here would
// only make the assertion disagree with the certificate. Renaming it means
// regenerating and re-signing the fixture, which is not part of a rename.
// notAfter 2036-07-15T14:43:57Z, serial 0E9E...2E9F.
const FIXTURE_PEM = `-----BEGIN CERTIFICATE-----
MIIDbTCCAlWgAwIBAgIUDp6EiEfMr98205tBqHfwoL2zLp8wDQYJKoZIhvcNAQEL
BQAwRjELMAkGA1UEBhMCTkwxEzARBgNVBAoMCkNvbmR1Y3Rpb24xIjAgBgNVBAMM
GXg1MDktZml4dHVyZS5kb3JpYXRoLnRlc3QwHhcNMjYwNzE4MTQ0MzU3WhcNMzYw
NzE1MTQ0MzU3WjBGMQswCQYDVQQGEwJOTDETMBEGA1UECgwKQ29uZHVjdGlvbjEi
MCAGA1UEAwwZeDUwOS1maXh0dXJlLmRvcmlhdGgudGVzdDCCASIwDQYJKoZIhvcN
AQEBBQADggEPADCCAQoCggEBAODW75TJFfVWW5fOLq4sLmhvpSLgGwuau32Iomta
OHCrtOFlg1ldtxmh/9/b7mNTUjbEEH5yp5HppjnKML2/nbiVa7OexvR7SuBbQVp5
xS34hgtF/CY5WDQmT4BmDQ3bekScZUrstpRT5YQebSzN4ZWLKVflmkeaHDBb88eg
eYRvfJtYF2wn+jSb7VQLCMc1U2zOn69ANY29TdzqRcWn2aatAo9iJQElXvMSK2R5
LDQJwx6tsLRFHCcoHMLn16D1tkGEnp4va421d7bsQXNwLFJYH5PckMRSTP8HXqSz
fI2yKRTj14TJrC0tpRSwfPrHBPE68uvZuXRa1c7vjAgO8K8CAwEAAaNTMFEwHQYD
VR0OBBYEFCiQYhQ+W2Vi4LXzdJeTMtczZ8n4MB8GA1UdIwQYMBaAFCiQYhQ+W2Vi
4LXzdJeTMtczZ8n4MA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEB
AAtZKjbKjYmWmUly84GkV2MlMAJNF5rXVkwJBZxDRKBEHVo6FWrLGcSWVsgAPylF
PgDKOIuJ3guFGlGV3pdpJmA/N/Q1d0uKTSGdnXBadPPqfSvt4nhLQU1UoHKlfEPC
0s4PIrkNHSklZlm0kPiNI2fNR784re/zZHEZ+Gggz1CGU6wTssirKmiKt4zACnGq
jfRZLmj/f+rZ2QcCjL/qEXzNE6oJ6iM4cxsA3qzYm2D6PQ9rnKnH4i5vRSvie96Y
W1yGh5Ssllx7+SxqpkSGNpCEQTCTf8reVXrlfyt+IM1ZUvyYtAh5rnzW9O6Y19pQ
8ud+ZuuBZwClT2SQY8yBiBQ=
-----END CERTIFICATE-----`

describe('parseCertificatePem', () => {
	it('extracts subject, issuer, serial, validity, and fingerprint', async () => {
		const parsed = await parseCertificatePem(FIXTURE_PEM)
		expect(parsed).not.toBeNull()
		expect(parsed.subject).toContain('CN=x509-fixture.doriath.test')
		expect(parsed.subject).toContain('O=Conduction')
		expect(parsed.issuer).toContain('CN=x509-fixture.doriath.test')
		expect(parsed.serial).toBe('0E9E848847CCAFDF36D39B41A877F0A0BDB32E9F')
		expect(parsed.notBefore).toBe('2026-07-18T14:43:57Z')
		expect(parsed.notAfter).toBe('2036-07-15T14:43:57Z')
		expect(parsed.fingerprintSha256).toBe(
			'sha256:'
				+ 'f573d3b4f1ac2d13c3a34c424539293a64117f3803e8c3243fe0eba93620a7fd',
		)
	})

	it('returns null for non-certificate input', async () => {
		expect(await parseCertificatePem('not a pem')).toBeNull()
		expect(await parseCertificatePem('')).toBeNull()
		expect(
			await parseCertificatePem(
				'-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----',
			),
		).toBeNull()
	})

	it('never returns private-key material', async () => {
		const parsed = await parseCertificatePem(FIXTURE_PEM)
		const json = JSON.stringify(parsed)
		expect(json).not.toContain('PRIVATE KEY')
		expect(Object.keys(parsed).sort()).toEqual([
			'fingerprintSha256',
			'issuer',
			'notAfter',
			'notBefore',
			'serial',
			'subject',
		])
	})
})
