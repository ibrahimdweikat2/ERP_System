# Graph Report - .  (2026-09-22)

## Corpus Check
- 96 files · ~63,925 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 492 nodes · 1745 edges · 21 communities (14 shown, 7 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Operations Pages
- Shared UI and Purchasing
- App Shell and API Client
- Auth Navigation and Security
- Accounting Master Data
- TS App Config
- Inventory Stock Types
- TS Node Config
- Runtime Dependencies
- Dev Dependencies
- Package Scripts
- Lint Config
- E2E Test Credentials
- TS Project References
- React DOM
- Prettier
- Node Types
- TypeScript
- Foundation E2E

## God Nodes (most connected - your core abstractions)
1. `api()` - 100 edges
2. `useAuth()` - 86 edges
3. `react` - 49 edges
4. `ErrorNotice()` - 49 edges
5. `money()` - 48 edges
6. `Loading()` - 42 edges
7. `useCommand()` - 42 edges
8. `ApiEnvelope` - 39 edges
9. `useRecord()` - 35 edges
10. `DataTable()` - 33 edges

## Surprising Connections (you probably didn't know these)
- `RequireAuth()` --calls--> `useAuth()`  [EXTRACTED]
  src/App.tsx → src/lib/auth/context.ts
- `Permit()` --calls--> `useAuth()`  [EXTRACTED]
  src/App.tsx → src/lib/auth/context.ts
- `MasterEditor()` --calls--> `api()`  [EXTRACTED]
  src/features/accounting/MasterPage.tsx → src/lib/api/client.ts
- `PolicyForm()` --calls--> `useCommand()`  [EXTRACTED]
  src/features/operations/OperationsSettingsPage.tsx → src/features/operations/shared.tsx
- `ApprovalDetail()` --calls--> `useCommand()`  [EXTRACTED]
  src/features/operations/OperationsSettingsPage.tsx → src/features/operations/shared.tsx

## Import Cycles
- None detected.

## Communities (21 total, 7 thin omitted)

### Community 0 - "Operations Pages"
Cohesion: 0.06
Nodes (109): CheckFollowup(), FeeForm(), FollowupForm(), Batch, BatchDetail(), Check, CheckDepositsPage(), CheckDetail() (+101 more)

### Community 1 - "Shared UI and Purchasing"
Cohesion: 0.06
Nodes (78): react, Column, DataTable(), Filters(), Pagination(), ApprovalDialog(), MoneyInput(), PrintDialog() (+70 more)

### Community 2 - "App Shell and API Client"
Cohesion: 0.06
Nodes (45): App(), client, Permit(), RequireAuth(), LoginPage(), MfaChallenge, ProductsPage(), ApprovalsPage() (+37 more)

### Community 3 - "Auth Navigation and Security"
Cohesion: 0.08
Nodes (24): AuthProvider(), NavGroup, navigation, NavItem, ErpShell(), icons, AccountSecurityPage(), Approval (+16 more)

### Community 4 - "Accounting Master Data"
Cohesion: 0.10
Nodes (25): blankLine(), EntryEditor(), labels, Mapping, MappingsPage(), account, active, arabic (+17 more)

### Community 5 - "TS App Config"
Cohesion: 0.08
Nodes (23): DOM, src, vite/client, compilerOptions, allowArbitraryExtensions, allowImportingTsExtensions, erasableSyntaxOnly, jsx (+15 more)

### Community 6 - "Inventory Stock Types"
Cohesion: 0.17
Nodes (19): SerialOption, SerialSelector(), blank(), LineForm, localDate(), StockDocumentEditor(), StockPrint(), Store (+11 more)

### Community 7 - "TS Node Config"
Cohesion: 0.10
Nodes (19): node, vite.config.ts, compilerOptions, allowImportingTsExtensions, erasableSyntaxOnly, lib, module, moduleDetection (+11 more)

### Community 8 - "Runtime Dependencies"
Cohesion: 0.11
Nodes (19): decimal.js, @fontsource/ibm-plex-sans-arabic, @fontsource/noto-sans-arabic, lucide-react, dependencies, decimal.js, @fontsource/ibm-plex-sans-arabic, @fontsource/noto-sans-arabic (+11 more)

### Community 9 - "Dev Dependencies"
Cohesion: 0.12
Nodes (17): oxlint, devDependencies, oxlint, @playwright/test, tailwindcss, @tailwindcss/vite, @types/react, @types/react-dom (+9 more)

### Community 10 - "Package Scripts"
Cohesion: 0.20
Nodes (9): name, private, scripts, build, dev, lint, preview, type (+1 more)

### Community 11 - "Lint Config"
Cohesion: 0.22
Nodes (8): plugins, rules, react/only-export-components, react/rules-of-hooks, $schema, oxc, typescript, warn

## Knowledge Gaps
- **152 isolated node(s):** `$schema`, `typescript`, `oxc`, `react/rules-of-hooks`, `warn` (+147 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **7 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `api()` connect `App Shell and API Client` to `Operations Pages`, `Shared UI and Purchasing`, `Auth Navigation and Security`, `Accounting Master Data`, `Inventory Stock Types`?**
  _High betweenness centrality (0.094) - this node is a cross-community bridge._
- **Why does `useAuth()` connect `Operations Pages` to `Shared UI and Purchasing`, `App Shell and API Client`, `Auth Navigation and Security`, `Accounting Master Data`, `Inventory Stock Types`?**
  _High betweenness centrality (0.070) - this node is a cross-community bridge._
- **Why does `react` connect `Shared UI and Purchasing` to `Operations Pages`, `App Shell and API Client`, `Auth Navigation and Security`, `Accounting Master Data`, `Inventory Stock Types`, `Lint Config`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **What connects `$schema`, `typescript`, `oxc` to the rest of the system?**
  _152 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Operations Pages` be split into smaller, more focused modules?**
  _Cohesion score 0.06349862258953168 - nodes in this community are weakly interconnected._
- **Should `Shared UI and Purchasing` be split into smaller, more focused modules?**
  _Cohesion score 0.06320081549439348 - nodes in this community are weakly interconnected._
- **Should `App Shell and API Client` be split into smaller, more focused modules?**
  _Cohesion score 0.0593990216631726 - nodes in this community are weakly interconnected._