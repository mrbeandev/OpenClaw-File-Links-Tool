
## 2026-03-05

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
