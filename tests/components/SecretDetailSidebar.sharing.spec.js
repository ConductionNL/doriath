/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the §12.6 sharing integration on the secret-detail
 * sidebar (the restyle Stage-8 successor of the SecretDetail view). The
 * component is mounted with the RecipientList / DelegationManager /
 * ShareRequestForm components stubbed, so the test asserts the role-driven
 * visibility branches rather than re-asserting each child's behaviour
 * (those are covered by their own .spec.js files). NcAppSidebar and its
 * tabs are stubbed to plain slot-renderers, so every tab's content is in
 * the DOM at once.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-12.6
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	Delete: { template: '<span />' },
	Lock: { template: '<span />' },
	Pencil: { template: '<span />' },
	FolderMove: { template: '<span />' },
	ShareVariant: { template: '<span />' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div data-testid="stub-share-list" />' },
	DelegationManager: { template: '<div data-testid="stub-delegation-manager" />' },
	ShareRequestForm: { template: '<div data-testid="stub-share-request-form" />' },
	SecretRequestList: { template: '<div data-testid="stub-request-list" />' },
	SecretRequestCreateDialog: {
		template: '<div data-testid="stub-request-dialog" />',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

async function mountDetail({ secret, currentUser }) {
	window.OC = { currentUser }
	// Pre-seed the secret store so the component's mounted hook resolves
	// `fetchSecret` to our fixture rather than going through the live
	// RSA decryption path.
	const secretStore = useSecretStore()
	secretStore.fetchSecret = vi.fn().mockResolvedValue(secret)
	const typeStore = useSecretTypeStore()
	typeStore.fetchTypes = vi.fn().mockResolvedValue([])

	// VTU v2 moved `stubs` and `mocks` under `global`. At the top level they
	// are SILENTLY IGNORED — the component would render its real children, so
	// this must stay nested.
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: secret?.id ?? 's-1' },
		global: {
			stubs: stubAll,
		},
	})
	await flush()
	await flush()
	return wrapper
}

describe('SecretDetailSidebar sharing tab (§12.6)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// Stub @nextcloud/axios so the secretType store + child component
		// mounted hooks resolve to empty payloads instead of blowing up.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		window.OC = { currentUser: null }
	})

	it('renders the recipient list + delegation manager for the secret owner', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-share-list"]').exists(),
		).toBe(true)
		expect(
			wrapper
				.find('[data-testid="secret-detail-delegation-manager"]')
				.exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="secret-detail-share-request"]').exists(),
		).toBe(false)
	})

	it('renders the share-request form for a non-owner recipient', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'bob',
		})

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-share-list"]').exists(),
		).toBe(false)
		expect(
			wrapper
				.find('[data-testid="secret-detail-delegation-manager"]')
				.exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="secret-detail-share-request"]').exists(),
		).toBe(true)
	})

	it('hides the entire sharing tab when no secret is loaded', async () => {
		// The component's mounted hook will set `secret` via fetchSecret;
		// when our store stub returns null, the v-if="!error && secret"
		// short-circuits every tab and the sharing section never renders.
		const wrapper = await mountDetail({ secret: null, currentUser: 'alice' })

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			false,
		)
	})

	it('treats ownerId as the single canonical owner field (fail-closed)', async () => {
		// The Secret entity has serialized `ownerId` since its first version,
		// so no response or offline snapshot ever carried `owner_id`/`userId`.
		// A payload with only a legacy-style name therefore reads as
		// not-owned rather than re-introducing a fallback chain every
		// consumer would have to duplicate (PR #479 review).
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', owner_id: 'alice' },
			currentUser: 'alice',
		})

		expect(
			wrapper.find('[data-testid="secret-detail-share-list"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="secret-detail-share-request"]').exists(),
		).toBe(true)
	})

	it('renders the Requests section + SecretRequestList for the owner', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		expect(wrapper.find('[data-testid="secret-detail-requests"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-request-list"]').exists(),
		).toBe(true)
		// The dialog is mounted lazily; not visible until the create button fires.
		expect(
			wrapper.find('[data-testid="secret-detail-request-dialog"]').exists(),
		).toBe(false)
	})

	it('hides the Requests section from non-owner recipients', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'bob',
		})

		expect(wrapper.find('[data-testid="secret-detail-requests"]').exists()).toBe(
			false,
		)
	})
	it('keeps the request dialog open after creation, so the fill link survives', async () => {
		// The dialog computes fillUrl in submit() and THEN emits `created`. This
		// handler used to close the dialog, which unmounts it (v-if) one tick later
		// and destroys the only copy of the link the requester is ever offered —
		// the URL the whole feature exists to produce.
		const wrapper = await mountDetail({
			secret: {
				id: 's-1',
				name: 'GitHub PAT',
				ownerId: 'alice',
				key: 'CIPHER',
			},
			currentUser: 'alice',
		})

		wrapper.vm.requestDialogOpen = true
		await flush()

		wrapper.vm.onRequestCreated()
		await flush()

		expect(wrapper.vm.requestDialogOpen).toBe(true)
	})
})
