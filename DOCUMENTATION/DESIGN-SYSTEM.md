# Forge File Manager — UI and Interaction Specification

## Style

Clean, professional, flat surfaces. No gradients or decorative color strips at the beginning of cards, buttons, or headers. Consistent rounded corners (6–14 px), a reserved blue accent, muted border colors, medium-contrast text. Icons use a local inline SVG sprite so they render even without external icon services. Dark and light themes share component tokens.

## Core layout

1. **Global header:** Brand, contextual description, notifications, settings, authenticated username with avatar and dropdown.
2. **Centered action sub-header:** New folder, new file, upload, download, copy, move, rename, CHMOD, ZIP, recycle bin, move to trash, refresh. Disabled when operation cannot apply. Compact on smaller screens.
3. **Navigation:** Full-width breadcrumb navigation without a sidebar or redundant explorer title.
4. **Main panel:** Breadcrumbs, current-folder search, hidden-file toggle, sorting, list/grid, selectable rows, per-item menu, constrained scroll area and 25-item pages.
5. **Status bar:** Current path, file/selection count, server disk usage (unavailable fallback and a visible disclaimer that it is not an account quota), connection status.

## Context menus

- **File:** Open/Edit, Download, Rename, Copy, Move, CHMOD, ZIP, Properties, Move to recycle bin (when enabled).
- **Folder:** Open, Rename, Move, CHMOD, ZIP, Properties, Move to recycle bin (including non-empty folders, when enabled).
- **Blank area:** New folder, New file, Upload, Refresh.
- **Symlinks:** Properties only (access blocked).

Use `contextmenu` event, viewport-constrained placement, keyboard context-menu key and `Shift+F10`. A per-row three-dot button supports touch and pointer devices. Multi-selection is supported, but destructive bulk actions are not yet offered by the backend.

## CHMOD interaction

Numeric octal permission state is derived from a nine-checkbox matrix. `Owner`, `Group` and `Others` each have `Read`, `Write`, `Execute`. The symbolic representation is displayed in parallel. World-writable modes show a warning and are disabled server-side by default. Only a selected file/folder is modified; no recursive mode, ownership edits or ACLs are claimed.

## Modals

Modals use an accessible dialog role and Escape-to-close. New folder/new file/rename use a common prompt; delete has explicit confirmation. The text editor handles Ctrl/Cmd+S. Save conflicts are checked using a SHA-256 value. A full focus trap and full WCAG audit remain to be implemented.

## Responsive

On narrow screens the toolbar prioritizes icons and stays horizontally scrollable; no sidebar/drawer exists. Less important file columns disappear. Keyboard access and accessible focus states are included; formal mobile/touch and screen-reader tests remain required.

## v0.2 contextual UX

The Copy action supports files and folders when `me.capabilities.copyDirectories` is true. The action prompts for a destination relative to the hosting home and communicates naming conflicts through automatic ` (copy)` suffixes. Properties for regular files include an opt-in SHA-256 action; hashing is not automatic. Upload drag/drop and picker display a local size warning, but server policy is authoritative. Context menus expose `menuitem` semantics with Arrow Up/Down/Home/End keyboard traversal. Focus trapping and a full WCAG screen-reader audit are still outstanding.

## v0.2.3 — full-width explorer and pagination

- Removed the sidebar, the "FILE EXPLORER" eyebrow, and the "My files" heading. Breadcrumbs remain the authoritative navigation.
- Centered the action toolbar; on mobile it becomes a horizontally scrollable strip.
- Added client-side pagination: 25 visible files/folders per page after search/hidden filters/sort, numbered pages, previous/next, range indicator, reset on navigation/filter/sort, selection scoped to the current page.
- Vertical scroll bar stays inside the file listing rather than scrolling the whole page. Table headers are sticky.
- Relocated the server-volume disk meter to the footer. If the server cannot provide usage, show "Unavailable" instead of inventing quota metrics.
- Removed the visual read-only demo banner while retaining `demo / demo123` as an explicitly local-test-only hint on the login screen. The previous virtual demo backend is removed.
- Historical limitation resolved in v0.2.4: earlier responses returned at most `FFM_MAX_ENTRIES` entries. See server-side pagination below.

## v0.2.4 — server pagination and navigation

- The browser never holds more than the current 25 server rows. Search, sort and hidden-file filters execute on the server across the full directory.
- The selection is cleared when paging or changing filters; selecting all affects only the current 25 entries.
- Pagination includes previous/next, page numbers, range indicator and a direct jump field for folders longer than 7 pages.
- Search requests are debounced (200 ms). Responses for superseded searches/pages are ignored instead of overwriting newer results.
- The existing full-width UI, centered toolbar, sticky table header, vertical scroll area, footer storage meter and English/French support remain unchanged.

## v0.2.5 — recoverable delete / restore UX

- Toolbar: always display the Recycle bin button but disable it when the private trash store is unavailable.
- File/directory context menu and Delete key: offer "Move to recycle bin" only when the API advertises `capabilities.trash=true`.
- Confirmation describes a recoverable move; never claim permanent deletion or direct trash availability without the actual backend capability.
- Recycle bin modal lists original paths, deletion time, restore actions and paging at 25 entries per screen.
- Restore reports collisions, missing parent directories and server errors; no implicit overwrite or silent rename of restored files.
- The UI retains no sidebar, has a centered toolbar, footer volume statistics and bounded server pagination.

## v0.2.6 — archive extraction and multiple selected items

When one valid `.zip` is selected and the server advertises `zipExtract`, the centered action sub-header enables **Extract** and the file's right-click menu includes **Extract to folder**. A confirmation explains that extraction creates a new folder, does not overwrite files, and has a 100 MiB limit. Without ZIP support, the action is disabled/absent.

With 2–25 ordinary files/folders selected, the **Copy** and **Move** header actions prompt once for a destination. The backend returns item-level results; the UI reports the success and failure counts and displays individual failures. Single selection retains the existing operation. Do not present bulk moves as atomic/transactional.
