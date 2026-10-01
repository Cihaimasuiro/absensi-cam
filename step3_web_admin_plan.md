# Step 3 — Web Admin Panel Build Plan

> **Status:** Awaiting approval  
> **Tech:** Blade + Alpine.js + Tailwind CSS v4 (already in `app.css`)  
> **Goal:** Port Facenox Electron/React UI → Laravel server-rendered Blade with thin Alpine.js sprinkles

---

## What makes Facenox work (the pieces worth copying)

Facenox has **5 distinct UI surfaces**. Here's what each does and how it maps to Laravel:

| Facenox Surface | File(s) | What it does | Laravel equivalent |
|---|---|---|---|
| **Live Scanner** | `main/index.tsx` | WebSocket → canvas face overlays, camera feed, recognition events | ❌ **Skip** — this runs on the edge C++ engine, not the admin PC |
| **Overview / Dashboard** | `group/sections/Overview.tsx` | Stats cards (present today, late, absent), live activity log with date filter | ✅ Blade + Alpine.js (poll via `fetch`) |
| **Members** | `group/sections/Members.tsx` | Searchable member table, enrollment status badge, multi-select, filter by enrolled/non-enrolled | ✅ Blade table + Alpine.js search/filter |
| **Enrollment** | `enrollment/FaceCapture.tsx` | Camera capture OR file upload, send frames to Python backend for embedding | ✅ Blade modal — `<input type=file>` upload → POST → queue job `face-embed` |
| **Reports** | `group/sections/Reports.tsx` | Date-range picker, attendance table (check-in/out, hours, status, late flag), CSV export | ✅ Blade + server-side filter, queue `.xlsx`/PDF export |
| **Settings → Attendance** | `settings/sections/Attendance.tsx` | Cooldown, late threshold, confidence threshold, mode (in/out/both) | ✅ Blade form → PATCH `/groups/{id}` |
| **Settings → Sync/Database** | `settings/sections/Sync.tsx` | Cloud pair status, enrolled count per group, time authority | ✅ Blade → Device dashboard (heartbeat table, pairing codes) |
| **Settings → Audit Log** | `AuditLogExportModal.tsx` | Export spatie/activitylog | ✅ Blade → `activity_log` query + CSV download |

**What to skip entirely (Electron-only):**
- Window chrome (`WindowBar`, titlebar controls) — browser handles this
- `main/index.tsx` live camera canvas — edge terminal's job, not admin
- `SoundEffectsService`, display brightness, notification sounds
- `BulkEnrollment.tsx` (direct-from-camera at edge) — upload-only in admin

---

## Pages to build (7 pages)

| # | Route | Blade view | Facenox source copied from |
|---|---|---|---|
| 1 | `GET /` → dashboard | `views/dashboard/index.blade.php` | `Overview.tsx` — stats cards + activity log |
| 2 | `GET /members` | `views/members/index.blade.php` | `Members.tsx` — table, search, filter |
| 3 | `GET /members/create` | `views/members/create.blade.php` | `Members.tsx` → add member form |
| 4 | `GET /members/{id}/enroll` | `views/members/enroll.blade.php` | `FaceCapture.tsx` — upload mode only |
| 5 | `GET /devices` | `views/devices/index.blade.php` | `settings/sections/Sync.tsx` — pairing, status |
| 6 | `GET /reports` | `views/reports/index.blade.php` | `Reports.tsx` — date filter, table, export |
| 7 | `GET /organizations` | `views/organizations/index.blade.php` | (no direct facenox equiv — our addition for org/group/branch CRUD) |

Plus the **shared layout** (`views/layouts/app.blade.php`) — sidebar structure from `settings/Sidebar.tsx`.

---

## CSS / design tokens to copy from Facenox

Facenox uses a dark theme with CSS custom properties in `index.css`. These map directly to CSS variables we add to `app.css`:

```css
/* From facenox app/src/index.css — adapted for Tailwind v4 @theme */
--bg-primary: #0f1117;      /* main background */
--bg-secondary: #161b27;    /* sidebar, cards */
--bg-tertiary: #1e2536;     /* inputs, rows */
--border-primary: #2a3245;  /* dividers */
--text-primary: #e8eaf0;    /* headings */
--text-secondary: #8b92a8;  /* labels, muted */
--accent-primary: #3b82f6;  /* blue buttons */
--accent-success: #22c55e;  /* recognized/green */
--accent-danger: #ef4444;   /* spoof/error/red */
--accent-warning: #f59e0b;  /* late/amber */
```

---

## Alpine.js interactions to copy (no React, just sprinkles)

| Interaction | Facenox equivalent | Alpine.js version |
|---|---|---|
| Member search filter | `useState memberSearch` in Members.tsx | `x-data="{ search: '' }"` + `x-show` on rows |
| Enrolled/non-enrolled filter | `enrollmentFilter` state | `x-data="{ filter: 'all' }"` |
| Date filter (today/yesterday/week) | `DateFilter` in Overview.tsx | `x-data="{ range: 'today' }"` + form submit |
| Flash/toast messages | Floating alerts | `x-data="{ show: true }"` + auto-dismiss |
| Enrollment modal (camera/upload toggle) | `source` state in FaceCapture.tsx | `x-data="{ source: 'upload' }"` |
| Pairing code generate + copy | Sync.tsx | `x-data="{ code: '' }"` + clipboard |

---

## Files to create

```
resources/
├── css/
│   └── app.css                          ← add CSS variables from facenox
├── views/
│   ├── layouts/
│   │   └── app.blade.php                ← sidebar from Sidebar.tsx
│   ├── dashboard/
│   │   └── index.blade.php              ← Overview.tsx stats + activity log
│   ├── members/
│   │   ├── index.blade.php              ← Members.tsx table
│   │   ├── create.blade.php             ← add member form
│   │   └── enroll.blade.php             ← FaceCapture.tsx upload mode
│   ├── devices/
│   │   └── index.blade.php              ← Sync.tsx device table + pair modal
│   ├── reports/
│   │   └── index.blade.php              ← Reports.tsx date filter + table
│   └── organizations/
│       └── index.blade.php              ← org/group/branch CRUD
└── js/
    └── app.js                           ← import Alpine.js

app/Http/Controllers/Web/
├── DashboardController.php
├── MemberController.php
├── DeviceController.php
├── ReportController.php
└── OrganizationController.php

routes/web.php                           ← add web routes
```

---

## Build order

1. `app.css` — dark theme CSS variables (5 min)
2. `layouts/app.blade.php` — sidebar + flash messages (15 min)
3. `dashboard/index.blade.php` + `DashboardController` (20 min)
4. `members/index.blade.php` + `MemberController` (20 min)
5. `members/create.blade.php` + `members/enroll.blade.php` (20 min)
6. `devices/index.blade.php` + `DeviceController` (15 min)
7. `reports/index.blade.php` + `ReportController` (20 min)
8. `organizations/index.blade.php` + `OrganizationController` (15 min)
9. `routes/web.php` — wire all routes (5 min)

**Total estimated: ~2 hours of coding**

---

## What is NOT being copied

| Facenox feature | Reason skipped |
|---|---|
| Live camera canvas in admin | Edge terminal's job |
| `framer-motion` animations | CSS transitions sufficient; no JS animation lib |
| Zustand stores (`useGroupStore`, etc.) | Server-rendered; no client state store needed |
| Electron IPC (`window.facenoxElectron`) | Browser context, not desktop app |
| Python FastAPI service calls from admin | Laravel calls `face-embed` CLI via queue job |
| `WebSocketService` | Edge pushes via REST; no WebSocket in admin for now |
| `BulkEnrollment.tsx` (bulk capture at edge) | Upload-only in web admin per PRD |

---

> **Ready to proceed?** Click Proceed to start building all 9 items above in order.
