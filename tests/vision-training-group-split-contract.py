#!/usr/bin/env python3
from pathlib import Path
import importlib.util

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("gelato_pipeline",root/"tools/vision_training/pipeline.py")
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)

images=[Path(f"frame-{i:06d}.jpg") for i in range(1,7)]
manifest={"samples":[
 {"index":1,"buildPublicId":"build-a","captureGroup":"burst-a"},
 {"index":2,"buildPublicId":"build-a","captureGroup":"burst-a"},
 {"index":3,"buildPublicId":"build-b","captureGroup":"burst-b"},
 {"index":4,"buildPublicId":"build-b","captureGroup":"burst-b"},
 {"index":5,"buildPublicId":"build-c","captureGroup":"burst-c"},
 {"index":6,"buildPublicId":"build-d","captureGroup":"burst-d"},
]}
splits,prov=mod.deterministic_split(images,74,(.70,.15,.15),manifest)
lookup={p.name:k for k,vals in splits.items() for p in vals}
assert lookup["frame-000001.jpg"]==lookup["frame-000002.jpg"]
assert lookup["frame-000003.jpg"]==lookup["frame-000004.jpg"]
assert all(splits.values())
assert prov["mode"]=="group_aware"
splits2,prov2=mod.deterministic_split(images,74,(.70,.15,.15),manifest)
assert {k:[p.name for p in v] for k,v in splits.items()}=={k:[p.name for p in v] for k,v in splits2.items()}
fallback,prov3=mod.deterministic_split(images[:3],74,(.70,.15,.15),{"samples":[]})
assert prov3["mode"]=="sample_fallback"
try:
    mod.deterministic_split(images[:4],74,(.70,.15,.15),{"samples":[
      {"index":1,"buildPublicId":"one"},{"index":2,"buildPublicId":"one"},{"index":3,"buildPublicId":"two"},{"index":4,"buildPublicId":"two"}
    ]})
    raise AssertionError("expected insufficient lineage groups to fail")
except mod.PipelineError:
    pass
print("vision-training-group-split-ok")
