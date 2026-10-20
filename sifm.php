<?php

/**** SiFM — single-file file manager. Configuration. ****/

## Display name / version / session-cookie id
define('SIFM_NAME', 'SiFM');
define('SIFM_VERSION', '2.0');
define('SIFM_ID', 'sifm');

## Admin credentials — regenerate the hash with:
## php -r "echo password_hash('yourpass', PASSWORD_DEFAULT), PHP_EOL;"
define('SIFM_ADMIN', 'Hiswill');
define('SIFM_PASSWORD', '$2y$10$30j49pwoX4mF9uDFCPz2AOY6M1RIrzmjo2LnvEciNQx0A7jYg7ozu');

## Scope of every file operation + host used by "open in browser" links
define('SIFM_HOME', $_SERVER['DOCUMENT_ROOT']);
define('SIFM_HOST', $_SERVER['HTTP_HOST']);
define('SIFM_DATE', 'Y-m-d H:i');

## Largest file the editor opens in one textarea (bytes)
define('SIFM_MAX_EDIT', 2097152);

## Largest file the importer pulls from a URL (bytes)
define('SIFM_MAX_IMPORT', 26214400);

## Soft delete: park deletions in .sifm-trash instead of unlinking them.
## true (default) makes every delete recoverable from the trash page;
## false restores the old behaviour — a delete is immediate and final.
define('SIFM_TRASH', true);

/**** Routing table — a query flag (?action&f=path) picks the page.
 **** A new page registers in three places: here, $title in dispatch, and the
 **** render switch at the bottom. Missing any one silently falls back home. ****/
$actions = [
    'signin', 'signout', 'folder', 'edit', 'source', 'backup', 'copy', 'move',
    'import', 'upload', 'create', 'download', 'delete', 'file', 'chmod',
    'extract', 'rename', 'syntax', 'multi', 'search', 'trash',
];

// Start a file manager instance
$fm = new fm();
/**** Dispatch: pick the page, secure the session, run POST handlers,
 **** then fall through to the HTML renderer. Nothing outputs before this. ****/
$action = null;
foreach ($actions as $flag) {
    if (isset($_GET[$flag]) && is_string($_GET[$flag])) {
        $action = $flag;
        break;
    }
}

session_cache_limiter('nocache');
session_name('SIFM_');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();
csrf(); // mint the token for this session

$f = path(is_string($_GET[$action] ?? null) ? $_GET[$action] : '');

if ($action !== 'signin' && empty($_SESSION[SIFM_ID]['auth'])) {
    redirect('signin');
}
if ($action === null) {
    redirect('folder');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
    message('Your session token expired — please try again.', 2);
    redirect($action, $action === 'signin' ? '' : $f);
}

if(post('multi')){
    $action = 'multi';
}
/* The requested path, as plain strings: "" means "root" or "not this kind". */
$folder = $fm->isfolder($f) ? $f : '';
$file = $fm->isfile($f) ? $f : '';
$file_folder = $file !== '' ? dirname($file) : '';
if ($file === '' && $folder === '' && $f !== '') {
    redirect('folder');
}
/* The listing that is currently open (folder pages) or the file's folder. */
$listing = $file !== '' ? $file_folder : $folder;
/* The item a row action works on — a file page wins over its folder. */
$target = $file !== '' ? $file : $folder;

/**** View preconditions — by the time a view runs the HTML has started, so
 **** a redirect() there rides the output buffer and dies the moment the page
 **** outgrows it ("headers already sent" in the FPM log, half a page in the
 **** browser). Every "this URL makes no sense" redirect therefore lives
 **** here, before the first byte: views may only ever render. ****/
$needs_item = ['chmod', 'copy', 'move', 'rename', 'delete'];
$needs_file = ['file', 'edit', 'source', 'syntax', 'extract'];
if (in_array($action, $needs_item, true) && $target === '') {
    redirect('folder');
}
if (in_array($action, $needs_file, true) && $file === '') {
    redirect('folder');
}
if ($action === 'edit') {
    if (!$fm->writable($file)) {
        message('File is not writable — chmod it first, then edit.', 0);
        redirect('file', $file);
    }
    $size = (int) @filesize(SIFM_HOME . '/' . $file);
    if ($size > SIFM_MAX_EDIT) {
        message('Too large for the editor (2 MB limit) — use Source or download it.', 2);
        redirect('file', $file);
    }
}
if ($action === 'multi') {
    $kind = post('action');
    $items = array_values(array_filter((array) ($_POST['items'] ?? []), 'is_string'));
    if (!in_array($kind, ['backup', 'copy', 'move', 'chmod', 'delete', 'download'], true) || !$items) {
        message('Nothing selected.', 2);
        redirect('folder', $folder);
    }
}

/**** POST handlers — each processes only if its own button was pressed,
 **** then redirects (PRG), so a refresh never repeats an action. ****/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {

    case 'signin':
        sleep(1); // throttle brute force
        if (hash_equals(SIFM_ADMIN, post('username')) && password_verify(post('password'), SIFM_PASSWORD)) {
            session_regenerate_id(true);
            $_SESSION[SIFM_ID]['auth'] = SIFM_ADMIN;
            message('You are now signed in!');
            redirect('folder');
        }
        message('Sign in failed — invalid username or password.', 0);
        break;

    case 'backup':
        if (post('bk') !== '') {
            $name = path(post('n'));
            if ($fm->backup($folder, $name)) {
                message('Backup created successfully!');
            } else {
                message('Backup failed.', 0);
            }
            redirect('folder', $folder);
        }
        break;

    case 'chmod':
        if (post('mod') !== '') {
            if ($fm->chmod($target, trim(post('mod')))) {
                message('Chmod successful!');
            } else {
                message('Chmod failed — use 3–4 octal digits like 644.', 0);
            }
            redirect('folder', dirname($target));
        }
        break;

    case 'copy':
        if (post('copy') !== '') {
            if ($fm->copy($target, path(post('new')))) {
                message('Copy successful!');
            } else {
                message('Failed to copy.', 0);
            }
            redirect('folder', dirname($target));
        }
        break;

    case 'create':
        if (post('create') !== '') {
            $name = path(post('i'));
            if ($name === '') {
                message('Invalid name.', 0);
            } elseif ($fm->create(($listing !== '' ? $listing . '/' : '') . $name, post('folder') === '1')) {
                message('Creation successful!');
            } else {
                message('Failed to create.', 0);
            }
            redirect('folder', $listing);
        }
        break;

    case 'delete':
        if (post('del') !== '') {
            $done = keeps() ? $fm->trash($target) : $fm->delete($target);
            if ($done) {
                message(keeps() ? 'Moved to trash — restore it from the trash page.' : 'Deletion successful!');
            } else {
                message('Deletion failed.', 0);
            }
            redirect('folder', dirname($target));
        }
        break;

    case 'trash':
        /* Restore one entry, or empty the whole trash for good. */
        if (post('restore') !== '') {
            $id = trim(post('id'));
            if ($fm->restore($id)) {
                message('Restored!');
            } else {
                message('Restore failed — something already occupies that path.', 0);
            }
            redirect('trash');
        }
        if (post('purge') !== '') {
            $id = trim(post('id'));
            if ($fm->purge($id !== '' ? $id : null)) {
                message($id !== '' ? 'Entry destroyed.' : 'Trash emptied.');
            } else {
                message('Could not empty the trash.', 0);
            }
            redirect('trash');
        }
        break;

    case 'extract':
        if (post('ex') !== '') {
            if ($file === '') {
                redirect('folder');
            }
            if ($fm->extract($file, path(post('u')))) {
                message('Extraction successful!');
            } else {
                message('Extraction failed.', 0);
            }
            redirect('folder', dirname($file));
        }
        break;

    case 'import':
        if (post('url') !== '') {
            $name = basename(trim(post('name')) ?: (string) (parse_url(post('url'), PHP_URL_PATH) ?: 'imported')) ?: 'imported';
            $why  = $fm->import(trim(post('url')), ($folder !== '' ? $folder . '/' : '') . $name);
            $ok   = $why === null;
            json_out(['ok' => $ok, 'msg' => $ok ? "Imported {$name}!" : $why]);
            message($ok ? "Imported {$name}!" : $why, $ok ? 1 : 0);
            redirect('folder', $folder);
        }
        break;

    case 'move':
        if (post('move') !== '') {
            if ($fm->move($target, path(post('new')))) {
                message('Moving successful!');
            } else {
                message('Moving failed.', 0);
            }
            redirect('folder', dirname($target));
        }
        break;

    case 'rename':
        if (post('ren') !== '') {
            if ($fm->rename($target, trim(post('new')))) {
                message('Renaming successful!');
            } else {
                message('Renaming failed — no slashes in the new name.', 0);
            }
            redirect('folder', dirname($target));
        }
        break;

    case 'upload':
        if (post('up') !== '') {
            $files = $_FILES['files'] ?? [];
            $total = count((array) ($files['name'] ?? []));
            $done = 0;
            for ($i = 0; $i < $total; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $one = [
                    'name' => (string) $files['name'][$i],
                    'tmp_name' => (string) $files['tmp_name'][$i],
                ];
                if ($fm->upload($one, $listing)) {
                    $done++;
                }
            }
            $text = $done > 0 ? "Uploaded {$done} of {$total} file(s)." : 'Upload failed.';
            json_out(['ok' => $done > 0, 'msg' => $text]);
            message($text, $done > 0 ? 1 : 0);
            redirect('upload', $listing);
        }
        break;

    case 'edit':
        if ($file === '') {
            redirect('folder');
        }
        if (post('save') !== '') {
            $on_disk = (int) (@filemtime(SIFM_HOME . '/' . $file));
            $fresh = (int) post('mtime') === $on_disk;
            $saved = $fresh && file_put_contents(SIFM_HOME . '/' . $file, is_string($_POST['data'] ?? null) ? $_POST['data'] : '') !== false;
            $text  = !$fresh
                ? 'The file changed on disk since you opened it — reload to get the latest version.'
                : ($saved ? 'Changes saved!' : 'Failed to save changes.');
            json_out(['ok' => $saved, 'msg' => $text, 'mtime' => (int) (@filemtime(SIFM_HOME . '/' . $file))]);
            message($text, $fresh ? ($saved ? 1 : 0) : 2);
            redirect('edit', $file); // PRG: a refresh never reposts the body
        }
        if (post('replace') !== '') {
            $on_disk = (int) (@filemtime(SIFM_HOME . '/' . $file));
            if ((int) post('mtime') !== $on_disk) {
                message('The file changed on disk since you opened it — nothing replaced.', 2);
            } else {
                $new = str_replace(post('from'), post('to'), $fm->source($file));
                if (file_put_contents(SIFM_HOME . '/' . $file, $new) !== false) {
                    message('Phrase replaced!');
                } else {
                    message('Failed to replace.', 0);
                }
            }
            redirect('edit', $file);
        }
        break;

    case 'multi':
        if (post('submit') !== '') {
            $dest = path(post('dest'));
            $ok = false;

            if ($kind === 'download') {
                /* Build a ZIP in the home folder, stream it, delete it. */
                $tmp = '.sifm-' . bin2hex(random_bytes(6)) . '.zip';
                if (!$fm->backup($folder, $tmp, $items)) {
                    message('Could not build the ZIP archive.', 0);
                    redirect('folder', $folder);
                }
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="SiFM-' . date('Ymd-His') . '.zip"');
                header('Content-Length: ' . filesize(SIFM_HOME . '/' . $tmp));
                header('Cache-Control: no-store');
                readfile(SIFM_HOME . '/' . $tmp);
                unlink(SIFM_HOME . '/' . $tmp);
                exit;
            }

            if ($kind === 'backup') {
                $ok = $fm->backup($folder, $dest, $items);
            } else {
                $ok = true;
                foreach ($items as $item) {
                    $item = path($item);
                    if ($item === '') {
                        $ok = false;
                        continue;
                    }
                    $full = ($folder !== '' ? $folder . '/' : '') . $item;
                    $ok = match ($kind) {
                        'copy'   => $fm->copy($full, $dest . '/' . $item),
                        'move'   => $fm->move($full, $dest . '/' . $item),
                        'chmod'  => $fm->chmod($full, $dest),
                        'delete' => keeps() ? $fm->trash($full) : $fm->delete($full),
                    } && $ok;
                }
            }
            message(($ok ? ucfirst($kind) . ' successful!' : ucfirst($kind) . ' failed.'), $ok ? 1 : 0);
            redirect('folder', $folder);
        }
        break; // no "submit": fall through to the confirmation screen below
    }
}

/**** GET-only shortcuts — run before any output so headers still fly. ****/
if ($action === 'download') {
    $fm->download($file);
}
if ($action === 'signout') {
    session_destroy();
    setcookie('SIFM_', '', time() - 3600, '/');
    redirect('signin');
}

/**** Page title (register new pages here too). ****/
$title = match (true) {
    $action === 'signin'    => 'Sign in',
    $action === 'folder'    => 'Folder: ' . ($listing !== '' ? $listing : '/'),
    $action === 'multi'     => ucfirst(post('action', 'items')),
    $action === 'trash'     => 'Trash',
    $action === 'about',
    $action === 'help'      => ucfirst($action),
    default                 => ucfirst($action) . ': /' . ($file !== '' ? $file : $folder),
};

/* Context for the breadcrumb bar. */
$crumbs = $file !== '' ? ['path' => $file_folder, 'leaf' => basename($file)] : ['path' => $folder, 'leaf' => ''];

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &ndash; <?= SIFM_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:ital,wght@0,400;0,700;1,400&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<?php /* CodeMirror 6 arrives through an import map so every bare @codemirror/*
       and @lezer/* specifier resolves to ONE instance. jsdelivr's +esm bundles
       each inline their own copy of @codemirror/state, which breaks the
       editor's instanceof checks; esm.sh with ?deps= pinned to exact versions
       emits absolute, shared dependency URLs instead — that is what keeps the
       whole graph single-instance. Every package's deps list must name the same
       versions, or a caret range sneaks a second copy back in. */
$deps = '@codemirror/state@6.7.6,@codemirror/view@6.43.15,@codemirror/language@6.13.1,'
    . '@lezer/common@1.5.3,@lezer/highlight@1.2.5,@lezer/lr@1.4.2,@lezer/php@1.0.2';
$cm = [
    '@codemirror/state'   => '6.7.6',
    '@codemirror/view'    => '6.43.15',
    '@codemirror/language' => '6.13.1',
    '@codemirror/commands' => '6.11.1',
    '@codemirror/search'  => '6.7.2',
    '@lezer/highlight'    => '1.2.5',
    '@lezer/common'       => '1.5.3',
    '@lezer/lr'           => '1.4.2',
    '@lezer/php'          => '1.0.2',
];
$packs = [
    '@codemirror/lang-php'        => '6.0.2',
    '@codemirror/lang-html'       => '6.4.12',
    '@codemirror/lang-javascript' => '6.2.5',
    '@codemirror/lang-css'        => '6.3.1',
    '@codemirror/lang-json'       => '6.0.2',
    '@codemirror/lang-markdown'   => '6.5.2',
    '@codemirror/lang-python'     => '6.2.1',
    '@codemirror/lang-sql'        => '6.10.0',
    '@codemirror/lang-xml'        => '6.1.0',
    '@codemirror/lang-yaml'       => '6.1.3',
];
$url = static fn(string $pkg, string $ver, string $extra = ''): string
    => "https://esm.sh/{$pkg}@{$ver}{$extra}";
/* Everything that must exist exactly once, as bare specifiers. Passing this to
   ?external= stops a package from resolving its own copy: the emitted import
   stays bare and lands on the SAME instance my module and every other package
   use. A grammar that resolves @lezer/highlight on its own attaches tag objects
   from a second copy, and highlightingFor() then answers null for every node —
   the editor mounts and shows plain text. */
$shared = implode(',', array_keys($cm));
$map = [];
foreach ($cm as $pkg => $ver) {
    $bare = in_array($pkg, ['@codemirror/state', '@lezer/highlight', '@lezer/common', '@lezer/lr', '@lezer/php'], true);
    $map[$pkg] = $url($pkg, $ver, $bare ? '' : "?deps={$deps}&external={$shared}");
}
foreach ($packs as $pkg => $ver) {
    $map[$pkg] = $url($pkg, $ver, "?deps={$deps}&external={$shared}");
}
?>
<script type="importmap">
<?= json_encode(['imports' => $map], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>
</script>
<?php if ($action === 'source'): ?>
<link id="hljs" rel="stylesheet" data-base="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-">
<?php endif; ?>
<script>
/* Theme — one switchboard for the whole page: the attribute Bootstrap's skin
   hangs off, the highlight.js sheet, the header glyph and the editor's
   re-theme hook. The stored choice wins; with none, the OS decides (auto).
   This runs in <head>, before first paint, so nothing ever flashes. */
const sifmTheme = (() => {
  const key = '<?= SIFM_ID ?>_theme';
  const os = matchMedia('(prefers-color-scheme: light)');
  const read = () => {
    const t = localStorage.getItem(key);
    return t === 'dark' || t === 'light' ? t : (os.matches ? 'light' : 'dark');
  };
  const apply = (t = read()) => {
    document.documentElement.dataset.bsTheme = t;
    const hl = document.getElementById('hljs');
    const css = hl && hl.dataset.base + t + '.min.css';
    if (css && hl.getAttribute('href') !== css) hl.href = css;
    const btn = document.getElementById('theme-toggle');
    if (btn) {
      btn.title = 'Switch to ' + (t === 'dark' ? 'light' : 'dark') + ' theme';
      btn.querySelector('i').className = 'fas fa-' + (t === 'dark' ? 'sun' : 'moon');
    }
    if (window.__retheme) window.__retheme(t === 'dark');
  };
  apply();
  os.addEventListener('change', () => { if (localStorage.getItem(key) === null) apply(); });
  return { apply, flip: () => { const n = read() === 'dark' ? 'light' : 'dark'; localStorage.setItem(key, n); apply(n); } };
})();
</script>
<style>
/* ===========================================================================
   SiFM — "Bullion". A dark-first gold-on-graphite theme on Bootstrap 5.3.
   Bootstrap components are re-skinned through --bs-* tokens only; bespoke
   classes exist where Bootstrap has no primitive (header, crumbs, icon-btn,
   login card, code editor). Light mode lifts everything via [data-bs-theme].
   =========================================================================== */
:root, [data-bs-theme="dark"] {
  color-scheme: dark;

  --bs-font-sans-serif: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
  --bs-font-monospace: 'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
  --bs-body-font-family: var(--bs-font-sans-serif);
  --bs-body-line-height: 1.65;

  /* Surfaces — graphite */
  --bs-body-bg: #101216;
  --bs-body-bg-rgb: 16, 18, 22;
  --bs-body-color: #d4d7dd;
  --bs-body-color-rgb: 212, 215, 221;
  --bs-heading-color: #f4f4f3;
  --bs-emphasis-color: #f4f4f3;
  --bs-emphasis-color-rgb: 244, 244, 243;
  --bs-secondary-color: #8b909a;
  --bs-secondary-bg: #171a1f;
  --bs-tertiary-bg: #1c2026;
  --bs-border-color: rgba(255, 255, 255, 0.11);
  --bs-border-color-translucent: rgba(255, 255, 255, 0.07);
  --surface: #171a20;
  --surface-2: #1e222a;

  /* Brand — bullion gold */
  --bs-primary: #f2b33d;
  --bs-primary-rgb: 242, 179, 61;
  --bs-primary-text-emphasis: #f7cd85;
  --bs-primary-bg-subtle: rgba(242, 179, 61, 0.13);
  --bs-primary-border-subtle: rgba(242, 179, 61, 0.32);
  --on-primary: #1d1504;
  --gold: #f2b33d;
  --gold-hover: #e0a12c;
  --gold-active: #cc901f;

  /* Second accent — ember, gradients only */
  --ember: #ff8f3f;
  --ember-rgb: 255, 143, 63;

  /* Editor tokens with no theme-wide equivalent */
  --tok-string: #9fd08a;
  --tok-def: #ffcf80;
  --tok-attr: #ffe3b0;

  --bs-warning: #f59e0b;
  --bs-warning-rgb: 245, 158, 11;
  --bs-success: #46c98b;
  --bs-success-rgb: 70, 201, 139;
  --bs-danger: #ff6b6b;
  --bs-danger-rgb: 255, 107, 107;
  --bs-info: #6aa8ff;
  --bs-info-rgb: 106, 168, 255;

  --bs-link-color-rgb: var(--bs-primary-rgb);
  --bs-link-hover-color-rgb: 247, 205, 133;
  --bs-link-decoration: none;

  --bs-border-radius: 0.7rem;
  --bs-border-radius-sm: 0.45rem;
  --bs-border-radius-lg: 1rem;
  --bs-border-radius-xl: 1.3rem;

  --bs-box-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.4), 0 8px 22px -12px rgba(0, 0, 0, 0.7);
  --bs-box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45), 0 20px 45px -20px rgba(0, 0, 0, 0.8);

  --snap: 0.18s ease;
  --focus: 0 0 0 0.22rem rgba(242, 179, 61, 0.3);
}

[data-bs-theme="light"] {
  color-scheme: light;

  --bs-body-bg: #f7f6f2;
  --bs-body-bg-rgb: 247, 246, 242;
  --bs-body-color: #2a2721;
  --bs-body-color-rgb: 42, 39, 33;
  --bs-heading-color: #17150f;
  --bs-emphasis-color: #000;
  --bs-emphasis-color-rgb: 0, 0, 0;
  --bs-secondary-color: #6f6a5e;
  --bs-secondary-bg: #eeece5;
  --bs-tertiary-bg: #e7e4db;
  --bs-border-color: rgba(23, 21, 15, 0.14);
  --bs-border-color-translucent: rgba(23, 21, 15, 0.08);
  --surface: #ffffff;
  --surface-2: #f1efe8;

  --bs-primary: #9a6f0c;
  --bs-primary-rgb: 154, 111, 12;
  --bs-primary-text-emphasis: #7c5a09;
  --bs-primary-bg-subtle: #f6ebd2;
  --bs-primary-border-subtle: #e6d0a2;
  --on-primary: #ffffff;
  --gold: #9a6f0c;
  --gold-hover: #85600a;
  --gold-active: #715108;

  --ember: #d96a26;
  --ember-rgb: 217, 106, 38;

  /* Editor tokens — dark hexes wash out on white, so light gets its own */
  --tok-string: #2e7d32;
  --tok-def: #9a4d0c;
  --tok-attr: #7a5c14;

  --bs-warning: #b45309;
  --bs-warning-rgb: 180, 83, 9;
  --bs-success: #14855a;
  --bs-success-rgb: 20, 133, 90;
  --bs-danger: #d43d3d;
  --bs-danger-rgb: 212, 61, 61;
  --bs-info: #2563d9;
  --bs-info-rgb: 37, 99, 217;

  --bs-link-color-rgb: 154, 111, 12;
  --bs-link-hover-color-rgb: 113, 81, 8;

  --bs-box-shadow-sm: 0 1px 2px rgba(23, 21, 15, 0.06), 0 8px 20px -12px rgba(23, 21, 15, 0.18);
  --bs-box-shadow: 0 2px 6px rgba(23, 21, 15, 0.08), 0 20px 42px -22px rgba(23, 21, 15, 0.28);

  --focus: 0 0 0 0.22rem rgba(154, 111, 12, 0.25);
}

body {
  min-height: 100vh;
  background-color: var(--bs-body-bg);
  background-image:
    radial-gradient(1000px 520px at 85% -10%, rgba(var(--bs-primary-rgb), 0.09), transparent 60%),
    radial-gradient(800px 460px at -5% -5%, rgba(var(--ember-rgb), 0.06), transparent 55%);
  background-attachment: fixed;
  -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6 { font-weight: 600; letter-spacing: -0.015em; color: var(--bs-heading-color); }
.mono { font-family: var(--bs-font-monospace); }
code { font-family: var(--bs-font-monospace); color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.1); padding: 0.1em 0.4em; border-radius: var(--bs-border-radius-sm); }
::selection { background: rgba(var(--bs-primary-rgb), 0.3); }
a{text-decoration:none;}
a:not(.btn):not(.icon-btn):not(.crumbs a):hover { text-decoration: underline; text-underline-offset: 0.2em; }
/* ---- Buttons: pill shape, gold primary, a quiet ghost variant. ---- */
.btn {
  --bs-btn-border-radius: 999px;
  --bs-btn-font-weight: 600;
  display: inline-flex; align-items: center; justify-content: center; gap: 0.45em;
  transition: transform var(--snap), box-shadow var(--snap), background-color var(--snap), border-color var(--snap), color var(--snap);
}
.btn:active { transform: translateY(1px); }
.btn:focus-visible { box-shadow: var(--focus); }
.btn-primary {
  --bs-btn-color: var(--on-primary);
  --bs-btn-bg: var(--bs-primary);
  --bs-btn-border-color: var(--bs-primary);
  --bs-btn-hover-color: var(--on-primary);
  --bs-btn-hover-bg: var(--gold-hover);
  --bs-btn-hover-border-color: var(--gold-hover);
  --bs-btn-active-color: var(--on-primary);
  --bs-btn-active-bg: var(--gold-active);
  --bs-btn-active-border-color: var(--gold-active);
  --bs-btn-focus-shadow-rgb: var(--bs-primary-rgb);
}
.btn-primary:hover { box-shadow: 0 10px 26px -12px rgba(var(--bs-primary-rgb), 0.7); }
.btn-ghost {
  --bs-btn-color: var(--bs-body-color);
  --bs-btn-bg: transparent;
  --bs-btn-border-color: var(--bs-border-color);
  --bs-btn-hover-color: var(--bs-primary);
  --bs-btn-hover-bg: var(--surface-2);
  --bs-btn-hover-border-color: rgba(var(--bs-primary-rgb), 0.55);
  --bs-btn-active-color: var(--bs-primary);
  --bs-btn-active-bg: var(--surface-2);
  --bs-btn-active-border-color: rgba(var(--bs-primary-rgb), 0.55);
  --bs-btn-focus-shadow-rgb: var(--bs-primary-rgb);
}
.form-control, .form-select { border-radius: 999px; }
textarea.form-control { border-radius: var(--bs-border-radius); }
.form-check-input:focus { border-color: var(--bs-primary); box-shadow: var(--focus); }
.form-check-input:checked { background-color: var(--bs-primary); border-color: var(--bs-primary); }
/* ---- Header: sticky, blurred, brand + icon cluster. ---- */
html { --site-head-h: 3.4rem; }
.site-header {
  position: sticky; top: 0; z-index: 1030;
  height: var(--site-head-h);
  background: rgba(var(--bs-body-bg-rgb), 0.82);
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--bs-border-color);
}
.header-inner { display: flex; align-items: center; gap: 1rem; padding: 0.55rem 1rem; max-width: 1400px; margin: 0 auto; }
.brand { display: inline-flex; align-items: center; gap: 0.6rem; flex: 0 0 auto; }
.brand-mark {
  width: 2.3rem; height: 2.3rem; display: inline-flex; align-items: center; justify-content: center;
  border-radius: 0.75rem; font-family: var(--bs-font-monospace); font-weight: 700; color: var(--on-primary);
  background-image: linear-gradient(135deg, var(--bs-primary), var(--ember));
  box-shadow: 0 8px 22px -10px rgba(var(--bs-primary-rgb), 0.8);
}
.brand-name { font-weight: 700; letter-spacing: -0.01em; color: var(--bs-heading-color); line-height: 1; }
.brand:hover .brand-name { color: var(--bs-primary); text-decoration: none; }
.brand-tagline { font-family: var(--bs-font-monospace); font-size: 0.64rem; letter-spacing: 0.1em; color: var(--bs-secondary-color); }
.header-tools { display: flex; align-items: center; gap: 0.4rem; flex: 0 0 auto; margin-left: auto; }

/* ---- Icon buttons: a ring around a bare glyph. The ring belongs to the base
   class, so every context shares it; each context only sizes its own circles. ---- */
.icon-btn {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 0; background: none; /* the base must survive a <button>: no UA chrome */
  border: 1px solid var(--bs-border-color); border-radius: 999px;
  color: var(--bs-secondary-color);
  transition: color var(--snap), background-color var(--snap), border-color var(--snap);
}
.icon-btn:hover {
  color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.12);
  border-color: rgba(var(--bs-primary-rgb), 0.45); text-decoration: none;
}
/* Dark mode: the 0.11 ring vanishes on the deep background — icon-only
   controls need their outline to read at a glance. */
[data-bs-theme="dark"] .icon-btn { border-color: rgba(255, 255, 255, 0.3); }
/* Header circles are one step bigger than the rows'; the actions column sits
   between the two so the row glyphs breathe without shouting. */
.header-tools .icon-btn { width: 2.5rem; height: 2.5rem; font-size: 1.3rem; }
.cell-actions .icon-btn { width: 1.85rem; height: 1.85rem; font-size: 0.95rem; }
/* Cells run on white-space: pre, so the buttons sit flush — the ring needs
   its own gap (header gets theirs from .header-tools' flex gap). */
.cell-actions .icon-btn + .icon-btn { margin-left: 0.5rem; }

/* ---- Breadcrumb: top of <main>, right under the sticky header. ---- */
.crumbs {
  display: flex; align-items: center; gap: 0.35rem; min-width: 0; overflow-x: auto;
  white-space: nowrap; font-family: var(--bs-font-monospace); font-size: 0.85rem;
  scrollbar-width: none; margin-bottom: 0.9rem;
}
.crumbs::-webkit-scrollbar { display: none; }
.crumbs a { color: var(--bs-secondary-color); }
.crumbs a:hover { color: var(--bs-primary); }
.crumbs .sep { color: var(--bs-secondary-color); opacity: 0.6; }
.crumbs .leaf { color: var(--bs-primary); font-weight: 600; }
.toolbar { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.search-form .input-group { width: min(21rem, 70vw); }
.search-form .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
.search-form .btn { border-top-left-radius: 0; border-bottom-left-radius: 0; }

/* ---- Table on a tile: cells stay transparent over the rounded card. ---- */
.tile { background: var(--surface); border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius-lg); box-shadow: var(--bs-box-shadow-sm); }
.table { --bs-table-bg: transparent; --bs-table-color: var(--bs-body-color); --bs-table-border-color: var(--bs-border-color); --bs-table-striped-bg: rgba(var(--bs-body-color-rgb), 0.03); --bs-table-hover-bg: rgba(var(--bs-primary-rgb), 0.06); }
.table thead th { color: var(--bs-secondary-color); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; }
/* Every header and cell keeps its text verbatim — no wrapping, no collapsing.
   Names, sizes and timestamps stay byte-for-byte as they are on disk; the
   card already scrolls sideways (.table-responsive), so nothing is lost. */
table th, table td { white-space: pre; }
.table > tbody > td { vertical-align: middle; }
.col-check { width: 1%; }
.cell-name a { color: var(--bs-body-color); font-weight: 500; }
.cell-name a:hover { color: var(--bs-primary); }
.cell-name i { width: 1.6rem; color: var(--bs-secondary-color); text-align: center; }
.cell-name .fa-folder-open { color: var(--bs-primary) !important; }
.perms { color: var(--bs-secondary-color); }
.perms:hover { color: var(--bs-primary); }
.empty-cell { text-align: center; padding: 3rem 1rem; color: var(--bs-secondary-color); }
.empty-cell i { font-size: 2rem; opacity: 0.5; }

/* ---- Cards / panels / flashes ---- */
.card { --bs-card-bg: var(--surface); --bs-card-border-color: var(--bs-border-color); --bs-card-cap-bg: transparent; box-shadow: var(--bs-box-shadow-sm); border-radius: var(--bs-border-radius-lg); }
.panel-head { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; justify-content: space-between; margin-bottom: 0.9rem; position: sticky; top: var(--site-head-h); z-index: 1020; background: var(--bs-body-bg); }
.alert { border-radius: var(--bs-border-radius); border-width: 1px; font-weight: 500; }
.kv { display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1.2rem; }
.kv dt { color: var(--bs-secondary-color); font-weight: 500; font-size: 0.88rem; }
.kv dd { margin: 0; overflow-wrap: anywhere; }

/* ---- Sign-in card ---- */
.login-wrap { min-height: calc(100vh - 8rem); display: grid; place-items: center; padding: 1.5rem; }
.login-card { width: 100%; max-width: 24rem; }
.login-card .brand-mark { width: 3rem; height: 3rem; font-size: 1.2rem; margin-inline: auto; }

/* ---- Code pages: source view (highlight.js) and the editor (in-file tokenizer) ---- */
pre.src { border-radius: var(--bs-border-radius); border: 1px solid var(--bs-border-color); background: var(--surface); padding: 1rem; overflow-x: auto; font-size: 0.86rem; line-height: 1.6; font-family: 'JetBrains Mono', var(--bs-font-monospace); }
pre.src code { background: none; color: inherit; font-family: inherit; padding: 0; }
/* highlight.js line-numbers plugin */
pre.src .hljs-ln { border-collapse: collapse; }
pre.src .hljs-ln-numbers { user-select: none; text-align: right; color: var(--bs-secondary-color); opacity: 0.7; border-right: 1px solid var(--bs-border-color); padding-right: 0.75rem; vertical-align: top; white-space: nowrap; }
pre.src .hljs-ln-code { padding-left: 0.75rem; white-space: pre; }
pre.src .hljs-ln-line { height: 1.6em; }
pre.out { border-radius: var(--bs-border-radius); border: 1px solid var(--bs-border-color); background: var(--surface); padding: 1rem; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.86rem; }
/* Editor: a CodeMirror 6 instance fills the box; the textarea below it is the
   no-JS / no-network fallback and the form's actual field. Both share one
   viewport-driven height: it tracks the window, clamped so tiny and huge
   screens stay sane. */
.cm-editor, #editor { height: clamp(16rem, calc(100vh - 10.5rem), 68rem); height: clamp(16rem, calc(100dvh - 10.5rem), 68rem); }
.drop-zone { border: 2px dashed var(--bs-border-color); border-radius: var(--bs-border-radius); padding: 1.2rem; text-align: center; transition: border-color .15s, background-color .15s; }
.drop-zone.drag-over { border-color: var(--bs-primary); background-color: rgba(var(--bs-primary-rgb), .08); }
.drop-zone .form-control { max-width: 26rem; margin-inline: auto; }
.ed { border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius); overflow: hidden; }
/* CodeMirror fills .ed and paints its own scrollbars; the gutter border and
   theme colours arrive from the JS theme, so only the shell is styled here. */
.ed .cm-editor { height: 100%; }
.ed .cm-scroller { overflow: auto; font-family: var(--bs-font-monospace); font-size: 0.9rem; line-height: 1.6; }
.ed .cm-gutters { border-right: 1px solid var(--bs-border-color); }

.site-footer { color: var(--bs-secondary-color); font-size: 0.82rem; }
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition-duration: 0.001ms !important; animation-duration: 0.001ms !important; }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</head>
<body class="py-0">

<?php if ($action !== 'signin'): ?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="<?= url('folder') ?>">
      <span class="brand-mark">S</span>
      <span>
        <span class="brand-name"><?= SIFM_NAME ?></span>
      </span>
    </a>
    <div class="header-tools">
      <?php if (keeps()): ?>
      <a class="icon-btn" href="<?= url('trash') ?>" title="Trash"><?= icon('trash') ?></a>
      <?php endif; ?>
      <button type="button" class="icon-btn" id="theme-toggle" title="Switch to light theme" onclick="sifmTheme.flip()">
        <?= icon('moon') ?>
      </button>
      <a class="icon-btn" href="<?= url('signout') ?>" title="Sign out"
         onclick="return confirm('Are you sure you want to sign out?')"><?= icon('right-from-bracket') ?></a>
    </div>
  </div>
</header>
<script>sifmTheme.apply(); /* the markup above is dark-first: sync the glyph and title before paint */</script>
<?php endif; ?>

<main class="container-fluid px-3 px-md-4 py-4" style="max-width:1400px">
<?php if ($action !== 'signin'): ?>
<?= breadcrumb($crumbs['path'], $crumbs['leaf']) ?>
<?php endif; ?>
<?= message() ?>
<?php switch ($action): ?>
<?php case 'signin': ?>
<div class="login-wrap">
  <div class="card login-card">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <span class="brand-mark mb-3">S</span>
        <h1 class="h4 mb-0">Sign in to SiFM</h1>
      </div>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input type="text" class="form-control" id="username" name="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <div class="d-grid"><button class="btn btn-primary" type="submit">Sign in</button></div>
      </form>
    </div>
  </div>
</div>
<?php break; ?>

<?php case 'folder': ?>
<?php
    $children = $fm->list($folder);
    $dirs = [];
    $files = [];
    foreach ($children as $child) {
        $rel = ($folder !== '' ? $folder . '/' : '') . $child;
        if ($fm->isfolder($rel)) {
            $dirs[] = $child;
        } else {
            $files[] = $child;
        }
    }
    sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
?>
<div class="toolbar">
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-ghost btn-sm" href="<?= url('create', $folder) ?>"><?= icon('circle-plus') ?> Create</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('upload', $folder) ?>"><?= icon('upload') ?> Upload</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('import', $folder) ?>"><?= icon('cloud-arrow-down') ?> Import</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('backup', $folder) ?>"><?= icon('suitcase') ?> Backup</a>
    <?php if ($folder !== ''): ?>
    <a class="btn btn-ghost btn-sm" href="<?= url('copy', $folder) ?>"><?= icon('copy') ?> Copy</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('move', $folder) ?>"><?= icon('exchange') ?> Move</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('rename', $folder) ?>"><?= icon('pencil') ?> Rename</a>
    <a class="btn btn-ghost btn-sm text-danger" href="<?= url('delete', $folder) ?>"><?= icon('trash') ?> Delete</a>
    <?php endif; ?>
  </div>
  <form class="search-form" method="post" action="<?= url('search', $folder) ?>">
    <?= csrf_field(); ?>
    <div class="input-group">
      <input type="search" class="form-control" name="q" placeholder="Search — * and ? wildcards" value="<?= e(post('q')) ?>" aria-label="Search">
      <button class="btn btn-primary" type="submit"><?= icon('magnifying-glass') ?> Search</button>
    </div>
  </form>
</div>

<form method="post" action="<?= url('folder', $folder) ?>">
  <?= csrf_field() ?>
  <div class="table-responsive tile">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th class="col-check"><input type="checkbox" class="form-check-input" onclick="toggleAll(this)" aria-label="Select all"></th>
          <th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$dirs && !$files): ?>
        <tr><td colspan="6" class="empty-cell"><?= icon('folder-open') ?><div class="mt-2">Empty folder</div></td></tr>
<?php endif; ?>
<?php foreach ([...$dirs, ...$files] as $child): ?>
        <?= row(($folder !== '' ? $folder . '/' : '') . $child, true) ?>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
    <label class="text-secondary small mb-0" for="multi-action">With selected:</label>
    <select class="form-select form-select-sm w-auto" name="action" id="multi-action">
      <option value="backup">Backup</option>
      <option value="copy">Copy</option>
      <option value="move">Move</option>
      <option value="chmod">Chmod</option>
      <option value="download">Download</option>
      <option value="delete">Delete</option>
    </select>
    <input type="hidden" name="multi" value="1">
    <button class="btn btn-primary btn-sm" type="submit" name="submit" value="1">Go</button>
  </div>
</form>
<?php break; ?>

<?php case 'search': ?>
<?php
    $q = trim(post('q'));
    $scope = path($folder);
    $hits = $q !== '' ? $fm->search($q, $scope) : [];
?>
<div class="panel-head">
  <form class="search-form d-flex" method="post" action="<?= url('search', $folder) ?>">
    <div class="input-group">
      <input type="search" class="form-control" name="q" placeholder="Search — * and ? wildcards" value="<?= e($q) ?>" aria-label="Search" autofocus>
      <button class="btn btn-primary" type="submit"><?= icon('magnifying-glass') ?> Search</button>
    </div>
  </form>
  <span class="text-secondary small mono">Searching in folder: /<?= e($scope) ?><?= $q !== '' ? ' · ' . count($hits) . ' result(s)' : '' ?></span>
</div>
<div class="table-responsive tile">
  <table class="table align-middle mb-0">
    <thead>
      <tr><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th></tr>
    </thead>
    <tbody>
<?php if ($q === ''): ?>
      <tr><td colspan="5" class="empty-cell"><?= icon('magnifying-glass') ?><div class="mt-2">Type a keyword — or use wildcards like <code>*.log</code> or <code>report*</code></div></td></tr>
<?php elseif (!$hits): ?>
      <tr><td colspan="5" class="empty-cell"><?= icon('magnifying-glass') ?><div class="mt-2">Nothing matched &ldquo;<?= e($q) ?>&rdquo;</div></td></tr>
<?php else: ?>
      <?php foreach ($hits as $hit): ?>
      <?= row($hit, false, true) ?>
      <?php endforeach; ?>
<?php endif; ?>
    </tbody>
  </table>
</div>
<?php break; ?>

<?php case 'file': ?>
<?php
    $info = (object) $fm->info($file);
    $ext = strtolower((string) ($info->extension ?? ''));
    $editable = in_array($ext, $fm::edit, true);
?>
<div class="panel-head">
  <h1 class="h5 mb-0">File details</h1>
</div>
<div class="card"><div class="card-body">
  <dl class="kv mb-0">
    <dt>Path</dt><dd class="mono"><?= e($info->path) ?></dd>
    <dt>Size</dt><dd><?= e($info->size ?? '—') ?></dd>
    <dt>MIME-Type</dt><dd class="mono"><?= e($info->mimetype ?? '—') ?></dd>
    <dt>Permissions</dt><dd><a class="perms mono" href="<?= url('chmod', $file) ?>"><?= e($info->perms) ?></a></dd>
    <dt>Owner</dt><dd class="mono"><?= e($info->owner) ?></dd>
    <dt>Last modified</dt><dd><?= e($info->last_modified) ?> <span class="text-secondary">(<?= e(timeago($info->last_modified)) ?>)</span></dd>
  </dl>
</div></div>
<div class="d-flex flex-wrap gap-2 mt-3">
  <a class="btn btn-primary btn-sm" href="<?= url('download', $file) ?>"><?= icon('download') ?> Download</a>
  <a class="btn btn-ghost btn-sm" href="//<?= SIFM_HOST ?>/<?= e($file) ?>"><?= icon('globe') ?> Open</a>
<?php if ($editable): ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('edit', $file) ?>"><?= icon('pen') ?> Edit</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('source', $file) ?>"><?= icon('code') ?> Source</a>
<?php endif; ?>
<?php if ($ext === 'php'): ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('syntax', $file) ?>"><?= icon('bug') ?> Syntax</a>
<?php endif; ?>
<?php if ($ext === 'zip'): ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('extract', $file) ?>"><?= icon('box') ?> Extract</a>
<?php endif; ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('copy', $file) ?>"><?= icon('copy') ?> Copy</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('move', $file) ?>"><?= icon('exchange') ?> Move</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('rename', $file) ?>"><?= icon('pencil') ?> Rename</a>
  <a class="btn btn-ghost btn-sm text-danger" href="<?= url('delete', $file) ?>"><?= icon('trash') ?> Delete</a>
</div>
<?php break; ?>

<?php case 'edit': ?>
<?php
    /* $size and the "writable / small enough" gates were settled in dispatch —
       a view runs after the HTML has begun and may not redirect. */
    $content = $fm->source($file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mtime = (int) (@filemtime(SIFM_HOME . '/' . $file));
?>
<form method="post" action="<?= url('edit', $file) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="mtime" value="<?= $mtime ?>">
  <div class="panel-head">
    <span class="mono small text-secondary text-truncate" style="max-width:50%">/<?= e($file) ?> · <?= e($fm->size($file, $size)) ?></span>
    <div class="d-flex flex-wrap gap-2 ms-auto">
<?php if (get('replace') !== '1'): ?>
      <button class="btn btn-primary btn-sm" name="save" value="1"><?= icon('floppy-disk') ?> Save</button>
<?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="<?= url('source', $file) ?>"><?= icon('code') ?> Source</a>
<?php if ($ext === 'php'): ?>
      <a class="btn btn-ghost btn-sm" href="<?= url('syntax', $file) ?>"><?= icon('bug') ?> Syntax</a>
<?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="<?= url('edit', $file) . '&replace=1' ?>"><?= icon('arrows-rotate') ?> Replace&hellip;</a>
    </div>
  </div>
<?php if (get('replace') === '1'): ?>
  <div class="card"><div class="card-body">
    <h2 class="h6">Replace a phrase</h2>
    <div class="mb-3">
      <label class="form-label" for="rep-from">Find</label>
      <textarea class="form-control" id="rep-from" name="from" rows="3" required></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label" for="rep-to">Replace with</label>
      <textarea class="form-control" id="rep-to" name="to" rows="3"></textarea>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="replace" value="1">Replace</button>
      <a class="btn btn-ghost" href="<?= url('edit', $file) ?>">Cancel</a>
    </div>
  </div></div>
<?php else: ?>
  <div id="ed" class="ed"></div>
  <textarea id="editor" name="data" class="form-control mt-2" rows="24" spellcheck="false" hidden><?= e($content) ?></textarea>
<?php endif; ?>
</form>
<?php break; ?>

<?php case 'source': ?>
<?php $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)); ?>
<div class="panel-head">
  <span class="mono small text-secondary">/<?= e($file) ?></span>
  <div class="d-flex gap-2 ms-auto">
<?php if (in_array($ext, $fm->edit, true)): ?>
    <a class="btn btn-ghost btn-sm" href="<?= url('edit', $file) ?>"><?= icon('pen') ?> Edit</a>
<?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="<?= url('file', $file) ?>">Back</a>
  </div>
</div>
<pre class="src"><code class="language-<?= e(lang($ext) === 'plain' ? 'plaintext' : lang($ext)) ?>"><?= e($fm->source($file)) ?></code></pre>
<?php break; ?>

<?php case 'syntax': ?>
<?php
    $out = (string) shell_exec('php -l ' . escapeshellarg(SIFM_HOME . '/' . $file));
    $bad = $out === '' || str_contains(strtolower($out), 'error');
?>
<div class="panel-head">
  <span class="mono small text-secondary">php -l /<?= e($file) ?></span>
  <a class="btn btn-ghost btn-sm ms-auto" href="<?= url('file', $file) ?>">Back</a>
</div>
<?php if ($bad): ?>
<div class="alert alert-danger mb-3" role="alert"><?= icon('triangle-exclamation') ?> Check errors in your code:</div>
<pre class="out mono"><?= e($out !== '' ? $out : 'php -l produced no output — is shell_exec() enabled?') ?></pre>
<?php else: ?>
<div class="alert alert-success" role="alert"><?= icon('circle-check') ?> No syntax errors detected.</div>
<?php endif; ?>
<?php break; ?>

<?php case 'backup': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Backup folder &ldquo;/<?= e($folder) ?>&rdquo;</h1>
  <form method="post" action="<?= url('backup', $folder) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="bk-name">Backup filename</label>
      <input type="text" class="form-control mono" id="bk-name" name="n" value="<?= e(($folder !== '' ? $folder . '/' : '') . 'Backup.zip') ?>">
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="bk" value="1">Backup</button>
      <a class="btn btn-ghost" href="<?= url('folder', $folder) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'chmod': ?>
<?php
    $mod = (string) ($fm->info($target)['perms'] ?? '');
    $type = $file !== '' ? 'file' : 'folder';
?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">CHMOD <?= $type ?> &ldquo;/<?= e($target) ?>&rdquo;</h1>
  <form method="post" action="<?= url('chmod', $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="mod">Permissions: <span class="mono"><?= e($mod) ?> &rarr;</span></label>
      <input type="text" class="form-control mono" id="mod" name="mod" value="<?= e($mod) ?>" pattern="[0-7]{3,4}" maxlength="4" required>
      <div class="form-text">3–4 octal digits, e.g. 644 or 755.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit">CHMOD</button>
      <a class="btn btn-ghost" href="<?= url($file !== '' ? 'file' : 'folder', $file !== '' ? $file : dirname($target)) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'copy': case 'move': ?>
<?php $is_copy = $action === 'copy'; ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3"><?= $is_copy ? 'Copy' : 'Move' ?> &ldquo;<span class="mono">/<?= e($target) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url($action, $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="dest">Destination path</label>
      <input type="text" class="form-control mono" id="dest" name="new" value="/<?= e($target) ?>" required>
      <div class="form-text">Relative to the home folder. Missing parent folders are created.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="<?= $is_copy ? 'copy' : 'move' ?>" value="1"><?= $is_copy ? 'Copy' : 'Move' ?></button>
      <a class="btn btn-ghost" href="<?= url($file !== '' ? 'file' : 'folder', $target) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'create': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Create in &ldquo;/<?= e($listing) ?>&rdquo;</h1>
  <form method="post" action="<?= url('create', $folder) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="new-name">Name</label>
      <input type="text" class="form-control" id="new-name" name="i" required autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label" for="new-type">Type</label>
      <select class="form-select" id="new-type" name="folder">
        <option value="0">File</option>
        <option value="1">Folder</option>
      </select>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="create" value="1">Create</button>
      <a class="btn btn-ghost" href="<?= url('folder', $listing) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'delete': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 text-danger"><?= icon('triangle-exclamation') ?> Delete <?= is_dir(SIFM_HOME . '/' . $target) ? 'folder' : 'file' ?> &ldquo;<span class="mono">/<?= e($target) ?></span>&rdquo;?</h1>
  <p class="text-secondary mb-3"><?= keeps()
      ? 'It moves to the trash — you can restore it from the <a href="' . url('trash') . '">trash page</a> until you empty the trash.'
      : 'This is irreversible — deleted items cannot be recovered.' ?></p>
  <form method="post" action="<?= url('delete', $target) ?>" class="d-inline">
    <?= csrf_field() ?>
    <button class="btn btn-danger" name="del" value="1"><?= icon('trash') ?> Yes, delete</button>
    <a class="btn btn-ghost" href="<?= url($file !== '' ? 'file' : 'folder', $file !== '' ? $file : dirname($target)) ?>">No, back</a>
  </form>
</div></div>
<?php break; ?>

<?php case 'trash': ?>
<?php $entries = $fm->trashed(); krsort($entries); ?>
<div class="card"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h6 mb-0"><?= icon('trash') ?> Trash <span class="text-secondary fw-normal">(<?= count($entries) ?> item<?= count($entries) === 1 ? '' : 's' ?>)</span></h1>
    <?php if ($entries): ?>
    <form method="post" action="<?= url('trash') ?>">
      <?= csrf_field() ?>
      <button class="btn btn-danger btn-sm" name="purge" value="1" onclick="return confirm('Destroy everything in the trash for good? This cannot be undone.')"><?= icon('fire') ?> Empty trash</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$entries): ?>
  <p class="text-secondary mb-0">The trash is empty.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Name</th><th>Was at</th><th>Deleted</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
<?php foreach ($entries as $id => $one): ?>
        <tr>
          <td class="cell-name"><?= icon($one['dir'] ? 'folder' : 'file') ?> <span class="mono"><?= e(preg_replace('/^[^-]+-[^-]+-/', '', (string) $id)) ?></span></td>
          <td class="mono text-secondary">/<?= e((string) ($one['from'] ?? '')) ?></td>
          <td class="text-secondary"><?= e(date(SIFM_DATE, (int) ($one['at'] ?? 0))) ?></td>
          <td class="text-end">
            <div class="d-inline-flex gap-1">
              <form method="post" action="<?= url('trash') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <button class="btn btn-ghost btn-sm" name="restore" value="1"><?= icon('rotate-left') ?> Restore</button>
              </form>
              <form method="post" action="<?= url('trash') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <button class="btn btn-ghost btn-sm text-danger" name="purge" value="1" onclick="return confirm('Destroy this for good?')"><?= icon('xmark') ?> Destroy</button>
              </form>
            </div>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div></div>
<?php break; ?>

<?php case 'extract': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Extract &ldquo;<span class="mono">/<?= e($file) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url('extract', $file) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="ex-dest">Destination folder</label>
      <input type="text" class="form-control mono" id="ex-dest" name="u" value="/<?= e(dirname($file)) ?>" required>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="ex" value="1">Extract</button>
      <a class="btn btn-ghost" href="<?= url('file', $file) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'import': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Import into &ldquo;/<?= e($folder) ?>&rdquo;</h1>
  <form method="post" action="<?= url('import', $folder) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="im-url">URL <span class="text-secondary">(http/https only, up to <?= (int) (SIFM_MAX_IMPORT / 1048576) ?> MB)</span></label>
      <input type="url" class="form-control" id="im-url" name="url" value="<?= e(get('url', 'https://')) ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="im-name">Save as</label>
      <input type="text" class="form-control" id="im-name" name="name" placeholder="defaults to the URL file name">
    </div>
    <div class="progress mb-2 d-none" id="im-bar" style="height:.4rem">
      <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%"></div>
    </div>
    <div class="small text-secondary mb-2" id="im-note">The server fetches the file — this page stays put.</div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="import" value="1"><?= icon('cloud-arrow-down') ?> Import</button>
      <a class="btn btn-ghost" href="<?= url('folder', $folder) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<script>
/* Import without leaving the page: the striped bar runs while the server
   streams the download, the verdict lands in the note line. */
(function () {
  var form = document.getElementById('im-url').form, note = document.getElementById('im-note'), bar = document.getElementById('im-bar');
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var fd = new FormData(form);
    fd.append('import', '1'); // FormData alone drops the button's pair
    note.className = 'small text-secondary mb-2';
    note.textContent = 'Importing — the server is fetching the file…';
    bar.classList.remove('d-none');
    ev.submitter.disabled = true;
    fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        ev.submitter.disabled = false;
        bar.classList.add('d-none');
        note.className = 'small mb-2 text-' + (d.ok ? 'success' : 'danger');
        note.textContent = d.msg;
        if (d.ok) form.reset();
      });
  });
})();
</script>
<?php break; ?>

<?php case 'rename': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Rename &ldquo;<span class="mono">/<?= e($target) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url('rename', $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="ren-new">New name <span class="text-secondary">(no slashes)</span></label>
      <input type="text" class="form-control" id="ren-new" name="new" value="<?= e(basename($target)) ?>" required>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="ren" value="1">Rename</button>
      <a class="btn btn-ghost" href="<?= url($file !== '' ? 'file' : 'folder', $file !== '' ? $file : dirname($target)) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'upload': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Upload to &ldquo;/<?= e($listing) ?>&rdquo;</h1>
  <form enctype="multipart/form-data" method="post" action="<?= url('upload', $listing) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="up-files">Files <span class="text-secondary">(select several at once — or drop them in the box)</span></label>
      <div class="drop-zone" id="drop">
        <input type="file" class="form-control" id="up-files" name="files[]" multiple required>
        <div class="progress mt-2 d-none" id="drop-bar" style="height:.4rem"><div class="progress-bar" style="width:0%"></div></div>
        <div class="small text-secondary mt-2" id="drop-note">Drag &amp; drop anywhere on this page — folders included</div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="up" value="1"><?= icon('upload') ?> Upload</button>
      <a class="btn btn-ghost" href="<?= url('folder', $listing) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<script>
/* The upload page is a drop target from edge to edge: folders are walked
   recursively (the tree flattens on the way up) and files go up one at a
   time so the bar always reflects the current file. */
(function () {
  var box = document.getElementById('drop'), note = document.getElementById('drop-note'),
      bar = document.getElementById('drop-bar'), fill = bar.querySelector('.progress-bar'),
      input = document.getElementById('up-files'), form = input.form, depth = 0;

  function state(text, kind, busy) {
    note.className = 'small mt-2 text-' + kind;
    note.textContent = text;
    bar.classList.toggle('d-none', !busy);
    if (busy) fill.style.width = '0%';
  }

  function send(files) {
    var total = files.length, done = 0, bad = 0, i = 0;
    if (!total) return;
    (function next() {
      var f = files[i++];
      if (!f) {
        state(bad ? done + ' uploaded, ' + bad + ' failed.' : 'Uploaded ' + done + ' file(s).', bad ? 'danger' : 'success', false);
        input.value = ''; // ready for the next batch
        return;
      }
      state(f.name + ' — ' + i + ' of ' + total + '…', 'secondary', true);
      var fd = new FormData();
      fd.append('csrf', form.querySelector('[name=csrf]').value);
      fd.append('files[]', f, f.name);
      fd.append('up', '1'); // FormData alone drops the button's pair
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) fill.style.width = (ev.loaded / ev.total * 100) + '%'; };
      xhr.onload = function () {
        var r; try { r = JSON.parse(xhr.responseText); } catch (e) { r = { ok: false }; }
        r.ok ? done++ : bad++;
        next();
      };
      xhr.onerror = function () { bad++; next(); };
      xhr.send(fd);
    })();
  }

  function expand(items) {
    var out = [];
    function walk(entry) {
      return new Promise(function (done) {
        if (entry.isFile) { entry.file(function (f) { out.push(f); done(); }); return; }
        var reader = entry.createReader(), kids = [];
        (function read() {
          reader.readEntries(function (more) {
            if (!more.length) { Promise.all(kids.map(walk)).then(done); return; }
            kids = kids.concat(more);
            read();
          });
        })();
      });
    }
    var entries = [];
    for (var i = 0; i < items.length; i++) {
      var e = items[i].webkitGetAsEntry && items[i].webkitGetAsEntry();
      if (e) entries.push(e);
    }
    if (entries.length) return Promise.all(entries.map(walk)).then(function () { return out; });
    return Promise.resolve(Array.prototype.filter.call(items, function (i) { return i.kind === 'file'; })
      .map(function (i) { return i.getAsFile(); }).filter(Boolean));
  }

  form.addEventListener('submit', function (ev) { ev.preventDefault(); send(Array.prototype.slice.call(input.files)); }); // browser validation still runs first
  input.addEventListener('change', function () {
    if (input.files.length) state(input.files.length + ' file(s) selected — press Upload, or drop more.', 'secondary', false);
  });
  document.addEventListener('dragover', function (ev) { ev.preventDefault(); });
  document.addEventListener('dragenter', function (ev) { ev.preventDefault(); if (++depth === 1) box.classList.add('drag-over'); });
  document.addEventListener('dragleave', function () { if (--depth <= 0) { depth = 0; box.classList.remove('drag-over'); } });
  document.addEventListener('drop', function (ev) {
    ev.preventDefault(); depth = 0; box.classList.remove('drag-over');
    expand(ev.dataTransfer.items).then(send); // a stray text drop just yields nothing
  });
})();
</script>
<?php break; ?>

<?php case 'multi': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">You are about to <span class="text-primary"><?= e($kind) ?></span> <?= count($items) ?> item(s):</h1>
  <ul class="list-unstyled mb-3">
    <?php foreach ($items as $item): ?>
    <li class="mono small py-1 border-bottom border-secondary border-opacity-25">/<?= e(($folder !== '' ? $folder . '/' : '') . $item) ?></li>
    <?php endforeach; ?>
  </ul>
<?php if ($kind === 'delete'): ?>
  <p class="text-secondary"><?= keeps()
      ? 'The selected items move to the trash — restore them from the <a href="' . url('trash') . '">trash page</a> until you empty it.'
      : 'This is irreversible — the selected items cannot be recovered.' ?></p>
<?php endif; ?>
  <form method="post" action="<?= url('multi', $folder) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= e($kind) ?>">
    <?php foreach ($items as $item): ?>
    <input type="hidden" name="items[]" value="<?= e($item) ?>">
    <?php endforeach; ?>
<?php if (!in_array($kind, ['delete', 'download'], true)): ?>
    <div class="mb-3">
      <label class="form-label" for="multi-dest"><?= $kind === 'chmod' ? 'Permissions (e.g. 755)' : ($kind === 'backup' ? 'Backup filename' : 'Destination path') ?></label>
      <input type="text" class="form-control mono" id="multi-dest" name="dest" required
             value="<?= e($kind === 'backup' ? ($folder !== '' ? $folder . '/' : '') . 'Backup.zip' : ($kind === 'chmod' ? '' : '/' . $folder)) ?>"
             <?= $kind === 'chmod' ? 'pattern="[0-7]{3,4}" maxlength="4" placeholder="755"' : '' ?>>
    </div>
<?php endif; ?>
    <div class="d-flex gap-2">
      <?php if ($kind === 'delete'): ?>
      <button class="btn btn-danger" name="submit" value="1"><?= icon('trash') ?> Yes, delete</button>
      <?php elseif ($kind === 'download'): ?>
      <button class="btn btn-primary" name="submit" value="1"><?= icon('file-zipper') ?> Build &amp; download ZIP</button>
      <?php else: ?>
      <button class="btn btn-primary" name="submit" value="1"><?= ucfirst(e($kind)) ?></button>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= url('folder', $folder) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php default: ?>
<div class="alert alert-warning" role="alert"><?= icon('triangle-exclamation') ?>
  No view is registered for the <code><?= e((string) $action) ?></code> action — it is listed in <code>$actions</code>
  but has no <code>case</code> in the render switch.
</div>
<?php endswitch; ?>
</main>

<?php if ($action !== 'signin'): ?>
<footer class="site-footer text-center py-3 mono"><?= SIFM_NAME ?> v<?= SIFM_VERSION ?></footer>
<?php endif; ?>

<script>
function toggleAll(box) {
  document.querySelectorAll('input[name="items[]"]').forEach(function (b) { b.checked = box.checked; });
}
</script>
<?php if ($action === 'edit' && get('replace') !== '1'): ?>
<?php
    /* The editor's palette, straight off the design tokens — one map per
       colour mode, so the JS theme and the PHP CSS can never drift apart. */
    $ed = [
        'dark' => ['bg' => 'var(--surface)', 'fg' => 'var(--bs-body-color)', 'gut' => 'var(--surface-2)', 'gut2' => '#242932', 'brd' => 'var(--bs-border-color)', 'mut' => 'var(--bs-secondary-color)', 'sel' => 'rgba(242,179,61,.22)', 'line' => 'var(--surface-2)', 'cur' => 'var(--bs-primary)', 'kw' => 'var(--bs-primary)', 'num' => 'var(--ember)', 'str' => 'var(--tok-string)', 'cm' => '#7d828b', 'tag' => 'var(--tok-def)', 'vd' => '#ff8a80', 'fn' => '#64b5f6'],
        'light' => ['bg' => 'var(--surface)', 'fg' => 'var(--bs-body-color)', 'gut' => 'var(--surface-2)', 'gut2' => '#f6f3ec', 'brd' => 'var(--bs-border-color)', 'mut' => 'var(--bs-secondary-color)', 'sel' => 'rgba(154,111,12,.18)', 'line' => '#f7f6f2', 'cur' => 'var(--bs-primary)', 'kw' => 'var(--bs-primary)', 'num' => 'var(--ember)', 'str' => 'var(--tok-string)', 'cm' => '#6f6a5e', 'tag' => 'var(--tok-def)', 'vd' => '#c62828', 'fn' => '#1565c0'],
    ];
?>
<script type="module">
/* Bullion, in CodeMirror 6 — native ES modules straight from jsdelivr.
   The textarea below stays the form's only real field: every edit is mirrored
   into it, and if the CDN or the browser lets us down it simply stays visible. */
const ta = document.getElementById('editor');
const pals = <?= json_encode($ed) ?>;
const fam = '<?= lang($ext) ?>';
const dark = document.documentElement.dataset.bsTheme !== 'light';

/* The palette, as CM6 theme and highlight style. Built per polarity from the
   same tokens the rest of the page uses, so a theme flip is a compartment swap. */
const theme = (p) => ({
  '&': { backgroundColor: p.bg, color: p.fg, height: '100%' },
  '.cm-content': { caretColor: p.cur },
  '.cm-cursor, .cm-dropCursor': { borderLeftColor: p.cur },
  '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: p.sel },
  '.cm-activeLine': { backgroundColor: p.line },
  '.cm-gutters': { backgroundColor: p.gut, color: p.mut, border: 'none' },
  '.cm-activeLineGutter': { backgroundColor: p.gut2 },
  '.cm-panels': { backgroundColor: p.gut, color: p.fg },
  '.cm-searchMatch': { backgroundColor: p.sel },
});
const style = (p, tags) => [
  { tag: [tags.keyword, tags.moduleKeyword, tags.controlKeyword, tags.operatorKeyword], color: p.kw },
  { tag: [tags.string, tags.special(tags.string), tags.regexp], color: p.str },
  { tag: [tags.comment, tags.blockComment, tags.lineComment], color: p.cm, fontStyle: 'italic' },
  { tag: [tags.number, tags.bool, tags.null, tags.atom], color: p.num },
  { tag: [tags.tagName, tags.typeName, tags.className, tags.angleBracket], color: p.tag },
  { tag: [tags.function(tags.variableName), tags.function(tags.propertyName)], color: p.fn },
  { tag: [tags.definition(tags.variableName), tags.definition(tags.propertyName)], color: p.vd },
  { tag: [tags.propertyName, tags.attributeName, tags.variableName], color: p.fg },
];

/* Save in place: POST through fetch, show the verdict, refresh the mtime
   guard — the page never moves. Anything but Save posts normally. */
const liveSave = (form) => {
  let flash;
  form.addEventListener('submit', (ev) => {
    if (!ev.submitter || ev.submitter.name !== 'save') return;
    ev.preventDefault();
    ev.submitter.disabled = true;
    const fd = new FormData(form);
    fd.append('save', '1'); // FormData alone drops the submit button's pair
    fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => r.json())
      .then((d) => {
        ev.submitter.disabled = false;
        const m = form.querySelector('[name=mtime]');
        if (d.ok && m && d.mtime) m.value = d.mtime; // conflict keeps the stale mtime, so a blind retry can never overwrite
        flash = flash || form.insertBefore(document.createElement('div'), form.firstChild);
        flash.className = 'alert alert-' + (d.ok ? 'success' : 'danger') + ' mt-2';
        flash.textContent = d.msg;
        setTimeout(() => { flash.remove(); flash = null; }, 4000);
      })
      .catch(() => { ev.submitter.disabled = false; });
  });
};

/* [bare specifier, exported factory] for the curated grammars, [mode file,
   parser] for the stream parsers. PHP's family comes from lang(). */
const curated = {
  php:  ['@codemirror/lang-php', 'php'],
  xml:  ['@codemirror/lang-xml', 'xml'],
  js:   ['@codemirror/lang-javascript', 'javascript'],
  css:  ['@codemirror/lang-css', 'css'],
  md:   ['@codemirror/lang-markdown', 'markdown'],
  sql:  ['@codemirror/lang-sql', 'sql'],
  py:   ['@codemirror/lang-python', 'python'],
};
const streams = { sh: ['shell', 'shell'], ruby: ['ruby', 'ruby'], perl: ['perl', 'perl'] };
const legacy = (file) => import(`https://cdn.jsdelivr.net/npm/@codemirror/legacy-modes@6.5.5/mode/${file}.js`);

try {
  const [state, view, language, commands, search, lezer] = await Promise.all([
    import('@codemirror/state'),
    import('@codemirror/view'),
    import('@codemirror/language'),
    import('@codemirror/commands'),
    import('@codemirror/search'),
    import('@lezer/highlight'),
  ]);
  const { EditorState, Compartment } = state;
  const { EditorView, keymap, lineNumbers, drawSelection, highlightActiveLine, highlightActiveLineGutter } = view;
  const { syntaxHighlighting, HighlightStyle, StreamLanguage, LanguageSupport } = language;
  const { defaultKeymap, history, historyKeymap, indentWithTab } = commands;
  const { searchKeymap, openSearchPanel } = search;
  const { tags } = lezer;

  let grammar = [];
  if (fam in curated) {
    const [pkg, name] = curated[fam];
    grammar = (await import(pkg))[name]();
  } else if (fam in streams) {
    const [file, name] = streams[fam];
    grammar = new LanguageSupport(StreamLanguage.define((await legacy(file))[name]));
  } else if (fam === 'ini') {
    grammar = new LanguageSupport(StreamLanguage.define((await legacy('properties')).properties));
  } else if (fam === 'clike') {
    grammar = new LanguageSupport(StreamLanguage.define((await legacy('clike')).cpp));
  }

  const pal = new Compartment();
  const paint = (p) => [EditorView.theme(theme(p)), syntaxHighlighting(HighlightStyle.define(style(p, tags)))];

  const editor = new EditorView({
    parent: document.getElementById('ed'),
    state: EditorState.create({
      doc: ta.value,
      extensions: [
        lineNumbers(), history(), drawSelection(), highlightActiveLine(), highlightActiveLineGutter(),
        EditorView.lineWrapping, EditorState.tabSize.of(4), EditorView.updateListener.of((u) => { if (u.docChanged) ta.value = u.state.doc.toString(); }),
        keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap, indentWithTab]),
        pal.of(paint(pals[dark ? 'dark' : 'light'])),
        grammar,
      ],
    }),
  });
  liveSave(ta.form);

  /* CM6 ships its own find & replace panel — Ctrl/Cmd+F opens it. */
  EditorView.domEventHandlers({ keydown: (ev) => ((ev.ctrlKey || ev.metaKey) && ev.key === 'f' ? openSearchPanel(editor) || true : false) });

  /* sifmTheme.apply() hands this the new polarity so the open editor repaints at once. */
  window.__retheme = (dark) => editor.dispatch({ effects: pal.reconfigure(paint(pals[dark ? 'dark' : 'light'])) });
} catch (err) {
  document.getElementById('ed').remove();
  ta.hidden = false;
  window.__err = String(err && err.stack || err); // visible to the smoke probe and DevTools
}
</script>
<?php endif; ?>
<?php if ($action === 'source'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.9.0/highlightjs-line-numbers.min.js"></script>
<script>
document.querySelectorAll('pre.src code').forEach(function (el) {
  if (window.hljs) { hljs.highlightElement(el); }
  if (window.hljs && typeof hljs.lineNumbersBlock === 'function') {
    hljs.lineNumbersBlock(el, { singleLine: true });
  }
});
</script>
<?php endif; ?>
</body>
</html>
<?php

/**** Helpers ****/

/** Normalise a path relative to SIFM_HOME: drop empty segments, "." and "..".
 *  Escaping is deliberately NOT done here — names read off disk stay raw;
 *  every echo goes through e(). */
function path(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg !== '' && $seg !== '.' && $seg !== '..') {
            $parts[] = $seg;
        }
    }
    return implode('/', $parts);
}

/** Escape for HTML output — every echoed name, path and value goes through this. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** $_GET / $_POST readers that refuse arrays (handlers always see a string). */
function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

/** Is soft delete on? The one place deletion decides between parking an item
 *  in the trash and unlinking it — every delete site reads this, so flipping
 *  SIFM_TRASH cannot leave one path still trashing behind the other's back. */
function keeps(): bool
{
    return SIFM_TRASH;
}

/** Build ?action&f=path — the whole router. The item runs through path(),
 *  so a link never carries a leading/trailing slash or a "." segment, and
 *  the root folder drops ?f= entirely. */
function url(string $action, ?string $item = null): string
{
    $item = $item === null ? '' : path($item);
    return $item !== ''
        ? $_SERVER['DOCUMENT_URI'] . '?' . rawurlencode($action) . '=' . rawurlencode($item)
        : $_SERVER['DOCUMENT_URI'] . '?' . rawurlencode($action);
}

/** PRG helper: the browser always lands on a clean GET. */
function redirect(string $action, ?string $item = ''): void
{
    header('Location: ' . url($action, $item), true, 302);
    exit;
}

/** FA6 icon. String second arg = text-colour utility, int = pixel size. */
function icon(string $name, $opt = 0): string
{
    $extra = is_string($opt) && $opt !== ''
        ? ' text-' . e($opt)
        : (is_int($opt) && $opt > 0 ? ' style="font-size:' . $opt . 'px"' : '');
    return '<i class="fas fa-' . e($name) . $extra . '" aria-hidden="true"></i>';
}

/** One-shot flash message. $kind: 1 success, 2 info, 0 error. */
function message(?string $text = null, int $kind = 1): ?string
{
    if ($text !== null) {
        $class = match ($kind) {
            0 => 'danger',
            2 => 'info',
            default => 'success',
        };
        $_SESSION['flash'] = ['text' => $text, 'class' => $class];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash
        ? '<div class="alert alert-' . $flash['class'] . ' mb-3" role="alert">' . e($flash['text']) . '</div>'
        : null;
}

/** CSRF — one session token guards every POST, sign-in included. */
function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">';
}

function csrf_ok(): bool
{
    return hash_equals(csrf(), post('csrf'));
}

/** True when the request came in through fetch() from our own pages. */
function ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

/** For fetch() requests, answer in JSON and stop; normal requests fall through. */
function json_out(array $data): void
{
    if (!ajax()) {
        return;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** "3 days ago" for the file details page. */
function timeago($time): string
{
    $time = is_numeric($time) ? (int) $time : (strtotime((string) $time) ?: time());
    $units = ['second', 'minute', 'hour', 'day', 'month', 'year'];
    $steps = [60, 60, 24, 30, 12];
    $diff = time() - $time;
    $i = 0;
    while ($diff >= $steps[$i] && $i < count($steps) - 1) {
        $diff = (int) round($diff / $steps[$i]);
        $i++;
    }
    return $diff . ' ' . $units[$i] . ($diff === 1 ? '' : 's') . ' ago';
}

/** Breadcrumb: home icon, then each segment as a folder link. $leaf is the
 *  current file name shown in bold. At the root ($path and $leaf both empty)
 *  it returns nothing — a lone home icon tells nobody anything. Replaces the
 *  two duplicated copies the old code kept (top nav + list header). */
function breadcrumb(string $path, string $leaf = ''): string
{
    if ($path === '' && $leaf === '') {
        return '';
    }
    $out = '<nav class="crumbs" aria-label="Breadcrumb">'
        . '<a href="' . url('folder') . '" title="Home folder">' . icon('house') . '</a>';
    $deep = '';
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        $deep = $deep === '' ? $seg : $deep . '/' . $seg;
        $out .= '<span class="sep">/</span><a href="' . url('folder', $deep) . '">' . e($seg) . '</a>';
    }
    if ($leaf !== '') {
        $out .= '<span class="sep">/</span><strong class="leaf">' . e($leaf) . '</strong>';
    }
    return $out . '</nav>';
}

/** Syntax family for a file extension — one key drives the in-file tokenizer
 *  for both the editor and the source view ('plain' = no highlighting). */
function lang(string $ext): string
{
    return match (strtolower($ext)) {
        'php', 'phtml', 'php3', 'php4', 'php5', 'inc' => 'php',
        'html', 'htm', 'xhtml', 'svg', 'xml', 'wml' => 'xml',
        'js', 'mjs', 'cjs', 'json', 'ts' => 'js',
        'css' => 'css',
        'md', 'markdown' => 'md',
        'sql' => 'sql',
        'py' => 'py',
        'sh', 'bash', 'zsh', 'cgi' => 'sh',
        'yml', 'yaml', 'ini', 'conf', 'env', 'htaccess', 'properties', 'tpl' => 'ini',
        'c', 'h', 'cpp', 'hpp', 'java', 'cs' => 'clike',
        'rb' => 'ruby',
        'pl' => 'perl',
        default => 'plain',
    };
}

/** One table row — shared by the folder listing and the search results.
 *  $select adds the checkbox cell; $full shows the whole relative path
 *  instead of the bare name (search results need the location). */
function row(string $rel, bool $select, bool $full = false): string
{
    static $fa = null;
    $fm = new fm();
    $fa ??= json_decode($fm::icons, true) ?: [];

    $rel = path($rel);
    $is_dir = $fm->isfolder($rel);
    $info = (object) $fm->info($rel);
    $name = $full ? $rel : basename($rel);
    $ext = strtolower((string) ($info->extension ?? ''));

    $glyph = $is_dir
        ? 'fa-folder-open text-primary'
        : ($fa[$ext] ?? $fa['empty'] ?? 'fas fa-file-lines');
    $link = $is_dir ? url('folder', $rel) : url('file', $rel);

    $actions = ['copy' => 'copy', 'delete' => 'trash', 'download' => 'download', 'move' => 'exchange', 'rename' => 'pencil'];
    $links = '';
    foreach ($actions as $act => $glyph_act) {
        if ($act === 'download' && $is_dir) {
            continue;
        }
        $links .= '<a class="icon-btn" href="' . url($act, $rel) . '" title="' . ucfirst($act) . '">' . icon($glyph_act) . '</a>';
    }

    return '<tr>'
        . ($select
            ? '<td class="col-check"><input class="form-check-input" type="checkbox" name="items[]" value="' . e(basename($rel)) . '" aria-label="Select ' . e($name) . '"></td>'
            : '')
        . '<td class="cell-name"><i class="fas ' . e($glyph) . '" aria-hidden="true"></i>'
        . '<a href="' . $link . '">' . e($name) . '</a></td>'
        . '<td>' . ($is_dir ? '<span class="text-secondary">Folder</span>' : e($info->size)) . '</td>'
        . '<td><a class="perms mono" href="' . url('chmod', $rel) . '">' . e($info->perms) . '</a></td>'
        . '<td class="text-secondary small">' . e($info->last_modified) . '</td>'
        . '<td class="cell-actions">' . $links . '</td>'
        . '</tr>';
}

/**** The filesystem engine. Every path is relative to SIFM_HOME and is
 **** re-normalised with path() inside each method, so a caller that forgets
 **** to sanitise still cannot escape the home folder. ****/
class fm
{
    /** Extensions the built-in editor accepts. */
    const edit = ["", "bat", "c", "cert", "cgi", "cpp", "css", "dat", "htaccess", "htm", "html", "ini", "js", "key", "json", "lock", "log", "md", "php", "pl", "py", "sh", "sql", "tpl", "txt", "wml", "xhtml", "xml"];

    /** Extension → FA6 icon class (json, decoded once by row()). */
    const icons = '{"7z":"fas fa-file-archive","aac":"fas fa-music","accdb":"fas fa-database","ai":"fas fa-image","aif":"fas fa-music","apk":"fab fa-android","app":"fas fa-window","avi":"fas fa-video","bak":"fas fa-save","bat":"fas fa-terminal","bmp":"fas fa-image","box":"fas fa-archive","bz":"fas fa-file-archive","c":"fas fa-code","cfg":"fas fa-file-alt","class":"fas fa-code","cmd":"fas fa-terminal","com":"fas fa-window","cpp":"fas fa-code","css":"fab fa-css3","csv":"fas fa-file-csv","db":"fas fa-database","dbf":"fas fa-database","deb":"fas fa-archive","doc":"fas fa-file-word","docx":"fas fa-file-word","drw":"fas fa-image","email":"fas fa-envelope","empty":"fas fa-file-alt","eot":"fas fa-font-case","eps":"fas fa-image","erb":"fas fa-code","exe":"fab fa-windows","flac":"fas fa-music","flv":"fas fa-video","gam":"fas fa-gamepad","gif":"fas fa-image","gz":"fas fa-file-archive","htaccess":"fas fa-server","htm":"fab fa-html5","html":"fab fa-html5","ico":"fas fa-image","ini":"fas fa-cog","iso":"fas fa-compact-disc","jar":"fas fa-window","java":"fab fa-java","jpeg":"fas fa-image","jpg":"fas fa-image","js":"fab fa-js","json":"fas fa-file-alt","lock":"fas fa-lock","log":"fas fa-file-alt","mail":"fas fa-envelope","md":"fab fa-markdown","mdb":"fas fa-database","mid":"fas fa-music","midi":"fas fa-music","mkv":"fas fa-video","mov":"fas fa-video","mp3":"fas fa-music","mp4":"fas fa-video","mpa":"fas fa-music","mpg":"fas fa-video","msg":"fas fa-envelope","msi":"fas fa-window","nes":"fas fa-gamepad","odt":"fas fa-file-alt","ogg":"fas fa-music","ogv":"fas fa-video","otf":"fas fa-font-case","pdb":"fas fa-database","pdf":"fas fa-file-pdf","php":"fab fa-php","phtml":"fab fa-html5","pl":"fas fa-code","png":"fas fa-image","ppt":"fas fa-file-powerpoint","pptx":"fas fa-file-powerpoint","ps":"fas fa-image","psd":"fas fa-image","py":"fab fa-python","rar":"fas fa-file-archive","rb":"fas fa-code","rom":"fas fa-gamepad","rpm":"fas fa-archive","rtf":"fas fa-file-alt","sav":"fas fa-save","sh":"fas fa-terminal","sql":"fas fa-database","svg":"fas fa-image","swf":"fas fa-video","tar":"fas fa-file-archive","tga":"fas fa-image","tif":"fas fa-image","tiff":"fas fa-image","ttf":"fas fa-font-case","twig":"fab fa-php","txt":"fas fa-file-alt","vb":"fas fa-window","wav":"fas fa-music","webm":"fas fa-video","wma":"fas fa-music","wmv":"fas fa-video","woff":"fas fa-font-case","xhtml":"fas fa-code","xls":"fas fa-file-excel","xlsx":"fas fa-file-excel","xml":"fas fa-code","xz":"fas fa-file-archive","yaml":"fas fa-file-alt","zip":"fas fa-file-archive"}';

    /** Help topics, rendered as collapses on the help page. */
    const help = '{"search":{"title":"search files and folders","info":"Open the search box in the toolbar (or the search page) and type a keyword — SiFM matches it anywhere in the path, case-insensitively.<br>Wildcards work too: <code>*.log</code> matches every log file, <code>report*</code> matches everything starting with \"report\", and <code>?</code> stands for a single character. Results link straight to the file or folder."},"download":{"title":"download files","info":"Click the download icon in a listing, or the Download button on the file page.<br><b>Several at once:</b> tick the boxes in the listing, pick <i>Download</i> under \"With selected\", press <i>Go</i> — SiFM builds one ZIP of your selection and downloads it."}}';

    private static function abs(string $path): string
    {
        if(is_dir(SIFM_HOME)){
          $abs = SIFM_HOME . "/" . trim($path, "/");
        } else {
          $abs = $_SERVER['DOCUMENT_ROOT'] ."/". trim($path, "/");
        }
        return $abs;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->abs(path($path)));
    }

    public function writable(string $item): bool
    {
        return is_writable($this->abs(path($item)));
    }

    public function isfile(string $path): bool
    {
        return is_file($this->abs(path($path)));
    }

    public function isfolder(string $path): bool
    {
        return is_dir($this->abs(path($path)));
    }

    /** Children of a folder (no "." / "..", hidden files kept). $deep walks
     *  the whole tree — home-relative paths — and $folders adds the folder
     *  entries themselves, right before their contents. */
    public function list(string $folder, bool $deep = false, bool $folders = false): array
    {
        if (!$this->isfolder($folder)) {
            return [];
        }
        $items = scandir($this->abs(path($folder)));
        $items = $items ? array_values(array_diff($items, ['.', '..'])) : [];
        if (!$deep) {
            return $items;
        }
        $out = [];
        foreach ($items as $item) {
            $rel = $folder === '' ? $item : $folder . '/' . $item;
            if ($this->isfolder($rel)) {
                if ($folders) {
                    $out[] = $rel;
                }
                $out = [...$out, ...$this->list($rel, true, $folders)];
            } else {
                $out[] = $rel;
            }
        }
        return $out;
    }

    public function create(string $name, bool $dir = false): bool
    {
        $name = path($name);
        if ($name === '') {
            return false;
        }
        if ($dir) {
            return is_dir($this->abs($name)) || mkdir($this->abs($name), 0755, true);
        }
        return file_exists($this->abs($name)) || file_put_contents($this->abs($name), '') !== false;
    }

    /** $mod must be 3–4 octal digits ("644" or "0644"). */
    public function chmod(string $target, string $mod): bool
    {
        if (!preg_match('/0?[0-7]{3}$/', $mod)) {
            return false;
        }
        $target = path($target);
        if ($target === '' || !file_exists($this->abs($target))) {
            return false;
        }
        return chmod($this->abs($target), intval($mod, 8));
    }

    public function copy(string $from, string $to): bool
    {
        $from = path($from);
        $to = path($to);
        if ($from === '' || $to === '') {
            return false;
        }
        if ($this->isfolder($from)) {
            if (!$this->isfolder($to) && !$this->create($to, true)) {
                return false;
            }
            foreach ($this->list($from) as $item) {
                if (!$this->copy($from . '/' . $item, $to . '/' . $item)) {
                    return false;
                }
            }
            return true;
        }
        if (!$this->isfile($from)) {
            return false;
        }
        $dest_dir = dirname($to);
        if ($dest_dir !== '.' && !$this->isfolder($dest_dir) && !$this->create($dest_dir, true)) {
            return false;
        }
        return copy($this->abs($from), $this->abs($to));
    }

    public function move(string $from, string $to): bool
    {
        $from = path($from);
        $to = path($to);
        if ($from === '' || $to === '') {
            return false;
        }
        $dest_dir = dirname($to);
        if ($dest_dir !== '.' && !$this->isfolder($dest_dir) && !$this->create($dest_dir, true)) {
            return false;
        }
        return rename($this->abs($from), $this->abs($to));
    }

    /** Rename inside the item's own folder — $new_name may not contain "/". */
    public function rename(string $from, string $new_name): bool
    {
        $from = path($from);
        if ($from === '' || $new_name === '' || str_contains($new_name, '/')) {
            return false;
        }
        $dest = path(dirname($from) . '/' . $new_name);
        return rename($this->abs($from), $this->abs($dest));
    }

    /** Name of the hidden folder deletions are parked in, at the home root. */
    const trash = '.sifm-trash';

    /** Manifest inside the trash: entry id → the path it came from, so a
     *  restore can put it back exactly where it was. */
    private function manifest(): string
    {
        return $this->abs(self::trash . '/.manifest.json');
    }

    /** Park a file or folder in the trash instead of unlinking it: one atomic
     *  rename, the original path remembered, nothing recursive to go wrong. */
    public function trash(string $target): bool
    {
        $target = path($target);
        if ($target === '' || !file_exists($this->abs($target))) {
            return false;
        }
        /* Never take the trash itself, and never take the home root. */
        if ($target === self::trash || str_starts_with($target, self::trash . '/')) {
            return false;
        }
        if (!is_dir($this->abs(self::trash)) && !mkdir($this->abs(self::trash), 0755, true)) {
            return false;
        }
        /* id is unique by time + randomness: the name the item carries in the
           trash, so two deletes of the same filename never collide. */
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . basename($target);
        if (!rename($this->abs($target), $this->abs(self::trash . '/' . $id))) {
            return false;
        }
        $map = $this->trashed();
        $map[$id] = ['from' => $target, 'at' => time(), 'dir' => $this->isfolder(self::trash . '/' . $id)];
        file_put_contents($this->manifest(), json_encode($map, JSON_PRETTY_PRINT));
        return true;
    }

    /** The manifest, oldest first. An unreadable or absent file reads as empty. */
    public function trashed(): array
    {
        $raw = @file_get_contents($this->manifest());
        $map = $raw ? json_decode($raw, true) : [];
        return is_array($map) ? $map : [];
    }

    /** Put one entry back where it came from. Refuses if that path is now
     *  taken — a restore must never overwrite whatever moved in since. */
    public function restore(string $id): bool
    {
        $map = $this->trashed();
        if (!isset($map[$id])) {
            return false;
        }
        $from = (string) ($map[$id]['from'] ?? '');
        $parked = self::trash . '/' . $id;
        if ($from === '' || !file_exists($this->abs($parked))) {
            return false;
        }
        if (file_exists($this->abs($from))) {
            return false;
        }
        $dest_dir = dirname($from);
        if ($dest_dir !== '.' && !$this->isfolder($dest_dir) && !$this->create($dest_dir, true)) {
            return false;
        }
        if (!rename($this->abs($parked), $this->abs($from))) {
            return false;
        }
        unset($map[$id]);
        file_put_contents($this->manifest(), json_encode($map, JSON_PRETTY_PRINT));
        return true;
    }

    /** Destroy one entry for good, or the whole trash with $all. */
    public function purge(?string $id = null): bool
    {
        $map = $this->trashed();
        $ids = $id === null ? array_keys($map) : [$id];
        $ok = true;
        foreach ($ids as $one) {
            $parked = self::trash . '/' . $one;
            if (file_exists($this->abs($parked)) && !$this->delete($parked)) {
                $ok = false;
                continue;
            }
            unset($map[$one]);
        }
        file_put_contents($this->manifest(), json_encode($map, JSON_PRETTY_PRINT));
        return $ok;
    }

    /** Delete for real — recursive unlink. The trash uses this to empty out. */
    public function delete(string $target): bool
    {
        $target = path($target);
        if ($target === '' || !file_exists($this->abs($target))) {
            return false;
        }
        if ($this->isfolder($target)) {
            foreach ($this->list($target) as $item) {
                $this->delete($target . '/' . $item);
            }
            return rmdir($this->abs($target));
        }
        return unlink($this->abs($target));
    }


    /** A file → itself; a folder → itself plus everything under it.
     *  The path list used by backups and multi-download ZIPs. */
    public function collect(string $item): array
    {
        $item = path($item);
        if ($item === '') {
            return [];
        }
        if ($this->isfolder($item)) {
            return array_merge([$item], $this->list($item, true, true));
        }
        return $this->isfile($item) ? [$item] : [];
    }

    /** Write $paths (home-relative) into zip $out. Empty folders are kept. */
    public function zip(array $paths, string $out): bool
    {
        $out = path($out);
        if ($out === '') {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($this->abs($out), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $ok = true;
        foreach ($paths as $p) {
            if ($this->isfolder($p)) {
                $ok = ($this->list($p) !== [] || $zip->addEmptyDir($p)) && $ok;
            } elseif ($this->isfile($p)) {
                $ok = $zip->addFile($this->abs($p), $p) && $ok;
            }
        }
        $closed = $zip->close();
        return $closed && $ok;
    }

    /** Zip a whole folder — or just $items picked from it — into $name.
     *  Leave $items out to back up everything under $folder. */
    public function backup(string $folder, string $name = '', ?array $items = null): bool
    {
        $folder = path($folder);
        $name = $name !== '' ? $name : 'Backup-' . date('YmdHis') . '.zip';
        $base = $folder !== '' ? $folder . '/' : '';
        $targets = [$folder];
        if ($items !== null) {
            $targets = [];
            foreach ($items as $item) {
                $item = path((string) $item);
                if ($item !== '') {
                    $targets[] = $base . $item;
                }
            }
        }
        $paths = [];
        foreach ($targets as $target) {
            $paths = [...$paths, ...$this->collect($target)];
        }
        return $this->zip($paths, $name);
    }

    public function extract(string $file, string $dest = ''): bool
    {
        $file = path($file);
        $zip = new ZipArchive();
        if ($file === '' || $zip->open($this->abs($file)) !== true) {
            return false;
        }
        $ok = $zip->extractTo($this->abs(path($dest)));
        $zip->close();
        return $ok;
    }

    public function upload(array $file, string $dir): bool
    {
        $dir = path($dir);
        $name = basename((string) ($file['name'] ?? ''));
        if ($name === '' || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return false;
        }
        $target = ($dir !== '' ? $dir . '/' : '') . $name;
        return move_uploaded_file($file['tmp_name'], $this->abs($target));
    }

    /** Stream a file to the browser and stop. */
    public function download(string $file): void
    {
        $file = path($file);
        if ($file === '' || !$this->isfile($file)) {
            message('File not found.', 0);
            redirect('folder');
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename($file)) . '"');
        header('Content-Length: ' . filesize($this->abs($file)));
        header('Cache-Control: no-store');
        readfile($this->abs($file));
        exit;
    }

    public function source(string $file): string
    {
        if (!$this->isfile($file)) {
            return '';
        }
        return (string) file_get_contents($this->abs(path($file)));
    }

    /** Fetch an http(s) URL into a home-relative destination. Other schemes
     *  (file://, ftp:// …) are refused — this is not an SSRF Swiss army knife. */
    /** Stream a URL into $dest — null on success, or the one reason it failed. */
    public function import(string $url, string $dest): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Only http(s) URLs can be imported.';
        }
        $ctx = stream_context_create(['http' => [
            'header'      => "Accept: */*\r\n",
            'user_agent'  => SIFM_NAME . '/' . SIFM_VERSION,
            'method'      => 'GET',
            'timeout'     => 20,
        ]]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            return 'Could not reach that URL.';
        }
        $tmp  = tempnam(sys_get_temp_dir(), 'sifm');
        $out  = @fopen($tmp, 'wb');
        $size = $out === false ? false : stream_copy_to_stream($in, $out, SIFM_MAX_IMPORT + 1); // +1 to spot the overflow
        fclose($in);
        $out === false || fclose($out);
        if ($size === false) {
            @unlink($tmp);
            return 'The download broke part-way.';
        }
        if ($size > SIFM_MAX_IMPORT) {
            @unlink($tmp);
            return 'That file is over the ' . (int) (SIFM_MAX_IMPORT / 1048576) . ' MB import cap.';
        }
        $dest = path($dest);
        if ($dest === '') {
            @unlink($tmp);
            return 'That name cannot be a file name.';
        }
        if (!@rename($tmp, $this->abs($dest)) && !@copy($tmp, $this->abs($dest))) { // rename first; copy when the filesystems differ
            @unlink($tmp);
            return 'Could not write the file into this folder.';
        }
        @unlink($tmp); // rename consumed it already — a no-op then
        return null;
    }

    /** Size + MIME + perms + dates for the file details page and the table. */
    public function info(string $item): array
    {
        $path = $this->abs(path($item));
        $info = [];
        if (is_file($path)) {
            $info['size'] = $this->size($item);
            $info['last_accessed'] = date(SIFM_DATE, fileatime($path));
            $info['last_modified'] = date(SIFM_DATE, filemtime($path));
            $info += pathinfo($path);
            $info['owner'] = fileowner($path);
            $info['perms'] = intval(substr(sprintf('%o', fileperms($path)), -4));
            $info['path'] = realpath($path);
            $info['mimetype'] = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        } else {
            $info['perms'] = intval(substr(sprintf('%o', fileperms($path)), -4));
            $info['basename'] = basename($path);
            $info['dirname'] = dirname($path);
            $info['path'] = realpath($path);
            $info['owner'] = fileowner($path);
            $info['last_accessed'] = date(SIFM_DATE, fileatime($path));
            $info['last_modified'] = date(SIFM_DATE, filemtime($path));
        }
        ksort($info);
        return $info;
    }

    /** Human-readable size, 2 dp ("1.21 MB", "512 B"). $bytes skips the stat. */
    public function size(string $item, int $bytes = 0): string
    {
        if ($bytes) {
            $size = $bytes;
        } else {
            $path = $this->abs(path($item));
            if (is_file($path)) {
                $size = filesize($path);
            } else {
                $size = 0;
                foreach ($this->list($item, true) as $file) {
                    $size += (int) filesize($this->abs($file));
                }
            }
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size = round($size / 1024, 2);
        }
        return $size . ' ' . $units[$i];
    }

    /** Keyword (substring) or wildcard (* ?) search of a subtree — returns
     *  files AND folders, home-relative. Case-insensitive. */
    public function search(string $q, string $folder = ''): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $items = $this->list($folder, true, true);
        if (str_contains($q, '*') || str_contains($q, '?')) {
            $re = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($q, '/')) . '$/iu';
            return array_values(preg_grep($re, $items) ?: []);
        }
        $needle = mb_strtolower($q);
        return array_values(array_filter($items, fn($i) => str_contains(mb_strtolower($i), $needle)));
    }
}