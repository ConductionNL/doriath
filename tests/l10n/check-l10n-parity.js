#!/usr/bin/env node
/**
 * l10n translation-PARITY gate.
 *
 * Guards that every REQUIRED locale carries a real translation for every
 * English source key. Without this, a new English string ships and the other
 * languages silently fall back to English with a green pipeline — the app
 * slowly stops "fully supporting" those languages.
 *
 * The required set is the official language of every European country plus
 * Russian and Turkish (ISO 639-1). Override with L10N_REQUIRED_LOCALES.
 *
 * For BOTH translation sets that exist in the app:
 *   • frontend  l10n/en.js   (OC.L10N.register)  -> l10n/<locale>.js
 *   • backend   l10n/en.json ({ translations })  -> l10n/<locale>.json
 * it asserts, for every required locale:
 *   1. the locale file exists,
 *   2. it contains every key present in the English source (no MISSING keys),
 *   3. no value is empty / whitespace-only (no UNTRANSLATED placeholders);
 *      for plural arrays, no element may be empty.
 *
 * Values identical to English are allowed (cognates / proper nouns / acronyms
 * are legitimately the same) and only counted.
 *
 * Sparse override locales (en, en_US and any other regional en_*) are skipped.
 *
 * Dependency-free pure Node so CI can run it in a bare node container:
 *   node tests/l10n/check-l10n-parity.js
 *
 * Exit codes:
 *   0  every required locale is at full parity for every existing source set
 *   1  one or more locales are missing keys, missing files, or empty values
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = process.cwd()
const L10N_DIR = path.join(ROOT, 'l10n')

// Official language of every European country (ISO 639-1) + Russian + Turkish.
// nl/de/fr/es/it lead (the original supported set); then the EU-24 remainder,
// wider-Europe national languages, and micro-state / co-official nationals.
const EUROPEAN = [
	'nl', 'de', 'fr', 'es', 'it',
	'bg', 'hr', 'cs', 'da', 'et', 'fi', 'el', 'hu', 'ga', 'lv', 'lt', 'mt',
	'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
	'sq', 'is', 'nb', 'sr', 'bs', 'mk', 'uk', 'be', 'ru', 'tr',
	'ca', 'lb', 'rm',
].join(',')

function readJson (p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

const REQUIRED = (process.env.L10N_REQUIRED_LOCALES || EUROPEAN)
	.split(',').map((s) => s.trim()).filter(Boolean)

// ---------------------------------------------------------------------------
// Two-tier enforcement.
//
// REQUIRED (above) is the set this app intends to fully support — all 36.
// ENFORCED is the subset that must be at FULL parity right now; anything in
// REQUIRED but not in ENFORCED is still MEASURED, and is held to a
// no-regression ratchet instead.
//
// Why the split: when this gate was first wired up, the 36 required locales
// were 20,433 translations short (see keepiq#180). A gate that is red on
// every PR from day one gets switched off, and a gate that is not wired up at
// all — which is what this file was for its entire life — measures nothing.
// The ratchet is the honest middle: the debt is printed in full on every run
// and can never grow, while ENFORCED widens as locales are completed.
//
// This is deliberately NOT a suppression. Compared to the previous state
// (the script had zero callers) every one of these checks is new:
//   • ENFORCED locales must be at exact full parity — hard fail.
//   • EVERY required locale has a hard upper bound on its missing count —
//     so adding an English source string without translating it turns this
//     gate red, which is precisely the drift this file exists to catch.
// Nothing that was being measured before is measured less.
// ENFORCED starts EMPTY, and that is a measured fact, not a shrug: no locale
// in REQUIRED is at full parity today (the closest, nl, is 413 keys short), so
// there is no locale that could be put here without turning the gate red on
// every PR. The first draft of this defaulted to 'en' — which LOOKS like
// enforcement but is not, because `en` is the source language and is not a
// member of REQUIRED at all. That default passed while enforcing precisely
// nothing. Guard below makes that class of mistake impossible to repeat.
const ENFORCED = (process.env.L10N_PARITY_ENFORCED || '')
	.split(',').map((s) => s.trim()).filter(Boolean)

// Ratchet: locale -> highest missing count tolerated. A locale absent from the
// file is tolerated at 0, so a NEW locale must land complete.
const RATCHET_FILE = path.join(__dirname, 'parity-ratchet.json')
const RATCHET = fs.existsSync(RATCHET_FILE) ? readJson(RATCHET_FILE) : {}
const TIGHTEN = process.argv.includes('--write')

// An ENFORCED locale that is not also REQUIRED is never compared against
// anything, so it would silently enforce nothing. Refuse to run rather than
// report a green that means less than it looks like it means.
const unmeasured = ENFORCED.filter((l) => !REQUIRED.includes(l))
if (unmeasured.length > 0) {
	console.error(`l10n-parity: CONFIGURATION ERROR — L10N_PARITY_ENFORCED names `
		+ `locale(s) that are not in the required set and are therefore never `
		+ `checked: ${unmeasured.join(', ')}. Enforcing an unmeasured locale is a `
		+ `green that proves nothing. Add them to L10N_REQUIRED_LOCALES or remove them.`)
	process.exit(2)
}

if (!fs.existsSync(L10N_DIR)) {
	console.error(`l10n-parity: no l10n/ directory at ${L10N_DIR}`)
	process.exit(2)
}

/** Load an OC.L10N.register(...) .js file into its translations object. */
function loadJs (file) {
	const code = fs.readFileSync(file, 'utf8')
	let captured = null
	const sandbox = { OC: { L10N: { register: (id, obj) => { captured = obj } } } }
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: file, timeout: 5000 })
	return captured || {}
}

/** Load an l10n .json file into its translations object. */
function loadJsonSet (file) {
	return readJson(file).translations || {}
}

/** True when a translation value is empty (string) or has an empty plural. */
function isEmpty (v) {
	if ((v === null || v === undefined)) {
		return true
	}
	if (Array.isArray(v)) {
		return v.length === 0 || v.some((e) => typeof e !== 'string' || e.trim() === '')
	}
	return typeof v !== 'string' || v.trim() === ''
}

const sets = [
	{
		kind: 'frontend (.js)',
		enFile: path.join(L10N_DIR, 'en.js'),
		file: (loc) => path.join(L10N_DIR, `${loc}.js`),
		load: loadJs,
	},
	{
		kind: 'backend (.json)',
		enFile: path.join(L10N_DIR, 'en.json'),
		file: (loc) => path.join(L10N_DIR, `${loc}.json`),
		load: loadJsonSet,
	},
]

const failures = []
let checkedSets = 0
// Work actually performed. These exist so a run that compared NOTHING cannot
// be mistaken for a run that compared everything and found it clean — see the
// "did this gate actually execute" block below.
let comparedLocaleFiles = 0
let comparedKeys = 0

for (const set of sets) {
	if (!fs.existsSync(set.enFile)) {
		continue // this app does not ship this translation set
	}
	checkedSets++
	const enKeys = Object.keys(set.load(set.enFile))
	for (const loc of REQUIRED) {
		const locFile = set.file(loc)
		if (!fs.existsSync(locFile)) {
			failures.push({ set: set.kind, loc, kind: 'MISSING FILE', detail: path.relative(ROOT, locFile) })
			continue
		}
		let locObj
		try {
			locObj = set.load(locFile)
		} catch (e) {
			failures.push({ set: set.kind, loc, kind: 'UNPARSEABLE', detail: e.message })
			continue
		}
		comparedLocaleFiles++
		comparedKeys += enKeys.length
		const missing = enKeys.filter((k) => !Object.hasOwn(locObj, k))
		const empty = enKeys.filter((k) => Object.hasOwn(locObj, k) && isEmpty(locObj[k]))
		if (missing.length || empty.length) {
			failures.push({ set: set.kind, loc, kind: 'INCOMPLETE', missing, empty, total: enKeys.length })
		}
	}
}

const label = appId ? `[${appId}]` : ''
console.log(`l10n-parity ${label}: ${REQUIRED.length} required locales; checked ${checkedSets} translation set(s)`)

// ---------------------------------------------------------------------------
// DID THIS GATE ACTUALLY EXECUTE?
//
// Every line below exists because a check that fails or passes for an
// environmental reason is not measuring the code. This file was itself the
// falsely-GREEN case (keepiq#180: it had zero callers for its entire life),
// and the same week gate-30 fleet-wide reported PASS having matched nothing
// and written a 0-byte log (.github#213), while gates 22 and 53 reported
// failures that were an unresolvable `ajv`, not findings.
//
// So: a run that compared NOTHING must never look like a run that compared
// everything and found it clean. Each of these exits non-zero and says which
// input was empty, rather than printing OK.
// ---------------------------------------------------------------------------
console.log(`l10n-parity: WORK DONE — ${comparedLocaleFiles} locale file(s) compared, `
	+ `${comparedKeys} key comparison(s). A zero here means this gate measured nothing.`)

if (REQUIRED.length === 0) {
	console.error('l10n-parity: REFUSING TO PASS — the required-locale set is EMPTY '
		+ '(L10N_REQUIRED_LOCALES resolved to nothing), so every locale would trivially '
		+ 'be "at parity". An empty scope is a broken configuration, not a clean result.')
	process.exit(2)
}

if (checkedSets === 0) {
	console.error('l10n-parity: REFUSING TO PASS — no en.js / en.json source set found under '
		+ `${path.relative(ROOT, L10N_DIR)}, so there was nothing to compare against. `
		+ 'Previously this exited 0 and reported "nothing to check", which is exactly the '
		+ 'shape of a gate that is green because it never ran.')
	process.exit(2)
}

if (comparedKeys === 0) {
	console.error('l10n-parity: REFUSING TO PASS — a source set exists but ZERO key '
		+ 'comparisons were performed. Either the English source is empty or every locale '
		+ 'file is missing/unparseable. A gate cannot report success on an empty comparison.')
	process.exit(2)
}

if (failures.length === 0) {
	console.log('l10n-parity: OK — every required locale is at full parity (no missing keys, no empty values)')
	process.exit(0)
}

/** Ratchet key — one bound per translation set + locale. */
function ratchetKey (f) {
	return `${f.set} ${f.loc}`
}

/** Total shortfall for a failure row. MISSING FILE / UNPARSEABLE are absolute. */
function shortfall (f) {
	if (f.kind !== 'INCOMPLETE') {
		return Infinity
	}
	return (f.missing.length + f.empty.length)
}

/** Render one failure row. */
function describe (f) {
	if (f.kind === 'MISSING FILE') {
		return `${f.set} ${f.loc}: locale file missing (${f.detail})`
	}
	if (f.kind === 'UNPARSEABLE') {
		return `${f.set} ${f.loc}: cannot parse (${f.detail})`
	}
	return `${f.set} ${f.loc}: ${f.missing.length} missing key(s), `
		+ `${f.empty.length} empty value(s) of ${f.total}`
}

// Split the failures three ways.
const hardEnforced = []  // in ENFORCED — must be at full parity
const regressions = []   // worse than the recorded ratchet bound
const withinRatchet = [] // known debt, not growing

for (const f of failures) {
	if (ENFORCED.includes(f.loc)) {
		hardEnforced.push(f)
		continue
	}
	const bound = Object.hasOwn(RATCHET, ratchetKey(f)) ? RATCHET[ratchetKey(f)] : 0
	if (shortfall(f) > bound) {
		regressions.push({ f, bound })
	} else {
		withinRatchet.push({ f, bound })
	}
}

const totalDebt = failures.reduce((n, f) => (n + (f.kind === 'INCOMPLETE' ? shortfall(f) : 0)), 0)

// `--write` re-records the ratchet at the CURRENT shortfall. It only ever
// TIGHTENS: a locale that got worse is still a failure and is not recorded,
// otherwise "--write" would be a one-command way to erase a regression.
if (TIGHTEN) {
	const next = {}
	for (const f of failures) {
		const k = ratchetKey(f)
		const cur = shortfall(f)
		const bound = Object.hasOwn(RATCHET, k) ? RATCHET[k] : 0
		next[k] = Math.min(cur, bound === 0 && !Object.hasOwn(RATCHET, k) ? cur : bound)
	}
	const ordered = {}
	for (const k of Object.keys(next).sort()) {
		ordered[k] = next[k]
	}
	fs.writeFileSync(RATCHET_FILE, JSON.stringify(ordered, null, 2) + '\n')
	console.log(`l10n-parity: WROTE ratchet for ${Object.keys(ordered).length} locale(s) `
		+ `to ${path.relative(ROOT, RATCHET_FILE)} (total outstanding: ${totalDebt})`)
	process.exit(0)
}

// Always print what IS and IS NOT covered, plus the full outstanding debt. A
// gate that quietly caps its own coverage reads as "covered everything", so
// the covered set and the deferred count go to stdout on every single run.
console.log(`l10n-parity: COVERAGE — required locales: ${REQUIRED.length}; `
	+ `held to FULL PARITY (hard fail): ${ENFORCED.length > 0 ? ENFORCED.join(',') : 'NONE YET'}; `
	+ `held to NO-REGRESSION ONLY: ${REQUIRED.length - ENFORCED.length}.`)
if (ENFORCED.length === 0) {
	console.log('l10n-parity: NOTE — no locale is at full parity yet, so no locale can be '
		+ 'fully enforced without failing every build. This gate currently proves only that '
		+ 'the debt below does not GROW. Widen with L10N_PARITY_ENFORCED as locales complete.')
}
console.log(`l10n-parity: OUTSTANDING TRANSLATION DEBT: ${totalDebt} missing/empty `
	+ `across ${withinRatchet.length + regressions.length + hardEnforced.length} locale set(s). `
	+ 'This is tracked, capped, and must only go down (keepiq#180).')
for (const { f, bound } of withinRatchet) {
	console.log(`  · ${describe(f)}  [ratchet ${bound}]`)
}

if (hardEnforced.length === 0 && regressions.length === 0) {
	console.log(ENFORCED.length > 0
		? 'l10n-parity: OK — every enforced locale is at full parity and no locale regressed'
		: 'l10n-parity: OK — no locale regressed. NOT a statement that translations are complete.')
	process.exit(0)
}

console.error('\nl10n-parity: FAIL — required language support regressed or is incomplete:')
for (const f of hardEnforced) {
	console.error(`  • [ENFORCED] ${describe(f)}`)
	for (const k of (f.missing || []).slice(0, 8)) {
		console.error(`      missing: ${JSON.stringify(k)}`)
	}
	if ((f.missing || []).length > 8) {
		console.error(`      … +${f.missing.length - 8} more missing`)
	}
	for (const k of (f.empty || []).slice(0, 4)) {
		console.error(`      empty:   ${JSON.stringify(k)}`)
	}
}
for (const { f, bound } of regressions) {
	console.error(`  • [REGRESSION] ${describe(f)} — ratchet allows at most ${bound}`)
	for (const k of (f.missing || []).slice(0, 8)) {
		console.error(`      missing: ${JSON.stringify(k)}`)
	}
	if ((f.missing || []).length > 8) {
		console.error(`      … +${f.missing.length - 8} more missing`)
	}
}
console.error('\nA locale may never lose ground. Translate the keys listed above — or, if you '
	+ 'just added an English source string, add it to every locale file. '
	+ 'Run `node tests/l10n/check-l10n-parity.js --write` ONLY to record genuine progress.')
process.exit(1)
