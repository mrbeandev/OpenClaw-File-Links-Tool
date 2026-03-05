
## 2026-03-05 (v3.0.5)

fix: increase border visibility across all UI elements

- All dark gray borders replaced with border-white/[opacity] notation
- Auth modal card: border-white/15
- Upload zone dashed border: border-white/20, hover border-white/40
- Progress upload panel: border-white/12
- Select All button: border-white/15
- File cards: border-white/10
- Action button separator: border-t border-white/8
- Toast notification: border-white/15
- File viewer header divider: border-white/10
- File viewer content box: border-white/10
- File viewer Raw/Link buttons: border-white/15
- ZIP viewer header divider: border-white/10
- Text code toolbar: border-white/10

---

## 2026-03-05 (v3.0.4)

feat: complete black & white contrast UI redesign + bigger icons/text

- Complete color theme overhaul: removed all indigo/purple accents, pure B&W + shades of gray
- Tailwind gray palette override changed to neutral pure blacks (no blue tint): gray-900=#0a0a0a, gray-800=#121212, gray-700=#1c1c1c
- Selection color: white on black (was indigo)
- Auth modal: lock icon enlarged to text-7xl (was text-5xl), heading text-3xl, Enter Dashboard button white/black
- Header: logo badge now white square with black cloud icon, h1 text-2xl, "TEMP STORAGE" badge neutral gray
- Upload zone: icon container enlarged w-20 h-20 (was w-16), icon text-4xl white (was text-3xl gray), heading text-xl
- File cards: NEW badge white/black (was indigo), checkboxes updated, file icons text-5xl (was text-4xl), filename text-base bold (was text-sm), action buttons p-2.5 with text-xl icons
- Progress bar: white fill (was indigo), percentage text white
- Loading spinner: white (was indigo)
- Empty state: text-7xl icon opacity-30 (was text-4xl opacity-50)
- File viewer: Download button white/black (was indigo), back button w-12 h-12 with text-2xl chevron
- ZIP tree: hover states use white/rgba instead of indigo, View badges use white border style
- Prism code viewer: background updated to match pure-neutral theme

---

## 2026-03-05 (v3.0.3)

feat: replace ZIP flat-list with collapsible folder tree view

- Built PHP tree data structure from flat ZIP entry paths
- Recursive `renderTree()` function renders nested folders as collapsible nodes
- Folder nodes show toggle chevron + folder icon + item count
- Files show extension-aware icons and inline uncompressed sizes
- "View" buttons retained for viewable text/code/image files
- "Expand all" / "Collapse all" controls added to header toolbar
- Entry count shown in toolbar (e.g. "24 entries")

---

## 2026-03-05 (v3.0.2)

fix: declare env vars in SKILL.md frontmatter to resolve security scanner metadata mismatch

- Added `env` section to frontmatter declaring API_KEY and SERVER_URL with descriptions
- Bumped version to 3.0.2

---

## 2026-03-05 (v3.0.1)

fix: improve SKILL.md to address security scanner concerns

- Added compatibility section declaring required tools and explicit permission model
- Added Permissions & Security Boundaries table
- Every sensitive autonomous action now requires explicit step-by-step user confirmation
- Reordered: Manual Mode (recommended) presented first, Autonomous Mode second
- Removed implicit "clone and run" language; clarified open-source self-hosted nature

---

## 2026-03-05 (v3.0.0)

feat: live upload progress, client-side validation & expanded file type support

- Replaced fake progress bar with real XHR upload tracking (speed, ETA, bytes uploaded/total, %)
- Client-side file type validation: blocked files show instant error toast before any upload attempt
- Expanded allowed file types: archives (tar gz tgz bz2 xz 7z rar zst), images (bmp tiff ico avif heic heif), videos (mp4 mov avi mkv wmv flv webm etc), office docs (doc docx xls xlsx ppt pptx csv rtf odt)
- Rich file card icons: video thumbnail preview, smart icon per file category (archive/pdf/office/text)

---

## 2026-02-02 14:07:56
- Added rich file viewer with syntax highlighting (Prism.js) for code/text and image previews.
- Implemented ZIP archive inspection to browse and view inner files without extraction.
- Enhanced Main Dashboard UI:
    - Added relative timestamps and 'NEW' badges for recent uploads.
    - Redesigned file cards with integrated action bar (View, Download, Copy Link, Delete).
- Updated API instructions and README to reflect new viewing capabilities.
- Fixed PHP warnings and updated gitignore.

---

## 2026-02-02 14:07:56

Robust ZIP Handling & Documentation Updates

- Implemented `class_exists('ZipArchive')` check to prevent server errors when the extension is missing.
- Added a user-friendly UI warning in the viewer if ZIP inspection is unavailable.
- Updated `README.md` and `SKILL.md` to explicitly list `php-zip` as a requirement for full functionality.

---
