# Keepiq — Design References & Dashboard Wireframes

## 1. Design Inspiration Sources

### Lock Screen / Master Password Entry
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Bitwarden Web Vault | vault.bitwarden.com | Full-page lock with logo, single password field, strength indicator on setup |
| 1Password Lock Screen | 1password.com | Centered card, vault icon, password field with show/hide toggle |
| KeePassXC | keepassxc.org | Database unlock with key file option, password field, minimalist design |
| Dribbble "vault lock screen" | Search Dribbble | Shield/lock icon, gradient backgrounds, centered form card |
| LastPass Vault | lastpass.com | Simple centered password entry with timeout message |

### Vault / Secret List
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Bitwarden Web Vault | vault.bitwarden.com | Folder tree sidebar + secret list, type icons (login, card, note, identity), search bar at top |
| 1Password | 1password.com | Category sidebar, search-first navigation, item cards with favicons |
| Passbolt | passbolt.com | Team-oriented list with sharing indicators, folder tree, filter bar |
| KeePassXC | keepassxc.org | Tree view (groups) + table view (entries), column-based with type icons |
| Nextcloud Passwords | apps.nextcloud.com/apps/passwords | Folder navigation, tag filters, list view with copy-to-clipboard actions |
| Dribbble "password manager UI" | Search Dribbble | Card-based layouts, favicon next to entries, copy buttons, strength indicators |

### Secret Detail View
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Bitwarden Item View | vault.bitwarden.com | Field-by-field layout with copy buttons, password hidden by default, custom fields section |
| 1Password Item Detail | 1password.com | Card-based sections (credentials, website, notes), sidebar for metadata |
| Passbolt Resource View | passbolt.com | Side panel detail with sharing tab, copy password button |
| Dribbble "credential detail" | Search Dribbble | Clean field presentation with icons, show/hide toggles, copy actions |

### Sharing UI
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Bitwarden Organizations | vault.bitwarden.com | Collection-based sharing, member management |
| Passbolt Sharing | passbolt.com | User/group picker, permission levels, share dialog |
| 1Password Vault Sharing | 1password.com | Vault membership, per-item sharing |
| Nextcloud Files Sharing | Nextcloud | User/group autocomplete, link sharing with options, share sidebar tab |
| Dribbble "share dialog" | Search Dribbble | User search autocomplete, sharing chips, permission dropdowns |

### Admin Settings / CA Management
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| HashiCorp Vault UI | vaultproject.io | PKI engine: CA chain visualization, certificate list, issue/revoke actions |
| Nextcloud Admin Settings | Nextcloud | Section-based settings page, toggle switches, info cards |
| AWS Certificate Manager | AWS Console | Certificate status badges (issued, expired, revoked), chain visualization |
| Let's Encrypt Dashboard | certbot.eff.org | Certificate status, expiry countdown, renewal actions |

### Dashboard
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Bitwarden Reports | vault.bitwarden.com | Password health: reused, weak, exposed counts with action items |
| 1Password Watchtower | 1password.com | Security score, vulnerable items count, breach alerts |
| Dribbble "security dashboard" | Search Dribbble | Shield-based KPI cards, health scores, alert lists |
| Nextcloud Dashboard | Nextcloud | Widget-based dashboard, card layout, status panels |

---

## 2. Missing Features Identified from Design Patterns

### MVP Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Copy-to-clipboard on secret list items | Bitwarden, 1Password, Passwords app | Most-used action — copy password without opening detail view |
| Show/hide toggle on password fields | All vault UIs | Standard pattern — passwords hidden by default with eye icon |
| Favicon/icon next to secrets by URL | 1Password, Bitwarden | Visual identification of which service a secret belongs to |

### V1 Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Password health scoring per secret | Bitwarden Reports, 1Password Watchtower | Flag weak, reused, or old passwords |
| Secret strength indicator in list view | Passbolt, Bitwarden | Color-coded strength badge next to each secret |
| Vault search with keyboard shortcut (Ctrl+K) | 1Password, many modern apps | Power-user quick access |
| Dark mode support | All modern UIs | User preference; Nextcloud supports dark mode |

### Enterprise Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Breach detection (HaveIBeenPwned) | 1Password Watchtower, Bitwarden Reports | Proactive security alerts for compromised URLs |
| Password age indicator | 1Password, Bitwarden | Show how old each secret is; flag stale credentials |
| Export to PDF (single secret) | KeePassXC | Print-friendly credential sheet for offline backup |

---

## 3. Dashboard Wireframes

### 3.1 Lock Screen

```
┌─────────────────────────────────────────────────────────────────────┐
│                                                                     │
│                                                                     │
│                                                                     │
│                          ┌─────────────┐                            │
│                          │    🔒       │                            │
│                          │   KEEPIQ   │                            │
│                          └─────────────┘                            │
│                                                                     │
│                    ┌───────────────────────────┐                    │
│                    │                           │                    │
│                    │   Enter your master       │                    │
│                    │   password to unlock      │                    │
│                    │   your vault              │                    │
│                    │                           │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │ ••••••••••••  👁  │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │                           │                    │
│                    │   Session timeout:        │                    │
│                    │   [Nextcloud session ▾]   │                    │
│                    │                           │                    │
│                    │       [ Unlock ]          │                    │
│                    │                           │                    │
│                    └───────────────────────────┘                    │
│                                                                     │
│                                                                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**First-time setup variant** — shown when user has no EncryptionSuite:

```
┌─────────────────────────────────────────────────────────────────────┐
│                          ┌─────────────┐                            │
│                          │    🔒       │                            │
│                          │   KEEPIQ   │                            │
│                          └─────────────┘                            │
│                                                                     │
│                    ┌───────────────────────────┐                    │
│                    │                           │                    │
│                    │   Welcome to Keepiq      │                    │
│                    │                           │                    │
│                    │   Choose a master         │                    │
│                    │   password for your vault  │                    │
│                    │                           │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │                👁  │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │   ████████░░  Strong      │                    │
│                    │   "Add a number or symbol │                    │
│                    │    to make it stronger"   │                    │
│                    │                           │                    │
│                    │   Confirm:                │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │                👁  │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │                           │                    │
│                    │   ⚠ If you forget your    │                    │
│                    │   master password, your   │                    │
│                    │   secrets cannot be        │                    │
│                    │   recovered.              │                    │
│                    │                           │                    │
│                    │       [ Create Vault ]    │                    │
│                    │                           │                    │
│                    └───────────────────────────┘                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.2 Main Dashboard (Landing Page)

```
┌─────────────────────────────────────────────────────────────────────┐
│  KEEPIQ                                           [Search...] [⚙] │
├──────────┬──────────┬──────────┬──────────┬──────────────────────────┤
│ Dashboard│  Vault   │  Apps    │  Shared  │                          │
├──────────┴──────────┴──────────┴──────────┴──────────────────────────┤
│                                                                     │
│  ⚠ Key migration in progress — 12 secrets remaining  [Resume →]    │
│                                                                     │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐  │
│  │   SECRETS   │ │   SHARED    │ │   FOLDERS   │ │ ⚠ ATTENTION │  │
│  │             │ │  WITH ME    │ │             │ │             │  │
│  │     47      │ │      8      │ │      5      │ │      2      │  │
│  │             │ │             │ │             │ │  compromised │  │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘  │
│                                                                     │
│  ┌────────────────────────────────┐ ┌──────────────────────────┐   │
│  │ Recently Accessed              │ │ Sharing Activity         │   │
│  │                                │ │                          │   │
│  │ 🔑 GitHub API Token           │ │ • Maria shared "DB pwd"  │   │
│  │    api_key · 2 min ago        │ │   with you — 1 hour ago  │   │
│  │                                │ │                          │   │
│  │ 🔑 Production Database        │ │ • You shared "SSH Key"   │   │
│  │    database · 15 min ago      │ │   with Jan — yesterday   │   │
│  │                                │ │                          │   │
│  │ 🔑 AWS Console                │ │ • Request for "Vendor    │   │
│  │    login · 1 hour ago         │ │   API Key" fulfilled     │   │
│  │                                │ │   — 2 days ago           │   │
│  │ 🔑 VPN Credentials            │ │                          │   │
│  │    login · 3 hours ago        │ │ [View all →]             │   │
│  │                                │ └──────────────────────────┘   │
│  │ 🔑 Email Server               │                                │
│  │    login · yesterday          │                                │
│  │                                │                                │
│  │ [View all secrets →]          │                                │
│  └────────────────────────────────┘                                │
│                                                                     │
│  ┌──── Admin Only ────────────────────────────────────────────┐    │
│  │                                                            │    │
│  │  ┌──────────────────┐  ┌──────────────────────────────┐   │    │
│  │  │ PENDING APPS     │  │ CA HEALTH                    │   │    │
│  │  │                  │  │                              │   │    │
│  │  │     3 pending    │  │  ● Healthy                   │   │    │
│  │  │  [Review →]      │  │  Root: expires 2046-03-23    │   │    │
│  │  │                  │  │  Intermediate: 2029-03-23    │   │    │
│  │  └──────────────────┘  │  Suites signed: 24           │   │    │
│  │                         └──────────────────────────────┘   │    │
│  └────────────────────────────────────────────────────────────┘    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.3 Vault / Secret List View

```
┌─────────────────────────────────────────────────────────────────────┐
│  KEEPIQ > Vault                        [🔍 Search secrets...]  [⚙]│
├──────────┬──────────┬──────────┬──────────┬──────────────────────────┤
│ Dashboard│  Vault   │  Apps    │  Shared  │                          │
├──────────┴──────────┴──────────┴──────────┴──────────────────────────┤
│                                                                     │
│ ┌─────────────────┐ ┌──────────────────────────────────────────────┐│
│ │ FOLDERS         │ │                                              ││
│ │                 │ │  All Secrets (47)           [+ New Secret]   ││
│ │ 📁 All (47)    │ │                                              ││
│ │                 │ │  ┌─ Filter: [All types ▾] [All folders ▾]   ││
│ │ 📁 Work (12)   │ │  │  Sort: [Name ▾]                          ││
│ │  └ 📁 APIs (5) │ │                                              ││
│ │  └ 📁 DBs (3)  │ │  ┌────┬──────────────────┬────────┬────────┐││
│ │                 │ │  │Type│ Name             │ URL    │Actions ││ │
│ │ 📁 Personal(18)│ │  ├────┼──────────────────┼────────┼────────┤││
│ │  └ 📁 Email(4) │ │  │ 🔑 │ AWS Console      │aws.com │ 📋 👁  │││
│ │  └ 📁 Social(6)│ │  │ 🔑 │ Azure Portal     │azure.. │ 📋 👁  │││
│ │                 │ │  │ 🔐 │ GitHub API Token  │github..│ 📋 👁  │││
│ │ 📁 Shared (8)  │ │  │ 🔐 │ GitLab Deploy Key │gitlab..│ 📋 👁  │││
│ │                 │ │  │ 📝 │ License Keys      │ —     │ 📋 👁  │││
│ │ 📁 Apps (9)    │ │  │ 🔑 │ Office 365       │office..│ 📋 👁  │││
│ │                 │ │  │ 🗄  │ Prod Database    │ —     │ 📋 👁  │││
│ │                 │ │  │ 🔑 │ VPN Credentials  │ —     │ 📋 👁  │││
│ │ [+ New folder]  │ │  │ 🔒 │ SSH Server Key   │ —     │ 📋 👁  │││
│ │                 │ │  │ 📄 │ TLS Certificate  │ —     │ 📋 👁  │││
│ │                 │ │  └────┴──────────────────┴────────┴────────┘││
│ │                 │ │                                              ││
│ │                 │ │  Showing 10 of 47 · Page 1 of 5  [< 1 2 >] ││
│ └─────────────────┘ └──────────────────────────────────────────────┘│
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Type icons:**
| Icon | Type | Color |
|------|------|-------|
| 🔑 | login | Blue |
| 🔐 | api_key | Purple |
| 🔒 | ssh_key | Green |
| 📄 | certificate | Orange |
| 📝 | note | Gray |
| 🗄 | database | Red |

**Actions column:**
- 📋 = Copy password to clipboard (most-used action, single click)
- 👁 = Open detail view

### 3.4 Secret Detail View

Using **CnDetailPage + CnObjectSidebar** pattern:

```
┌──────────────────────────────────────────────────────────────────────┐
│ ← Back to vault    🔑 AWS Console Login          [Edit] [Delete]    │
├─────────────────────────────────────────────┬────────────────────────┤
│                                             │ ╔═══ Sidebar ═══════╗ │
│  ┌─── Core Info ──────────────────────┐     │ ║ Files | Notes |   ║ │
│  │                                    │     │ ║ Tags | Audit      ║ │
│  │ Type:      🔑 Login               │     │ ║ Trail              ║ │
│  │ URL:       console.aws.amazon.com  │     │ ╠══════════════════╣ │
│  │ Folder:    Work / APIs             │     │ ║                  ║ │
│  │                                    │     │ ║ 📝 "Remember to  ║ │
│  │ Login:     admin@company.nl        │     │ ║ rotate this key  ║ │
│  │            [📋 Copy]               │     │ ║ quarterly"       ║ │
│  │                                    │     │ ║ — Jan, Mar 15    ║ │
│  │ Password:  ••••••••••••••••        │     │ ║                  ║ │
│  │            [👁 Show] [📋 Copy]     │     │ ║ 📝 "MFA seed is  ║ │
│  │                                    │     │ ║ in additional     ║ │
│  │ Additional fields:                 │     │ ║ fields"          ║ │
│  │   MFA Seed: ••••••••  [📋]        │     │ ║ — Maria, Mar 10  ║ │
│  │   Region:   eu-west-1 [📋]        │     │ ║                  ║ │
│  │                                    │     │ ║ [+ Add note]     ║ │
│  └────────────────────────────────────┘     │ ║                  ║ │
│                                             │ ╚══════════════════╝ │
│  ┌─── Sharing ────────────────────────┐     │                      │
│  │                                    │     │                      │
│  │ Shared with 3 users:              │     │                      │
│  │   👤 Jan de Vries                  │     │                      │
│  │   👤 Maria Jansen                  │     │                      │
│  │   👤 Pieter Bakker                 │     │                      │
│  │                                    │     │                      │
│  │ [+ Share with user]                │     │                      │
│  │ [🔗 Create link share]            │     │                      │
│  │ [📨 Create secret request]        │     │                      │
│  └────────────────────────────────────┘     │                      │
│                                             │                      │
│  ┌─── Encryption ─────────────────────┐     │                      │
│  │                                    │     │                      │
│  │ Suite: EncryptionSuite-abc123      │     │                      │
│  │ Status: ● Active                   │     │                      │
│  │ Created: 2026-02-10               │     │                      │
│  │ Updated: 2026-03-15               │     │                      │
│  └────────────────────────────────────┘     │                      │
├─────────────────────────────────────────────┴────────────────────────┤
```

**Component hierarchy:**
```
CnDetailPage (title="AWS Console Login", back-route={name:'Secrets'})
├─ #header-actions → [Edit] [Delete]
├─ #default → Card widgets
│   ├─ CnDetailCard (title="Core Info")
│   │   └─ Type, URL, folder, login (with copy), password (hidden + copy),
│   │      additional fields (each with copy)
│   ├─ CnDetailCard (title="Sharing")
│   │   └─ Recipient list (owner only), share/link/request buttons
│   └─ CnDetailCard (title="Encryption")
│       └─ Suite ID, status badge, timestamps
└─ CnObjectSidebar (object-type="keepiq_secret", object-id, ...)
    ├─ Tab: Files    — attachments (e.g., key files, certificates)
    ├─ Tab: Notes    — team notes about this secret
    ├─ Tab: Tags     — categorization tags
    └─ Tab: Audit Trail — who accessed/modified this secret
```

### 3.5 Key Generator Modal

```
┌─────────────────────────────────────────────┐
│  Generate Password                    [✕]   │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │ Xp7!kM2@nQ9$bR4w                   │    │
│  │                        [📋] [🔄]   │    │
│  └─────────────────────────────────────┘    │
│  Strength: ████████████████  Very Strong    │
│                                             │
│  Length: [────●────────────] 16             │
│                                             │
│  ☑ Include special characters               │
│    (!@#$%^&*()-_=+[]{}|;:,.<>?/)           │
│                                             │
│  Excluded characters:                       │
│  ┌─────────────────────────────────────┐    │
│  │ 0Ol1I                              │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ▸ Advanced: regex override                 │
│                                             │
│  [Cancel]                      [Use This]   │
└─────────────────────────────────────────────┘
```

### 3.6 Share Dialog

```
┌─────────────────────────────────────────────┐
│  Share "AWS Console Login"            [✕]   │
│                                             │
│  ┌─── Share with User ────────────────┐     │
│  │                                    │     │
│  │  🔍 [Search users or groups...]    │     │
│  │                                    │     │
│  │  Current shares:                   │     │
│  │                                    │     │
│  │  👤 Jan de Vries          [Revoke] │     │
│  │  👤 Maria Jansen          [Revoke] │     │
│  │  👥 Team DevOps (3 users) [Revoke] │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
│  ┌─── Link Shares ───────────────────┐      │
│  │                                    │     │
│  │  Active links: 1                   │     │
│  │                                    │     │
│  │  🔗 Link #1 — 2/5 uses            │     │
│  │     Created: Mar 10     [Revoke]   │     │
│  │                                    │     │
│  │  [+ Create new link share]         │     │
│  │    Usage limit: [5 ▾]             │     │
│  │    Expires: [Never ▾]             │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
│  ┌─── Secret Requests ───────────────┐      │
│  │                                    │     │
│  │  📨 Pending request (expires Apr 5)│     │
│  │     Fields: username, password     │     │
│  │     [Copy link] [Revoke]          │     │
│  │                                    │     │
│  │  [+ Create new request]           │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
│                              [Close]        │
└─────────────────────────────────────────────┘
```

### 3.7 Link Share Access Page (Public)

```
┌─────────────────────────────────────────────────────────────────────┐
│                                                                     │
│                          ┌─────────────┐                            │
│                          │    🔒       │                            │
│                          │   KEEPIQ   │                            │
│                          └─────────────┘                            │
│                                                                     │
│                    ┌───────────────────────────┐                    │
│                    │                           │                    │
│                    │   A secret has been       │                    │
│                    │   shared with you         │                    │
│                    │                           │                    │
│                    │   Enter the password      │                    │
│                    │   provided by the sender  │                    │
│                    │                           │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │                👁  │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │                           │                    │
│                    │       [ Decrypt ]         │                    │
│                    │                           │                    │
│                    └───────────────────────────┘                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**After successful decryption:**

```
┌─────────────────────────────────────────────────────────────────────┐
│                    ┌───────────────────────────┐                    │
│                    │                           │                    │
│                    │   Shared Secret           │                    │
│                    │                           │                    │
│                    │   Name:    AWS Console    │                    │
│                    │   Login:   admin@co.nl    │                    │
│                    │            [📋 Copy]      │                    │
│                    │                           │                    │
│                    │   Password: ••••••••••    │                    │
│                    │            [👁] [📋 Copy] │                    │
│                    │                           │                    │
│                    │   URL: console.aws.com    │                    │
│                    │            [📋 Copy]      │                    │
│                    │                           │                    │
│                    │   ⚠ This link has 3       │                    │
│                    │   remaining uses          │                    │
│                    │                           │                    │
│                    └───────────────────────────┘                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.8 Secret Request Fill-in Page (Public)

```
┌─────────────────────────────────────────────────────────────────────┐
│                          ┌─────────────┐                            │
│                          │    📨       │                            │
│                          │   KEEPIQ   │                            │
│                          └─────────────┘                            │
│                                                                     │
│                    ┌───────────────────────────┐                    │
│                    │                           │                    │
│                    │   Secret Request          │                    │
│                    │                           │                    │
│                    │   Someone has requested   │                    │
│                    │   the following fields:   │                    │
│                    │                           │                    │
│                    │   Username:               │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │                   │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │                           │                    │
│                    │   Password:               │                    │
│                    │   ┌───────────────────┐   │                    │
│                    │   │                👁  │   │                    │
│                    │   └───────────────────┘   │                    │
│                    │                           │                    │
│                    │   🔒 Your submission      │                    │
│                    │   will be encrypted       │                    │
│                    │   immediately. The        │                    │
│                    │   requester cannot see    │                    │
│                    │   these values without    │                    │
│                    │   their master password.  │                    │
│                    │                           │                    │
│                    │       [ Submit ]          │                    │
│                    │                           │                    │
│                    └───────────────────────────┘                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.9 Application Management (Admin View)

```
┌─────────────────────────────────────────────────────────────────────┐
│  KEEPIQ > Applications                        [+ Register App]  [⚙]│
├──────────┬──────────┬──────────┬──────────┬──────────────────────────┤
│ Dashboard│  Vault   │  Apps    │  Shared  │                          │
├──────────┴──────────┴──────────┴──────────┴──────────────────────────┤
│                                                                     │
│  ┌──── Pending Approval (3) ──────────────────────────────────────┐ │
│  │                                                                │ │
│  │  ┌────────────────────────────────────────────────────────┐   │ │
│  │  │ 🟡 N8N Workflow Engine              external            │   │ │
│  │  │   Registered by: anonymous · Mar 28                    │   │ │
│  │  │   CSR: uploaded ✓                                      │   │ │
│  │  │                              [Approve]  [Reject]       │   │ │
│  │  └────────────────────────────────────────────────────────┘   │ │
│  │                                                                │ │
│  │  ┌────────────────────────────────────────────────────────┐   │ │
│  │  │ 🟡 Monitoring Agent                 internal            │   │ │
│  │  │   Registered by: admin · Mar 27                        │   │ │
│  │  │   CSR: not uploaded — key pair will be generated        │   │ │
│  │  │                              [Approve]  [Reject]       │   │ │
│  │  └────────────────────────────────────────────────────────┘   │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  ┌──── Active Applications (4) ───────────────────────────────────┐ │
│  │                                                                │ │
│  │  ┌────────────────────────────────────────────────────────┐   │ │
│  │  │ 🟢 OpenConnector                   internal             │   │ │
│  │  │   Approved by: admin · Mar 15 · Secrets: 12            │   │ │
│  │  │   Suite: ● Active                    [View] [Delete]   │   │ │
│  │  └────────────────────────────────────────────────────────┘   │ │
│  │                                                                │ │
│  │  ┌────────────────────────────────────────────────────────┐   │ │
│  │  │ 🟢 CI/CD Pipeline                  external             │   │ │
│  │  │   Approved by: admin · Mar 10 · Secrets: 5             │   │ │
│  │  │   Suite: ● Active (CSR)              [View] [Delete]   │   │ │
│  │  └────────────────────────────────────────────────────────┘   │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.10 Admin Settings

```
┌─────────────────────────────────────────────────────────────────────┐
│  Administration > Keepiq                                           │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌── CnVersionInfoCard ──────────────────────────────────────────┐ │
│  │  Keepiq v0.1.0                                  [Check for   │ │
│  │  Encrypted secrets manager                        updates]    │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  ┌── CnSettingsSection: Certificate Authority ────────────────────┐ │
│  │                                                                │ │
│  │  Status: ● Healthy                                             │ │
│  │                                                                │ │
│  │  Root Certificate                                              │ │
│  │    Created: 2026-03-23 · Expires: 2046-03-23                  │ │
│  │    Fingerprint: SHA256:AB:CD:EF:12:34:...                     │ │
│  │                                                                │ │
│  │  Intermediate Certificate (active)                             │ │
│  │    Created: 2026-03-23 · Expires: 2029-03-23                  │ │
│  │    Suites signed: 24                                           │ │
│  │    Auto-renew: ☑ enabled                                       │ │
│  │                                                                │ │
│  │  [Force Renew Intermediate]  [View Certificate Chain]          │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  ┌── CnSettingsSection: Master Password Policy ───────────────────┐ │
│  │                                                                │ │
│  │  Minimum length:  [────●──────────] 12 characters              │ │
│  │                   (range: 12–20)                               │ │
│  │                                                                │ │
│  │  Minimum strength: [Score 3 (Strong) ▾]                        │ │
│  │                    Score 3 = resists online attacks             │ │
│  │                    Score 4 = resists offline attacks            │ │
│  │                                                                │ │
│  │  Default session timeout: [Nextcloud session ▾]                │ │
│  │                                                                │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  ┌── CnSettingsSection: Applications ─────────────────────────────┐ │
│  │                                                                │ │
│  │  3 pending · 4 active · 0 rejected                             │ │
│  │                                                                │ │
│  │  [Manage Applications →]                                       │ │
│  │                                                                │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Component hierarchy:**
```
CnVersionInfoCard (app-name="Keepiq", app-version="0.1.0")
CnSettingsSection (name="Certificate Authority", doc-url="...")
  └─ CA status display, certificate details, action buttons
CnSettingsSection (name="Master Password Policy")
  └─ Length slider, score dropdown, session timeout dropdown
CnSettingsSection (name="Applications")
  └─ Application count summary, link to management page
```

### 3.11 User Settings Dialog (NcAppSettingsDialog)

```
┌─────────────────────────────────────────────┐
│  Keepiq Settings                     [✕]   │
│                                             │
│  ┌─── Security ──────────────────────┐      │
│  │                                    │     │
│  │  Session timeout:                  │     │
│  │  [30 minutes ▾]                    │     │
│  │                                    │     │
│  │  Options:                          │     │
│  │  • Nextcloud session duration      │     │
│  │  • 10 minutes                      │     │
│  │  • 30 minutes                      │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
│  ┌─── Notifications ─────────────────┐      │
│  │                                    │     │
│  │  ☑ Secret shared with me          │     │
│  │  ☑ Secret request fulfilled       │     │
│  │  ☑ Group share additions          │     │
│  │  ☑ Security alerts (compromised   │     │
│  │    secrets, suite revocation)      │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
│  ┌─── Display ───────────────────────┐      │
│  │                                    │     │
│  │  Default secret type:              │     │
│  │  [Login ▾]                         │     │
│  │                                    │     │
│  │  Default vault view:               │     │
│  │  [List ▾]                          │     │
│  │  • List (flat)                     │     │
│  │  • Folders (tree sidebar)          │     │
│  │                                    │     │
│  └────────────────────────────────────┘     │
│                                             │
└─────────────────────────────────────────────┘
```

Must use `NcAppSettingsDialog` (NOT `NcDialog`) with `NcAppSettingsSection` for each group. See `openspec/specs/nextcloud-app/spec.md` for the full pattern.

### 3.12 Master Password Change

```
┌─────────────────────────────────────────────────────────────────────┐
│  KEEPIQ > Change Master Password                                   │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                                                                │ │
│  │  Why are you changing your password?                           │ │
│  │                                                                │ │
│  │  ○ Routine password change                                     │ │
│  │    Your encryption keys stay the same.                         │ │
│  │    Only the wrapping password changes.                         │ │
│  │                                                                │ │
│  │  ○ My master password was compromised                          │ │
│  │    ⚠ This will generate new encryption keys                    │ │
│  │    and migrate ALL your secrets. During                        │ │
│  │    migration, your vault will be read-only.                    │ │
│  │    Shared link shares will be revoked.                         │ │
│  │                                                                │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                                                                │ │
│  │  Current password:                                             │ │
│  │  ┌─────────────────────────────────────────────────────┐      │ │
│  │  │                                                 👁  │      │ │
│  │  └─────────────────────────────────────────────────────┘      │ │
│  │                                                                │ │
│  │  New password:                                                 │ │
│  │  ┌─────────────────────────────────────────────────────┐      │ │
│  │  │                                                 👁  │      │ │
│  │  └─────────────────────────────────────────────────────┘      │ │
│  │  ████████████████░░  Very Strong (score 4)                     │ │
│  │                                                                │ │
│  │  Confirm new password:                                         │ │
│  │  ┌─────────────────────────────────────────────────────┐      │ │
│  │  │                                                 👁  │      │ │
│  │  └─────────────────────────────────────────────────────┘      │ │
│  │                                                                │ │
│  │                              [ Change Password ]               │ │
│  │                                                                │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```
