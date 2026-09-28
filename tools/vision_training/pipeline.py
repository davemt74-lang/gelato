#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, os, random, shutil, sys, tempfile, zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any

SCHEMA_DATASET="gelato.vision_training_dataset.v1"
SCHEMA_RELEASE="gelato.vision_model_release.v1"
SCHEMA_BROWSER="gelato.browser_onnx_detector.v1"
IMAGE_EXTS={".jpg",".jpeg",".png",".webp"}

class PipelineError(RuntimeError): pass

@dataclass(frozen=True)
class Thresholds:
    precision: float=.70
    recall: float=.70
    map50: float=.75
    map5095: float=.45
    per_class_precision: float=.60
    per_class_recall: float=.60
    per_class_map50: float=.65

def sha256_file(path: Path)->str:
    h=hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda:f.read(1024*1024),b""): h.update(chunk)
    return h.hexdigest()

def safe_extract_zip(source: Path,dest: Path)->None:
    with zipfile.ZipFile(source) as zf:
        root=dest.resolve()
        for info in zf.infolist():
            target=(dest/info.filename).resolve()
            if os.path.commonpath([root,target])!=str(root):
                raise PipelineError(f"Unsafe ZIP path: {info.filename}")
        zf.extractall(dest)

def materialize_dataset(source: Path)->tuple[Path,tempfile.TemporaryDirectory|None]:
    source=source.resolve()
    if source.is_dir(): return source,None
    if not source.is_file() or source.suffix.lower()!=".zip":
        raise PipelineError("Dataset must be a simulator YOLO ZIP or extracted directory.")
    tmp=tempfile.TemporaryDirectory(prefix="gelato-vision-dataset-")
    root=Path(tmp.name)
    safe_extract_zip(source,root)
    return root,tmp

def load_manifest(root: Path)->dict[str,Any]:
    path=root/"gelato-manifest.json"
    if not path.is_file(): raise PipelineError("gelato-manifest.json is missing.")
    data=json.loads(path.read_text("utf-8"))
    if data.get("schema")!=SCHEMA_DATASET: raise PipelineError("Dataset schema is unsupported.")
    if data.get("format")!="yolo_detection": raise PipelineError("Dataset format must be yolo_detection.")
    classes=data.get("classes")
    if not isinstance(classes,list) or not classes: raise PipelineError("Dataset classes are missing.")
    for i,item in enumerate(classes):
        if not isinstance(item,dict) or item.get("index")!=i or not str(item.get("name","")).strip():
            raise PipelineError("Dataset class indexes must be contiguous and named.")
    return data

def parse_label_file(path: Path,class_count: int)->list[tuple[int,float,float,float,float]]:
    out=[]
    if not path.exists(): return out
    for line_no,line in enumerate(path.read_text("utf-8").splitlines(),1):
        line=line.strip()
        if not line: continue
        parts=line.split()
        if len(parts)!=5: raise PipelineError(f"{path}: line {line_no} must contain five YOLO fields.")
        try:
            cls=int(parts[0]); vals=[float(v) for v in parts[1:]]
        except ValueError as e: raise PipelineError(f"{path}: invalid numeric label on line {line_no}.") from e
        if cls<0 or cls>=class_count: raise PipelineError(f"{path}: class index {cls} is out of range.")
        cx,cy,w,h=vals
        if not all(0<=v<=1 for v in vals): raise PipelineError(f"{path}: YOLO geometry must be normalized 0-1.")
        if w<=0 or h<=0 or cx-w/2<0 or cy-h/2<0 or cx+w/2>1 or cy+h/2>1:
            raise PipelineError(f"{path}: bounding box leaves the image.")
        out.append((cls,cx,cy,w,h))
    return out

def inspect_dataset(root: Path)->dict[str,Any]:
    manifest=load_manifest(root)
    classes=[str(c["name"]) for c in manifest["classes"]]
    images=sorted([p for p in (root/"images"/"train").glob("*") if p.suffix.lower() in IMAGE_EXTS])
    if not images: raise PipelineError("Dataset contains no training images.")
    counts=[0]*len(classes); empty=0
    for image in images:
        labels=parse_label_file(root/"labels"/"train"/f"{image.stem}.txt",len(classes))
        if not labels: empty+=1
        for cls,*_ in labels: counts[cls]+=1
    declared=int(manifest.get("sampleCount",len(images)))
    if declared!=len(images): raise PipelineError(f"Manifest sampleCount={declared} but {len(images)} images were found.")
    return {"root":str(root),"manifest":manifest,"classes":classes,"images":images,"boxCounts":dict(zip(classes,counts)),"emptyImages":empty}

def deterministic_split(images:list[Path],seed:int,ratios:tuple[float,float,float])->dict[str,list[Path]]:
    if len(images)<3: raise PipelineError("At least 3 images are required for train/val/test splitting.")
    if abs(sum(ratios)-1.0)>1e-9 or min(ratios)<=0: raise PipelineError("Split ratios must be positive and sum to 1.")
    keyed=[]
    for p in images:
        digest=hashlib.sha256(f"{seed}|{p.name}".encode()).hexdigest()
        keyed.append((digest,p))
    ordered=[p for _,p in sorted(keyed)]
    n=len(ordered); n_val=max(1,round(n*ratios[1])); n_test=max(1,round(n*ratios[2])); n_train=n-n_val-n_test
    if n_train<1:
        n_train=1
        if n_val>n_test:n_val-=1
        else:n_test-=1
    return {"train":ordered[:n_train],"val":ordered[n_train:n_train+n_val],"test":ordered[n_train+n_val:]}

def prepare_workspace(dataset: Path,out: Path,seed:int=74,train_ratio:float=.70,val_ratio:float=.15,test_ratio:float=.15)->dict[str,Any]:
    root,tmp=materialize_dataset(dataset)
    try:
        info=inspect_dataset(root); splits=deterministic_split(info["images"],seed,(train_ratio,val_ratio,test_ratio))
        if out.exists(): shutil.rmtree(out)
        for split,images in splits.items():
            (out/"images"/split).mkdir(parents=True,exist_ok=True);(out/"labels"/split).mkdir(parents=True,exist_ok=True)
            for image in images:
                shutil.copy2(image,out/"images"/split/image.name)
                label=root/"labels"/"train"/f"{image.stem}.txt"
                if label.exists(): shutil.copy2(label,out/"labels"/split/label.name)
                else: (out/"labels"/split/f"{image.stem}.txt").write_text("",encoding="utf-8")
        names=info["classes"]
        yaml_lines=["path: "+out.as_posix(),"train: images/train","val: images/val","test: images/test","names:"]
        yaml_lines += [f"  {i}: {json.dumps(name)}" for i,name in enumerate(names)]
        (out/"data.yaml").write_text("\n".join(yaml_lines)+"\n",encoding="utf-8")
        split_manifest={"schema":"gelato.vision_training_split.v1","seed":seed,"ratios":{"train":train_ratio,"val":val_ratio,"test":test_ratio},"counts":{k:len(v) for k,v in splits.items()},"classes":names,"sourceManifest":info["manifest"]}
        (out/"split-manifest.json").write_text(json.dumps(split_manifest,indent=2)+"\n",encoding="utf-8")
        return split_manifest
    finally:
        if tmp: tmp.cleanup()

def quality_gate(metrics:dict[str,Any],thresholds:Thresholds)->dict[str,Any]:
    overall=metrics.get("overall") or {}; per_class=metrics.get("perClass") or []
    failures=[]
    checks=[("precision",thresholds.precision),("recall",thresholds.recall),("map50",thresholds.map50),("map5095",thresholds.map5095)]
    for key,minimum in checks:
        value=float(overall.get(key,0))
        if value<minimum: failures.append(f"overall {key} {value:.4f} < {minimum:.4f}")
    for item in per_class:
        name=str(item.get("name","class"))
        for key,minimum in [("precision",thresholds.per_class_precision),("recall",thresholds.per_class_recall),("map50",thresholds.per_class_map50)]:
            value=float(item.get(key,0))
            if value<minimum: failures.append(f"{name} {key} {value:.4f} < {minimum:.4f}")
    return {"passed":not failures,"failures":failures,"thresholds":thresholds.__dict__}

def extract_ultralytics_metrics(metrics:Any,names:dict[int,str]|list[str])->dict[str,Any]:
    names_map={int(k):str(v) for k,v in (names.items() if isinstance(names,dict) else enumerate(names))}
    rd=getattr(metrics,"results_dict",{}) or {}
    overall={
        "precision":float(rd.get("metrics/precision(B)",0)),
        "recall":float(rd.get("metrics/recall(B)",0)),
        "map50":float(rd.get("metrics/mAP50(B)",0)),
        "map5095":float(rd.get("metrics/mAP50-95(B)",0)),
    }
    box=getattr(metrics,"box",None); per=[]
    if box is not None:
        p=list(getattr(box,"p",[]) or []);r=list(getattr(box,"r",[]) or []);ap50=list(getattr(box,"ap50",[]) or []);ap=list(getattr(box,"ap",[]) or [])
        for i,name in names_map.items():
            per.append({"index":i,"name":name,"precision":float(p[i]) if i<len(p) else 0.0,"recall":float(r[i]) if i<len(r) else 0.0,"map50":float(ap50[i]) if i<len(ap50) else 0.0,"map5095":float(ap[i]) if i<len(ap) else 0.0})
    return {"schema":"gelato.vision_model_metrics.v1","overall":overall,"perClass":per}

def verify_onnx(path:Path,input_size:int)->dict[str,Any]:
    try:
        import numpy as np
        import onnxruntime as ort
    except ImportError as e: raise PipelineError("onnxruntime and numpy are required for ONNX verification.") from e
    session=ort.InferenceSession(str(path),providers=["CPUExecutionProvider"])
    inputs=session.get_inputs(); outputs=session.get_outputs()
    if len(inputs)!=1 or len(outputs)<1: raise PipelineError("Browser detector ONNX must expose one input and at least one output.")
    inp=inputs[0]
    shape=list(inp.shape)
    layout="nchw"
    if len(shape)!=4: raise PipelineError("Browser detector ONNX input must be rank 4.")
    if shape[1]==3: tensor=np.zeros((1,3,input_size,input_size),dtype=np.float32)
    elif shape[-1]==3: layout="nhwc";tensor=np.zeros((1,input_size,input_size,3),dtype=np.float32)
    else: raise PipelineError("ONNX input must use three RGB channels.")
    result=session.run(None,{inp.name:tensor})
    if not result: raise PipelineError("ONNX smoke inference returned no outputs.")
    return {"inputName":inp.name,"outputName":outputs[0].name,"inputLayout":layout,"outputShape":list(result[0].shape),"inputShape":shape}

def release_package(onnx_path:Path,labels:list[str],metrics:dict[str,Any],out:Path,detector_name:str,model_name:str,model_version:str,input_size:int,verification:dict[str,Any],thresholds:Thresholds)->dict[str,Any]:
    gate=quality_gate(metrics,thresholds)
    if not gate["passed"]: raise PipelineError("Release quality gate failed: "+"; ".join(gate["failures"]))
    out.mkdir(parents=True,exist_ok=True)
    artifact=out/f"{model_name}-{model_version}.onnx";shutil.copy2(onnx_path,artifact)
    sha=sha256_file(artifact);size=artifact.stat().st_size
    package={
      "schema":SCHEMA_RELEASE,"detectorName":detector_name,"modelName":model_name,"modelVersion":model_version,
      "runtimeType":"onnx","artifactFile":artifact.name,"artifactBytes":size,"artifactSha256":sha,
      "qualityGate":gate,"metrics":metrics,
      "metadata":{"browserInference":{"schema":SCHEMA_BROWSER,"decoder":"yolo_v8","input":{"name":verification["inputName"],"width":input_size,"height":input_size,"layout":verification["inputLayout"]},"output":{"name":verification["outputName"],"layout":"channels_first","boxScale":"pixels"},"labels":labels,"nmsIou":0.45,"maxDetections":25}}
    }
    (out/"gelato-package.json").write_text(json.dumps(package,indent=2)+"\n",encoding="utf-8")
    release_zip=out/f"{model_name}-{model_version}-gelato-release.zip"
    with zipfile.ZipFile(release_zip,"w",compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        z.write(artifact,artifact.name);z.write(out/"gelato-package.json","gelato-package.json")
        z.writestr("metrics.json",json.dumps(metrics,indent=2)+"\n")
    package["releaseZip"]=release_zip.name;package["releaseZipSha256"]=sha256_file(release_zip)
    (out/"release-summary.json").write_text(json.dumps(package,indent=2)+"\n",encoding="utf-8")
    return package

def train_release(args:argparse.Namespace)->dict[str,Any]:
    workspace=Path(args.output).resolve()/"workspace";prepare_workspace(Path(args.dataset),workspace,args.seed,args.train_ratio,args.val_ratio,args.test_ratio)
    try:
        from ultralytics import YOLO
    except ImportError as e: raise PipelineError("Install tools/vision_training/requirements-train.txt before training.") from e
    model=YOLO(args.base_model)
    model.train(data=str(workspace/"data.yaml"),epochs=args.epochs,imgsz=args.imgsz,batch=args.batch,project=str(Path(args.output)/"runs"),name="train",seed=args.seed,deterministic=True,patience=args.patience)
    best_path=Path(str(model.trainer.best))
    if not best_path.is_file(): raise PipelineError("Training completed without a best checkpoint.")
    best=YOLO(str(best_path))
    val=best.val(data=str(workspace/"data.yaml"),split="test",imgsz=args.imgsz,project=str(Path(args.output)/"runs"),name="test")
    metrics=extract_ultralytics_metrics(val,best.names)
    thresholds=Thresholds(args.min_precision,args.min_recall,args.min_map50,args.min_map5095,args.min_class_precision,args.min_class_recall,args.min_class_map50)
    gate=quality_gate(metrics,thresholds)
    metrics["qualityGate"]=gate
    metrics_path=Path(args.output)/"metrics.json";metrics_path.parent.mkdir(parents=True,exist_ok=True);metrics_path.write_text(json.dumps(metrics,indent=2)+"\n",encoding="utf-8")
    if not gate["passed"]: raise PipelineError("Training finished but release gate failed: "+"; ".join(gate["failures"]))
    exported=Path(str(best.export(format="onnx",imgsz=args.imgsz,simplify=True,dynamic=False,opset=17)))
    verification=verify_onnx(exported,args.imgsz)
    labels=[str(best.names[i]) for i in sorted(best.names)]
    return release_package(exported,labels,metrics,Path(args.output)/"release",args.detector_name,args.model_name,args.model_version,args.imgsz,verification,thresholds)

def parser()->argparse.ArgumentParser:
    p=argparse.ArgumentParser(description="Gelato vision training/evaluation/ONNX release pipeline")
    sp=p.add_subparsers(dest="command",required=True)
    v=sp.add_parser("validate");v.add_argument("dataset")
    prep=sp.add_parser("prepare");prep.add_argument("dataset");prep.add_argument("output");prep.add_argument("--seed",type=int,default=74);prep.add_argument("--train-ratio",type=float,default=.70);prep.add_argument("--val-ratio",type=float,default=.15);prep.add_argument("--test-ratio",type=float,default=.15)
    gate=sp.add_parser("gate");gate.add_argument("metrics"); add_threshold_args(gate)
    tr=sp.add_parser("train-release");tr.add_argument("dataset");tr.add_argument("output");tr.add_argument("--base-model",default="yolo11n.pt");tr.add_argument("--detector-name",default="food-detector");tr.add_argument("--model-name",required=True);tr.add_argument("--model-version",required=True);tr.add_argument("--epochs",type=int,default=100);tr.add_argument("--imgsz",type=int,default=640);tr.add_argument("--batch",type=int,default=16);tr.add_argument("--patience",type=int,default=20);tr.add_argument("--seed",type=int,default=74);tr.add_argument("--train-ratio",type=float,default=.70);tr.add_argument("--val-ratio",type=float,default=.15);tr.add_argument("--test-ratio",type=float,default=.15);add_threshold_args(tr)
    return p

def add_threshold_args(p:argparse.ArgumentParser)->None:
    p.add_argument("--min-precision",type=float,default=.70);p.add_argument("--min-recall",type=float,default=.70);p.add_argument("--min-map50",type=float,default=.75);p.add_argument("--min-map5095",type=float,default=.45);p.add_argument("--min-class-precision",type=float,default=.60);p.add_argument("--min-class-recall",type=float,default=.60);p.add_argument("--min-class-map50",type=float,default=.65)

def main(argv:list[str]|None=None)->int:
    args=parser().parse_args(argv)
    try:
        if args.command=="validate":
            root,tmp=materialize_dataset(Path(args.dataset))
            try:
                info=inspect_dataset(root);print(json.dumps({k:v for k,v in info.items() if k not in {"images","root"}},indent=2))
            finally:
                if tmp: tmp.cleanup()
        elif args.command=="prepare": print(json.dumps(prepare_workspace(Path(args.dataset),Path(args.output),args.seed,args.train_ratio,args.val_ratio,args.test_ratio),indent=2))
        elif args.command=="gate":
            m=json.loads(Path(args.metrics).read_text("utf-8"));t=Thresholds(args.min_precision,args.min_recall,args.min_map50,args.min_map5095,args.min_class_precision,args.min_class_recall,args.min_class_map50);result=quality_gate(m,t);print(json.dumps(result,indent=2));return 0 if result["passed"] else 3
        elif args.command=="train-release": print(json.dumps(train_release(args),indent=2))
        return 0
    except (PipelineError,ValueError,json.JSONDecodeError) as e:
        print(f"ERROR: {e}",file=sys.stderr);return 2

if __name__=="__main__": raise SystemExit(main())
