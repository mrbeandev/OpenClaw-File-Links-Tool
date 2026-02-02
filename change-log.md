
## 2026-02-02 13:59:16

Feature: Rich File Viewer, Zip Inspection, and UI Overhaul

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
