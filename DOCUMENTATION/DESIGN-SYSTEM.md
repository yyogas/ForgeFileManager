# Forge File Manager — UI and Interaction Specification

## Style

Clean, professional, flat surfaces. No gradients or decorative color strips at the beginning of cards, buttons, or headers. Consistent rounded corners (6–14 px), a reserved blue accent, muted border colors, medium-contrast text. Icons use a local inline SVG sprite so they render even without external icon services. Dark and light themes share component tokens.

## Core layout

1. **Global header:** Brand, contextual description, notifications, settings, authenticated username with avatar and dropdown.
2. **Action sub-header:** New folder, new file, upload, download, copy, move, rename, CHMOD, ZIP, delete, refresh. Disabled when operation cannot apply. Compact on smaller screens.
3. **Sidebar:** Home and root directory navigation plus clearly labelled host-volume disk usage (not account quota).
4. **Main panel:** Title, breadcrumb path, current-folder search, hidden-file toggle, sorting, list/grid, selectable rows, per-item menu.
5. **Status bar:** Current path, number of files/selection, status.

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

On narrow screens the toolbar prioritizes icons, the sidebar becomes a drawer, and less important file columns disappear. Keyboard access and accessible focus states are included; formal mobile/touch and screen-reader tests remain required.