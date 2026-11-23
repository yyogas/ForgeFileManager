# Forge File Manager — UI and Interaction Specification

## Style

Clean, professional, flat surfaces. No gradients or decorative color strips at the beginning of cards, buttons, or headers. Consistent rounded corners (6–14 px), a reserved blue accent, muted border colors, medium-contrast text. Icons use a local inline SVG sprite so they render even without external icon services. Dark and light themes share component tokens.

## Core layout

1. **Global header:** Brand, contextual description, notifications, settings, authenticated username with avatar and dropdown.
2. **Centered action sub-header:** New folder, new file, upload, download, copy, move, rename, CHMOD, ZIP, delete, refresh. Disabled when operation cannot apply. Compact on smaller screens.
3. **Navigation:** Full-width breadcrumb navigation without a sidebar or redundant explorer title.
4. **Main panel:** Breadcrumbs, current-folder search, hidden-file toggle, sorting, list/grid, selectable rows, per-item menu, constrained scroll area and 25-item pages.
5. **Status bar:** Current path, file/selection count, server disk usage (unavailable fallback and a visible disclaimer that it is not an account quota), connection status.

## Context menus

- **File:** Open/Edit, Download, Rename, Copy, Move, CHMOD, ZIP, Properties, Delete.
- **Folder:** Open, Rename, Move, CHMOD, ZIP, Properties, Delete empty only.
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
- Removed the visual read-only demo banner while retaining `demo / demo123` on an unconfigured login screen. Backend read-only virtual isolation is not weakened.
- The current PHP API returns at most `FFM_MAX_ENTRIES` (default 2,000) per directory. The UI paginates those returned items; server-side cursor/offset pagination for bigger directories remains a separate backlog task.
