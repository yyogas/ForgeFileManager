# Requested Upstream Source Import — BLOCKED

**Requested location:** `https://download.wcp360.com/?dir=filemanager-master`

**Requested target:** `https://github.com/yyogas/ForgeFileManager`

**Observed in this session (2026-11-23):** The upstream directory could not be retrieved with available HTTP tooling; the destination GitHub repository was empty. The first alpha implementation in this repository was written independently and **must not be described as an import, fork or rewrite of verified upstream content**.

## Required import procedure once source is available

1. Obtain the original upstream archive or browse/download all files from the source directory.
2. Record original URL, retrieval date, SHA-256 hashes, directory tree and file count.
3. Inventory languages, dependencies, bundled third-party components, the license and author attributions.
4. Quarantine potentially sensitive files, passwords, certificates, `.env`, logs and personal data; do not publish secrets.
5. Audit any PHP web entry points for default credentials and remote code execution.
6. Create an immutable original snapshot in a separate `import/upstream-filemanager-master` branch/tag if licensing permits.
7. Compare behavior and interfaces with the independent alpha implementation.
8. Port the approved design behind current contracts; preserve functionality and security controls.
9. Run security, regression and browser E2E tests; only then propose integration into main.

**No blind overwrite of the independent baseline or unreviewed mass import.**