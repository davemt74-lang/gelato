"""Prove the new regressions detect their original Release C defects.

Run only against the disposable, fully upgraded CI database. Each original
production module is restored in finally; new tests stay identical throughout.
"""
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = "5f328cc67babaa4a654f1562c8b0e623c3defe98"
CASES = [
    ("task_archive_authority", ["tests/hardening-task-archive-contract.php", "authority-only"],
     "A request waiting on its authority boundary cannot retain revoked owner_role authority.",
     ["includes/operations-archive-core.php", "includes/food-monster-g9i-task-archive.php"]),
    ("recipe_ingredient_names", ["tests/hardening-recipe-ingredients.php"],
     "Complete multiword name must be preserved:",
     ["includes/operations-core.php", "includes/catering-operations.php"]),
    ("complete_receivables", ["tests/hardening-receivables-totals.php"],
     "All 301 invoices must contribute to receivables.",
     ["includes/wholesale-receivables.php", "includes/wholesale-portal-operations.php"]),
    ("overnight_availability", ["tests/hardening-scheduling-intervals.php"],
     "A daytime window must not cover an overnight shift",
     ["includes/scheduling-core.php"]),
    ("recipe_source_fraction", ["tests/hardening-recipe-sources.php"],
     "The object fraction must reach the canonical inventory source as one half.",
     ["includes/operations-core.php"]),
    ("media_retention", ["tests/hardening-media-retention-contract.php", "availability-only"],
     "Expired active media must be absent from export before cleanup.",
     ["includes/glasses-vision-training-media.php"]),
]

evidence = []
for name, arguments, expected, paths in CASES:
    saved = {p: (ROOT / p).read_bytes() for p in paths}
    try:
        for path in paths:
            source = subprocess.run(["git", "show", f"{BASE}:{path}"], cwd=ROOT, capture_output=True, check=True).stdout
            (ROOT / path).write_bytes(source)
        result = subprocess.run(["php", *arguments], cwd=ROOT, text=True, capture_output=True, timeout=180)
        output = result.stdout + result.stderr
        if result.returncode == 0 or expected not in output:
            raise RuntimeError(f"{name}: baseline did not fail for the expected behavior:\n{output}")
        evidence.append({"case": name, "baseline": BASE, "result": "expected_failure", "assertion": expected})
        print(f"Baseline detected: {name} — {expected}", flush=True)
    finally:
        for path, source in saved.items():
            (ROOT / path).write_bytes(source)

target = Path("/tmp/gelato-hardening-d-baseline.json")
target.write_text(json.dumps(evidence, indent=2) + "\n")
print(f"{len(evidence)} original regressions reproduced; tested production sources restored.")
