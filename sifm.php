<?php

/**** SiFM — Simple File Manager  Configuration. ****/

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

/**** Routing table — a query flag (?action&f=path) picks the page.
 **** A new page registers in three places: here, $title in dispatch, and the
 **** render switch at the bottom. Missing any one silently falls back home. ****/
$actions = [
    'signin', 'signout', 'folder', 'edit', 'source', 'backup', 'copy', 'move',
    'import', 'upload', 'create', 'download', 'delete', 'file', 'chmod',
    'extract', 'rename', 'syntax', 'multi', 'search', 'duplicate', 'clip',
    'paste', 'image',
];

/**** The multi-select actions: the key is the kind, the label is what the
 **** select and the button show. The select, the validation and the handler
 **** all read this one list. ****/
$kinds = [
    'backup' => 'Backup', 'copy' => 'Copy to…', 'move' => 'Move to…',
    'chmod' => 'Chmod', 'download' => 'Download ZIP', 'delete' => 'Delete',
    'pick' => 'Copy to clipboard', 'cut' => 'Cut to clipboard',
    'rename' => 'Rename with a pattern',
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
session_name(SIFM_ID);
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
$needs_item = ['chmod', 'copy', 'move', 'rename', 'delete', 'duplicate'];
$needs_file = ['file', 'edit', 'source', 'syntax', 'extract', 'image'];
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
if ($action === 'image') {
    if (!@getimagesize(SIFM_HOME . '/' . $file)) {
        message('That file is not an image.', 2);
        redirect('file', $file);
    }
    if (!$fm->writable($file)) {
        message('File is not writable — chmod it first, then edit.', 0);
        redirect('file', $file);
    }
}
if ($action === 'multi') {
    $kind = post('action');
    $items = array_values(array_filter((array) ($_POST['items'] ?? []), 'is_string'));
    if (!isset($kinds[$kind]) || !$items) {
        message('Nothing selected.', 2);
        redirect('folder', $folder);
    }
}
/* Nothing to paste without a clipboard. */
if ($action === 'paste' && empty($_SESSION['clip'])) {
    redirect('folder', $folder);
}
/* Listing preferences — the sort bar posts them, they live in the session, so
   every link on the page keeps them without a query string. */
if ($action === 'folder' && post('sort') !== '') {
    if (post('reset') !== '') {
        unset($_SESSION['listing']); // back to the defaults
    } else {
        $_SESSION['listing'] = [
            'sort' => in_array(post('sort'), ['name', 'size', 'date', 'type'], true) ? post('sort') : 'name',
            'dir' => post('dir') === 'desc' ? 'desc' : 'asc',
            'hidden' => post('hidden') === '1' ? '1' : '0',
            'ext' => path(post('ext')),
        ];
    }
    redirect('folder', $folder);
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

    case 'chmod': case 'copy': case 'move': case 'rename': case 'delete': case 'duplicate': {
        /* The five single-item actions differ only in the button that fires
         * them, the two messages and the call itself — one row each, one body.
         * They all land back in the item's own folder. */
        $ops = [
            'copy'   => ['copy', 'Copy successful!',     'Failed to copy.',                                  fn($t) => $fm->copy($t, path(post('new')))],
            'move'   => ['move', 'Moving successful!',   'Moving failed.',                                   fn($t) => $fm->move($t, path(post('new')))],
            'rename' => ['ren',  'Renaming successful!', 'Renaming failed — no slashes in the new name.',     fn($t) => $fm->rename($t, trim(post('new')))],
            'chmod'  => ['mod',  'Chmod successful!',    'Chmod failed — check the octal mode and PHP ownership of the item.', fn($t) => $fm->chmod($t, trim(post('mod')))],
            'delete' => ['del',  'Deletion successful!', 'Deletion failed.',                                  fn($t) => $fm->delete($t)],
            'duplicate' => ['dup', 'Duplicated!', 'Duplication failed.',                               fn($t) => $fm->copy($t, rel(dirname($t), path(post('new'))))],
        ];
        [$btn, $ok, $err, $run] = $ops[$action];
        if (post($btn) !== '') {
            $done = $run($target);
            message($done ? $ok : $err, $done ? 1 : 0);
            redirect('folder', dirname($target));
        }
        break;
    }

    case 'create':
        if (post('create') !== '') {
            $name = path(post('i'));
            if ($name === '') {
                message('Invalid name.', 0);
            } elseif ($fm->create(rel($listing, $name), post('folder') === '1')) {
                message('Creation successful!');
            } else {
                message('Failed to create.', 0);
            }
            redirect('folder', $listing);
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
            $why  = $fm->import(trim(post('url')), rel($folder, $name));
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
                : ($saved ? 'Saved successfully!' : 'Save failed!');
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
        if (in_array($kind, ['pick', 'cut'], true)) {
            redirect('folder', $folder); // the clipboard kinds post to the clip action
        }
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
                foreach ($items as $n => $item) {
                    $item = path($item);
                    if ($item === '') {
                        $ok = false;
                        continue;
                    }
                    $full = rel($folder, $item);
                    $ok = match ($kind) {
                        'copy'   => $fm->copy($full, $dest . '/' . $item),
                        'move'   => $fm->move($full, $dest . '/' . $item),
                        'chmod'  => $fm->chmod($full, $dest),
                        'delete' => $fm->delete($full),
                        'rename' => $fm->rename($full, rename_pattern($dest, $item, $n + 1)),
                    } && $ok;
                }
            }
            message(($ok ? ucfirst($kind) . ' successful!' : ucfirst($kind) . ' failed.'), $ok ? 1 : 0);
            redirect('folder', $folder);
        }
        break; // no "submit": fall through to the confirmation screen below

    case 'clip':
        /* The clipboard: items staged for a later paste. $mode is copy or cut,
           $from is the folder they live in. */
        $mode = post('mode') === 'cut' ? 'cut' : 'copy';
        $staged = array_values(array_filter(array_map('path', array_filter((array) ($_POST['items'] ?? []), 'is_string'))));
        if (!$staged) {
            message('Nothing to copy.', 2);
            redirect('folder', $folder);
        }
        $_SESSION['clip'] = ['mode' => $mode, 'from' => $folder, 'items' => $staged];
        message(($mode === 'cut' ? 'Cut ' : 'Copied ') . count($staged) . ' item(s) — paste them where they belong.');
        redirect('folder', $folder);
        break;

    case 'paste':
        $clip = $_SESSION['clip'] ?? null;
        $done = 0;
        $left = [];
        foreach ($clip['items'] as $item) {
            $src = rel($clip['from'], $item);
            $dst = rel($folder, $item);
            if ($src === $dst || $fm->exists($dst)) {
                $left[] = $item; // same folder, or the name is taken
                continue;
            }
            $ok = $clip['mode'] === 'cut' ? $fm->move($src, $dst) : $fm->copy($src, $dst);
            if ($ok) {
                $done++;
            } else {
                $left[] = $item;
            }
        }
        if ($clip['mode'] === 'cut') {
            unset($_SESSION['clip']); // a cut is spent
        }
        $text = $done > 0 ? 'Pasted ' . $done . ' item(s).' : 'Nothing pasted.';
        if ($left) {
            $text .= ' Left alone: ' . implode(', ', $left) . '.';
        }
        message($text, $done > 0 ? 1 : 0);
        redirect('folder', $folder);
        break;

    case 'image':
        if (post('op') !== '') {
            $what = $fm->image($file, post('op'), (int) post('w'));
            message($what ?? 'The image tool failed.', $what !== null ? 1 : 0);
            redirect('file', $file);
        }
        break;
    }
}

/**** GET-only shortcuts — run before any output so headers still fly. ****/
if ($action === 'download') {
    $fm->download($file);
}
if ($action === 'signout') {
    session_destroy();
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    redirect('signin');
}

/**** Page title (register new pages here too). ****/
$title = match (true) {
    $action === 'signin'    => 'Sign in',
    $action === 'folder'    => 'Folder: ' . ($listing !== '' ? $listing : '/'),
    $action === 'multi'     => ucfirst(post('action', 'items')),
    default                 => ucfirst($action) . ': /' . ($file !== '' ? $file : $folder),
};

/* The actions the toolbar shows and the command palette offers — one list,
 * two views: [label, icon, url, key, extra class]. The key is the single-key
 * shortcut; delete gets none, a stray keystroke must never destroy anything. */
$tools = [
    ['Create', 'circle-plus', url('create', $listing), 'n', ''],
    ['Upload', 'upload', url('upload', $listing), 'u', ''],
    ['Import', 'cloud-arrow-down', url('import', $listing), 'i', ''],
    ['Backup', 'suitcase', url('backup', $listing), 'b', ''],
];
if ($action === 'folder' && $folder !== '') {
    $tools[] = ['Copy', 'copy', url('copy', $folder), 'c', ''];
    $tools[] = ['Move', 'exchange', url('move', $folder), 'm', ''];
    $tools[] = ['Rename', 'pencil', url('rename', $folder), 'r', ''];
    $tools[] = ['Delete', 'trash', url('delete', $folder), '', 'text-danger'];
}
if (!empty($_SESSION['clip'])) {
    $tools[] = ['Paste here', 'paste', url('paste', $listing), 'p', ''];
}
$palette = array_merge([['Home folder', 'house', url('folder'), 'h', '']], $tools);

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
/* Everything that must exist exactly once. Passing this to ?external= stops a
   package from resolving its own copy: the emitted import stays bare and lands
   on the same instance my module and every other package use. This includes
   the Lezer parser packages, whose tags must match @lezer/highlight exactly. */
$shared = implode(',', array_keys($cm));
$map = [];
foreach ($cm as $pkg => $ver) {
    $dependencies = [];
    $external = [];
    foreach ($cm as $dependency => $dependency_version) {
        if ($dependency !== $pkg) {
            $dependencies[] = "{$dependency}@{$dependency_version}";
            $external[] = $dependency;
        }
    }
    $map[$pkg] = $url(
        $pkg,
        $ver,
        '?deps=' . implode(',', $dependencies) . '&external=' . implode(',', $external)
    );
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
  };
  apply();
  os.addEventListener('change', () => { if (localStorage.getItem(key) === null) apply(); });
  return { apply, flip: () => { const n = read() === 'dark' ? 'light' : 'dark'; localStorage.setItem(key, n); apply(n); } };
})();
</script>
<style>
/* SiFM — "Bullion": a dark-first gold-on-graphite theme on Bootstrap 5.3.
   Bootstrap is re-skinned through --bs-* tokens only; bespoke classes exist
   only where Bootstrap has no primitive (header, icon-btn, login,
   editor). Light mode lifts everything through [data-bs-theme]. */
:root, [data-bs-theme="dark"] {
  color-scheme: dark;
  --bs-font-sans-serif: 'IBM Plex Sans', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
  --bs-font-monospace: 'IBM Plex Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
  --bs-body-font-family: var(--bs-font-sans-serif); --bs-body-line-height: 1.65;

  /* Surfaces — graphite */
  --bs-body-bg: #101216; --bs-body-bg-rgb: 16, 18, 22;
  --bs-body-color: #d4d7dd; --bs-body-color-rgb: 212, 215, 221;
  --bs-heading-color: #f4f4f3; --bs-emphasis-color: #f4f4f3; --bs-emphasis-color-rgb: 244, 244, 243;
  --bs-secondary-color: #8b909a; --bs-secondary-rgb: 139, 144, 154; --bs-secondary-bg: #171a1f; --bs-tertiary-bg: #1c2026;
  --bs-border-color: rgba(255, 255, 255, .11); --bs-border-color-translucent: rgba(255, 255, 255, .07);
  --surface: #171a20; --surface-2: #1e222a;

  /* Brand — bullion gold */
  --bs-primary: #f2b33d; --bs-primary-rgb: 242, 179, 61; --bs-primary-text-emphasis: #f7cd85;
  --bs-primary-bg-subtle: rgba(242, 179, 61, .13); --bs-primary-border-subtle: rgba(242, 179, 61, .32);
  --on-primary: #1d1504; --gold: #f2b33d; --gold-hover: #e0a12c; --gold-active: #cc901f;

  /* Second accent — ember, gradients only */
  --ember: #ff8f3f; --ember-rgb: 255, 143, 63;

  /* Code surface + syntax tokens — the atom-one highlight.js theme, value for
     value, so the editor and the source view are one and the same theme. */
  --code-bg: #282c34; --code-fg: #abb2bf; --code-gut: #21252b; --code-line: #23272f;
  --code-sel: rgba(242, 179, 61, .22);
  --tok-keyword: #c678dd; --tok-string: #98c379; --tok-number: #d19a66;
  --tok-comment: #5c6370; --tok-title: #61aeee; --tok-attr: #e06c75;
  --tok-builtin: #e6c07b; --tok-literal: #56b6c2;

  --bs-warning: #f59e0b; --bs-warning-rgb: 245, 158, 11;
  --bs-success: #46c98b; --bs-success-rgb: 70, 201, 139;
  --bs-danger: #ff6b6b; --bs-danger-rgb: 255, 107, 107;
  --bs-info: #6aa8ff; --bs-info-rgb: 106, 168, 255;

  --bs-link-color-rgb: var(--bs-primary-rgb); --bs-link-hover-color-rgb: 247, 205, 133;

  --bs-border-radius: .7rem; --bs-border-radius-sm: .45rem;
  --bs-border-radius-lg: 1rem; --bs-border-radius-xl: 1.3rem;

  --bs-box-shadow-sm: 0 1px 2px rgba(0, 0, 0, .4), 0 8px 22px -12px rgba(0, 0, 0, .7);
  --bs-box-shadow: 0 2px 6px rgba(0, 0, 0, .45), 0 20px 45px -20px rgba(0, 0, 0, .8);

  --snap: .18s ease; --focus: 0 0 0 .22rem rgba(242, 179, 61, .3);
}

[data-bs-theme="light"] {
  color-scheme: light;

  --bs-body-bg: #f7f6f2; --bs-body-bg-rgb: 247, 246, 242;
  --bs-body-color: #2a2721; --bs-body-color-rgb: 42, 39, 33;
  --bs-heading-color: #17150f; --bs-emphasis-color: #000; --bs-emphasis-color-rgb: 0, 0, 0;
  --bs-secondary-color: #6f6a5e; --bs-secondary-rgb: 111, 106, 94; --bs-secondary-bg: #eeece5; --bs-tertiary-bg: #e7e4db;
  --bs-border-color: rgba(23, 21, 15, .14); --bs-border-color-translucent: rgba(23, 21, 15, .08);
  --surface: #fff; --surface-2: #f1efe8;

  --bs-primary: #9a6f0c; --bs-primary-rgb: 154, 111, 12; --bs-primary-text-emphasis: #7c5a09;
  --bs-primary-bg-subtle: #f6ebd2; --bs-primary-border-subtle: #e6d0a2;
  --on-primary: #fff; --gold: #9a6f0c; --gold-hover: #85600a; --gold-active: #715108;

  --ember: #d96a26; --ember-rgb: 217, 106, 38;

  /* Code surface + syntax tokens — atom-one-light, the sheet the source view
     loads in light mode, value for value. */
  --code-bg: #fafafa; --code-fg: #383a42; --code-gut: #f0f0f0; --code-line: #f2f2f2;
  --code-sel: rgba(154, 111, 12, .18);
  --tok-keyword: #a626a4; --tok-string: #50a14f; --tok-number: #986801;
  --tok-comment: #a0a1a7; --tok-title: #4078f2; --tok-attr: #e45649;
  --tok-builtin: #c18401; --tok-literal: #0184bb;

  --bs-warning: #b45309; --bs-warning-rgb: 180, 83, 9;
  --bs-success: #14855a; --bs-success-rgb: 20, 133, 90;
  --bs-danger: #d43d3d; --bs-danger-rgb: 212, 61, 61;
  --bs-info: #2563d9; --bs-info-rgb: 37, 99, 217;

  --bs-link-color-rgb: 154, 111, 12; --bs-link-hover-color-rgb: 113, 81, 8;

  --bs-box-shadow-sm: 0 1px 2px rgba(23, 21, 15, .06), 0 8px 20px -12px rgba(23, 21, 15, .18);
  --bs-box-shadow: 0 2px 6px rgba(23, 21, 15, .08), 0 20px 42px -22px rgba(23, 21, 15, .28);

  --focus: 0 0 0 .22rem rgba(154, 111, 12, .25);
}

body {
  min-height: 100vh; background-color: var(--bs-body-bg); background-attachment: fixed; -webkit-font-smoothing: antialiased;
  background-image: radial-gradient(1000px 520px at 85% -10%, rgba(var(--bs-primary-rgb), .09), transparent 60%),
                    radial-gradient(800px 460px at -5% -5%, rgba(var(--ember-rgb), .06), transparent 55%);
}
h1, h2, h3, h4, h5, h6 { font-weight: 600; letter-spacing: -.015em; }
code { font-family: var(--bs-font-monospace); color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), .1); padding: .1em .4em; border-radius: var(--bs-border-radius-sm); }
::selection { background: rgba(var(--bs-primary-rgb), .3); }
a { text-decoration: none; }
a:not(.btn):not(.icon-btn):hover { text-decoration: underline; text-underline-offset: .2em; }

/* ---- Buttons: pill shape, gold primary, a quiet ghost variant. ---- */
.btn {
  --bs-btn-border-radius: 999px; --bs-btn-font-weight: 600;
  display: inline-flex; align-items: center; justify-content: center; gap: .45em;
  transition: transform var(--snap), box-shadow var(--snap), background-color var(--snap), border-color var(--snap), color var(--snap);
}
.btn:active { transform: translateY(1px); }
.btn:focus-visible { box-shadow: var(--focus); }
.btn-primary {
  --bs-btn-color: var(--on-primary); --bs-btn-bg: var(--bs-primary); --bs-btn-border-color: var(--bs-primary);
  --bs-btn-hover-color: var(--on-primary); --bs-btn-hover-bg: var(--gold-hover); --bs-btn-hover-border-color: var(--gold-hover);
  --bs-btn-active-color: var(--on-primary); --bs-btn-active-bg: var(--gold-active); --bs-btn-active-border-color: var(--gold-active);
  --bs-btn-focus-shadow-rgb: var(--bs-primary-rgb);
}
.btn-primary:hover { box-shadow: 0 10px 26px -12px rgba(var(--bs-primary-rgb), .7); }
.btn-ghost {
  --bs-btn-color: var(--bs-body-color); --bs-btn-bg: transparent; --bs-btn-border-color: var(--bs-border-color);
  --bs-btn-hover-color: var(--bs-primary); --bs-btn-hover-bg: var(--surface-2); --bs-btn-hover-border-color: rgba(var(--bs-primary-rgb), .55);
  --bs-btn-active-color: var(--bs-primary); --bs-btn-active-bg: var(--surface-2); --bs-btn-active-border-color: rgba(var(--bs-primary-rgb), .55);
  --bs-btn-focus-shadow-rgb: var(--bs-primary-rgb);
}
.form-control, .form-select { border-radius: 999px; }
textarea.form-control { border-radius: var(--bs-border-radius); }
.form-check-input:focus { border-color: var(--bs-primary); box-shadow: var(--focus); }
.form-check-input:checked { background-color: var(--bs-primary); border-color: var(--bs-primary); }

/* ---- Header: sticky, blurred, brand + icon cluster. ---- */
html { --site-head-h: 3.4rem; }
/* .sticky-top + .z-3 give the position, the offset and the stacking order —
   only the blur, the height and the hairline are bespoke. */
.site-header {
  height: var(--site-head-h);
  background: rgba(var(--bs-body-bg-rgb), .82); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--bs-border-color);
}
.brand-mark {
  width: 2.3rem; height: 2.3rem; display: inline-flex; align-items: center; justify-content: center;
  border-radius: .75rem; font-family: var(--bs-font-monospace); font-weight: 700; color: var(--on-primary);
  background-image: linear-gradient(135deg, var(--bs-primary), var(--ember));
  box-shadow: 0 8px 22px -10px rgba(var(--bs-primary-rgb), .8);
}
.brand-name { font-weight: 700; letter-spacing: -.01em; color: var(--bs-heading-color); line-height: 1; }
.brand:hover .brand-name { color: var(--bs-primary); text-decoration: none; }

/* ---- Icon buttons: one ring around a bare glyph; each context only sizes it.
   The dark ring is stronger because the 0.11 ring vanishes on deep graphite. ---- */
.icon-btn {
  display: inline-flex; align-items: center; justify-content: center; padding: 0; background: none;
  border: 1px solid var(--bs-border-color); border-radius: 999px; color: var(--bs-secondary-color);
  transition: color var(--snap), background-color var(--snap), border-color var(--snap);
}
.icon-btn:hover { color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), .12); border-color: rgba(var(--bs-primary-rgb), .45); text-decoration: none; }
[data-bs-theme="dark"] .icon-btn { border-color: rgba(255, 255, 255, .3); }
.header-tools .icon-btn { width: 2.5rem; height: 2.5rem; font-size: 1.3rem; }
.cell-actions .icon-btn { width: 1.85rem; height: 1.85rem; font-size: .95rem; }
/* Cells run on white-space: pre, so the buttons sit flush — the ring needs its own gap. */
.cell-actions .icon-btn + .icon-btn { margin-left: .5rem; }

/* ---- Search: the box is the only thing that needs a width of its own. ---- */
.search-form .input-group { width: min(21rem, 70vw); }

/* ---- Table on a card: cells stay transparent over the rounded surface. ---- */
.table { --bs-table-bg: transparent; --bs-table-color: var(--bs-body-color); --bs-table-border-color: var(--bs-border-color); --bs-table-striped-bg: rgba(var(--bs-body-color-rgb), .03); --bs-table-hover-bg: rgba(var(--bs-primary-rgb), .06); }
.table thead th { color: var(--bs-secondary-color); font-weight: 600; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; }
/* Names, sizes and timestamps stay byte-for-byte as they are on disk; the
   card already scrolls sideways, so nothing is lost. */
table th, table td { white-space: pre; }
.col-check { width: 1%; }
/* The name keeps the body colour (Bootstrap's .text-body) and turns gold on
   hover — the hover needs !important to beat that utility's own !important. */
.cell-name a:hover { color: var(--bs-primary) !important; }
.cell-name i { width: 1.6rem; color: var(--bs-secondary-color); text-align: center; }
.perms { color: var(--bs-secondary-color); }
.perms:hover { color: var(--bs-primary); }

/* ---- Cards / panels / flashes ---- */
/* Bootstrap declares --bs-card-box-shadow but never reads it in 5.3.3 (vestigial,
   like --bs-link-decoration), so the shadow and radius are set directly. */
.card { --bs-card-bg: var(--surface); --bs-card-border-color: var(--bs-border-color); --bs-card-cap-bg: transparent; box-shadow: var(--bs-box-shadow-sm); border-radius: var(--bs-border-radius-lg); }
.panel-head { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; justify-content: space-between; margin-bottom: .9rem; position: sticky; top: var(--site-head-h); z-index: 1020; background: var(--bs-body-bg); }
#flash-toast, #editor-save-toast { position: fixed; top: 50%; left: 50%; z-index: 1091; width: min(28rem, calc(100vw - 2rem)); margin: 0; transform: translate(-50%, -50%); text-align: center; box-shadow: var(--bs-box-shadow); transition: opacity .3s ease, transform .3s ease; }
#flash-toast.flash-toast-hide, #editor-save-toast.flash-toast-hide { opacity: 0; transform: translate(-50%, calc(-50% - .5rem)); }
.kv { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1.2rem; }
.kv dd { margin: 0; overflow-wrap: anywhere; }

/* ---- Sign-in card ---- */
.login-card .brand-mark { width: 3rem; height: 3rem; font-size: 1.2rem; margin-inline: auto; }

/* ---- Code pages: source view (highlight.js) and editor (CodeMirror 6) ----
   CodeMirror fills .ed and paints its own scrollbars; only the shell is here.
   The editor and its textarea fallback share one viewport-driven height. ---- */
pre.src, pre.out, .ed { border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius); background: var(--code-bg); }
pre.src, pre.out { padding: 1rem; font-size: .86rem; }
pre.src { overflow-x: auto; line-height: 1.6; font-family: 'JetBrains Mono', var(--bs-font-monospace); }
pre.src code { background: none; color: inherit; font-family: inherit; padding: 0; }
pre.src .hljs-ln { border-collapse: collapse; } /* highlight.js line-numbers plugin */
pre.src .hljs-ln-numbers { user-select: none; text-align: right; color: var(--bs-secondary-color); opacity: .7; border-right: 1px solid var(--bs-border-color); padding-right: .75rem; vertical-align: top; white-space: nowrap; }
pre.src .hljs-ln-code { padding-left: .75rem; white-space: pre; }
pre.src .hljs-ln-line { height: 1.6em; }
pre.out { white-space: pre-wrap; overflow-wrap: anywhere; }
.cm-editor, #editor { height: clamp(16rem, calc(100vh - 10.5rem), 68rem); height: clamp(16rem, calc(100dvh - 10.5rem), 68rem); }
.drop-zone { border: 2px dashed var(--bs-border-color); border-radius: var(--bs-border-radius); padding: 1.2rem; text-align: center; transition: border-color .15s, background-color .15s; }
.drop-zone.drag-over { border-color: var(--bs-primary); background-color: rgba(var(--bs-primary-rgb), .08); }
.drop-zone .form-control { max-width: 26rem; margin-inline: auto; }
.ed { overflow: hidden; }
.ed .cm-editor { height: 100%; }
.ed .cm-scroller { overflow: auto; font-family: var(--bs-font-monospace); font-size: .9rem; line-height: 1.6; }
.ed .cm-gutters { border-right: 1px solid var(--bs-border-color); }

/* ---- Markdown preview: the rendered document, in the app's own tokens. ---- */
.md { line-height: 1.7; }
.md > :first-child { margin-top: 0; }
.md h1, .md h2, .md h3, .md h4 { font-weight: 600; letter-spacing: -.015em; margin: 1.4rem 0 .6rem; }
.md h1 { font-size: 1.7rem; } .md h2 { font-size: 1.4rem; } .md h3 { font-size: 1.15rem; } .md h4 { font-size: 1rem; }
.md p { margin-bottom: .9rem; }
.md ul, .md ol { margin-bottom: .9rem; padding-left: 1.5rem; }
.md li { margin-bottom: .25rem; }
.md hr { border-color: var(--bs-border-color); opacity: 1; }
.md blockquote { border-left: 3px solid var(--bs-border-color); padding: .2rem 0 .2rem 1rem; color: var(--bs-secondary-color); }
.md pre { background: var(--code-bg); border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius); padding: 1rem; overflow-x: auto; }
.md pre code { background: none; color: inherit; padding: 0; }
.md table { width: 100%; margin-bottom: 1rem; border-collapse: collapse; }
.md th, .md td { border: 1px solid var(--bs-border-color); padding: .5rem .75rem; text-align: left; }
.md th { background: var(--surface-2); }
.md img { max-width: 100%; border-radius: var(--bs-border-radius); }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition-duration: .001ms !important; animation-duration: .001ms !important; }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</head>
<body class="py-0">

<?php if ($action !== 'signin'): ?>
<header class="site-header sticky-top z-3">
  <div class="d-flex align-items-center gap-3 px-3 py-2 mx-auto" style="max-width:1400px">
    <a class="brand d-inline-flex align-items-center gap-2 flex-shrink-0" href="<?= url('folder') ?>">
      <span class="brand-mark">S</span>
      <span>
        <span class="brand-name"><?= SIFM_NAME ?></span>
      </span>
    </a>
    <div class="header-tools d-flex align-items-center gap-2 flex-shrink-0 ms-auto">
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
<?php if ($action !== 'signin'):
    /* Breadcrumb: home, then every folder segment as a link, the file as the
       active item. Bootstrap's own markup — the "/" divider and the two
       colours come from the framework, so no CSS of our own is needed. At the
       root there is nothing to show: a lone home icon tells nobody anything. */
    $segments = [];
    $deep = '';
    foreach (explode('/', path($file !== '' ? $file_folder : $folder)) as $seg) {
        if ($seg === '') {
            continue;
        }
        $deep = $deep === '' ? $seg : $deep . '/' . $seg;
        $segments[] = [$deep, $seg];
    }
    $leaf = $file !== '' ? basename($file) : '';
?>
<?php if ($segments || $leaf !== ''): ?>
<nav aria-label="Breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= url('folder') ?>" aria-label="Home folder" title="Home folder"><?= icon('house') ?></a></li>
<?php foreach ($segments as [$seg_path, $seg]): ?>
    <li class="breadcrumb-item"><a href="<?= url('folder', $seg_path) ?>"><?= e($seg) ?></a></li>
<?php endforeach; ?>
<?php if ($leaf !== ''): ?>
    <li class="breadcrumb-item active" aria-current="page"><?= e($leaf) ?></li>
<?php endif; ?>
  </ol>
</nav>
<?php endif; ?>
<?php endif; ?>
<?= message() ?>
<?php switch ($action): ?>
<?php case 'signin': ?>
<div class="d-flex align-items-center justify-content-center p-4" style="min-height:calc(100vh - 8rem)">
  <div class="card login-card w-100" style="max-width:24rem">
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
    /* The listing preferences (sort, order, hidden files, extension filter)
       live in the session — the sort bar posts them and every link on the
       page keeps them without a query string. */
    $prefs = array_merge(['sort' => 'name', 'dir' => 'asc', 'hidden' => '1', 'ext' => ''], $_SESSION['listing'] ?? []);
    $children = $fm->list($folder);
    $exts = [];
    foreach ($children as $child) {
        $ext = strtolower(pathinfo($child, PATHINFO_EXTENSION));
        if ($ext !== '') {
            $exts[$ext] = true;
        }
    }
    if ($prefs['hidden'] !== '1') {
        $children = array_values(array_filter($children, fn($c) => !str_starts_with($c, '.')));
    }
    if ($prefs['ext'] !== '') {
        $want = strtolower($prefs['ext']);
        $children = array_values(array_filter($children, fn($c) => strtolower(pathinfo($c, PATHINFO_EXTENSION)) === $want));
    }
    $dirs = [];
    $files = [];
    $meta = [];
    foreach ($children as $child) {
        $rel = rel($folder, $child);
        $meta[$child] = $fm->info($rel);
        if ($fm->isfolder($rel)) {
            $dirs[] = $child;
        } else {
            $files[] = $child;
        }
    }
    $cmp = match ($prefs['sort']) {
        'size' => fn($a, $b) => ($meta[$a]['size'] ?? 0) <=> ($meta[$b]['size'] ?? 0),
        'date' => fn($a, $b) => ($meta[$a]['last_modified'] ?? '') <=> ($meta[$b]['last_modified'] ?? ''),
        'type' => fn($a, $b) => [strtolower(pathinfo($a, PATHINFO_EXTENSION)), strtolower($a)]
                            <=> [strtolower(pathinfo($b, PATHINFO_EXTENSION)), strtolower($b)],
        default => fn($a, $b) => strnatcasecmp($a, $b),
    };
    usort($dirs, $cmp);
    usort($files, $cmp);
    if ($prefs['dir'] === 'desc') {
        $dirs = array_reverse($dirs);
        $files = array_reverse($files);
    }
?>
<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
  <form method="post" action="<?= url('folder', $folder) ?>" class="d-flex flex-wrap justify-content-right gap-2">
  <?= csrf_field() ?>
  <span class="text-secondary small"><b>Sort</b></span>
  <select class="form-select form-select-sm w-auto" name="sort" aria-label="Sort by">
    <option value="name"<?= $prefs['sort'] === 'name' ? ' selected' : '' ?>>Name</option>
    <option value="size"<?= $prefs['sort'] === 'size' ? ' selected' : '' ?>>Size</option>
    <option value="date"<?= $prefs['sort'] === 'date' ? ' selected' : '' ?>>Modified</option>
    <option value="type"<?= $prefs['sort'] === 'type' ? ' selected' : '' ?>>Type</option>
  </select>
  <select class="form-select form-select-sm w-auto" name="dir" aria-label="Order">
    <option value="asc"<?= $prefs['dir'] === 'asc' ? ' selected' : '' ?>>Ascending</option>
    <option value="desc"<?= $prefs['dir'] === 'desc' ? ' selected' : '' ?>>Descending</option>
  </select>
  <input type="text" class="form-control form-control-sm" name="ext" list="exts" value="<?= e($prefs['ext']) ?>" placeholder="Filter .ext" aria-label="Filter by extension" style="max-width:9rem">
  <datalist id="exts"><?php foreach (array_keys($exts) as $one): ?><option value="<?= e($one) ?>"><?php endforeach; ?></datalist>
  <input type="hidden" name="hidden" value="0">
  <div class="form-check form-switch m-0">
    <input class="form-check-input" type="checkbox" role="switch" id="show-hidden" name="hidden" value="1"<?= $prefs['hidden'] === '1' ? ' checked' : '' ?>>
    <label class="form-check-label small" for="show-hidden">Hidden</label>
  </div>
  <button class="btn btn-primary btn-sm" type="submit">Apply</button>
  <button class="btn btn-ghost btn-sm" type="submit" name="reset" value="1">Reset</button>
</form>
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
  <div class="table-responsive card">
    <table class="table align-middle table-sm table-striped table-bordered mb-0">
      <thead>
        <tr>
          <th class="col-check"><input type="checkbox" class="form-check-input" onclick="toggleAll(this)" aria-label="Select all"></th>
          <th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$dirs && !$files): ?>
        <tr><td colspan="6" class="text-center py-5 px-3 text-secondary"><span class="fs-2 opacity-50"><?= icon('folder-open', 'primary') ?></span><div class="mt-2">Empty folder</div></td></tr>
<?php endif; ?>
<?php foreach ([...$dirs, ...$files] as $child): ?>
        <?= row(rel($folder, $child), true, false, $meta[$child]) ?>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3">
    <div class="d-flex flex-wrap gap-2 mt-2">
    <label class="text-secondary small mb-0" for="multi-action"><b>With selected:</b></label>
    <select class="form-select form-select-sm w-auto" name="action" id="multi-action">
      <?php foreach ($kinds as $k => $label): ?>
      <option value="<?= e($k) ?>"><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="multi" value="1">
    <button class="btn btn-primary btn-sm" type="submit" name="submit" value="1">Go</button>
      </div>
     <div class="d-flex flex-wrap gap-2 mt-2">
    <?php foreach ($tools as [$label, $glyph, $link, $key, $cls]): ?>
    <a class="btn btn-ghost btn-sm <?= $cls ?>" href="<?= $link ?>"><?= icon($glyph) ?> <?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  </div>
</form>
<?php break; ?>

<?php case 'search': ?>
<?php
    $q = trim(post('q'));
    $scope = path($folder);
    /* Two kinds of search: by name (with wildcards) and by content. */
    $mode = post('mode') === 'content' ? 'content' : 'names';
    $hits = $q !== '' ? ($mode === 'content' ? $fm->grep($q, $scope) : $fm->search($q, $scope)) : [];
?>
<div class="panel-head">
  <form class="search-form d-flex" method="post" action="<?= url('search', $folder) ?>">
    <?= csrf_field() ?>
    <div class="input-group">
      <select class="form-select" name="mode" aria-label="What to search">
        <option value="names"<?= $mode === 'names' ? ' selected' : '' ?>>Names</option>
        <option value="content"<?= $mode === 'content' ? ' selected' : '' ?>>Content</option>
      </select>
      <input type="search" class="form-control" name="q" placeholder="<?= $mode === 'content' ? 'Search inside the files' : 'Search — * and ? wildcards' ?>" value="<?= e($q) ?>" aria-label="Search" autofocus>
      <button class="btn btn-primary" type="submit"><?= icon('magnifying-glass') ?> Search</button>
    </div>
  </form>
  <span class="text-secondary small font-monospace"><?= $mode === 'content' ? 'Inside the files of' : 'Searching in folder:' ?> /<?= e($scope) ?><?= $q !== '' ? ' · ' . count($hits) . ' result(s)' : '' ?></span>
</div>
<div class="table-responsive card">
  <table class="table align-middle mb-0">
    <thead>
      <tr><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th></tr>
    </thead>
    <tbody>
<?php if ($q === ''): ?>
      <tr><td colspan="5" class="text-center py-5 px-3 text-secondary"><span class="fs-2 opacity-50"><?= icon('magnifying-glass') ?></span><div class="mt-2"><?= $mode === 'content' ? 'Type a keyword — the content of every file under the folder is searched' : 'Type a keyword — or use wildcards like <code>*.log</code> or <code>report*</code>' ?></div></td></tr>
<?php elseif (!$hits): ?>
      <tr><td colspan="5" class="text-center py-5 px-3 text-secondary"><span class="fs-2 opacity-50"><?= icon('magnifying-glass') ?></span><div class="mt-2">Nothing matched &ldquo;<?= e($q) ?>&rdquo;</div></td></tr>
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
    $editable = in_array($ext, fm::edit, true);
    /* What the browser can show on its own. The file lives inside the
       document root, so its plain URL is all the preview needs. */
    $preview = match ($ext) {
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg' => 'image',
        'pdf' => 'pdf',
        'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' => 'audio',
        'mp4', 'webm', 'ogv', 'mov' => 'video',
        'md' => 'markdown',
        default => '',
    };
    $src = '//' . SIFM_HOST . '/' . implode('/', array_map('rawurlencode', explode('/', $file)));
?>
<div class="panel-head">
  <h1 class="h5 mb-0">File details</h1>
</div>
<div class="card"><div class="card-body">
  <dl class="kv mb-0">
    <dt class="text-secondary fw-medium small">Path</dt><dd class="font-monospace"><?= e($info->path) ?></dd>
    <dt class="text-secondary fw-medium small">Size</dt><dd><?= e($info->size ?? '—') ?></dd>
    <dt class="text-secondary fw-medium small">MIME-Type</dt><dd class="font-monospace"><?= e($info->mimetype ?? '—') ?></dd>
    <dt class="text-secondary fw-medium small">Permissions</dt><dd><a class="perms font-monospace" href="<?= url('chmod', $file) ?>"><?= e($info->perms) ?></a></dd>
    <dt class="text-secondary fw-medium small">Owner</dt><dd class="font-monospace"><?= e($info->owner) ?></dd>
    <dt class="text-secondary fw-medium small">Last modified</dt><dd><?= e($info->last_modified) ?> <span class="text-secondary">(<?= e(timeago($info->last_modified)) ?>)</span></dd>
  </dl>
</div></div>
<div class="d-flex flex-wrap gap-2 mt-3">
  <a class="btn btn-primary btn-sm" href="<?= url('download', $file) ?>"><?= icon('download') ?> Download</a>
  <a class="btn btn-ghost btn-sm" href="<?= e($src) ?>"><?= icon('globe') ?> Open</a>
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
<?php if ($preview === 'image'): ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('image', $file) ?>"><?= icon('wand-magic-sparkles') ?> Image tools</a>
<?php endif; ?>
  <a class="btn btn-ghost btn-sm" href="<?= url('copy', $file) ?>"><?= icon('copy') ?> Copy</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('move', $file) ?>"><?= icon('exchange') ?> Move</a>
  <a class="btn btn-ghost btn-sm" href="<?= url('rename', $file) ?>"><?= icon('pencil') ?> Rename</a>
  <a class="btn btn-ghost btn-sm text-danger" href="<?= url('delete', $file) ?>"><?= icon('trash') ?> Delete</a>
</div>
<?php if ($preview !== ''): ?>
<div class="card mt-3"><div class="card-body">
<?php if ($preview === 'image'): ?>
  <img class="img-fluid rounded" src="<?= e($src) ?>" alt="<?= e(basename($file)) ?>">
<?php elseif ($preview === 'pdf'): ?>
  <iframe class="w-100 rounded" style="height:70vh" src="<?= e($src) ?>" title="<?= e(basename($file)) ?>"></iframe>
<?php elseif ($preview === 'audio'): ?>
  <audio class="w-100" controls src="<?= e($src) ?>"></audio>
<?php elseif ($preview === 'markdown'): ?>
  <div id="md" class="md"><p class="text-secondary">Rendering…</p></div>
  <script src="https://cdn.jsdelivr.net/npm/marked@18.1.1/lib/marked.umd.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.4.17/dist/purify.min.js"></script>
  <script>
  /* Markdown in, sanitised HTML out — DOMPurify is what keeps a hostile
     document from running inside the app's own origin. */
  fetch(<?= json_encode($src) ?>).then(function (r) { return r.text(); }).then(function (text) {
    document.getElementById('md').innerHTML = DOMPurify.sanitize(marked.parse(text));
  });
  </script>
<?php else: ?>
  <video class="w-100 rounded" controls src="<?= e($src) ?>"></video>
<?php endif; ?>
</div></div>
<?php endif; ?>
<?php break; ?>

<?php case 'edit': ?>
<?php
    /* $size and the "writable / small enough" gates were settled in dispatch —
       a view runs after the HTML has begun and may not redirect. */
    $content = $fm->source($file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mtime = (int) (@filemtime(SIFM_HOME . '/' . $file));
    /* ?replace=1 still opens the panel (deep link), but the button toggles it
       in place — the editor never navigates away. */
    $replace = get('replace') === '1';
?>
<form method="post" action="<?= url('edit', $file) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="mtime" value="<?= $mtime ?>">
  <div class="panel-head">
    <span class="font-monospace small text-secondary text-truncate" style="max-width:50%">/<?= e($file) ?> · <a href="<?= url('file', $file) ?>" class="btn btn-sm btn-ghost" tile="Co back to file information">Back</a></span>
    <div class="d-flex flex-wrap gap-2 ms-auto">
      <button class="btn btn-primary btn-sm<?= $replace ? ' d-none' : '' ?>" name="save" value="1" id="save-btn"><?= icon('floppy-disk') ?> Save</button>
      <button type="button" class="btn btn-ghost btn-sm" id="toggle-replace" aria-controls="replace-panel" aria-expanded="<?= $replace ? 'true' : 'false' ?>"><?= icon('arrows-rotate') ?> Replace&hellip;</button>
    </div>
  </div>
  <div class="card mb-3<?= $replace ? '' : ' d-none' ?>" id="replace-panel">
    <div class="card-body">
      <h2 class="h6">Replace a phrase</h2>
      <div class="mb-3">
        <label class="form-label" for="rep-from">Find</label>
        <textarea class="form-control" id="rep-from" name="from" rows="3" required<?= $replace ? '' : ' disabled' ?>></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label" for="rep-to">Replace with</label>
        <textarea class="form-control" id="rep-to" name="to" rows="3"<?= $replace ? '' : ' disabled' ?>></textarea>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary" name="replace" value="1">Replace</button>
        <button type="button" class="btn btn-ghost" id="replace-cancel">Cancel</button>
      </div>
    </div>
  </div>
  <div id="ed" class="ed"></div>
  <textarea id="editor" name="data" class="form-control mt-2" rows="24" spellcheck="false" hidden><?= e($content) ?></textarea>
</form>
<script>
/* Replace works in place: the panel opens under the toolbar, the editor stays
   where it is and the page never navigates. */
(function () {
  var panel = document.getElementById('replace-panel');
  var open = document.getElementById('toggle-replace');
  var cancel = document.getElementById('replace-cancel');
  var save = document.getElementById('save-btn');
  var from = document.getElementById('rep-from');
  var to = document.getElementById('rep-to');
  if (!panel || !open) {
    return;
  }
  function show(on) {
    panel.classList.toggle('d-none', !on);
    open.setAttribute('aria-expanded', on ? 'true' : 'false');
    from.disabled = !on;
    to.disabled = !on;
    if (save) {
      save.classList.toggle('d-none', on);
    }
    if (on) {
      from.focus();
    }
  }
  open.addEventListener('click', function () { show(panel.classList.contains('d-none')); });
  cancel.addEventListener('click', function () { show(false); });
})();
</script>
<script>
(function () {
  var button = document.getElementById('save-btn');
  if (!button || !button.form) return;
  var form = button.form;
  var toastTimer, removeTimer;

  function showToast(kind, text) {
    var toast = document.getElementById('editor-save-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'editor-save-toast';
      document.body.appendChild(toast);
    }
    window.clearTimeout(toastTimer);
    window.clearTimeout(removeTimer);
    toast.className = 'alert alert-' + kind;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.textContent = text;
    toastTimer = window.setTimeout(function () {
      toast.classList.add('flash-toast-hide');
      removeTimer = window.setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  }

  form.addEventListener('submit', async function (event) {
    if (!event.submitter || event.submitter.name !== 'save') return;
    event.preventDefault();
    var submitter = event.submitter;
    submitter.disabled = true;
    var data = new FormData(form);
    data.set('data', document.getElementById('editor').value);
    data.set('save', '1');
    try {
      var response = await fetch(form.action, {
        method: 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      var result = await response.json();
      if (!response.ok || typeof result.ok !== 'boolean' || typeof result.msg !== 'string') {
        throw new Error('Invalid save response');
      }
      if (result.ok) {
        var mtime = form.querySelector('[name="mtime"]');
        if (mtime && result.mtime) mtime.value = result.mtime;
      }
      showToast(result.ok ? 'success' : 'danger', result.msg);
    } catch (error) {
      showToast('danger', 'Save failed!');
      window.__saveErr = String(error && error.message || error);
    } finally {
      submitter.disabled = false;
    }
  });
})();
</script>
<?php break; ?>

<?php case 'source': ?>
<?php $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)); ?>
<div class="panel-head">
  <span class="font-monospace small text-secondary">/<?= e($file) ?> &mdash; <?= count(file($fm->abs($file))) ?> lines  [<?= $fm->size($file) ?>]</span>
  <div class="d-flex gap-2 ms-auto">
<?php if (in_array($ext, fm::edit, true)): ?>
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
  <span class="font-monospace small text-secondary">php -l /<?= e($file) ?></span>
  <a class="btn btn-ghost btn-sm ms-auto" href="<?= url('file', $file) ?>">Back</a>
</div>
<?php if ($bad): ?>
<div class="alert alert-danger fw-medium mb-3" role="alert"><?= icon('triangle-exclamation') ?> Check errors in your code:</div>
<pre class="out font-monospace"><?= e($out !== '' ? $out : 'php -l produced no output — is shell_exec() enabled?') ?></pre>
<?php else: ?>
<div class="alert alert-success fw-medium" role="alert"><?= icon('circle-check') ?> No syntax errors detected.</div>
<?php endif; ?>
<?php break; ?>

<?php case 'backup': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Backup folder &ldquo;/<?= e($folder) ?>&rdquo;</h1>
  <form method="post" action="<?= url('backup', $folder) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="bk-name">Backup filename</label>
      <input type="text" class="form-control font-monospace" id="bk-name" name="n" value="<?= e(rel($folder, 'Backup.zip')) ?>">
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
      <label class="form-label" for="mod">Permissions: <span class="font-monospace"><?= e($mod) ?> &rarr;</span></label>
      <input type="text" class="form-control font-monospace" id="mod" name="mod" value="<?= e($mod) ?>" pattern="[0-7]{3,4}" maxlength="4" required>
      <div class="form-text">3–4 octal digits, e.g. 644 or 755.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" type="submit">CHMOD</button>
      <a class="btn btn-ghost" href="<?= back($file, $target) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'copy': case 'move': ?>
<?php $is_copy = $action === 'copy'; ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3"><?= $is_copy ? 'Copy' : 'Move' ?> &ldquo;<span class="font-monospace">/<?= e($target) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url($action, $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="dest">Destination path</label>
      <input type="text" class="form-control font-monospace" id="dest" name="new" value="/<?= e($target) ?>" required>
      <div class="form-text">Relative to the home folder. Missing parent folders are created.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="<?= $is_copy ? 'copy' : 'move' ?>" value="1"><?= $is_copy ? 'Copy' : 'Move' ?></button>
      <a class="btn btn-ghost" href="<?= back($file, $target) ?>">Cancel</a>
    </div>
  </form>
  <form method="post" action="<?= url('clip', $target) ?>" class="mt-2">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= $is_copy ? 'copy' : 'cut' ?>">
    <input type="hidden" name="items[]" value="<?= e(basename($target)) ?>">
    <button class="btn btn-ghost" name="clip" value="1"><?= icon('clipboard') ?> <?= $is_copy ? 'Copy to clipboard' : 'Cut to clipboard' ?></button>
  </form>
</div></div>
<?php break; ?>

<?php case 'duplicate': ?>
<?php
    /* The name we suggest: "notes (copy).txt", then "(copy 2)", "(copy 3)"…
       while the file is already taken. */
    $stem = pathinfo($target, PATHINFO_FILENAME);
    $ext = pathinfo($target, PATHINFO_EXTENSION);
    $suffix = $ext !== '' ? '.' . $ext : '';
    $suggest = $stem . ' (copy)' . $suffix;
    $n = 2;
    while ($fm->exists(rel(dirname($target), $suggest))) {
        $suggest = $stem . ' (copy ' . $n . ')' . $suffix;
        $n++;
    }
?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Duplicate &ldquo;<span class="font-monospace">/<?= e($target) ?></span>&rdquo; as:</h1>
  <form method="post" action="<?= url('duplicate', $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="dup-name">New name</label>
      <input type="text" class="form-control font-monospace" id="dup-name" name="new" value="<?= e($suggest) ?>" required autofocus>
      <div class="form-text">Lands next to the original. A folder is copied with everything inside it.</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="dup" value="1">Duplicate</button>
      <a class="btn btn-ghost" href="<?= back($file, $target) ?>">Cancel</a>
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
  <h1 class="h6 text-danger"><?= icon('triangle-exclamation') ?> Delete <?= is_dir(SIFM_HOME . '/' . $target) ? 'folder' : 'file' ?> &ldquo;<span class="font-monospace">/<?= e($target) ?></span>&rdquo;?</h1>
  <p class="text-secondary mb-3">This is irreversible — deleted items cannot be recovered.</p>
  <form method="post" action="<?= url('delete', $target) ?>" class="d-inline">
    <?= csrf_field() ?>
    <button class="btn btn-danger" name="del" value="1"><?= icon('trash') ?> Yes, delete</button>
    <a class="btn btn-ghost" href="<?= back($file, $target) ?>">No, back</a>
  </form>
</div></div>
<?php break; ?>

<?php case 'extract': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Extract &ldquo;<span class="font-monospace">/<?= e($file) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url('extract', $file) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="ex-dest">Destination folder</label>
      <input type="text" class="form-control font-monospace" id="ex-dest" name="u" value="/<?= e(dirname($file)) ?>" required>
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
        note.className = 'small text-secondary mb-2';
        note.textContent = 'Ready for another import.';
        window.sifmToast(d.ok ? 'success' : 'danger', d.msg);
        if (d.ok) form.reset();
      })
      .catch(function () {
        ev.submitter.disabled = false;
        bar.classList.add('d-none');
        note.className = 'small text-secondary mb-2';
        note.textContent = 'Ready for another import.';
        window.sifmToast('danger', 'Import failed. Check your connection and try again.');
      });
  });
})();
</script>
<?php break; ?>

<?php case 'rename': ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Rename &ldquo;<span class="font-monospace">/<?= e($target) ?></span>&rdquo; to:</h1>
  <form method="post" action="<?= url('rename', $target) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="ren-new">New name <span class="text-secondary">(no slashes)</span></label>
      <input type="text" class="form-control" id="ren-new" name="new" value="<?= e(basename($target)) ?>" required>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="ren" value="1">Rename</button>
      <a class="btn btn-ghost" href="<?= back($file, $target) ?>">Cancel</a>
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
        state('Ready for another upload.', 'secondary', false);
        window.sifmToast(bad ? 'danger' : 'success', bad ? done + ' uploaded, ' + bad + ' failed.' : 'Uploaded ' + done + ' file(s).');
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
<?php $clipping = in_array($kind, ['pick', 'cut'], true); ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">You are about to <span class="text-primary"><?= e($kinds[$kind]) ?></span> <?= count($items) ?> item(s):</h1>
  <ul class="list-unstyled mb-3">
    <?php foreach ($items as $item): ?>
    <li class="font-monospace small py-1 border-bottom border-secondary border-opacity-25">/<?= e(rel($folder, $item)) ?></li>
    <?php endforeach; ?>
  </ul>
<?php if ($kind === 'delete'): ?>
  <p class="text-secondary">This is irreversible — the selected items cannot be recovered.</p>
<?php endif; ?>
  <form method="post" action="<?= $clipping ? url('clip', $folder) : url('multi', $folder) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= e($kind) ?>">
    <?php if ($clipping): ?>
    <input type="hidden" name="mode" value="<?= $kind === 'cut' ? 'cut' : 'copy' ?>">
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
    <input type="hidden" name="items[]" value="<?= e($item) ?>">
    <?php endforeach; ?>
<?php if ($kind === 'chmod'): ?>
    <div class="mb-3">
      <label class="form-label" for="multi-dest">Permissions (e.g. 755)</label>
      <input type="text" class="form-control font-monospace" id="multi-dest" name="dest" required pattern="[0-7]{3,4}" maxlength="4" placeholder="755">
    </div>
<?php elseif ($kind === 'backup'): ?>
    <div class="mb-3">
      <label class="form-label" for="multi-dest">Backup filename</label>
      <input type="text" class="form-control font-monospace" id="multi-dest" name="dest" required value="<?= e(rel($folder, 'Backup.zip')) ?>">
    </div>
<?php elseif ($kind === 'rename'): ?>
    <div class="mb-3">
      <label class="form-label" for="multi-dest">Rename pattern</label>
      <input type="text" class="form-control font-monospace" id="multi-dest" name="dest" required value="{name}">
      <div class="form-text">Tokens: <span class="font-monospace">{name} {ext} {n} {date}</span> — the first item becomes <span class="font-monospace"><?= e(rename_pattern('{name}', $items[0] ?? '', 1)) ?></span>, the second <span class="font-monospace"><?= e(rename_pattern('{name}', $items[1] ?? $items[0] ?? '', 2)) ?></span>.</div>
    </div>
<?php elseif ($kind === 'copy' || $kind === 'move'): ?>
    <div class="mb-3">
      <label class="form-label" for="multi-dest">Destination path</label>
      <input type="text" class="form-control font-monospace" id="multi-dest" name="dest" required value="/<?= e($folder) ?>">
      <div class="form-text">Relative to the home folder. Missing parent folders are created.</div>
    </div>
<?php endif; ?>
    <div class="d-flex gap-2">
      <?php if ($kind === 'delete'): ?>
      <button class="btn btn-danger" name="submit" value="1"><?= icon('trash') ?> Yes, delete</button>
      <?php elseif ($kind === 'download'): ?>
      <button class="btn btn-primary" name="submit" value="1"><?= icon('file-zipper') ?> Build &amp; download ZIP</button>
      <?php else: ?>
      <button class="btn btn-primary" name="submit" value="1"><?= e($kinds[$kind]) ?></button>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= url('folder', $folder) ?>">Cancel</a>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'image': ?>
<?php
    $dim = @getimagesize(SIFM_HOME . '/' . $file);
    $w = $dim ? (int) $dim[0] : 0;
    $h = $dim ? (int) $dim[1] : 0;
?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Image tools &mdash; <span class="font-monospace"><?= e(basename($file)) ?></span></h1>
  <p class="text-secondary"><?= $w ?> &times; <?= $h ?> px &middot; <?= e((string) ($fm->info($file)['mimetype'] ?? '')) ?></p>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post" action="<?= url('image', $file) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" name="op" value="left"><?= icon('rotate-left') ?> Rotate left</button>
    </form>
    <form method="post" action="<?= url('image', $file) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" name="op" value="right"><?= icon('rotate-right') ?> Rotate right</button>
    </form>
    <form method="post" action="<?= url('image', $file) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" name="op" value="webp"><?= icon('file-image') ?> Save as WebP</button>
    </form>
  </div>
  <form method="post" action="<?= url('image', $file) ?>">
    <?= csrf_field() ?>
    <div class="d-flex gap-2 align-items-end">
      <div>
        <label class="form-label" for="img-w">Resize to (px wide)</label>
        <input type="number" class="form-control font-monospace" id="img-w" name="w" min="1" max="10000" value="<?= $w > 0 ? (int) ($w / 2) : 800 ?>" required>
      </div>
      <button class="btn btn-primary" name="op" value="resize"><?= icon('arrows-left-right') ?> Resize</button>
    </div>
  </form>
</div></div>
<?php break; ?>

<?php case 'paste': ?>
<?php $clip = $_SESSION['clip'] ?? null; ?>
<div class="card"><div class="card-body">
  <h1 class="h6 mb-3">Paste <?= count($clip['items']) ?> item(s) into &ldquo;/<?= e($folder) ?>&rdquo;?</h1>
  <ul class="list-unstyled mb-3">
    <?php foreach ($clip['items'] as $item): ?>
    <li class="font-monospace small py-1 border-bottom border-secondary border-opacity-25">/<?= e(rel($clip['from'], $item)) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="text-secondary"><?= $clip['mode'] === 'cut' ? 'These items move out of their old folder.' : 'The originals stay where they are.' ?></p>
  <form method="post" action="<?= url('paste', $folder) ?>">
    <?= csrf_field() ?>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" name="paste" value="1"><?= icon('paste') ?> Paste here</button>
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
<div class="modal fade" id="palette" tabindex="-1" aria-labelledby="palette-label">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h1 class="modal-title fs-6 mb-0" id="palette-label">Command palette</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-0">
        <input type="text" class="form-control form-control-sm mb-2" id="palette-q" placeholder="Type a command…" aria-label="Type a command" autocomplete="off">
        <div class="list-group list-group-flush" id="palette-list">
<?php foreach ($palette as [$label, $glyph, $link, $key, $cls]): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= $link ?>"<?= $key !== '' ? ' data-key="' . e($key) . '"' : '' ?>>
            <span><?= icon($glyph) ?> <?= e($label) ?></span>
<?php if ($key !== ''): ?>
            <kbd><?= e($key) ?></kbd>
<?php endif; ?>
          </a>
<?php endforeach; ?>
        </div>
        <p class="text-secondary small mb-0 mt-2 d-none" id="palette-none">No command matches.</p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($action !== 'signin'): ?>
<footer class="text-center py-3 font-monospace text-secondary small"><?= SIFM_NAME ?> v<?= SIFM_VERSION ?></footer>
<?php endif; ?>

<script>
function toggleAll(box) {
  document.querySelectorAll('input[name="items[]"]').forEach(function (b) { b.checked = box.checked; });
}

/* Command palette + single-key shortcuts. The list is rendered by PHP — the
   script only filters it and reads the keys off the items themselves, so the
   palette, the toolbar and the shortcuts can never drift apart. */
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('palette');
  var list = document.getElementById('palette-list');
  if (!modal || !window.bootstrap) {
    return;
  }
  var pal = new bootstrap.Modal(modal);
  var query = document.getElementById('palette-q');
  var none = document.getElementById('palette-none');
  var items = Array.prototype.slice.call(list.querySelectorAll('a'));
  var keys = {};
  items.forEach(function (a) {
    if (a.dataset.key) {
      keys[a.dataset.key] = a.href;
    }
  });

  query.addEventListener('input', function () {
    var needle = query.value.toLowerCase();
    var hits = 0;
    items.forEach(function (a) {
      var show = a.textContent.toLowerCase().indexOf(needle) !== -1;
      a.classList.toggle('d-none', !show);
      if (show) {
        hits++;
      }
    });
    none.classList.toggle('d-none', hits > 0);
  });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || ev.target.isContentEditable;

    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
      ev.preventDefault();
      query.value = '';
      pal.show();
      query.focus();
      return;
    }
    if (typing || ev.ctrlKey || ev.metaKey || ev.altKey) {
      return;
    }
    var key = ev.key.toLowerCase();
    if (key === '/') {
      var box = document.querySelector('.search-form input');
      if (box) {
        ev.preventDefault();
        box.focus();
      }
      return;
    }
    if (key === 'escape') {
      document.querySelectorAll('input[name="items[]"]').forEach(function (b) { b.checked = false; });
      return;
    }
    if (keys[key]) {
      ev.preventDefault();
      window.location = keys[key];
    }
  });
});
</script>
<?php if ($action === 'edit' && get('replace') !== '1'): ?>
<?php
    /* The editor's chrome, straight off the design tokens. Every value is a
       CSS custom property, so a theme flip repaints the editor by itself —
       one map, no per-mode copy. The syntax colours live in the same tokens
       (--tok-*), the ones the source view's atom-one sheet uses. */
    $ed = [
        'bg' => 'var(--code-bg)', 'fg' => 'var(--code-fg)',
        'gut' => 'var(--code-gut)', 'gut2' => 'var(--code-line)',
        'brd' => 'var(--bs-border-color)', 'mut' => 'var(--tok-comment)',
        'sel' => 'var(--code-sel)', 'line' => 'var(--code-line)',
        'cur' => 'var(--bs-primary)',
    ];
?>
<script type="module">
/* Bullion, in CodeMirror 6 — native ES modules straight from jsdelivr.
   The textarea below stays the form's only real field: every edit is mirrored
   into it, and if the CDN or the browser lets us down it simply stays visible. */
const ta = document.getElementById('editor');
const pal = <?= json_encode($ed) ?>;
const fam = '<?= lang($ext) ?>';

/* The chrome, as a CM6 theme. The colours are CSS tokens, so the theme flip
   reaches the editor without touching the instance. */
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
/* The syntax colours mirror the atom-one highlight.js theme, bucket for
   bucket: keyword purple, string green, number/variable orange, literal cyan,
   title blue, name red, built-in yellow, comment grey italic. The same tokens
   paint the source view, so both read as one theme. */
const style = (tags) => [
  { tag: [tags.keyword, tags.moduleKeyword, tags.controlKeyword, tags.operatorKeyword, tags.definitionKeyword], color: 'var(--tok-keyword)' },
  { tag: [tags.string, tags.special(tags.string), tags.regexp, tags.attributeValue], color: 'var(--tok-string)' },
  { tag: [tags.comment, tags.blockComment, tags.lineComment, tags.docComment], color: 'var(--tok-comment)', fontStyle: 'italic' },
  { tag: [tags.number, tags.variableName, tags.propertyName, tags.attributeName, tags.typeName], color: 'var(--tok-number)' },
  { tag: [tags.bool, tags.null, tags.literal, tags.atom], color: 'var(--tok-literal)' },
  { tag: [tags.function(tags.variableName), tags.function(tags.propertyName), tags.definition(tags.variableName), tags.definition(tags.propertyName), tags.macroName, tags.meta], color: 'var(--tok-title)' },
  { tag: [tags.tagName], color: 'var(--tok-attr)' },
  { tag: [tags.standard(tags.variableName), tags.standard(tags.propertyName)], color: 'var(--tok-builtin)' },
];

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
  if (!ta) {
    throw new Error('Missing editor textarea');
  }
  const mount = document.getElementById('ed');
  if (!mount) {
    throw new Error('Missing editor mount node');
  }

  const [state, view, language, commands, search, lezer] = await Promise.all([
    import('@codemirror/state'),
    import('@codemirror/view'),
    import('@codemirror/language'),
    import('@codemirror/commands'),
    import('@codemirror/search'),
    import('@lezer/highlight'),
  ]);
  const { EditorState } = state;
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

  const editor = new EditorView({
    parent: mount,
    state: EditorState.create({
      doc: ta.value,
      extensions: [
        lineNumbers(), history(), drawSelection(), highlightActiveLine(), highlightActiveLineGutter(),
        EditorView.lineWrapping, EditorState.tabSize.of(4), EditorView.updateListener.of((u) => { if (u.docChanged) ta.value = u.state.doc.toString(); }),
        keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap, indentWithTab]),
        EditorView.theme(theme(pal)), syntaxHighlighting(HighlightStyle.define(style(tags))),
        EditorView.domEventHandlers({
          keydown: (ev) => ((ev.ctrlKey || ev.metaKey) && ev.key === 'f' ? openSearchPanel(editor) || true : false),
        }),
        grammar,
      ],
    }),
  });
} catch (err) {
  const mount = document.getElementById('ed');
  if (mount) mount.remove();
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
<script>
(function () {
  var toastTimer, removeTimer;
  window.sifmToast = function (kind, text) {
    var toast = document.getElementById('flash-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'flash-toast';
      document.body.appendChild(toast);
    }
    window.clearTimeout(toastTimer);
    window.clearTimeout(removeTimer);
    toast.className = 'alert alert-' + kind;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.textContent = text;
    toastTimer = window.setTimeout(function () {
      toast.classList.add('flash-toast-hide');
      removeTimer = window.setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  };
  var initialToast = document.getElementById('flash-toast');
  if (initialToast) {
    var kind = initialToast.classList.contains('alert-success') ? 'success'
      : (initialToast.classList.contains('alert-info') ? 'info' : 'danger');
    window.sifmToast(kind, initialToast.textContent);
  }
})();
</script>
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

/** Home-relative path of $name inside $folder — the root folder prefixes
 *  nothing, so no call ever has to spell out the "empty folder" case. */
function rel(string $folder, string $name): string
{
    return path($folder . '/' . $name);
}

/** Where an item's action form returns to: the file's own page for a file,
 *  the folder listing it lives in for a folder. */
function back(string $file, string $target): string
{
    return $file !== '' ? url('file', $file) : url('folder', dirname($target));
}

/** The new name of a multi-rename: {name} {ext} {n} {date}. */
function rename_pattern(string $pattern, string $item, int $n): string
{
    $info = pathinfo($item);
    return strtr($pattern, [
        '{name}' => $info['filename'] ?? $item,
        '{ext}' => $info['extension'] ?? '',
        '{n}' => (string) $n,
        '{date}' => date('Ymd'),
    ]);
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

/** FA6 icon. The name may be bare ("folder-open" → "fas fa-folder-open") or
 *  carry its own family ("fab fa-php") — never write the <i> tag by hand.
 *  String second arg = text-colour utility, int = pixel size. */
function icon(string $name, $opt = 0): string
{
    $family = preg_match('/^fa[bsrlkdt]\s/', $name) ? '' : 'fas fa-';
    $extra = is_string($opt) && $opt !== ''
        ? ' text-' . e($opt)
        : (is_int($opt) && $opt > 0 ? ' style="font-size:' . $opt . 'px"' : '');
    return '<i class="' . $family . e($name) . $extra . '" aria-hidden="true"></i>';
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
        ? '<div id="flash-toast" class="alert alert-' . $flash['class'] . '" role="alert" aria-live="assertive">' . e($flash['text']) . '</div>'
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
    while ($i < count($steps) && $diff >= $steps[$i]) {
        $diff = (int) round($diff / $steps[$i]);
        $i++;
    }
    return $diff . ' ' . $units[$i] . ($diff === 1 ? '' : 's') . ' ago';
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
function row(string $rel, bool $select, bool $full = false, ?array $meta = null): string
{
    static $fm = null, $fa = null;
    $fm ??= new fm();
    $fa ??= json_decode(fm::icons, true) ?: [];

    $rel = path($rel);
    $is_dir = $fm->isfolder($rel);
    $info = (object) ($meta ?? $fm->info($rel));
    $name = $full ? $rel : basename($rel);
    $ext = strtolower((string) ($info->extension ?? ''));

    /* The icon map already carries the full class ("fas fa-file-alt",
       "fab fa-php"), so icon() takes it as it is. */
    $glyph = $is_dir ? 'folder-open' : ($fa[$ext] ?? $fa['empty'] ?? 'file-lines');
    $link = $is_dir ? url('folder', $rel) : url('file', $rel);

    $actions = ['copy' => 'copy', 'duplicate' => 'clone', 'delete' => 'trash', 'download' => 'download', 'move' => 'exchange', 'rename' => 'pencil'];
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
        . '<td class="cell-name">' . icon($glyph, $is_dir ? 'primary' : 0)
        . '<a class="text-body fw-medium" href="' . $link . '">' . e($name) . '</a></td>'
        . '<td>' . ($is_dir ? '<span class="text-secondary">Folder</span>' : e($info->size)) . '</td>'
        . '<td><a class="perms font-monospace" href="' . url('chmod', $rel) . '">' . e($info->perms) . '</a></td>'
        . '<td class="text-secondary small">' . e($info->last_modified) . '</td>'
        . '<td class="cell-actions">' . $links . '</td>'
        . '</tr>';
}

/**** The filesystem engine. Every path is relative to SIFM_HOME and is
 **** re-normalised with path() inside each method, so a caller that forgets
 **** to sanitise still cannot escape the home folder. ****/
class fm
{
    public string $home;

    /** Extensions the built-in editor accepts. */
    const edit = ["", "bat", "c", "cert", "cgi", "cpp", "css", "dat", "htaccess", "htm", "html", "ini", "js", "key", "json", "lock", "log", "md", "php", "pl", "py", "sh", "sql", "tpl", "txt", "wml", "xhtml", "xml"];

    /** How far a content search goes: how many hits, how big a file. */
    const grep_files = 200;
    const grep_bytes = 1048576;

    /** Extension → FA6 icon class (json, decoded once by row()). */
    const icons = '{"7z":"fas fa-file-archive","aac":"fas fa-music","accdb":"fas fa-database","ai":"fas fa-image","aif":"fas fa-music","apk":"fab fa-android","app":"fas fa-window","avi":"fas fa-video","bak":"fas fa-save","bat":"fas fa-terminal","bmp":"fas fa-image","box":"fas fa-archive","bz":"fas fa-file-archive","c":"fas fa-code","cfg":"fas fa-file-alt","class":"fas fa-code","cmd":"fas fa-terminal","com":"fas fa-window","cpp":"fas fa-code","css":"fab fa-css3","csv":"fas fa-file-csv","db":"fas fa-database","dbf":"fas fa-database","deb":"fas fa-archive","doc":"fas fa-file-word","docx":"fas fa-file-word","drw":"fas fa-image","email":"fas fa-envelope","empty":"fas fa-file-alt","eot":"fas fa-font-case","eps":"fas fa-image","erb":"fas fa-code","exe":"fab fa-windows","flac":"fas fa-music","flv":"fas fa-video","gam":"fas fa-gamepad","gif":"fas fa-image","gz":"fas fa-file-archive","htaccess":"fas fa-server","htm":"fab fa-html5","html":"fab fa-html5","ico":"fas fa-image","ini":"fas fa-cog","iso":"fas fa-compact-disc","jar":"fas fa-window","java":"fab fa-java","jpeg":"fas fa-image","jpg":"fas fa-image","js":"fab fa-js","json":"fas fa-file-alt","lock":"fas fa-lock","log":"fas fa-file-alt","mail":"fas fa-envelope","md":"fab fa-markdown","mdb":"fas fa-database","mid":"fas fa-music","midi":"fas fa-music","mkv":"fas fa-video","mov":"fas fa-video","mp3":"fas fa-music","mp4":"fas fa-video","mpa":"fas fa-music","mpg":"fas fa-video","msg":"fas fa-envelope","msi":"fas fa-window","nes":"fas fa-gamepad","odt":"fas fa-file-alt","ogg":"fas fa-music","ogv":"fas fa-video","otf":"fas fa-font-case","pdb":"fas fa-database","pdf":"fas fa-file-pdf","php":"fab fa-php","phtml":"fab fa-html5","pl":"fas fa-code","png":"fas fa-image","ppt":"fas fa-file-powerpoint","pptx":"fas fa-file-powerpoint","ps":"fas fa-image","psd":"fas fa-image","py":"fab fa-python","rar":"fas fa-file-archive","rb":"fas fa-code","rom":"fas fa-gamepad","rpm":"fas fa-archive","rtf":"fas fa-file-alt","sav":"fas fa-save","sh":"fas fa-terminal","sql":"fas fa-database","svg":"fas fa-image","swf":"fas fa-video","tar":"fas fa-file-archive","tga":"fas fa-image","tif":"fas fa-image","tiff":"fas fa-image","ttf":"fas fa-font-case","twig":"fab fa-php","txt":"fas fa-file-alt","vb":"fas fa-window","wav":"fas fa-music","webm":"fas fa-video","wma":"fas fa-music","wmv":"fas fa-video","woff":"fas fa-font-case","xhtml":"fas fa-code","xls":"fas fa-file-excel","xlsx":"fas fa-file-excel","xml":"fas fa-code","xz":"fas fa-file-archive","yaml":"fas fa-file-alt","zip":"fas fa-file-archive"}';

    public function __construct(string $path = '')
    {
        $home = $path !== '' && is_dir($path) ? realpath($path) : realpath(SIFM_HOME);
        $this->home = $home === false ? SIFM_HOME : $home;
    }

    /** Every path is joined to the configured home — the one place an
     *  absolute path is ever built. */
    public function abs(string $path): string
    {
        return $this->home . '/' . path($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->abs($path));
    }

    public function writable(string $item): bool
    {
        return is_writable($this->abs($item));
    }

    public function isfile(string $path): bool
    {
        return is_file($this->abs($path));
    }

    public function isfolder(string $path): bool
    {
        return is_dir($this->abs($path));
    }

    /** Children of a folder (no "." / "..", hidden files kept). $deep walks
     *  the whole tree — home-relative paths — and $folders adds the folder
     *  entries themselves, right before their contents. */
    public function list(string $folder = '', bool $deep = false, bool $folders = false): array
    {
        if (!$this->isfolder($folder)) {
            return [];
        }
        $items = scandir($this->abs($folder));
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

    public function create(string $name, bool|string $dir = false): bool
    {
        if (is_string($dir)) {
            if (!in_array($dir, ['file', 'folder'], true)) {
                return false;
            }
            $dir = $dir === 'folder';
        }
        $name = path($name);
        if ($name === '') {
            return false;
        }
        if ($dir) {
            return is_dir($this->abs($name)) || mkdir($this->abs($name), 0755, true);
        }
        return file_exists($this->abs($name)) || file_put_contents($this->abs($name), '') !== false;
    }

    /** Accept an octal string or mode integer; optionally apply it recursively. */
    public function chmod(string $target, string|int $mod, bool $recursive = false): bool
    {
        if (is_string($mod) && !preg_match('/^[0-7]{3,4}$/', $mod)) {
            return false;
        }
        if (is_int($mod) && ($mod < 0 || $mod > 07777)) {
            return false;
        }
        $target = path($target);
        if ($target === '' || !file_exists($this->abs($target))) {
            return false;
        }
        $mode = is_int($mod) ? $mod : intval($mod, 8);
        $ok = true;
        if ($recursive && $this->isfolder($target)) {
            foreach ($this->list($target, true, true) as $item) {
                $ok = chmod($this->abs($item), $mode) && $ok;
            }
        }
        return chmod($this->abs($target), $mode) && $ok;
    }

    /** The folder that will hold a file — created when it is missing.
     *  "." (the home root) is already there and stays untouched. */
    private function ensure(string $dir): bool
    {
        return $this->isfolder($dir) || $this->create($dir, true);
    }

    public function copy(string $from, string $to): bool
    {
        $from = path($from);
        $to = path($to);
        if ($from === '' || $to === '' || $from === $to || str_starts_with($to, $from . '/')) {
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
        if (!$this->ensure(dirname($to))) {
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
        if (!$this->ensure(dirname($to))) {
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

    /** Delete for real — recursive unlink. */
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
    public function zip(array|string $paths, string $out): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
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
        $targets = [$folder];
        if ($items !== null) {
            $targets = [];
            foreach ($items as $item) {
                $item = path((string) $item);
                if ($item !== '') {
                    $targets[] = rel($folder, $item);
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
        $dest = path($dest);
        $zip = new ZipArchive();
        if ($file === '' || $zip->open($this->abs($file)) !== true) {
            return false;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entry = str_replace('\\', '/', (string) $name);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $mode = ($attributes >> 16) & 0170000;
            if ($name === false || $entry === '' || str_starts_with($entry, '/')
                || preg_match('/^[A-Za-z]:\//', $entry)
                || in_array('..', explode('/', $entry), true)
                || str_contains($entry, "\0") || $mode === 0120000) {
                $zip->close();
                return false;
            }
        }
        if ($dest !== '' && !$this->ensure($dest)) {
            $zip->close();
            return false;
        }
        $ok = $zip->extractTo($this->abs($dest));
        $zip->close();
        return $ok;
    }

    public function unzip(string $file, string $dest): bool
    {
        return $this->extract($file, $dest);
    }

    public function upload(array $file, string $dir): bool
    {
        $dir = path($dir);
        $name = basename((string) ($file['name'] ?? ''));
        if ($name === '' || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return false;
        }
        $target = rel($dir, $name);
        return move_uploaded_file($file['tmp_name'], $this->abs($target));
    }

    /** Stream a file to the browser and stop. */
    public function download(string $file, string $as = ''): void
    {
        $file = path($file);
        if ($file === '' || !$this->isfile($file)) {
            message('File not found.', 0);
            redirect('folder');
        }
        $name = basename($as !== '' ? $as : $file);
        $name = str_replace(['"', "\r", "\n"], '', $name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
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
        return (string) file_get_contents($this->abs($file));
    }

    public function read(string $file, bool $lines = false): string|array|false
    {
        if (!$this->isfile($file)) {
            return false;
        }
        $data = file_get_contents($this->abs($file));
        if ($data === false || !$lines) {
            return $data;
        }
        return preg_split('/\R/', $data) ?: [];
    }

    public function write(string $file, string $data = ''): bool
    {
        $file = path($file);
        if ($file === '' || !$this->isfolder(dirname($file))) {
            return false;
        }
        return file_put_contents($this->abs($file), $data, LOCK_EX) !== false;
    }

    public function folder(string $path): bool
    {
        return $this->isfolder($path);
    }

    public function file(string $path): bool
    {
        return $this->isfile($path);
    }

    public function mime(string $file): string|false
    {
        if (!$this->isfile($file)) {
            return false;
        }
        return (new finfo(FILEINFO_MIME_TYPE))->file($this->abs($file));
    }

    public function extension(string $file): string|false
    {
        if (!$this->isfile($file)) {
            return false;
        }
        return pathinfo($this->abs($file), PATHINFO_EXTENSION);
    }

    public function permissions(string $item): string|false
    {
        $item = path($item);
        $perms = $item === '' || !$this->exists($item) ? false : fileperms($this->abs($item));
        return $perms === false ? false : substr(sprintf('%o', $perms), -4);
    }

    public function modified(string $item): int|false
    {
        $item = path($item);
        if ($item === '' || !$this->exists($item)) {
            return false;
        }
        $time = filemtime($this->abs($item));
        return $time === false ? false : $time;
    }

    public function disk(): array|false
    {
        $total = disk_total_space($this->home);
        $free = disk_free_space($this->home);
        if ($total === false || $free === false) {
            return false;
        }
        return [
            'total' => $this->size('', (int) $total),
            'free' => $this->size('', (int) $free),
            'used' => $this->size('', (int) ($total - $free)),
        ];
    }

    public function link(string $target, string $link): bool
    {
        $target = path($target);
        $link = path($link);
        if ($target === '' || $link === '' || !$this->exists($target) || $this->exists($link)) {
            return false;
        }
        $home = realpath($this->home);
        $targetPath = realpath($this->abs($target));
        $linkPath = $this->abs($link);
        $linkParent = realpath(dirname($linkPath));
        if ($home === false || $targetPath === false || $linkParent === false
            || ($targetPath !== $home && !str_starts_with($targetPath, $home . DIRECTORY_SEPARATOR))
            || ($linkParent !== $home && !str_starts_with($linkParent, $home . DIRECTORY_SEPARATOR))
            || file_exists($linkPath) || is_link($linkPath)) {
            return false;
        }
        return symlink($targetPath, $linkParent . DIRECTORY_SEPARATOR . basename($linkPath));
    }

    public function hash(string $file, string $algo = 'sha256'): string|false
    {
        if (!$this->isfile($file) || !in_array($algo, hash_algos(), true)) {
            return false;
        }
        return hash_file($algo, $this->abs($file));
    }

    public function imageDataUri(string $file): string|false
    {
        $data = $this->read($file);
        $mime = $this->mime($file);
        if (!is_string($data) || $mime === false || !str_starts_with($mime, 'image/')) {
            return false;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
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
        $path = $this->abs($item);
        $info = [
            'perms' => intval(substr(sprintf('%o', fileperms($path)), -4)),
            'path' => realpath($path),
            'owner' => fileowner($path),
            'last_accessed' => date(SIFM_DATE, fileatime($path)),
            'last_modified' => date(SIFM_DATE, filemtime($path)),
        ];
        if (is_file($path)) {
            $info['size'] = $this->size($item);
            $info['mimetype'] = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $info += pathinfo($path);
        } else {
            $info['basename'] = basename($path);
            $info['dirname'] = dirname($path);
        }
        ksort($info);
        return $info;
    }

    /** Human-readable size, 2 dp; accepts raw bytes or the legacy raw-size flag. */
    public function size(string|int $item, int|bool|null $bytes = null): string|int|false
    {
        $human = $bytes !== false;
        if (is_int($item)) {
            $size = $item;
        } elseif (is_int($bytes)) {
            $size = $bytes;
        } else {
            $item = path($item);
            $path = $this->abs($item);
            if (is_file($path)) {
                $size = filesize($path);
            } elseif (is_dir($path)) {
                $size = 0;
                foreach ($this->list($item, true) as $file) {
                    $size += (int) filesize($this->abs($file));
                }
            } else {
                return false;
            }
        }
        if (!$human) {
            return $size;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size = round($size / 1024, 2);
        }
        return $size . ' ' . $units[$i];
    }

    public function zipinfo(string $file): array|false
    {
        $file = path($file);
        if ($file === '' || !$this->isfile($file)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($this->abs($file)) !== true) {
            return false;
        }
        $info = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry !== false) {
                $info[] = $entry;
            }
        }
        $zip->close();
        return $info;
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

    /** A text search through the content of the files under $folder — the
     *  files whose text holds the needle. Big and binary files are skipped. */
    public function grep(string $needle, string $folder = ''): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }
        $hits = [];
        foreach ($this->list($folder, true) as $file) {
            if (count($hits) >= self::grep_files) {
                break;
            }
            if (!$this->isfile($file) || (int) @filesize($this->abs($file)) > self::grep_bytes) {
                continue;
            }
            $text = $this->source($file);
            if ($text === '' || str_contains($text, "\0")) {
                continue; // binary
            }
            if (stripos($text, $needle) !== false) {
                $hits[] = $file;
            }
        }
        return $hits;
    }

    /** The image tools: rotate, resize, or save a WebP beside the original.
     *  Returns what it did, or null when the file is not an image it can open. */
    public function image(string $file, ?string $op = null, int $width = 0): ?string
    {
        if ($op === null) {
            $uri = $this->imageDataUri($file);
            return $uri === false ? null : $uri;
        }
        $path = $this->abs($file);
        if (!$this->isfile($file)) {
            return null;
        }
        $im = @imagecreatefromstring((string) @file_get_contents($path));
        if ($im === false) {
            return null;
        }
        if ($op === 'webp') {
            $webp = rel(dirname($file), pathinfo($file, PATHINFO_FILENAME) . '.webp');
            $done = imagewebp($im, $this->abs($webp), 85);
            imagedestroy($im);
            return $done ? 'Saved as ' . $webp . '.' : null;
        }
        $out = $im;
        if ($op === 'left' || $op === 'right') {
            $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
            $out = imagerotate($im, $op === 'left' ? -90 : 90, $bg);
        } elseif ($op === 'resize' && $width > 0 && $width <= 10000) {
            $out = imagescale($im, $width);
        } else {
            imagedestroy($im);
            return null;
        }
        if ($out === false) {
            imagedestroy($im);
            return null;
        }
        $done = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => imagepng($out, $path),
            'gif' => imagegif($out, $path),
            'webp' => imagewebp($out, $path, 85),
            default => imagejpeg($out, $path, 90),
        };
        imagedestroy($im);
        if ($out !== $im) {
            imagedestroy($out);
        }
        return $done ? match ($op) {
            'left' => 'Rotated 90° to the left.',
            'right' => 'Rotated 90° to the right.',
            default => 'Resized to ' . $width . ' px wide.',
        } : null;
    }
}