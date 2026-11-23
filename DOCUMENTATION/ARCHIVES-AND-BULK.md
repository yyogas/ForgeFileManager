# ZIP extraction and multi-selection — v0.2.6-alpha

These are **bounded synchronous** operations on the filesystem belonging to a single non-root hosting account. They are not a cross-account file manager, root daemon, backup engine, antivirus, or an asynchronous task queue.

## Secure ZIP extraction

`POST api.php?action=extract` accepts JSON `{ "path": "assets.zip", "directory": "public_html" }` and requires the normal authenticated PHP session, same-origin CSRF header, and an installed PHP `ext-zip`.

The service:

- Accepts an existing, readable `.zip` file inside the account's storage root.
- Rejects absolute paths, `..`, `.`, empty path segments, Windows drive prefixes, backslashes, control bytes, non-UTF-8 names, symlinks and special Unix entries.
- Rejects duplicate names and file/directory conflicts, unsupported compression formats and encrypted entries.
- Enforces **2,000 ZIP entries**, **20 path levels**, **100 MiB compressed archive**, **100 MiB total expanded content**, and **250:1 maximum entry compression ratio**.
- Decompresses in fixed-size chunks to a temporary directory with restrictive permissions (`0700` directories, `0600` files) and validates expected sizes and CRC-32 checksums.
- Publishes into a **new named directory**, for example `assets (extracted)` or `assets (extracted 2)`, never merging with or deliberately overwriting existing account files.
- Attempts to remove temporary staging content on any failure.

**Residual risks:** PHP path operations and `rename` are not provably race-free against malicious concurrent processes with the same Unix identity. Large ZIPs can consume significant disk, CPU and request time even within these limits. The extracting account may write executable server-side code inside its own website, so reverse-proxy/WAF policies, runtime resource constraints and malware-scanning hooks are still required. Extension availability determines whether this capability is advertised.

## Bounded bulk copy and move

`POST api.php?action=bulk-transfer` accepts:

```json
{
  "paths": ["a.txt", "folder-a"],
  "directory": "backups",
  "mode": "copy"
}
```

- Select **1–25** sources from the current page. No duplicate paths, traversal paths, symbolic-link traversal, ancestor-and-descendant selections, or unsupported actions.
- The server validates all source identities and the destination before starting. Each operation uses the existing account-scoped `Filesystem::transfer` policy, including directory-copy limits and collision-free duplication.
- Results explicitly report successes and failures by item, e.g. `{ "success": 1, "failed": 1, "results": [...] }`. **This is NOT atomic**. A failed later item does not roll back successful prior operations.
- Bulk permanent delete, recursive CHMOD, and changing ownership are **not** supported. Use private recycle bin separately for removals.
- Long-running jobs, cancellation, progress percentages, global quotas and durable audit trails are future development items.

## Tests and status

`tests/bulk-transfer.php` verifies copy, move collisions, ancestor/child rejection, duplicate selections and traversal. `tests/bulk-archive-http.sh` verifies authentication, CSRF, batch API and extraction via HTTP. `tests/archive-extraction.php` covers valid nested ZIP archives and malicious fixtures; **the full extraction test requires PHP ext-zip** and must pass in CI before the capability is treated as verified. Offline/local runs without ext-zip print `SKIP` and do not establish ZIP extraction readiness. No production security attestation is claimed.
