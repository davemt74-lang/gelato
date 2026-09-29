#!/usr/bin/env python3
from __future__ import annotations
import hashlib, importlib.util, json, sys, tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("pipeline",ROOT/"tools/vision_training/pipeline.py")
pipeline=importlib.util.module_from_spec(spec);assert spec and spec.loader;sys.modules[spec.name]=pipeline;spec.loader.exec_module(pipeline)

def sha(data:bytes)->str:return hashlib.sha256(data).hexdigest()

with tempfile.TemporaryDirectory() as td:
    root=Path(td)/"release";root.mkdir()
    members={}
    for split in ("train","val","test"):
        (root/"images"/split).mkdir(parents=True)
        (root/"labels"/split).mkdir(parents=True)
        image=(b"fake-"+split.encode())
        label=b"0 0.500000 0.500000 0.500000 0.500000\n"
        ip=f"images/{split}/sample-{split}.jpg";lp=f"labels/{split}/sample-{split}.txt"
        (root/ip).write_bytes(image);(root/lp).write_bytes(label)
        members[ip]=sha(image);members[lp]=sha(label)
    yaml=b'path: .\ntrain: images/train\nval: images/val\ntest: images/test\nnames:\n  0: "pizza"\n'
    prov=b'{"privatePathsExcluded":true}\n'
    (root/"data.yaml").write_bytes(yaml);members["data.yaml"]=sha(yaml)
    (root/"provenance.json").write_bytes(prov);members["provenance.json"]=sha(prov)
    manifest={
        "schema":"gelato.vision_training_release.v1",
        "packageHash":pipeline.training_release_core_hash(members),
        "splitPlanPublicId":"vision-split-test",
        "splitPlanHash":"a"*64,
        "classes":["pizza"],
        "splitMediaCounts":{"train":1,"val":1,"test":1},
        "members":members,
    }
    (root/"release-manifest.json").write_text(json.dumps(manifest),encoding="utf-8")
    checks="".join(f"{members[p]}  {p}\n" for p in sorted(members))
    (root/"checksums.sha256").write_text(checks,encoding="utf-8")

    out=Path(td)/"workspace"
    prepared=pipeline.prepare_workspace(root,out)
    assert prepared["grouping"]["mode"]=="governed_release"
    assert prepared["grouping"]["resplit"] is False
    assert sorted(p.name for p in (out/"images"/"val").iterdir())==["sample-val.jpg"]
    assert sorted(p.name for p in (out/"images"/"test").iterdir())==["sample-test.jpg"]

    (root/"images"/"val"/"sample-val.jpg").write_bytes(b"tampered")
    try:
        pipeline.prepare_workspace(root,Path(td)/"bad")
        raise AssertionError("tampered governed release was accepted")
    except pipeline.PipelineError as exc:
        assert "checksum mismatch" in str(exc)

print("Vision training governed release contract green")
