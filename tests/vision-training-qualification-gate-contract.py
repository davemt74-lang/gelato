#!/usr/bin/env python3
from pathlib import Path
import hashlib, importlib.util, json, sys, tempfile

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("gelato_pipeline",root/"tools/vision_training/pipeline.py")
mod=importlib.util.module_from_spec(spec);sys.modules[spec.name]=mod;spec.loader.exec_module(mod)

def sha(path:Path)->str: return hashlib.sha256(path.read_bytes()).hexdigest()
def canon_hash(value:dict)->str:
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()).hexdigest()

with tempfile.TemporaryDirectory(prefix="gelato-v6-qualification-") as td:
    src=Path(td)/"release";out=Path(td)/"workspace";files=[]
    for split,idx in (("train",1),("val",2),("test",3)):
        (src/"images"/split).mkdir(parents=True,exist_ok=True);(src/"labels"/split).mkdir(parents=True,exist_ok=True)
        image=src/"images"/split/f"image-{idx:06d}.png";label=src/"labels"/split/f"image-{idx:06d}.txt"
        image.write_bytes(f"image-{split}".encode());label.write_text("0 0.3 0.3 0.2 0.2\n",encoding="utf-8")
        for p,t in ((image,"image"),(label,"label")): files.append({"path":p.relative_to(src).as_posix(),"sha256":sha(p),"bytes":p.stat().st_size,"type":t})
    yaml=src/"dataset.yaml";yaml.write_text('path: .\ntrain: images/train\nval: images/val\ntest: images/test\nnames:\n  0: "pepperoni"\n',encoding="utf-8")
    files.append({"path":"dataset.yaml","sha256":sha(yaml),"bytes":yaml.stat().st_size,"type":"config"});files.sort(key=lambda x:x["path"])
    release_hash="a"*64
    manifest={"schema":mod.SCHEMA_TRAINING_RELEASE,"format":"yolo_detection","releaseHash":release_hash,
      "dataset":{"publicId":"dataset","datasetHash":"b"*64},"splitPlan":{"publicId":"plan","planHash":"c"*64,"policy":{"captureGroup":True}},
      "trainingProfile":{"name":"yolo11n_640","config":{}},"classes":["pepperoni"],"splitCounts":{"train":1,"val":1,"test":1},
      "itemCount":3,"items":[],"files":files,"privacy":{"absolutePathsIncluded":False,"privateStoragePathsIncluded":False}}
    (src/"provenance-manifest.json").write_text(json.dumps(manifest),encoding="utf-8")

    try:
        mod.prepare_workspace(src,out,require_qualification=True)
        raise AssertionError("unqualified V6 release must not train")
    except mod.PipelineError as exc:
        assert "not qualified" in str(exc)

    checks=[{"key":"samples_per_class","label":"Minimum samples per class","actual":20,"required":20,"operator":">=","passed":True},
            {"key":"split_leakage","label":"Protected-group leakage","actual":0,"required":0,"operator":"<=","passed":True}]
    q={"schema":mod.SCHEMA_TRAINING_QUALIFICATION,"releaseHash":release_hash,"datasetHash":"b"*64,"splitPlanHash":"c"*64,
       "trainingProfile":"yolo11n_640","policy":{"minimumSamplesPerClass":20},"checks":checks,"score":100,"passed":True}
    q["qualificationHash"]=canon_hash(q)
    (src/"qualification.json").write_text(json.dumps(q),encoding="utf-8")
    prepared=mod.prepare_workspace(src,out,require_qualification=True)
    assert prepared["mode"]=="governed_release" and prepared["releaseHash"]==release_hash

    bad=dict(q);bad["checks"]=[dict(checks[0]),dict(checks[1],passed=False)];bad["qualificationHash"]=canon_hash({k:v for k,v in bad.items() if k!="qualificationHash"})
    (src/"qualification.json").write_text(json.dumps(bad),encoding="utf-8")
    try:
        mod.prepare_workspace(src,out,require_qualification=True)
        raise AssertionError("qualification with failed check must not train")
    except mod.PipelineError as exc:
        assert "failed check" in str(exc)

    tampered=dict(q);tampered["score"]=99
    (src/"qualification.json").write_text(json.dumps(tampered),encoding="utf-8")
    try:
        mod.prepare_workspace(src,out,require_qualification=True)
        raise AssertionError("tampered qualification must fail hash verification")
    except mod.PipelineError as exc:
        assert "hash verification" in str(exc)

print("vision-training-qualification-gate-ok")
