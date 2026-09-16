from pathlib import Path

source_path = Path('scripts/online-order-agent-integration-patch.py')
source = source_path.read_text()
start = source.index('# Normalize the temporary registry tripwire')
end = source.index('# Shared fallback routing.')
safe = source[:start] + source[end:]
before, marker, after = safe.partition('# Production runtime closure.')
if not marker:
    raise SystemExit('production runtime marker missing')
# Agent Brain orchestration has one path-filter block, not two.
before = before.replace(', 2),', ', 1),')
safe = before + marker + after
exec(compile(safe, str(source_path), 'exec'), {'__name__': '__main__'})

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
