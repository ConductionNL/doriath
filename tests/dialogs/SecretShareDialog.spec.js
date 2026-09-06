/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/SecretShareDialog.vue` — the unified
 * link-share dialog that COMBINES the create form, the existing-shares
 * list, and the one-time password reveal (per the consolidation
 * documented in tasks 8.2 / 8.3 / 8.4 of implement-link-sharing).
 *
 * What these lock down:
 *  - On mount, the dialog calls `useLinkShareStore().fetchLinkShares`
 *    with the `secretId` prop (loads the existing-shares list).
 *  - `createLink` decrypts the secret via `useSecretStore().fetchSecret`,
 *    forwards the snapshot to `useLinkShareStore().createLinkShare`, and
 *    surfaces the store's transient `createdLinkUrl` + `createdPassword`
 *    onto the local component state for the one-time reveal.
 *  - `revoke` delegates to `useLinkShareStore().deleteLinkShare`.
 *  - Closing the dialog (`@update:open=false`) clears the transient
 *    password and emits `close` to the parent.
 *
 * Running under jsdom — the test mounts the SFC via `@vue/test-utils`
 * with shallow stubs for the `@nextcloud/vue` components so we don't
 * pull the entire NC design-system tree into the test bundle.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.4
 * @spec openspec/specs/link-sharing/spec.md#requirement-create-link-share
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretShareDialog from '../../src/dialogs/SecretShareDialog.vue'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

// Lightweight stubs for `@nextcloud/vue` + icon components. `mount` with
// `global.stubs` swaps the registered component name for a `<stub>` shell
// that still emits user events — exactly what we need to drive the
// click handlers without dragging in the design-system CSS pipeline.
const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['type', 'disabled', 'ariaLabel'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'value'],
		template: '<div class="nc-select-stub"><slot /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="nc-note-card-stub" :data-type="type"><slot /></div>',
	},
	NcLoadingIcon: { template: '<span class="nc-loading-icon-stub" />' },
	ShareVariant: { template: '<i class="icon-share" />' },
	AccountPlus: { template: '<i class="icon-account-plus" />' },
	Delete: { template: '<i class="icon-delete" />' },
	CopyButton: {
		props: ['value', 'label'],
		template:
			'<button class="copy-stub" :data-label="label">{{ value }}</button>',
	},
}

describe('SecretShareDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('fetches existing link shares for the secret on mount', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		const wrapper = mount(SecretShareDialog, {
			propsData: { secretId: 'secret-42' },
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/secrets/secret-42/link-shares',
		)
	})

	it('createLink: decrypts the secret, encrypts a snapshot, and surfaces the one-time reveal', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		// `useSecretStore().fetchSecret` is the in-process decrypt step;
		// stub it directly via Pinia so the test does not exercise the
		// real RSA private-key path (which needs a session-store CryptoKey).
		setActivePinia(createPinia())
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi.fn().mockResolvedValue({
			id: 'secret-42',
			name: 'GitHub PAT',
			url: 'https://github.com',
			login: 'git-user',
			key: 'ghp_AAA',
			additionalFields: { note: 'rotate' },
		})

		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				id: 'ls-001',
				token: 'tok-001',
				linkUrl: '/share/link/tok-001',
				usageLimit: 1,
				usageCount: 0,
			},
		})

		const wrapper = mount(SecretShareDialog, {
			propsData: { secretId: 'secret-42' },
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()
		await wrapper.vm.createLink()

		// Local state mirrors the store's transient one-time reveal fields.
		const linkStore = useLinkShareStore()
		expect(wrapper.vm.createdUrl).toBe('/share/link/tok-001')
		expect(wrapper.vm.createdPassword).toBe(linkStore.createdPassword)
		expect(wrapper.vm.createdPassword).toHaveLength(20)

		// The snapshot dispatched to fetchSecret was passed through.
		expect(secretStore.fetchSecret).toHaveBeenCalledWith('secret-42')

		// Component emits `saved` for the parent to refresh its own state.
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('revoke delegates to the store deleteLinkShare action', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'ls-1', token: 't1', usageLimit: 1, usageCount: 0 }],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })

		const wrapper = mount(SecretShareDialog, {
			propsData: { secretId: 'secret-42' },
			global: { stubs: ncStubs },
		})

		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		await wrapper.vm.revoke('ls-1')

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/link-shares/ls-1')
	})

	it('closes the dialog: clears the one-time password and emits `close`', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		const wrapper = mount(SecretShareDialog, {
			propsData: { secretId: 'secret-42' },
			global: { stubs: ncStubs },
		})

		const linkStore = useLinkShareStore()
		linkStore.createdPassword = 'leaked-if-not-cleared'
		linkStore.createdLinkUrl = '/share/link/x'

		await wrapper.vm.$nextTick()
		wrapper.vm.onUpdateOpen(false)

		expect(linkStore.createdPassword).toBeNull()
		expect(linkStore.createdLinkUrl).toBeNull()
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})
