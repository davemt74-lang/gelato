from pathlib import Path
p=Path('tests/menu-operations-agent-brain-contract.php')
s=p.read_text(encoding='utf-8')
old='$kdsNeedle="if(app_has_permission(\'kds.view\',$user)) $links[]=[\'label\'=>\'KDS\'";$posNeedle="if(app_has_permission(\'pos.use\',$user)) $links[]=[\'label\'=>\'POS\'";'
new='$kdsNeedle="if(app_has_permission(\'kds.view\',\\$user)) \\$links[]=[\'label\'=>\'KDS\'";$posNeedle="if(app_has_permission(\'pos.use\',\\$user)) \\$links[]=[\'label\'=>\'POS\'";'
if old not in s:
    raise SystemExit('literal assertion target not found')
p.write_text(s.replace(old,new,1),encoding='utf-8')
