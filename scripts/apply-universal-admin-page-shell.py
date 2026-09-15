from pathlib import Path

TARGETS = [
    'locations-admin.php',
    'operations.php',
    'catering-operations.php',
    'catering-pipeline.php',
    'sales-intelligence.php',
    'customer-promotions.php',
    'customer-crm.php',
    'online-orders-admin.php',
    'recipes.php',
]

SCRIPT_TAG = '<script src="js/universal-admin-page-shell.js?v=20260915-1"></script>'

changed = []
for target in TARGETS:
    path = Path(target)
    text = path.read_text(encoding='utf-8')
    if SCRIPT_TAG in text:
        continue
    if '</body>' not in text:
        raise SystemExit(f'{target}: missing </body>')
    text = text.replace('</body>', SCRIPT_TAG + '\n</body>', 1)
    path.write_text(text, encoding='utf-8')
    changed.append(target)

print('Integrated universal admin shell into:', ', '.join(changed) if changed else 'already integrated')
