<!--
  @visual exclude UNREACHABLE, not un-baselined — tracked in issue #208. Nothing
  in src/ imports this component; `grep -rn DashboardSettingsView src/` returns
  only this file. It is in no `pages[]` entry, not in src/registry.js, and there
  is no router. The shipped bundle settles it —
  `grep -c keepiq-dashboard-settings js/keepiq-main.js` returns 0 while the
  same probe returns 1 for wired views, so webpack tree-shook it out. There is
  no route for a browser to visit and therefore no screen to capture.
  ⚠️ This waiver is not "no baseline needed" — it records a defect, and a larger
  one than its neighbour: this view is the ONLY consumer of
  useDashboardSettingsStore, which is backed by a real controller and the
  oc_keepiq_dashboard_settings table, so the whole dashboard-preferences
  feature is implemented end to end with no way in. Remove this waiver together
  with issue #208, by wiring the view up or by deleting the feature.
-->
<template>
	<div class="keepiq-dashboard-settings">
		<h2>{{ t('keepiq', 'Dashboard preferences') }}</h2>

		<p class="keepiq-dashboard-settings__intro">
			{{
				t(
					'keepiq',
					'Customise how your Keepiq dashboard looks. Leave a value blank to fall back to the system default.',
				)
			}}
		</p>

		<form data-testid="dashboard-settings-form" @submit.prevent="save">
			<fieldset class="keepiq-dashboard-settings__group">
				<legend>{{ t('keepiq', 'Layout') }}</legend>

				<label class="keepiq-dashboard-settings__field">
					<span>{{ t('keepiq', 'Default view') }}</span>
					<select
						v-model="form.default_view"
						data-testid="default-view-select">
						<option value="">
							{{ t('keepiq', 'System default') }}
						</option>
						<option value="list">{{ t('keepiq', 'List') }}</option>
						<option value="grid">{{ t('keepiq', 'Grid') }}</option>
					</select>
				</label>

				<label class="keepiq-dashboard-settings__field">
					<span>{{ t('keepiq', 'Sort field') }}</span>
					<select
						v-model="form.sort_field"
						data-testid="sort-field-select">
						<option value="">
							{{ t('keepiq', 'System default') }}
						</option>
						<option value="name">{{ t('keepiq', 'Name') }}</option>
						<option value="created_at">
							{{ t('keepiq', 'Created at') }}
						</option>
						<option value="updated_at">
							{{ t('keepiq', 'Last modified') }}
						</option>
					</select>
				</label>

				<label class="keepiq-dashboard-settings__field">
					<span>{{ t('keepiq', 'Sort direction') }}</span>
					<select
						v-model="form.sort_direction"
						data-testid="sort-direction-select">
						<option value="">
							{{ t('keepiq', 'System default') }}
						</option>
						<option value="asc">{{ t('keepiq', 'Ascending') }}</option>
						<option value="desc">
							{{ t('keepiq', 'Descending') }}
						</option>
					</select>
				</label>
			</fieldset>

			<fieldset class="keepiq-dashboard-settings__group">
				<legend>{{ t('keepiq', 'Widgets') }}</legend>

				<label>
					<input
						v-model="form.show_kpi_widget"
						type="checkbox"
						data-testid="kpi-toggle" />
					{{ t('keepiq', 'Show KPI cards') }}
				</label>

				<label>
					<input
						v-model="form.show_recent_widget"
						type="checkbox"
						data-testid="recent-toggle" />
					{{ t('keepiq', 'Show recent secrets') }}
				</label>

				<label>
					<input
						v-model="form.show_migration_banner"
						type="checkbox"
						data-testid="migration-toggle" />
					{{ t('keepiq', 'Show migration banner when active') }}
				</label>
			</fieldset>

			<div class="keepiq-dashboard-settings__actions">
				<button
					type="submit"
					class="primary"
					:disabled="store.loading"
					data-testid="save-settings">
					{{
						store.loading ? t('keepiq', 'Saving…') : t('keepiq', 'Save')
					}}
				</button>
				<button
					type="button"
					:disabled="store.loading"
					data-testid="reset-settings"
					@click="reset">
					{{ t('keepiq', 'Reset to defaults') }}
				</button>
			</div>

			<p v-if="store.error" class="keepiq-dashboard-settings__error">
				{{ store.error }}
			</p>
		</form>
	</div>
</template>

<script>
import {
	ALLOWED_DASHBOARD_KEYS,
	useDashboardSettingsStore,
} from '../store/modules/dashboardSettings.js'

/**
 * Per-user dashboard preferences view backed by
 * useDashboardSettingsStore. Hydrates on mount, persists the diff on
 * submit, and exposes a reset action that wipes the user's overrides.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.3
 */
export default {
	name: 'DashboardSettingsView',

	data() {
		return {
			store: useDashboardSettingsStore(),
			form: {
				default_view: '',
				sort_field: '',
				sort_direction: '',
				show_kpi_widget: true,
				show_recent_widget: true,
				show_migration_banner: true,
			},
		}
	},

	async created() {
		try {
			await this.store.fetchAll()
		} catch (e) {
			// The store already stamped state.error; let the template
			// render the message instead of bubbling out of created().
		}
		this.hydrateFromStore()
	},

	methods: {
		/**
		 * Copy the hydrated settings into the local form. Boolean
		 * widgets default to true when the server has no preference
		 * stored.
		 *
		 * @return {void}
		 */
		hydrateFromStore() {
			const s = this.store.settings
			this.form.default_view = s.default_view ?? ''
			this.form.sort_field = s.sort_field ?? ''
			this.form.sort_direction = s.sort_direction ?? ''
			this.form.show_kpi_widget = this.coerceBool(s.show_kpi_widget, true)
			this.form.show_recent_widget = this.coerceBool(
				s.show_recent_widget,
				true,
			)
			this.form.show_migration_banner = this.coerceBool(
				s.show_migration_banner,
				true,
			)
		},

		/**
		 * Coerce a stored string preference to a boolean, falling back
		 * to the supplied default when the value is undefined.
		 *
		 * @param {*} value The raw value.
		 * @param {boolean} fallback The fallback.
		 * @return {boolean}
		 */
		coerceBool(value, fallback) {
			if (value === undefined || value === null) {
				return fallback
			}
			if (typeof value === 'boolean') {
				return value
			}
			return value === '1' || value === 'true' || value === true
		},

		/**
		 * Persist the form to the server.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			const payload = {
				default_view: this.form.default_view,
				sort_field: this.form.sort_field,
				sort_direction: this.form.sort_direction,
				show_kpi_widget: this.form.show_kpi_widget ? '1' : '0',
				show_recent_widget: this.form.show_recent_widget ? '1' : '0',
				show_migration_banner: this.form.show_migration_banner ? '1' : '0',
			}
			// Drop empty strings — the user is asking for the system
			// default for that key.
			for (const key of Object.keys(payload)) {
				if (payload[key] === '') {
					delete payload[key]
				}
			}
			await this.store.setMany(payload)
		},

		/**
		 * Wipe the user overrides and rehydrate the form.
		 *
		 * @return {Promise<void>}
		 */
		async reset() {
			await this.store.reset()
			this.hydrateFromStore()
		},
	},

	allowedKeys: ALLOWED_DASHBOARD_KEYS,
}
</script>

<style scoped>
.keepiq-dashboard-settings {
	max-width: 720px;
	padding: 1rem;
}

.keepiq-dashboard-settings__group {
	border: 1px solid var(--color-border, #ddd);
	padding: 1rem;
	margin-bottom: 1rem;
}

.keepiq-dashboard-settings__group legend {
	font-weight: 600;
}

.keepiq-dashboard-settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 0.75rem;
}

.keepiq-dashboard-settings__actions {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
}

.keepiq-dashboard-settings__error {
	color: var(--color-error-text);
}

.keepiq-dashboard-settings__intro {
	color: var(--color-text-lighter);
}
</style>
