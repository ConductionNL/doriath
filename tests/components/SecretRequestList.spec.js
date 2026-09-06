/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/secretRequest/SecretRequestList.vue`.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.4
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretRequestList from '../../src/components/secretRequest/SecretRequestList.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SecretRequestList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when there are no requests', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(
			wrapper.find('[data-testid="secret-request-list-empty"]').exists(),
		).toBe(true)
	})

	it('renders one row per request with the correct status-flavored testid', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'tok-abcdef0123',
					requested_fields: ['key'],
				},
				{
					id: 'r2',
					status: 'fulfilled',
					token: 'tok-zzzzzzzzzz',
					requested_fields: ['key'],
				},
			],
		})
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(
			wrapper.find('[data-testid="secret-request-row-pending"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="secret-request-row-fulfilled"]').exists(),
		).toBe(true)
		// Revoke only on pending rows.
		expect(
			wrapper.findAll('[data-testid="secret-request-row-revoke"]'),
		).toHaveLength(1)
	})

	it('filters by secretId when the prop is set', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 't1',
					secret_id: 'sec-1',
					requested_fields: ['key'],
				},
				{
					id: 'r2',
					status: 'pending',
					token: 't2',
					secret_id: 'sec-2',
					requested_fields: ['key'],
				},
			],
		})
		const wrapper = mount(SecretRequestList, {
			propsData: { secretId: 'sec-2' },
		})
		await flush()
		expect(
			wrapper.findAll('[data-testid="secret-request-row-pending"]'),
		).toHaveLength(1)
	})

	it('dispatches the revoke action when the revoke button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'tok-1',
					requested_fields: ['key'],
				},
			],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(SecretRequestList)
		await flush()
		await wrapper
			.find('[data-testid="secret-request-row-revoke"]')
			.trigger('click')
		// `flush()` is a SINGLE macrotask tick, and the submit path awaits an
		// async chain before it posts. One tick is not guaranteed to drain it,
		// so this asserted on a call that had not happened yet and failed about
		// once in twenty full-suite runs while passing every time in isolation.
		// waitFor retries until the call lands, and still fails if it never does.
		await flush()
		await vi.waitFor(() => expect(del).toHaveBeenCalled())
	})
	it('copies the fill link for a pending request', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'aaaaaaaabbbbbbbbccccccccdddddddd',
					requested_fields: ['key'],
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		await wrapper
			.find('[data-testid="secret-request-row-copy-r1"]')
			.trigger('click')

		// The anonymous shell form — the one a recipient without an account can open.
		expect(writeText).toHaveBeenCalledTimes(1)
		const copied = writeText.mock.calls[0][0]
		expect(copied).toContain('/apps/keepiq/public/share/request/')
		expect(copied).toContain('aaaaaaaabbbbbbbbccccccccdddddddd')
		expect(copied).not.toContain('/api/v1/public/')
	})

	it('offers no link for a fulfilled or lapsed request', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'done',
					status: 'fulfilled',
					token: 'tok-1',
					requested_fields: ['key'],
				},
				{
					id: 'lapsed',
					status: 'pending',
					token: 'tok-2',
					requested_fields: ['key'],
					// Expiry is checked against the timestamp, not the status: nothing
					// sweeps yet, so a lapsed request still reads as pending.
					expiresAt: '2000-01-01T00:00:00+00:00',
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		expect(
			wrapper.find('[data-testid="secret-request-row-copy-done"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="secret-request-row-copy-lapsed"]').exists(),
		).toBe(false)
	})

	it('never renders the full token', async () => {
		const full = 'ffffffff11111111222222223333333'
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: full,
					requested_fields: ['key'],
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		// Truncated on screen; the full value reaches the clipboard only on request.
		expect(wrapper.text()).not.toContain(full)
	})

	describe('application scope (administrator view)', () => {
		const appRows = [
			{
				id: 'r-app-1',
				status: 'pending',
				token: 'FULLTOKEN0123456789abcdef',
				requestedFields: ['key', 'login'],
				createdBy: 'application:app-1',
			},
			{
				id: 'r-app-2',
				status: 'pending',
				token: 'LAPSEDTOKEN0123456789abc',
				requestedFields: ['key'],
				createdBy: 'application:app-1',
				expiresAt: '2020-01-01T00:00:00+00:00',
			},
		]

		it('fetches from the admin endpoint, not the user one', async () => {
			// The scope has to come from the URL. If the component fetched the user
			// endpoint and filtered client-side, an administrator would see nothing
			// (application rows never match a user listing) and the authority for the
			// read would have moved into the component.
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			mount(SecretRequestList, { propsData: { applicationId: 'app-1' } })
			await flush()

			const urls = get.mock.calls.map((c) => c[0])
			expect(
				urls.some((u) => u.includes('/applications/app-1/secret-requests')),
			).toBe(true)
			// And NOT the user endpoint — note the admin URL also ends in
			// '/secret-requests', so the user one has to be matched exactly.
			expect(urls.some((u) => /\/api\/v1\/secret-requests$/.test(u))).toBe(
				false,
			)
		})

		it('renders the application rows', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			const wrapper = mount(SecretRequestList, {
				propsData: { applicationId: 'app-1' },
			})
			await flush()

			expect(wrapper.text()).toContain('key')
			expect(wrapper.findAll('li').length).toBe(2)
		})

		it('never renders a full token', async () => {
			// A row travels into screenshots and over shoulders, and a fill token is a
			// bearer credential: whoever reads it can submit against the request.
			vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			const wrapper = mount(SecretRequestList, {
				propsData: { applicationId: 'app-1' },
			})
			await flush()

			expect(wrapper.text()).not.toContain('FULLTOKEN0123456789abcdef')
			expect(wrapper.text()).not.toContain('LAPSEDTOKEN0123456789abc')
			expect(wrapper.text()).toContain('FULLTOKE…')
		})

		it('lists the expiry, and reads a lapsed row as expired', async () => {
			// The scenario requires status, requested fields AND expiry. The second
			// row lapsed in 2020 but is still stored as `pending` — nothing sweeps
			// within the hour — so labelling it "Pending" would show a state the
			// access gate no longer honours, and leave the absent copy button
			// unexplained.
			vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			const wrapper = mount(SecretRequestList, {
				propsData: { applicationId: 'app-1' },
			})
			await flush()

			const rows = wrapper.findAll('li')
			expect(rows[1].text()).toContain('Expired')
			expect(rows[1].text()).not.toContain('Pending')
			// A request with no expiry is a link that works forever — say so rather
			// than leaving the cell blank.
			expect(rows[0].text()).toContain('No expiry')
		})

		it('offers copy-link only where the link is still usable', async () => {
			// The second row lapsed in 2020. Nothing sweeps within the hour between
			// runs of the expiry job, so a pending row can still be past its expiry —
			// judged on the timestamp, never on the stored status alone.
			vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			const wrapper = mount(SecretRequestList, {
				propsData: { applicationId: 'app-1' },
			})
			await flush()

			const rows = wrapper.findAll('li')
			expect(rows[0].html()).toContain('secret-request-row-copy-r-app-1')
			expect(rows[1].html()).not.toContain('secret-request-row-copy-r-app-2')
		})

		it('asks before revoking, and revokes through the application endpoint', async () => {
			// An admin revoke interrupts software they did not write, so it confirms
			// first — unlike a user revoking a request they created themselves.
			vi.spyOn(axios, 'get').mockResolvedValue({ data: appRows })
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const wrapper = mount(SecretRequestList, {
				propsData: { applicationId: 'app-1' },
			})
			await flush()

			// One shared testid across rows, so select the first.
			await wrapper
				.findAll('[data-testid="secret-request-row-revoke"]')[0]
				.trigger('click')
			await flush()

			expect(del).not.toHaveBeenCalled()
			expect(wrapper.vm.revokeTarget).toEqual(
				expect.objectContaining({ id: 'r-app-1' }),
			)

			await wrapper.vm.onRevokeConfirmed()
			await flush()

			expect(del).toHaveBeenCalledTimes(1)
			expect(del.mock.calls[0][0]).toContain(
				'/applications/app-1/secret-requests/r-app-1',
			)
		})

		it('a user-scoped revoke still goes straight through', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [appRows[0]] })
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const wrapper = mount(SecretRequestList)
			await flush()

			await wrapper
				.findAll('[data-testid="secret-request-row-revoke"]')[0]
				.trigger('click')
			await flush()

			expect(del).toHaveBeenCalledTimes(1)
		})
	})
})
