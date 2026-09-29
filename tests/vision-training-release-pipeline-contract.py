#!/usr/bin/env python3
from pathlib import Path
import hashlib, importlib.util, json, sys, tempfile

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("gelato_pipeline",root/"tools/vision_training/pipeline.py")
mod=importlib.util.module_from_spec(spec);sys.modules[spec.name]=mod;spec.loader.exec_module(mod)

def sha(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

with tempfile.TemporaryDirectory(prefix="gelato-v6-release-") as td:
    src=Path(td)/"release";out=Path(td)/"workspace"
    files=[]
    for split,idx in (("train",1),("val",2),("test",3)):
        (src/"images"/split).mkdir(parents=True,exist_ok=True)
        (src/"labels"/split).mkdir(parents=True,exist_ok=True)
        image=src/"images"/split/f"image-{idx:06d}.png"
        label=src/"labels"/split/f"image-{idx:06d}.txt"
        image.write_bytes(f"image-{split}".encode())
        label.write_text("0 0.30000000 0.35000000 0.40000000 0.30000000\n",encoding="utf-8")
        for path,kind in ((image,"image"),(label,"label")):
            files.append({"path":path.relative_to(src).as_posix(),"sha256":sha(path),"bytes":path.stat().st_size,"type":kind})
    yaml=src/"dataset.yaml";yaml.write_text('path: .\ntrain: images/train\nval: images/val\ntest: images/test\nnames:\n  0: "pepperoni"\n',encoding="utf-8")
    files.append({"path":"dataset.yaml","sha256":sha(yaml),"bytes":yaml.stat().st_size,"type":"config"})
    files.sort(key=lambda x:x["path"])
    manifest={
      "schema":mod.SCHEMA_TRAINING_RELEASE,"format":"yolo_detection",
      "dataset":{"publicId":"vision-dataset-fixture","datasetHash":"a"*64},
      "splitPlan":{"publicId":"vision-plan-fixture","planHash":"b"*64,"policy":{"captureGroup":True,"buildSession":True}},
      "trainingProfile":{"name":"yolo11n_640","config":{"imageSize":640}},
      "classes":["pepperoni"],"splitCounts":{"train":1,"val":1,"test":1},"itemCount":3,
      "files":files,"items":[],"privacy":{"absolutePathsIncluded":False,"privateStoragePathsIncluded":False},
      "releaseHash":"c"*64,
    }
    (src/"provenance-manifest.json").write_text(json.dumps(manifest,indent=2)+"\n",encoding="utf-8")

    inspected=mod.inspect_training_release(src)
    assert inspected["splitCounts"]=={"train":1,"val":1,"test":1}
    prepared=mod.prepare_workspace(src,out,seed=999,train_ratio=.2,val_ratio=.3,test_ratio=.5)
    assert prepared["mode"]=="governed_release"
    assert prepared["releaseHash"]=="c"*64
    assert prepared["counts"]=={"train":1,"val":1,"test":1}
    assert (out/"images/train/image-000001.png").read_bytes()==b"image-train"
    assert (out/"images/val/image-000002.png").read_bytes()==b"image-val"
    assert (out/"images/test/image-000003.png").read_bytes()==b"image-test"
    assert not (out/"images/train/image-000002.png").exists(), "governed release must not be re-split"

    (src/"images/train/image-000001.png").write_bytes(b"tampered")
    try:
        mod.inspect_training_release(src)
        raise AssertionError("tampered package hash should fail")
    except mod.PipelineError as exc:
        assert "hash mismatch" in str(exc)

print("vision-training-release-pipeline-ok")
