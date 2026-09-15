from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'patch target not found: {label} ({path})')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')


# Canonical header order: KDS -> POS -> +. Permissions remain server-scoped.
replace_once(
    'includes/admin-shell-core.php',
    "    if(app_has_permission('pos.use',$user)) $links[]=['label'=>'POS','href'=>'pos.php','style'=>'dark'];\n    if(app_has_permission('kds.view',$user)) $links[]=['label'=>'KDS','href'=>'kds.php','style'=>'kds'];",
    "    if(app_has_permission('kds.view',$user)) $links[]=['label'=>'KDS','href'=>'kds.php','style'=>'kds'];\n    if(app_has_permission('pos.use',$user)) $links[]=['label'=>'POS','href'=>'pos.php','style'=>'dark'];",
    'header shortcut order',
)

# Universal admin shell brand is a real dashboard link.
replace_once(
    'js/universal-admin-page-shell.js',
    '.uas-brand{display:flex;align-items:center;gap:9px;padding:2px 7px 11px}',
    '.uas-brand{display:flex;align-items:center;gap:9px;padding:2px 7px 11px;text-decoration:none;color:inherit}',
    'universal brand link style',
)
replace_once(
    'js/universal-admin-page-shell.js',
    "sidebar.innerHTML = '<div class=\"uas-brand\"><div class=\"uas-logo\">G</div><div><h1>Gelato</h1><p>Restaurant operating system</p></div></div><nav class=\"uas-nav\"></nav><div class=\"uas-foot\"><span class=\"uas-status\"></span>System agent online<br>Permission-scoped restaurant access</div>';",
    "sidebar.innerHTML = '<a class=\"uas-brand\" href=\"workspace.php\" aria-label=\"Open admin dashboard\"><div class=\"uas-logo\">G</div><div><h1>Gelato</h1><p>Restaurant operating system</p></div></a><nav class=\"uas-nav\"></nav><div class=\"uas-foot\"><span class=\"uas-status\"></span>System agent online<br>Permission-scoped restaurant access</div>';",
    'universal brand dashboard link',
)

# Workspace-native sidebar brand links to the same default admin dashboard.
replace_once(
    'index.html',
    '.brand{display:flex;gap:9px;align-items:center;padding:2px 7px 11px}',
    '.brand{display:flex;gap:9px;align-items:center;padding:2px 7px 11px;text-decoration:none;color:inherit}',
    'workspace brand link style',
)
replace_once(
    'index.html',
    '    <div class="brand"><div class="logo">G</div><div><h1>Gelato</h1><p>Local menu knowledge agent</p></div></div>',
    '    <a class="brand" href="workspace.php" aria-label="Open admin dashboard"><div class="logo">G</div><div><h1>Gelato</h1><p>Local menu knowledge agent</p></div></a>',
    'workspace brand dashboard link',
)

# Make the global add launcher white with a black plus as requested.
replace_once(
    'css/global-add-canvas.css',
    '.gac-launch{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;border:1px solid #171815;border-radius:12px;background:#171815;color:#fff;font:900 26px/1 Inter,ui-sans-serif,system-ui;cursor:pointer;box-shadow:0 8px 24px rgba(20,22,18,.12)}',
    '.gac-launch{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;border:1px solid #deded8;border-radius:12px;background:#fff;color:#111;font:900 26px/1 Inter,ui-sans-serif,system-ui;cursor:pointer;box-shadow:0 5px 16px rgba(20,22,18,.08)}',
    'global add launcher colors',
)

# Give workspace shortcut links stable ids and preserve server-defined KDS/POS ordering.
p = Path('js/workspace-shell-sync.js')
s = p.read_text(encoding='utf-8')
old = """      link.dataset.canonicalShellShortcut = '1';
      link.className = `gelato-header-shortcut ${item.style === 'dark' ? 'pos' : item.style || ''}`.trim();
      link.href = item.href;
      link.textContent = item.label;
"""
new = """      link.dataset.canonicalShellShortcut = '1';
      link.className = `gelato-header-shortcut ${item.style === 'dark' ? 'pos' : item.style || ''}`.trim();
      if (item.label === 'POS') link.id = 'gelatoHeaderPos';
      if (item.label === 'KDS') link.id = 'gelatoHeaderKds';
      link.href = item.href;
      link.textContent = item.label;
"""
if old not in s:
    raise SystemExit('patch target not found: workspace shortcut stable ids')
p.write_text(s.replace(old, new, 1), encoding='utf-8')
