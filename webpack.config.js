// SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// ⚠️ THIS FILE IS AGPL-3.0-or-later. The rest of keepiq is EUPL-1.2, apart
// from `.editorconfig` — the only other file here that carries Nextcloud code.
//
// The config object below was inlined from `@nextcloud/webpack-vue-config`
// (AGPL-3.0-or-later, Nextcloud GmbH) when that package was dropped from the
// dependency tree — see WHY THE BASE CONFIG IS INLINED below. Roughly half of
// the inlined literal is byte-identical to upstream, including two of its own
// comments, so the file carries Nextcloud's licence rather than the repo-wide
// EUPL-1.2 blanket in REUSE.toml. `precedence = "closest"` there means this
// header wins; the same arrangement `.editorconfig` already uses.
//
// Keep copyright markers out of this file's prose — the circled-c glyph, the
// capitalised English word, and the parenthesised letter all count. REUSE
// matches them anywhere in a file, not only inside an SPDX tag, so one in a
// sentence gets reported as a third copyright holder with the rest of the
// sentence as its holder name. (This paragraph deliberately names none of the
// three literally, for that exact reason.)
//
// Consequence for anyone editing this file: treat all of it as
// AGPL-3.0-or-later unless you have checked a specific line's provenance.
// Conduction owns the other half of the literal and everything below it and
// may relicense that at will — but nothing marks which lines are which, so
// code copied OUT of here cannot simply be pasted into an EUPL-1.2 file.

const fs = require('fs')
const MinimizerPlugin = require('minimizer-webpack-plugin')
const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const webpack = require('webpack')

const appId = 'keepiq'

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'

// WHY THE BASE CONFIG IS INLINED
//
// This config used to start from `@nextcloud/webpack-vue-config` and mutate the
// object it exported. That package is gone: it peer-pins
// `node-polyfill-webpack-plugin@4.0.0`, whose `crypto-browserify` →
// `browserify-sign` / `create-ecdh` chain terminates at `elliptic`, and elliptic
// has NO patched release (GHSA-848j-6mx2-7j84, `first_patched_version: null`).
// That advisory is unresolvable by any version bump — the only way out is to
// stop installing the chain. Nothing in this app imports node's `crypto`, so the
// polyfill was never in a bundle to begin with; it was pure install-tree weight,
// and `@nextcloud/webpack-vue-config` was the subtree's only dependent.
//
// Only the fields below were ever actually inherited, and of those exactly one
// is deliberately NOT kept: `output.publicPath` (see the comment on it).
// `module.rules` and `resolve` were already REPLACED wholesale further down —
// this app runs no babel-loader and no ts-loader (there is no .babelrc and no
// tsconfig.json), so the package's rule set was dead code. Its `devServer`
// block went with it: there is no `webpack serve` script here.
//
// The base also carried a DefinePlugin defining `appName`/`appVersion` from
// `npm_package_*`. They are not repeated here — the two DefinePlugin calls
// further down already define those same two constants with the same values.
const webpackConfig = {
	target: 'web',
	mode: buildMode,
	devtool: isDev ? 'cheap-source-map' : 'source-map',

	stats: {
		colors: true,
		modules: false,
	},

	output: {
		path: path.resolve('./js'),

		// Lazy-loaded chunks (the argon2-browser WASM loader for link-share
		// encryption) must resolve relative to the script that loaded them.
		// Nextcloud serves the entry bundle from `/custom_apps/<app>/js/`, but the
		// base config's `/apps/<app>/js/` publicPath — the one field of it that is
		// deliberately NOT kept — points lazy chunks at a path that 401s.
		// `publicPath: 'auto'` makes webpack derive the chunk base from the
		// executing script's own URL, so chunks load from the same
		// `/custom_apps/keepiq/js/` directory as the main bundle.
		publicPath: 'auto',

		// Output file names
		filename: `${appId}-[name].js?v=[contenthash]`,
		chunkFilename: `${appId}-[name].js?v=[contenthash]`,

		// Clean output before each build
		clean: true,

		// Make sure sourcemaps have a proper path and do not leak local paths
		// https://github.com/webpack/webpack/issues/3603
		devtoolNamespace: appId,
		devtoolModuleFilenameTemplate(info) {
			const rootDir = process.cwd()
			const rel = path.relative(rootDir, info.absoluteResourcePath)
			return `webpack:///${appId}/${rel}`
		},
	},

	optimization: {
		chunkIds: 'named',
		splitChunks: {
			automaticNameDelimiter: '-',
		},
		minimize: !isDev,
		minimizer: [
			new MinimizerPlugin({
				minimizerOptions: {
					format: {
						comments: false,
					},
				},
				extractComments: true,
			}),
		],
	},

	plugins: [
		// Kept where the base config had it. The filter further down means to
		// strip this instance and prepend a fresh one, but vue-loader@17 exports its
		// plugin as a class named `Plugin`, so `constructor.name !== 'VueLoaderPlugin'`
		// never matches and both instances survive. That is pre-existing behaviour
		// from when this list came out of @nextcloud/webpack-vue-config, and the
		// build has always run with the pair — left as-is rather than changed here.
		new VueLoaderPlugin(),

		// Replaces NodePolyfillPlugin's ProvidePlugin injection. The plugin was
		// constructed with `additionalAliases: ['process']`, and its own alias
		// filter then admitted exactly these two globals — its third, `console`,
		// is not in the plugin's `defaultPolyfills` set and was filtered out.
		new webpack.ProvidePlugin({
			Buffer: [require.resolve('buffer/'), 'Buffer'],
			process: require.resolve('process/browser'),
		}),

		// Vue compile-time flags. Documented as optional, but omitting them can
		// break the build with `ReferenceError: __VUE_PROD_DEVTOOLS__ is not
		// defined`, and they are what lets the framework tree-shake.
		// See: https://vuejs.org/api/compile-time-flags.html#compile-time-flags
		new webpack.DefinePlugin({
			__VUE_OPTIONS_API__: JSON.parse(
				process.env.__VUE_OPTIONS_API__ ?? 'true',
			),
			__VUE_PROD_DEVTOOLS__: JSON.parse(
				process.env.__VUE_PROD_DEVTOOLS__ ?? 'false',
			),
			__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.parse(
				process.env.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ ?? 'false',
			),
		}),

		// @nextcloud/moment since v1.3.0 uses `moment/min/moment-with-locales.js`,
		// which only works in Node. Its unused `localLocale` requires locales by
		// the invalid relative path `./locale`; webpack still tries to resolve
		// that through require.context and fails.
		new webpack.IgnorePlugin({
			resourceRegExp: /^\.[/\\]locale$/,
			contextRegExp: /moment[/\\]min$/,
		}),
	],
}

webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	// Offline app-shell service worker (offline-readonly-cache §3). Emitted
	// as a standalone script (no runtime chunk imports) so it can be
	// registered at a stable URL and precache the shell.
	serviceWorker: {
		import: path.join(__dirname, 'src', 'offline', 'service-worker.js'),
		filename: appId + '-service-worker.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
// `USE_LOCAL_LIB=false` forces the published package even when a sibling checkout
// is present — without it a local build can never reproduce what CI and production
// build (they have no sibling, so they always resolve the npm dist).
//
// ⚠️ USE_LOCAL_LIB is opt-IN (ADR-090). Building against a developer's working
// checkout is the wrong default for a build that can ship.
//
// The sibling is validated against THIS app's own declared range. The previous
// test was `major < 2`, on the premise that a bad sibling would be 1.x. The
// sibling today is 2.0.5 while this app declares 2.2.0-vue3.16 — both major 2 —
// so the test waved through a version the app never asked for.
//
// The failure that skew produces is not obvious from the version alone. Building
// against the sibling also pulls packages out of the SIBLING's node_modules, and
// a stale vue-demi shim there (its postinstall picks v2/v2.7/v3 and does not
// re-run on `npm install`) yields errors of the form
//   export 'default' (imported as 'Vue') was not found in 'vue'
// — a Vue-2-shaped failure from a library that is itself Vue 3.
//
// Fail CLOSED: if the check cannot run, the sibling is refused. A guard that
// degrades to "allow" is not a guard.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			`[keepiq] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		//
		// `vue` and `pinia` still publish `main`/`module`, so a directory
		// alias resolves for those two.
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// @nextcloud/vue@9, @nextcloud/dialogs@7 and vue-router@5 are ESM-only:
		// their package.json has NO `main` and NO `module`, only an `exports`
		// map. A Vue-2-era alias to the package DIRECTORY bypasses `exports`
		// entirely (webpack applies an exports map to a PACKAGE REQUEST, never
		// to an already-absolutised path) and then looks for a main/index.js
		// that does not exist — every import fails with
		// "Can't resolve '@nextcloud/vue'". Alias to the absolute FILE. The
		// exact-match (`$`) form keeps deep imports going through the map.
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/vue@9 hard-depends on vue-router ^5.1.0 while this app is
		// on vue-router 4, so npm installs a SECOND nested copy under
		// node_modules/@nextcloud/vue/node_modules/vue-router. Two router
		// instances mean two different injection keys: NcAppNavigationItem's
		// RouterLink would look up a router this app never provided and
		// navigation dies with no console error. Force every `vue-router`
		// specifier onto this app's single copy.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by the aliased @conduction/nextcloud-vue components
			// (CnCard, CnDataTable, CnAppRoot internals, …) when building
			// against the monorepo-dev source tree.
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
		{
			// Image assets referenced by library components (e.g. Leaflet
			// marker icons pulled in transitively).
			test: /\.(png|jpe?g|gif|svg)$/,
			type: 'asset/resource',
			generator: {
				filename: 'img/[name][ext]',
			},
		},
	],
}

// Replace VueLoaderPlugin (don't push — duplicates break templates when using local package)
const otherPlugins = (webpackConfig.plugins || []).filter(
	(p) => p.constructor.name !== 'VueLoaderPlugin',
)
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	...otherPlugins,
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules, preventing
// a nested copy from leaking in. Register the exact-match style.css alias
// BEFORE the package alias: enhanced-resolve applies the first matching entry.
// dialogs v7 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
// v7 is exports-map-only (no main, no module) — the bare DIRECTORY alias that
// worked against v6 resolves to nothing. Alias the absolute file, exact-match.
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// Node-core fallbacks. Webpack 5 no longer auto-polyfills node builtins, and
// NodePolyfillPlugin — which used to inject a full map of them on demand — went
// out with @nextcloud/webpack-vue-config (see WHY THE BASE CONFIG IS INLINED
// at the top of this file). These are the only
// builtins the graph actually reaches, measured against a real build: `stream`
// carries the bulk (readable-stream, ~190 modules), `path` comes from the
// dialogs FilePicker chunk, and the rest are pulled in behind them.
//
// Anything NOT listed here now fails the build with webpack's own
// "Can't resolve 'x' … add a fallback" error rather than being polyfilled
// silently. That is the intended trade: a loud, one-line fix instead of an
// unbounded polyfill surface. `fs` stays mapped to `false` because the plugin
// mapped it that way — a dependency with a dead `require('fs')` branch got an
// empty module, and dropping that would turn the dead branch into a hard error.
webpackConfig.resolve.fallback = {
	buffer: require.resolve('buffer/'),
	events: require.resolve('events/'),
	fs: false,
	path: require.resolve('path-browserify'),
	process: require.resolve('process/browser'),
	stream: require.resolve('stream-browserify'),
	string_decoder: require.resolve('string_decoder/'),
}

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across the main / settings entry-points so each bundle no longer
// inlines its own ~3 MB framework copy. Stable filenames mean each
// entry's `Util::addScript` PHP call can reference the chunk directly
// without a manifest. The shared chunks are loaded once per page and
// cached across navigations between keepiq's own pages.
//
// CRITICAL: templates/index.php + templates/settings/admin.php MUST
// addScript these shared chunks BEFORE the entry script — without
// them the webpack runtime sits forever in `chunkOnLoad` and the app
// renders blank with no console error (same gotcha that bit
// docudesk#242).
webpackConfig.optimization = {
	...webpackConfig.optimization,
	splitChunks: {
		...webpackConfig.optimization.splitChunks,
		// The service worker must stay a self-contained script — exclude it
		// from shared-chunk extraction so it never references a chunk it
		// cannot import at the SW scope (offline-readonly-cache §3).
		chunks: (chunk) => chunk.name !== 'serviceWorker',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

// Nextcloud apps with @conduction/nextcloud-vue and zxcvbn exceed the default
// 244 KiB asset size hint. Raise the limit to suppress warnings.
webpackConfig.performance = {
	maxAssetSize: 5 * 1024 * 1024,
	maxEntrypointSize: 5 * 1024 * 1024,
}

// The crypto module lazy-loads the `argon2-browser` WASM library via a dynamic
// import (src/crypto/argon2.js). argon2-browser's own loader contains a
// `require('../dist/argon2.wasm')` that Webpack 5 cannot statically resolve
// (it parses the binary as a module). Emit the .wasm as a static resource so
// the bundle builds; argon2-browser falls back to fetching it from the
// configured `argon2WasmPath` at runtime.
webpackConfig.module.rules.push({
	test: /argon2\.wasm$/,
	type: 'asset/resource',
	generator: { filename: '[name][ext]' },
})

module.exports = webpackConfig
