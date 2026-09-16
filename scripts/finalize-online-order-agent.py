from pathlib import Path

source_path = Path('scripts/online-order-agent-integration-patch.py')
source = source_path.read_text()
start = source.index('# Normalize the temporary registry tripwire')
end = source.index('# Shared fallback routing.')
source_only = source[:start] + source[end:]
stop = source_only.index('# Existing Brain workflow also covers the new node.')
source_only = source_only[:stop]
exec(compile(source_only, str(source_path), 'exec'), {'__name__': '__main__'})

# Safety hardening: an explicit order identifier that is invalid must not silently
# fall back to the order currently selected in page context.
core = Path('includes/online-order-agent-core.php')
text = core.read_text()
old = """        $row=pickup_fulfillment_order($pdo,$organizationId,(string)$explicit['value'],false);
        if($row)return $row;
    }
    $selected=trim((string)($context['selectedOrderPublicId']??''));
"""
new = """        return pickup_fulfillment_order($pdo,$organizationId,(string)$explicit['value'],false);
    }
    $selected=trim((string)($context['selectedOrderPublicId']??''));
"""
if text.count(old) != 1:
    raise SystemExit('explicit order identifier hardening marker mismatch')
core.write_text(text.replace(old, new, 1))
