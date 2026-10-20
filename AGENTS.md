# AGENTS.md — SiFM (Simple File Manager)

## What this is

Single-file PHP app: **everything lives in `sifm.php`** (~1,900 lines) — config constants, routing table, session/auth gate, POST handlers, all HTML views (inline, mixed PHP/HTML), the `fm` filesystem class, and the helper functions (top of the file). No Composer, no package.json, no CI (workflows were deleted on purpose).

Other files: `README.md`, `LICENSE`, this file, `check.sh` + `tests/smoke.php` (the automated check), `.htaccess` (Apache leftover — its rewrite rule is commented out; nginx runs the show), and `test.php` — an **untracked scratch pad living in the docroot**, so `http://sifm/test.php` executes it; `check.sh` deliberately does not lint it.

The entrypoint was renamed `index.php` → `sifm.php` (commit `ab632b8`). Any surviving `index.php` reference — a doc, a vhost `index` directive, a hand-typed URL — is a bug.

## Run and verify

- **`./check.sh`** — the whole automated check, in two steps:
  1. `php -l` on `sifm.php` and `tests/*.php` (a parse error is the one failure that takes the site down outright),
  2. `php tests/smoke.php` — renders every action in its own process (46 cases as of 2026-10-19; the table sits in `tests/smoke.php` right above the parent runner).
- **`php tests/smoke.php render <action> <f> [key=value …]`** runs a single case. Reserved keys: `kind=page|bounce|bytes` (expected shape: full document / redirected before output / raw download) and `guest=1` (render without the signed-in seed). The harness is CLI-only (403 for HTTP) and gives every case its own process, because `sifm.php` declares its functions unconditionally — a second `include()` in one process is a fatal redeclaration. A case fails on: any `E_ALL` warning/notice/deprecation, a document truncated by an early exit, a page handed to a signed-out visitor (`guest`) or rendered instead of a redirect (`redirect`), an HTML body where raw bytes were expected (`stream`), and debug markers in the output (`DEBUG —`, `EDITOR DIAGNOSTIC`, `TOKENS —`).
- **Manual:** `https://sifm/` → 302 → `https://sifm/sifm.php?signin` (plain `http://` answers 301 to TLS since the vhost change of 2026-10-19; the local CA needs `curl -k`). nginx vhost `/etc/nginx/conf.d/sifm.conf` (`root /var/www/sifm`, **`index sifm.php`**) → PHP-FPM 8.5 through the shared `snippets/php-site.conf` (Lua .htaccess layer + deny backstop: `.md`, `.sh`, `.ini` and dot-files are denied by nginx, so `README.md` is *not* fetchable over HTTP). PHP code changes need no reload; restart `php8.5-fpm` only after `php.ini` changes.
- CLI PHP is 8.5; the app needs PHP ≥ 8.0 plus `zip` and `shell_exec()`.
- Everything except `?signin` requires the session (credentials are the `SIFM_ADMIN` / `SIFM_PASSWORD` defines at the top of `sifm.php`).

## Architecture facts you would miss

- **Routing is a query flag, not a path:** `sifm.php?<action>&f=<path>` (`url()` builds it; `$action` is the first set flag in `$_GET`, the path is `?f=`). Registering a page touches up to **four** places:
  1. `$actions` (line ~31) — the whitelist. Not listed → `$action === null` → silent redirect home (fires before output, by design).
  2. the `$title` match (~line 940) — the `<title>` / page heading.
  3. the view switch `<?php switch ($action): ?>` (~line 1273) — the HTML. Whitelisted but no `case` → a **loud** alert naming the action, not a silent bounce.
  4. only when it applies: a `case` in the POST dispatch switch (~line 695), and a line in the **view precondition block** (~line 659) if the page targets a file/folder.
- **A view may never call `redirect()`.** By the time a view runs the HTML has started, so the redirect only rides the output buffer and dies the moment the page outgrows it — `headers already sent` in the FPM log, half a page in the browser. Wrong-URL checks belong in the precondition block: `$needs_file` (`file, edit, source, syntax, extract`), `$needs_item` (`chmod, copy, move, rename, delete`), the edit *writable / 2 MB* gates, and the `multi` selection check. Bare `?edit`, `?chmod`, `?multi`, … used to warn-and-stall live; the smoke suite pins every one of them with a `redirect` case.
- **Paths are relative to `SIFM_HOME`** (`$_SERVER['DOCUMENT_ROOT']`), no leading slash. `path()` strips `/`, `.`, `..`; every `$fm->` method prefixes `SIFM_HOME."/"` itself — never pass an absolute path into `$fm->*`. **`url()` runs its item through `path()` too**, so links are canonical: no leading/trailing slash, no `.` segment, and the root folder is a bare `?folder` with no `?f=` at all. The listing action is **`folder`** (renamed from the old `list`).
- **Saving the editor writes the whole textarea**, guarded by mtime: `?edit` posts `data` + `mtime`, and a file that changed on disk since it was opened gets a flash instead of an overwrite (same guard on Find & replace). There are no session slices to corrupt — the old `pager()` reassembly is gone.
- **`message($text, $kind)` codes are 1 = success (also the default), 2 = info, 0 = error** — not booleans.
- **Helpers are plain top-level functions, declared unconditionally** at the top of the file — the whole app is one include, so never wrap them in a condition, a closure assigned inside a branch, or a class body that only loads sometimes: a conditionally declared function fatals at its first call.

## Conventions

- **Frontend:** Bootstrap **5.3.3**, Font Awesome **6.7.2**, highlight.js **11.9**, CodeMirror **6** (editor; falls back to a plain textarea if the modules never load), IBM Plex — all from CDN (jsdelivr for Bootstrap, cdnjs for FA/hljs). Markup is BS5 (`data-bs-toggle`, `data-bs-*`): never reintroduce BS4 attributes (`data-toggle`, `rounded-sm`).
- **The editor is CodeMirror 6, loaded as native ES modules.** It reaches the page through a `<script type="importmap">` in `<head>` built from `$cm`/`$packs` in PHP, and the page's `<script type="module">` imports **bare specifiers only** (`@codemirror/state`, not a URL). Every entry is `esm.sh` with `?deps=…` pinned to the same exact versions — that is what makes the whole graph **single-instance**. Do not switch back to a per-package CDN bundle (`jsdelivr/+esm`): each one inlines its own `@codemirror/state`, and the editor dies at mount with *"Unrecognized extension value … multiple instances of @codemirror/state"*. Add a package by adding it to one of those two PHP arrays; a caret range sneaking in anywhere reintroduces the duplicate. `lang()` (PHP) picks the grammar family; curated packs map 1:1, `sh`/`ini`/`clike`/`ruby`/`perl` use `@codemirror/legacy-modes` stream parsers, everything else is plain text.
- **The editor's colours come from the design tokens, twice.** A `$ed` PHP map feeds `EditorView.theme()` / `HighlightStyle`; the CSS custom properties in `:root`/`[data-bs-theme]` stay the single source. Theme flips go through `window.__retheme(dark)`, which the header's `flipTheme()` calls, and reconfigure a `Compartment` — never reload the page to re-theme. The `<textarea id="editor">` is still the form's only field: an update listener mirrors every edit into it, and if the module throws the `catch` unhides the textarea and hides the editor div (`window.__err` carries the stack).
- **Dark mode rides `data-bs-theme` on `<html>`** (`SIFM_THEME` ← the `sifm_theme` cookie). There are no per-input dark classes — never repeat `bg-dark text-white` on form controls and never add a PHP ternary just to colour an input; pick a Bootstrap class and let the attribute theme it.
- **Bespoke CSS stays minimal:** re-skin Bootstrap through `--bs-*` variables first (`btn-ghost` is the pattern); custom classes only for what Bootstrap has no primitive for (site header, breadcrumbs, icon buttons, login card, editor box). When Bootstrap does have the component — sidebar, dialog, nav — use it (an admin sidebar belongs in an `offcanvas`), don't invent one.
- `redirect()` is always post-POST (PRG): a refresh must never repost a body. Handlers end with `message(...)` + `redirect(...)` before any output.
- Regenerate `SIFM_PASSWORD` with `php -r "echo password_hash('yourpass', PASSWORD_DEFAULT), PHP_EOL;"` and paste it into the `define()` at the top of `sifm.php`.
- README is correct (it says `sifm.php`) — trust the code, not old notes claiming the entrypoint is `index.php`.
