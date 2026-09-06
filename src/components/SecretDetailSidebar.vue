<template>
	<!-- The secret detail as a right-hand sidebar over the vault list
	     (restyle Stage 8, Proton Pass / Passwork style). Mounted through
	     CnAppRoot's #sidebar slot — the NcContent level is the only place
	     NcAppSidebar slides in correctly (ADR-017). The open/closed state
	     lives in the route (src/utils/detailRoute.js); closing emits up so
	     the shell drops the `:id` segment. The `.secret-detail__*` classes
	     are a documented e2e contract shared with the old detail page.

	     Deliberately NO tabs (Stage-8 polish, per review): everything the
	     old detail page showed scrolls in ONE pane, Proton-style — the
	     action row sits with the title in the header, the input data
	     renders as grouped rows (icon, muted label, prominent value), and
	     editing stays a dialog. -->
	<!-- `--own-close`: while the action row is up, the sidebar's native X
	     is hidden and closing lives in the "…" menu (per review) — the
	     row itself takes the corner. Loading and error states keep the
	     native X (the row is not rendered there), and Esc always closes. -->
	<NcAppSidebar
		class="secret-detail"
		:class="{ 'secret-detail--own-close': secret && !error }"
		data-testid="secret-detail-sidebar"
		:name="sidebarName"
		:subname="typeLabel"
		:loading="loading"
		:empty="Boolean(error)"
		@close="$emit('close')">
		<!-- Action row lives in the HEADER's description slot so it sits
		     with the title (Proton pattern): Edit keeps its label and opens
		     the edit DIALOG; Share is icon-only but keeps its accessible
		     name via ariaLabel + title (WCAG 4.1.2); Move + Delete fold
		     into a trailing "…" menu with a distinct accessible name so it
		     can never collide with the list page's "Actions" overflow menu
		     in role-based queries. -->
		<template #description>
			<!-- Proton's vault tag (2026-09-03, per Remko): the one place
			     you look at a single secret names the vault it lives in,
			     with the vault's own Stage 9 icon and color. -->
			<VaultIndicator
				v-if="secret && !error && vault"
				class="secret-detail__vault"
				variant="tag"
				:vault="vault"
				data-testid="secret-detail-vault" />
			<div
				v-if="secret && !error && offlineReadOnly"
				class="secret-detail__offline-note"
				data-testid="secret-detail-offline-note">
				{{
					t(
						'keepiq',
						'Read-only while offline — reconnect to edit, move, share, or delete.',
					)
				}}
			</div>
			<!-- Rendered offline too (write actions hidden then): with the
			     native X hidden, the "…" menu is the pointer path to Close. -->
			<div v-if="secret && !error" class="secret-detail__actions">
				<NcButton
					v-if="!offlineReadOnly"
					variant="primary"
					data-testid="secret-detail-edit"
					@click="openEdit">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('keepiq', 'Edit') }}
				</NcButton>
				<NcButton
					v-if="!offlineReadOnly"
					variant="secondary"
					:ariaLabel="t('keepiq', 'Share')"
					:title="t('keepiq', 'Share')"
					data-testid="secret-detail-share"
					@click="openShare">
					<template #icon>
						<ShareVariant :size="20" />
					</template>
				</NcButton>
				<NcActions
					:ariaLabel="t('keepiq', 'Secret actions')"
					:forceMenu="true"
					data-testid="secret-detail-more">
					<template v-if="!offlineReadOnly">
						<NcActionButton
							:closeAfterClick="true"
							data-testid="secret-detail-move"
							@click="openMove">
							<template #icon>
								<FolderMove :size="20" />
							</template>
							{{ t('keepiq', 'Move') }}
						</NcActionButton>
						<NcActionButton
							:closeAfterClick="true"
							data-testid="secret-detail-delete"
							@click="openDelete">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('keepiq', 'Delete secret') }}
						</NcActionButton>
						<NcActionSeparator />
					</template>
					<NcActionButton
						:closeAfterClick="true"
						data-testid="secret-detail-close"
						@click="$emit('close')">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('keepiq', 'Close') }}
					</NcActionButton>
				</NcActions>
			</div>
		</template>

		<NcEmptyContent
			v-if="error"
			:name="t('keepiq', 'Cannot open secret')"
			:description="error">
			<template #icon>
				<Lock />
			</template>
		</NcEmptyContent>

		<div v-if="!error && secret" class="secret-detail__card">
			<!-- Write-grade badge (folder-permission-grades §4.3): the
			     member knows an edit propagates to the whole team. -->
			<p
				v-if="teamWritable"
				class="secret-detail__team-badge"
				data-testid="team-writable-badge">
				{{ t('keepiq', 'Editable — changes sync to the whole team') }}
			</p>

			<!--
			  Placed above the value, not below it: the point is that the
			  user reads this BEFORE they copy the password and carry on as
			  if it were still good. Not dismissible — it clears when the
			  value is actually replaced, which is what clears the flag
			  server-side.
			-->
			<NcNoteCard
				v-if="secret.possiblyCompromisedAt"
				type="error"
				data-testid="secret-detail-possibly-compromised">
				<p>
					{{
						t(
							'keepiq',
							'This value was in the vault when the encryption key was declared compromised, so it must be assumed exposed.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'keepiq',
							'Change it at its source, then save the new value here. Saving a new value clears this warning.',
						)
					}}
				</p>
			</NcNoteCard>

			<!-- Input data (Proton-style grouped rows): the credential
			     fields share one box, each row = icon + muted label +
			     prominent value with its copy/reveal affordance. A parsed
			     identity renders its own SECTIONS below instead, so the box
			     is suppressed when it would be empty. -->
			<div
				v-if="!isIdentity || !identityPayload || secret.login"
				class="secret-detail__box">
				<div v-if="secret.login" class="secret-detail__row">
					<span class="secret-detail__row-icon">
						<Account :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Login')
						}}</span>
						<div class="secret-detail__row-value">
							{{ secret.login }}
						</div>
					</div>
					<span class="secret-detail__row-trailing">
						<CopyButton
							:value="secret.login"
							:label="t('keepiq', 'Copy login')" />
					</span>
				</div>

				<!-- The raw key renders ONLY for scalar types: for card /
				     identity / passkey / totp the decrypted key IS the
				     structured payload their own rows below present — Proton
				     shows no raw blob either. PasswordField labels itself, so
				     no row label (it doubled the word before). -->
				<div v-if="showKeyRow" class="secret-detail__row">
					<span class="secret-detail__row-icon">
						<NoteTextOutline v-if="isNote" :size="20" />
						<KeyVariant v-else :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<div class="secret-detail__row-value">
							<PasswordField
								:key="secretLoadToken"
								:label="keyLabel"
								:resolve="resolveKey" />
						</div>
					</div>
				</div>

				<div
					v-if="isTotp"
					class="secret-detail__row secret-detail__row--block">
					<span class="secret-detail__row-icon">
						<ClockOutline :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'One-time code')
						}}</span>
						<div class="secret-detail__row-value">
							<TotpDisplay
								:seed="secret.key || ''"
								data-testid="secret-detail-totp" />
						</div>
					</div>
				</div>

				<div
					v-if="isPasskey"
					class="secret-detail__row secret-detail__row--block">
					<span class="secret-detail__row-icon">
						<Fingerprint :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Passkey')
						}}</span>
						<div class="secret-detail__row-value">
							<PasskeyDisplay
								:credentialJson="secret.key || ''"
								data-testid="secret-detail-passkey" />
						</div>
					</div>
				</div>

				<!-- Payment card as first-class rows (card-identity-items §4,
				     Proton layout): each field its own row — icon, muted
				     label, value; number/CVV/PIN masked with an eye toggle
				     and copy at the row end. Absent fields render no row. -->
				<template v-if="isCard && cardPayload">
					<div
						v-if="cardPayload.cardholder"
						class="secret-detail__row"
						data-testid="secret-detail-card">
						<span class="secret-detail__row-icon">
							<Account :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Cardholder')
							}}</span>
							<div class="secret-detail__row-value">
								{{ cardPayload.cardholder }}
							</div>
						</div>
					</div>

					<div v-if="cardPayload.number" class="secret-detail__row">
						<span class="secret-detail__row-icon">
							<CreditCardOutline :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Number')
							}}</span>
							<div
								class="secret-detail__row-value secret-detail__row-value--mono"
								data-testid="card-number-value">
								{{
									cardRevealed.number
										? cardNumberFormatted
										: cardNumberMasked
								}}
							</div>
						</div>
						<span class="secret-detail__row-trailing">
							<NcButton
								variant="tertiary"
								:ariaLabel="
									cardRevealed.number
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								:title="
									cardRevealed.number
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								data-testid="card-reveal-number"
								@click="toggleCardField('number')">
								<template #icon>
									<EyeOffOutline
										v-if="cardRevealed.number"
										:size="20" />
									<EyeOutline v-else :size="20" />
								</template>
							</NcButton>
							<CopyButton
								:value="cardPayload.number"
								:label="t('keepiq', 'Copy number')" />
						</span>
					</div>

					<div v-if="cardPayload.expiry" class="secret-detail__row">
						<span class="secret-detail__row-icon">
							<CalendarMonthOutline :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Expiry')
							}}</span>
							<div class="secret-detail__row-value">
								{{ cardPayload.expiry }}
							</div>
						</div>
					</div>

					<div v-if="cardPayload.cvv" class="secret-detail__row">
						<span class="secret-detail__row-icon">
							<ShieldOutline :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'CVV')
							}}</span>
							<div
								class="secret-detail__row-value secret-detail__row-value--mono"
								data-testid="card-cvv-value">
								{{ cardRevealed.cvv ? cardPayload.cvv : '•••' }}
							</div>
						</div>
						<span class="secret-detail__row-trailing">
							<NcButton
								variant="tertiary"
								:ariaLabel="
									cardRevealed.cvv
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								:title="
									cardRevealed.cvv
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								data-testid="card-reveal-cvv"
								@click="toggleCardField('cvv')">
								<template #icon>
									<EyeOffOutline
										v-if="cardRevealed.cvv"
										:size="20" />
									<EyeOutline v-else :size="20" />
								</template>
							</NcButton>
							<CopyButton
								:value="cardPayload.cvv"
								:label="t('keepiq', 'Copy CVV')" />
						</span>
					</div>

					<div v-if="cardPayload.pin" class="secret-detail__row">
						<span class="secret-detail__row-icon">
							<Dialpad :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'PIN')
							}}</span>
							<div
								class="secret-detail__row-value secret-detail__row-value--mono"
								data-testid="card-pin-value">
								{{ cardRevealed.pin ? cardPayload.pin : '••••' }}
							</div>
						</div>
						<span class="secret-detail__row-trailing">
							<NcButton
								variant="tertiary"
								:ariaLabel="
									cardRevealed.pin
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								:title="
									cardRevealed.pin
										? t('keepiq', 'Hide')
										: t('keepiq', 'Show')
								"
								data-testid="card-reveal-pin"
								@click="toggleCardField('pin')">
								<template #icon>
									<EyeOffOutline
										v-if="cardRevealed.pin"
										:size="20" />
									<EyeOutline v-else :size="20" />
								</template>
							</NcButton>
							<CopyButton
								:value="cardPayload.pin"
								:label="t('keepiq', 'Copy PIN')" />
						</span>
					</div>
				</template>
			</div>

			<!-- Identity as Proton-style SECTIONS (card-identity-items §4):
			     plain headings outside the boxes, icon-less label-over-value
			     rows, the BSN masked with a trailing eye + copy. Absent
			     fields render no row; empty sections render no box. -->
			<template v-if="isIdentity && identityPayload">
				<template
					v-if="
						identityFullName
						|| identityPayload.email
						|| identityPayload.phone
					">
					<h3 class="secret-detail__section-heading">
						{{ t('keepiq', 'Personal details') }}
					</h3>
					<div
						class="secret-detail__box"
						data-testid="secret-detail-identity">
						<div v-if="identityFullName" class="secret-detail__row">
							<div class="secret-detail__row-main">
								<span class="secret-detail__row-label">{{
									t('keepiq', 'Name')
								}}</span>
								<div
									class="secret-detail__row-value"
									data-testid="identity-name">
									{{ identityFullName }}
								</div>
							</div>
						</div>
						<div v-if="identityPayload.email" class="secret-detail__row">
							<div class="secret-detail__row-main">
								<span class="secret-detail__row-label">{{
									t('keepiq', 'Email')
								}}</span>
								<div class="secret-detail__row-value">
									{{ identityPayload.email }}
								</div>
							</div>
						</div>
						<div v-if="identityPayload.phone" class="secret-detail__row">
							<div class="secret-detail__row-main">
								<span class="secret-detail__row-label">{{
									t('keepiq', 'Phone')
								}}</span>
								<div class="secret-detail__row-value">
									{{ identityPayload.phone }}
								</div>
							</div>
						</div>
					</div>
				</template>

				<template v-if="identityPayload.address">
					<h3 class="secret-detail__section-heading">
						{{ t('keepiq', 'Address details') }}
					</h3>
					<div class="secret-detail__box">
						<div class="secret-detail__row">
							<div class="secret-detail__row-main">
								<span class="secret-detail__row-label">{{
									t('keepiq', 'Address')
								}}</span>
								<div class="secret-detail__row-value">
									{{ identityPayload.address }}
								</div>
							</div>
						</div>
					</div>
				</template>

				<template v-if="identityPayload.bsn">
					<h3 class="secret-detail__section-heading">
						{{ t('keepiq', 'Contact details') }}
					</h3>
					<div class="secret-detail__box">
						<div class="secret-detail__row">
							<div class="secret-detail__row-main">
								<span class="secret-detail__row-label">{{
									t('keepiq', 'BSN')
								}}</span>
								<div
									class="secret-detail__row-value secret-detail__row-value--mono"
									data-testid="identity-bsn-value">
									{{
										bsnRevealed
											? identityPayload.bsn
											: '•••••••••'
									}}
								</div>
							</div>
							<span class="secret-detail__row-trailing">
								<NcButton
									variant="tertiary"
									:ariaLabel="
										bsnRevealed
											? t('keepiq', 'Hide')
											: t('keepiq', 'Show')
									"
									:title="
										bsnRevealed
											? t('keepiq', 'Hide')
											: t('keepiq', 'Show')
									"
									data-testid="identity-reveal-bsn"
									@click="bsnRevealed = !bsnRevealed">
									<template #icon>
										<EyeOffOutline
											v-if="bsnRevealed"
											:size="20" />
										<EyeOutline v-else :size="20" />
									</template>
								</NcButton>
								<CopyButton
									:value="identityPayload.bsn"
									:label="t('keepiq', 'Copy BSN')" />
							</span>
						</div>
					</div>
				</template>
			</template>

			<div v-if="secret.url" class="secret-detail__box">
				<div class="secret-detail__row secret-detail__row--block">
					<span class="secret-detail__row-icon">
						<Web :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'URL')
						}}</span>
						<div class="secret-detail__row-value">
							<a
								:href="secret.url"
								target="_blank"
								rel="noopener noreferrer">
								{{ secret.url }}
							</a>
						</div>
					</div>
				</div>
			</div>

			<div v-if="hasAdditionalFields" class="secret-detail__box">
				<div class="secret-detail__row secret-detail__row--block">
					<span class="secret-detail__row-icon">
						<FormatListBulleted :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Additional fields')
						}}</span>
						<dl class="secret-detail__extra">
							<template
								v-for="(value, key) in secret.additionalFields"
								:key="key">
								<dt>
									{{ key }}
								</dt>
								<dd>
									{{ value }}
								</dd>
							</template>
						</dl>
					</div>
				</div>
			</div>

			<!-- Attachments stay VISIBLE (never behind a disclosure): every
			     researched manager shows attached files inline whenever they
			     exist — see SIDEBAR-UX-RESEARCH.md. Proton's position: after
			     the field boxes, before the metadata box. -->
			<div class="secret-detail__box">
				<div class="secret-detail__row">
					<span class="secret-detail__row-icon">
						<Paperclip :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Attachments')
						}}</span>
						<div class="secret-detail__row-value">
							<AttachmentPanel
								:secretId="secretId"
								:canManage="isOwner" />
						</div>
					</div>
				</div>
			</div>

			<!-- Metadata (Proton's "last modified / created" block). Only
			     rows the payload actually carries render. -->
			<div
				v-if="updatedDate || createdDate"
				class="secret-detail__box secret-detail__box--meta">
				<div v-if="updatedDate" class="secret-detail__row">
					<span class="secret-detail__row-icon">
						<Pencil :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Last modified')
						}}</span>
						<div class="secret-detail__row-value">
							<NcDateTime :timestamp="updatedDate" />
						</div>
					</div>
				</div>
				<div v-if="createdDate" class="secret-detail__row">
					<span class="secret-detail__row-icon">
						<Flash :size="20" />
					</span>
					<div class="secret-detail__row-main">
						<span class="secret-detail__row-label">{{
							t('keepiq', 'Created')
						}}</span>
						<div class="secret-detail__row-value">
							<NcDateTime :timestamp="createdDate" />
						</div>
					</div>
				</div>
			</div>

			<!-- Everything below the input data folds away (per review):
			     most users only need the fields above, Proton-style. Native
			     <details> keeps the disclosure keyboard- and SR-accessible
			     for free; content stays in the DOM (vitest .exists() and
			     deep links into panels keep working), it is just hidden
			     until opened. -->
			<details class="secret-detail__accordion">
				<summary
					class="secret-detail__accordion-summary"
					data-testid="secret-detail-more-info">
					<ChevronDown
						:size="20"
						class="secret-detail__accordion-chevron" />
					{{ t('keepiq', 'More information') }}
				</summary>

				<!-- Information only in here (per review + research):
				     sharing state, requests, activity — never files or
				     actions. -->
				<!--
			  Sharing — §12.6 integration. Renders the RecipientList,
			  ShareRequestForm (recipient role), and DelegationManager
			  stand-alone primitives the §12.x build cycle shipped.
			  Visibility derives from the current user's role:
			    - owner / delegate → ShareList + DelegationManager;
			    - recipient        → ShareRequestForm (request that the
			                          owner share with a third party).
			-->
				<section
					v-if="canSeeSharing"
					class="secret-detail__sharing"
					data-testid="secret-detail-sharing">
					<h3 class="secret-detail__sharing-heading">
						{{ t('keepiq', 'Sharing') }}
					</h3>

					<ShareList
						v-if="isOwner"
						:secretId="secretId"
						data-testid="secret-detail-share-list" />

					<DelegationManager
						v-if="isOwner"
						:secretId="secretId"
						:canReclaim="true"
						data-testid="secret-detail-delegation-manager"
						@reclaimed="onReclaimed" />

					<ShareRequestForm
						v-if="isRecipient && !isOwner"
						:secretId="secretId"
						data-testid="secret-detail-share-request" />

					<!--
				  Vault-admin takeover (user-sharing spec.md § Ownership
				  Delegation). Only offered to a vault admin looking at
				  somebody else's secret — the owner has ShareList and
				  DelegationManager above instead. The server re-checks group
				  membership AND that the admin already holds a share, so this
				  condition decides what to SHOW, never what is allowed.
				-->
					<AdminHandoverPanel
						v-if="!isOwner"
						:secretId="secretId"
						data-testid="secret-detail-admin-handover" />
				</section>

				<!--
			  Requests section — implement-secret-requests §8.4. Owners see
			  a paginated list of pending/fulfilled/locked SecretRequests +
			  a "Request fill-in" button that opens SecretRequestCreateDialog
			  for write-without-read filling.
			-->
				<section
					v-if="isOwner"
					class="secret-detail__requests"
					data-testid="secret-detail-requests">
					<h3 class="secret-detail__requests-heading">
						{{ t('keepiq', 'Requests') }}
					</h3>

					<div class="secret-detail__requests-actions">
						<!-- This action always targets THIS Secret, so the label
					     says which of the two things it does. Asking for a
					     credential you do not have yet starts from the vault,
					     not from inside a Secret. -->
						<NcButton
							variant="secondary"
							data-testid="secret-detail-request-create"
							@click="openRequestCreate">
							{{
								secretHasValue
									? t('keepiq', 'Ask for new values')
									: t('keepiq', 'Ask someone to fill this in')
							}}
						</NcButton>
					</div>

					<SecretRequestList
						:secretId="secretId"
						data-testid="secret-detail-request-list" />

					<SecretRequestCreateDialog
						v-if="requestDialogOpen"
						:open="requestDialogOpen"
						:secret="secret"
						:isReRequest="secretHasValue"
						data-testid="secret-detail-request-dialog"
						@update:open="requestDialogOpen = $event"
						@created="onRequestCreated" />
				</section>

				<!--
			  Activity — add-secret-audit-trail §5.2. The audit trail
			  for this secret, owner-scoped, newest first.
			-->
				<SecretActivityTab
					v-if="isOwner"
					:secretId="secretId"
					data-testid="secret-detail-activity" />
			</details>

			<details v-if="isOwner" class="secret-detail__accordion">
				<summary
					class="secret-detail__accordion-summary"
					data-testid="secret-detail-advanced">
					<ChevronDown
						:size="20"
						class="secret-detail__accordion-chevron" />
					{{ t('keepiq', 'Advanced') }}
				</summary>

				<div class="secret-detail__box">
					<div class="secret-detail__row secret-detail__row--block">
						<span class="secret-detail__row-icon">
							<History :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Version history')
							}}</span>
							<div class="secret-detail__row-value">
								<VersionHistoryPanel
									:secretId="secretId"
									:canManage="isOwner"
									@restored="load" />
							</div>
						</div>
					</div>

					<div class="secret-detail__row secret-detail__row--block">
						<span class="secret-detail__row-icon">
							<Autorenew :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Rotation & expiry')
							}}</span>
							<div class="secret-detail__row-value">
								<RotationPanel
									:secretId="secretId"
									:canManage="isOwner" />
							</div>
						</div>
					</div>

					<div class="secret-detail__row secret-detail__row--block">
						<span class="secret-detail__row-icon">
							<BeehiveOutline :size="20" />
						</span>
						<div class="secret-detail__row-main">
							<span class="secret-detail__row-label">{{
								t('keepiq', 'Honey tripwire')
							}}</span>
							<div class="secret-detail__row-value">
								<HoneyPanel :secretId="secretId" />
							</div>
						</div>
					</div>
				</div>
			</details>
		</div>
	</NcAppSidebar>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcActionSeparator,
	NcAppSidebar,
	NcButton,
	NcDateTime,
	NcEmptyContent,
	NcNoteCard,
} from '@nextcloud/vue'
import Account from 'vue-material-design-icons/Account.vue'
import Autorenew from 'vue-material-design-icons/Autorenew.vue'
import BeehiveOutline from 'vue-material-design-icons/BeehiveOutline.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Dialpad from 'vue-material-design-icons/Dialpad.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Fingerprint from 'vue-material-design-icons/Fingerprint.vue'
import Flash from 'vue-material-design-icons/Flash.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import History from 'vue-material-design-icons/History.vue'
import KeyVariant from 'vue-material-design-icons/KeyVariant.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ShieldOutline from 'vue-material-design-icons/ShieldOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'
import SecretRequestCreateDialog from '../dialogs/SecretRequestCreateDialog.vue'
import AttachmentPanel from './AttachmentPanel.vue'
import CopyButton from './CopyButton.vue'
import HoneyPanel from './HoneyPanel.vue'
import PasskeyDisplay from './PasskeyDisplay.vue'
import PasswordField from './PasswordField.vue'
import RotationPanel from './RotationPanel.vue'
import SecretActivityTab from './SecretActivityTab.vue'
import SecretRequestList from './secretRequest/SecretRequestList.vue'
import AdminHandoverPanel from './share/AdminHandoverPanel.vue'
import DelegationManager from './share/DelegationManager.vue'
import ShareList from './share/ShareList.vue'
import ShareRequestForm from './share/ShareRequestForm.vue'
import TotpDisplay from './TotpDisplay.vue'
import VaultIndicator from './VaultIndicator.vue'
import VersionHistoryPanel from './VersionHistoryPanel.vue'
import { cardLast4, parsePayload } from '../cardIdentity/cardIdentity.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { secretTypeLabel } from '../utils/secretTypes.js'
import { rootVaultOf } from '../utils/vaultList.js'

/**
 * The secret detail sidebar (restyle Stage 8). Encrypted fields are
 * decrypted client-side on load (login + additionalFields) while the key
 * stays masked until revealed — the on-demand decryption semantics are
 * unchanged from the old full-page detail this replaces.
 */
export default {
	name: 'SecretDetailSidebar',

	components: {
		NcActionButton,
		NcActions,
		NcActionSeparator,
		NcAppSidebar,
		NcButton,
		NcDateTime,
		NcEmptyContent,
		NcNoteCard,
		Account,
		Autorenew,
		BeehiveOutline,
		CalendarMonthOutline,
		ChevronDown,
		ClockOutline,
		Close,
		Dialpad,
		EyeOffOutline,
		EyeOutline,
		ShieldOutline,
		CreditCardOutline,
		Delete,
		Fingerprint,
		Flash,
		FolderMove,
		FormatListBulleted,
		History,
		KeyVariant,
		Lock,
		NoteTextOutline,
		Paperclip,
		Pencil,
		ShareVariant,
		Web,
		AdminHandoverPanel,
		AttachmentPanel,
		CopyButton,
		DelegationManager,
		HoneyPanel,
		PasskeyDisplay,
		PasswordField,
		RotationPanel,
		SecretActivityTab,
		SecretRequestCreateDialog,
		SecretRequestList,
		ShareList,
		ShareRequestForm,
		TotpDisplay,
		VaultIndicator,
		VersionHistoryPanel,
	},

	inject: {
		/**
		 * Open a registry-registered modal. Provided by CnAppRoot; defaults to a
		 * no-op so the component still mounts in isolation.
		 */
		cnOpenModal: { default: () => () => {} },
	},

	props: {
		/** The id of the secret to show. Route-driven (detailRoute.js). */
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			secret: null,
			loading: true,
			error: '',
			requestDialogOpen: false,
			teamWritable: false,
			/** Per-field reveal state for the card rows (masked by default). */
			cardRevealed: { number: false, cvv: false, pin: false },
			/** Reveal state for the identity's BSN row (masked by default). */
			bsnRevealed: false,
			/**
			 * Bumped by every successful `load()`, and bound to
			 * `<PasswordField :key>` so the field remounts whenever the
			 * loaded secret changes.
			 *
			 * PasswordField decrypts lazily and then CACHES the plaintext
			 * for its own lifetime (`plain` is only resolved while it is
			 * still `null`), and it keeps `revealed` across that lifetime
			 * too. That was harmless while the detail was a full page which
			 * remounted per secret. This sidebar deliberately does NOT
			 * remount — the `secretId` watcher above swaps the secret in
			 * place — so without a key the cache outlives the secret it
			 * belongs to, in two ways that both matter for a vault:
			 *
			 *   • Edit the open secret: `load()` refreshes `this.secret`,
			 *     the field keeps showing the OLD plaintext.
			 *   • Click another row while revealed: the panel shows secret
			 *     B's name with secret A's plaintext, and Copy copies A's.
			 *
			 * Remounting resets `plain` and `revealed` together, so a
			 * changed secret is re-masked until the user asks for it again.
			 */
			secretLoadToken: 0,
		}
	},

	computed: {
		/**
		 * The sidebar header name: the secret's name once loaded, a generic
		 * placeholder while loading/errored (NcAppSidebar requires a name).
		 *
		 * @return {string}
		 * @spec exclude Header-label fallback — no domain logic.
		 */
		sidebarName() {
			return this.secret?.name || t('keepiq', 'Secret')
		},

		/**
		 * The secret's type label for the header subline, empty while the
		 * type registry has no match.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		typeLabel() {
			if (!this.secret) {
				return ''
			}
			return secretTypeLabel(
				useSecretTypeStore().typesById[this.secret.typeId],
			)
		},

		/**
		 * The VAULT this secret ultimately lives under, for the header's
		 * vault tag (2026-09-03, per Remko — Proton's pattern: the rows in
		 * All secrets stay clean, and the one place you look at a single
		 * secret names its vault, with the vault's own icon and color).
		 * Null while nothing is loaded or the folder tree has no match.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		vault() {
			if (!this.secret) {
				return null
			}
			return rootVaultOf(useFolderStore().folders, this.secret.folderId)
		},

		/**
		 * When the secret was last modified, as a Date for NcDateTime — or
		 * null when the payload carries no timestamp (metadata row hidden).
		 *
		 * @return {Date|null}
		 * @spec exclude Payload-field normalization for display — no domain logic.
		 */
		updatedDate() {
			const value = this.secret?.updatedAt ?? this.secret?.updated_at
			return value ? new Date(value) : null
		},

		/**
		 * When the secret was created, as a Date for NcDateTime — or null
		 * when the payload carries no timestamp (metadata row hidden).
		 *
		 * @return {Date|null}
		 * @spec exclude Payload-field normalization for display — no domain logic.
		 */
		createdDate() {
			const value = this.secret?.createdAt ?? this.secret?.created_at
			return value ? new Date(value) : null
		},

		/**
		 * Whether this Secret already holds a value.
		 *
		 * Decides both the label and whether the request is a re-request: asking
		 * for values that exist overwrites them in place, which is exactly what a
		 * re-request is for. An empty placeholder awaiting its first fill is not
		 * an overwrite, so it must not be labelled or treated as one.
		 *
		 * @return {boolean} True when a value is present.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		secretHasValue() {
			return String(this.secret?.key || '') !== ''
		},

		/**
		 * Whether this secret has at least one additional field to show.
		 *
		 * The count matters, not just the presence of an object. `{}` is truthy AND
		 * an object, so a guard testing only those two rendered the "Additional
		 * fields" heading with nothing beneath it. That state only became reachable
		 * when owners could edit members: removing the last one now sends an empty
		 * blob (deliberately, so "none" stays distinguishable from "not loaded"),
		 * and the spec says re-opening the secret must then show NO additional
		 * fields — not an empty section.
		 *
		 * Returns a real boolean rather than the last truthy operand, so callers and
		 * tests get `false` instead of `null` or `undefined`.
		 *
		 * @return {boolean} True when there is at least one member to render.
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		hasAdditionalFields() {
			const blob = this.secret?.additionalFields

			return (
				blob !== null
				&& blob !== undefined
				&& typeof blob === 'object'
				&& Object.keys(blob).length > 0
			)
		},

		/**
		 * Whether this secret is the `note` system type — its decrypted
		 * `key` holds prose, so the row reads "Note" and carries the note
		 * glyph instead of the key glyph.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 */
		isNote() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'note'
		},

		/**
		 * Whether the raw key row renders: only for SCALAR key types. For
		 * card / identity / passkey / totp the decrypted key IS the
		 * structured payload their dedicated rows present — dumping the
		 * JSON blob above them reads as noise (and no researched manager
		 * shows one, see SIDEBAR-UX-RESEARCH.md).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		showKeyRow() {
			// A composite payload that fails to parse (legacy plain string)
			// falls back to the raw key row rather than showing nothing.
			if (this.isCard) {
				return !this.cardPayload
			}
			if (this.isIdentity) {
				return !this.identityPayload
			}
			return !this.isPasskey && !this.isTotp
		},

		/**
		 * The decrypted identity payload for the section rows, or null when
		 * this secret is not an identity (or the payload does not parse).
		 *
		 * @return {object|null}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		identityPayload() {
			if (!this.isIdentity) {
				return null
			}
			return parsePayload(this.secret?.key || '')
		},

		/**
		 * The identity's display name: first + last name, whichever exist.
		 *
		 * @return {string}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		identityFullName() {
			const p = this.identityPayload
			return [p?.firstName, p?.lastName].filter(Boolean).join(' ')
		},

		/**
		 * The revealed card number in display form: digit groups of four,
		 * space-separated, regardless of how the number was typed in.
		 *
		 * @return {string}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		cardNumberFormatted() {
			const digits = String(this.cardPayload?.number || '').replace(/\D/g, '')
			if (!digits) {
				return String(this.cardPayload?.number || '')
			}
			return digits.replace(/(.{4})/g, '$1 ').trim()
		},

		/**
		 * The decrypted card payload for the per-field rows, or null when
		 * this secret is not a card (or the payload does not parse).
		 *
		 * @return {object|null}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		cardPayload() {
			if (!this.isCard) {
				return null
			}
			return parsePayload(this.secret?.key || '')
		},

		/**
		 * The masked card number, Proton-style: first and last four digits
		 * visible, the middle groups dotted. Falls back to full dots when
		 * the number is too short to expose anything meaningfully.
		 *
		 * @return {string}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		cardNumberMasked() {
			const digits = String(this.cardPayload?.number || '').replace(/\D/g, '')
			const last4 = cardLast4(this.cardPayload?.number || '')
			if (digits.length < 12 || !last4) {
				return '•••• •••• •••• ••••'
			}
			return `${digits.slice(0, 4)} •••• •••• ${last4}`
		},

		/**
		 * The label for the decrypted key field, which reads "Note" for the
		 * `note` system type and "Key" otherwise.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		keyLabel() {
			return this.isNote ? t('keepiq', 'Note') : t('keepiq', 'Key')
		},

		/**
		 * True when this secret is a `totp` type — its decrypted `key` holds an
		 * authenticator seed and the client renders a live one-time code.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		isTotp() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'totp'
		},

		/**
		 * Whether this secret is a `passkey` credential: the encrypted `key`
		 * holds the canonical CXF-aligned credential JSON and the client
		 * renders the passkey presentation with the private key masked.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-listing-filtering-and-site-associated-presentation
		 */
		isPasskey() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'passkey'
		},

		/**
		 * Whether this secret is a `card` type — the decrypted `key` holds
		 * a composite payment-card payload rendered with per-field masking
		 * (card-identity-items §4).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		isCard() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'card'
		},

		/**
		 * Whether this secret is an `identity` type (card-identity-items §4).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		isIdentity() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'identity'
		},

		/**
		 * The current Nextcloud user ID, or null when unauthenticated.
		 *
		 * @return {string|null}
		 * @spec exclude Session-global passthrough — no domain logic.
		 */
		currentUserId() {
			return window.OC?.currentUser ?? null
		},

		/**
		 * True when the current user owns the secret. Owners see the
		 * recipient list + delegation manager.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
		 */
		isOwner() {
			if (this.secret === null || this.currentUserId === null) {
				return false
			}
			// `ownerId` is the one canonical owner field: the Secret entity
			// has serialized it since its first version, so every response
			// (offline snapshots included) carries it.
			return this.secret.ownerId === this.currentUserId
		},

		/**
		 * Whether the vault is served from the offline cache (read-only) —
		 * all write actions on the detail are hidden (offline-readonly-cache §4.2).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-offline-mode-is-strictly-read-only
		 */
		offlineReadOnly() {
			return useOfflineStore().readOnly
		},

		/**
		 * True when the current user is a non-owner recipient — they
		 * see the share-request form so they can ask the owner to
		 * share with a third party.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
		 */
		isRecipient() {
			return this.secret !== null && this.isOwner === false
		},

		/**
		 * Show the sharing section whenever the role is known (owner
		 * or recipient).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
		 */
		canSeeSharing() {
			return this.isOwner === true || this.isRecipient === true
		},
	},

	watch: {
		/**
		 * Route-driven reload: clicking another row while the sidebar is
		 * open swaps the `:id` segment without remounting this component,
		 * so the load runs from a watcher rather than `mounted()` alone.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		secretId() {
			// Masked fields never carry reveal state over to another secret.
			this.cardRevealed = { number: false, cvv: false, pin: false }
			this.bsnRevealed = false
			this.load()
		},
	},

	/**
	 * Fetch the type catalogue, then load and decrypt the secret.
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
	 */
	async mounted() {
		try {
			await useSecretTypeStore().fetchTypes()
		} catch (e) {
			// A failed catalogue fetch must not strand the sidebar on its
			// spinner — surface the error the same way load() does.
			this.error =
				e?.response?.data?.message || t('keepiq', 'Failed to load secret')
			this.loading = false
			return
		}
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load and decrypt the secret.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.secret = await useSecretStore().fetchSecret(this.secretId)
				// Retire the previous secret's decrypted plaintext with the
				// secret it came from. See `secretLoadToken` in data().
				this.secretLoadToken += 1
				// Write-grade badge (folder-permission-grades §4.3) — a
				// copy the user may team-write shows the sync warning.
				try {
					const { useShareStore } =
						await import('../store/modules/share.js')
					const context = await useShareStore().fetchWriteContext(
						this.secretId,
					)
					this.teamWritable =
						context.effectiveGrade === 'write'
						&& context.sourceSecretId !== this.secretId
				} catch {
					this.teamWritable = false
				}
			} catch (e) {
				if (e?.response?.status === 403) {
					this.error = t(
						'keepiq',
						'This secret is locked because its encryption suite was revoked.',
					)
				} else {
					this.error =
						e?.response?.data?.message
						|| t('keepiq', 'Failed to load secret')
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh the vault list behind the sidebar after a mutation. The
		 * old full-page detail could leave the list stale (it remounted on
		 * the way back); with the list visible the whole time, an edit or
		 * move must reflect immediately. `fetchSecrets()` with no options
		 * reuses the store's active filters/sort/page.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		refreshList() {
			useSecretStore()
				.fetchSecrets()
				.catch(() => {
					// The mutation itself succeeded — only the list behind the
					// sidebar is stale now, so a toast (not this.error) is the
					// right weight: the detail view is correct.
					showError(
						t(
							'keepiq',
							'Could not refresh the list — it may be out of date',
						),
					)
				})
		},

		/**
		 * Toggle one card field's reveal state (masked by default).
		 *
		 * @param {string} field The field name (number | cvv | pin).
		 * @return {void}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		toggleCardField(field) {
			this.cardRevealed = {
				...this.cardRevealed,
				[field]: !this.cardRevealed[field],
			}
		},

		/**
		 * Resolve the decrypted key for the password field.
		 *
		 * @return {Promise<string>}
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async resolveKey() {
			return this.secret ? this.secret.key || '' : ''
		},

		/**
		 * Open the edit dialog for this secret; reload the detail and the
		 * list behind it on success.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		openEdit() {
			this.cnOpenModal('secret-edit', {
				secretId: this.secretId,
				onSaved: () => {
					this.load()
					this.refreshList()
				},
			})
		},

		/**
		 * Open the move dialog for this secret; reload the detail and the
		 * list behind it on success (the row may leave the visible folder).
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		openMove() {
			this.cnOpenModal('secret-move', {
				subject: 'secret',
				secretId: this.secretId,
				currentFolderId: this.secret ? this.secret.folderId || null : null,
				onSaved: () => {
					this.load()
					this.refreshList()
				},
			})
		},

		/**
		 * Open the share dialog for this secret.
		 *
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
		 */
		openShare() {
			this.cnOpenModal('secret-share', {
				secretId: this.secretId,
			})
		},

		/**
		 * Open the delete confirmation for this secret; close the sidebar
		 * once the dialog reports the delete went through. The dialog owns
		 * the call and the failure message, so a refused delete leaves the
		 * sidebar open behind it. The store removes the row from the list
		 * state itself, so no list refetch is needed.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
		 */
		openDelete() {
			this.cnOpenModal('secret-delete', {
				secretId: this.secretId,
				onDeleted: () => {
					this.$emit('close')
				},
			})
		},

		/**
		 * Refresh the secret detail after a delegation reclaim so the
		 * sharing section's caches stay consistent.
		 *
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
		 */
		onReclaimed() {
			this.load()
		},

		/**
		 * Open the SecretRequestCreateDialog (§8.4 Requests section).
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		openRequestCreate() {
			this.requestDialogOpen = true
		},

		/**
		 * A request was created against this Secret.
		 *
		 * This must NOT close the dialog. The dialog has just built the fill link
		 * and is showing it with a Copy button and a Done action; closing here
		 * unmounts it (`v-if`) one tick after `submit()` set `fillUrl`, so the
		 * requester is never shown the URL the whole feature exists to produce.
		 * The list re-fetches itself via the store, so there is nothing else to do
		 * here — the user dismisses the dialog when they have the link.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		onRequestCreated() {
			// Intentionally empty: see the docblock. Kept as the `created` hook so
			// a future refresh has an obvious home.
		},
	},
}
</script>

<style scoped>
/* Wider pane (Proton sits around 560px): NcAppSidebar sizes itself off
   its own --app-sidebar-width custom property, so overriding the token
   is the supported knob. Media-guarded above the library's own 512px
   breakpoint, where its full-width (100vw) mobile rule must keep
   winning untouched. */
@media only screen and (min-width: 513px) {
	.secret-detail {
		--app-sidebar-width: clamp(300px, 35vw, 560px);
	}
}

.secret-detail__card {
	padding: 0 4px;
}

/* Action row ON the title line (Proton): the slot content renders in the
   header's description row, but the header block
   (.app-sidebar-header__desc) is position:relative with its
   padding-inline-end already reserving the close button's column — so
   the row is lifted to the top-right corner, next to the name. */
/* The vault tag sits in flow directly under the name/type sublines
   (Proton pattern) — the actions row beside it is corner-positioned.
   Generous block margins: pressed against the type subline above and the
   content below, the three lines read as one cramped blob (review call). */
.secret-detail__vault {
	margin-block: 6px 14px;
}

.secret-detail__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	position: absolute;
	top: var(--app-sidebar-padding, 10px);
	/* the native X is hidden while this row renders (--own-close), so the
	   row itself takes the corner */
	inset-inline-end: var(--app-sidebar-padding, 10px);
}

/* Closing lives in the "…" menu while the action row is up: hide the
   sidebar's native X there (loading/error states keep it — the row is
   not rendered then, and Esc closes in every state). */
.secret-detail--own-close :deep(.app-sidebar__close) {
	display: none;
}

/* "More information" / "Advanced" disclosures: native details/summary,
   custom chevron (the UA marker is hidden), rotating when open. */
.secret-detail__accordion {
	margin-bottom: 12px;
}

.secret-detail__accordion-summary {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 4px;
	font-weight: 600;
	cursor: pointer;
	list-style: none;
	border-radius: var(--border-radius, 8px);
}

.secret-detail__accordion-summary::-webkit-details-marker {
	display: none;
}

.secret-detail__accordion-summary:hover,
.secret-detail__accordion-summary:focus-visible {
	background-color: var(--color-background-hover, #f5f5f5);
}

.secret-detail__accordion-chevron {
	transition: transform var(--animation-quick, 100ms);
}

details[open] > summary .secret-detail__accordion-chevron {
	transform: rotate(180deg);
}

/* Vestibular safety (gate-45): no motion for users who asked for none. */
@media (prefers-reduced-motion: reduce) {
	.secret-detail__accordion-chevron {
		transition: none;
	}
}

/* Keep a long secret name from running under the lifted action row. */
.secret-detail :deep(.app-sidebar-header__mainname-container) {
	padding-inline-end: 210px;
}

/* Proton-style grouped rows: one rounded box per field group, each row an
   icon + muted label above a prominent value. */
.secret-detail__box {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	padding: 2px 14px;
	margin-bottom: 12px;
}

.secret-detail__row {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	padding: 12px 0;
}

.secret-detail__row + .secret-detail__row {
	border-top: 1px solid var(--color-border);
}

.secret-detail__row-icon {
	flex: 0 0 auto;
	margin-top: 2px;
	color: var(--color-text-maxcontrast);
}

.secret-detail__row-main {
	flex: 1 1 auto;
	min-width: 0;
}

.secret-detail__row-label {
	display: block;
	margin-bottom: 2px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.secret-detail__row-value {
	/* Same wrapping behaviour as the deprecated `word-break: break-word`
	   (stylelint: declaration-property-value-keyword-no-deprecated). */
	overflow-wrap: anywhere;
}

/* Masked card values read as digits: monospace, slightly tracked. */
.secret-detail__row-value--mono {
	font-family: monospace;
	letter-spacing: 0.04em;
}

/* Identity section headings: plain text OUTSIDE the boxes (Proton). */
.secret-detail__section-heading {
	margin: 16px 0 8px;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.secret-detail__row-trailing {
	flex: 0 0 auto;
	align-self: center;
}

/* Block rows (type displays, additional fields, attachments, owner
   panels): icon + label form a header LINE and the value spans the full
   box width beneath — Proton's card layout, instead of squeezing a
   whole component into the indented icon column. */
.secret-detail__row--block {
	display: grid;
	grid-template-columns: auto 1fr;
	column-gap: 14px;
	align-items: center;
}

.secret-detail__row--block .secret-detail__row-icon {
	grid-column: 1;
	margin-top: 0;
}

.secret-detail__row--block .secret-detail__row-main {
	display: contents;
}

.secret-detail__row--block .secret-detail__row-label {
	grid-column: 2;
	margin-bottom: 0;
	align-self: center;
}

.secret-detail__row--block .secret-detail__row-value {
	grid-column: 1 / -1;
	margin-top: 8px;
	min-width: 0;
}

/* Metadata box (Proton): tight rows, no separators. */
.secret-detail__box--meta .secret-detail__row {
	padding: 8px 0;
}

.secret-detail__box--meta .secret-detail__row + .secret-detail__row {
	border-top: none;
}

.secret-detail__sharing {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.secret-detail__sharing-heading {
	margin: 0 0 12px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.secret-detail__requests {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.secret-detail__requests-heading {
	margin: 0 0 12px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.secret-detail__requests-actions {
	margin-bottom: 12px;
}

.secret-detail__offline-note {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.secret-detail__team-badge {
	margin: 0 0 8px;
	padding: 2px 10px;
	display: inline-block;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	font-weight: 600;
	background-color: var(--color-primary-element-light, #dbe9f5);
	color: var(--color-main-text, #222);
}
</style>
