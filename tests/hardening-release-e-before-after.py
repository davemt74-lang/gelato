"""Reproduce original Release D runtime boundary defects without live services.

Run against the immutable baseline fetched by CI. Baseline source files are
copied to a temporary directory; the tested checkout is never replaced for the
PHP/JavaScript cases. A specific behavioral assertion is required, not merely
a nonzero exit or a missing dependency.
"""
import argparse
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = "82eda028f40af0c849b403ee037740f96bc3173b"


def original(path: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{BASE}:{path}"], cwd=ROOT,
        capture_output=True, check=True,
    ).stdout


def reproduce(name: str, command: list[str], assertion: str, timeout: int = 180) -> dict:
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    output = result.stdout + result.stderr
    if name == "unobserved_unity_heartbeat":
        Path("/tmp/gelato-hardening-e-unity-baseline.log").write_text(output)
    if result.returncode == 0 or assertion not in output:
        raise RuntimeError(f"{name}: original source did not fail for the expected behavior:\n{output}")
    print(f"Baseline detected: {name} — {assertion}", flush=True)
    return {"case": name, "baseline": BASE, "result": "expected_failure", "assertion": assertion}


def backend() -> list[dict]:
    with tempfile.TemporaryDirectory(prefix="gelato-hardening-e-baseline-") as temporary:
        baseline = Path(temporary)
        paths = [
            "api/public-agent.php", "js/agent-confirmation-transport.js", "js/global-agent.js",
            "js/restaurant-agent-bridge.js", "js/operations-core.js", "js/timeclock-voice.js",
        ]
        for path in paths:
            target = baseline / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(original(path))
        return [
            reproduce(
                "fresh_session_quota_bypass",
                ["php", "tests/hardening-public-agent-limits.php", "baseline", str(baseline / "api/public-agent.php")],
                "Fresh sessions must not obtain provider admission beyond the shared network limit.",
            ),
            reproduce(
                "legacy_microphone_channel_downgrade",
                ["node", "tests/hardening-legacy-voice.cjs", "--baseline", str(baseline)],
                "FE-08: microphone onresult must preserve voice:true, never downgrade speech to a typed command.",
            ),
        ]


def unity() -> list[dict]:
    relative = Path("glasses/inmo-air3-unity")
    with tempfile.TemporaryDirectory(prefix="gelato-hardening-e-unity-baseline-") as temporary:
        baseline = Path(temporary) / relative
        shutil.copytree(ROOT / relative, baseline, ignore=shutil.ignore_patterns("bin", "obj"))
        for path in ["Runtime/Unity/VisionRuntimeController.cs", "Runtime/Core/DeviceRuntimeSupervisor.cs"]:
            (baseline / path).write_bytes(original(str(relative / path)))
        return [reproduce(
            "unobserved_unity_heartbeat",
            ["dotnet", "run", "--project", str(baseline / "AdapterTests/Gelato.Ar.Unity.Adapter.Tests.csproj"),
             "-c", "Release", "--", "--baseline-heartbeat"],
            "Before the first acknowledged heartbeat, actual Unity controller must block automated vision.",
            timeout=300,
        )]


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    selection = parser.add_mutually_exclusive_group(required=True)
    selection.add_argument("--backend", action="store_true")
    selection.add_argument("--unity", action="store_true")
    arguments = parser.parse_args()
    kind = "backend" if arguments.backend else "unity"
    evidence = backend() if arguments.backend else unity()
    target = Path(f"/tmp/gelato-hardening-e-{kind}-baseline.json")
    target.write_text(json.dumps(evidence, indent=2) + "\n")
    print(f"{len(evidence)} original runtime regressions reproduced; production checkout unchanged.")
