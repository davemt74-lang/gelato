import json,tempfile,unittest,zipfile,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from tools.vision_training.pipeline import ComparisonPolicy,PipelineError,Thresholds,compare_models,golden_test_fingerprint,inspect_dataset,prepare_workspace,quality_gate,release_package,safe_extract_zip

class VisionTrainingPipelineTests(unittest.TestCase):
    def make_dataset(self,root:Path,count=10):
        (root/'images/train').mkdir(parents=True);(root/'labels/train').mkdir(parents=True)
        classes=[{'index':0,'name':'Bacon','slug':'bacon'},{'index':1,'name':'Lettuce','slug':'lettuce'}]
        for i in range(count):
            (root/f'images/train/frame-{i:06d}.jpg').write_bytes(b'jpeg'+bytes([i]))
            (root/f'labels/train/frame-{i:06d}.txt').write_text(f'{i%2} 0.500000 0.500000 0.200000 0.200000\n')
        (root/'gelato-manifest.json').write_text(json.dumps({'schema':'gelato.vision_training_dataset.v1','format':'yolo_detection','sampleCount':count,'classes':classes}))
    def test_dataset_and_split_are_deterministic(self):
        with tempfile.TemporaryDirectory() as td:
            src=Path(td)/'src';self.make_dataset(src)
            info=inspect_dataset(src);self.assertEqual(info['boxCounts'],{'Bacon':5,'Lettuce':5})
            a=prepare_workspace(src,Path(td)/'a',seed=74);b=prepare_workspace(src,Path(td)/'b',seed=74)
            self.assertEqual(a['counts'],b['counts']);self.assertEqual(a['counts'],{'train':6,'val':2,'test':2})
            self.assertEqual(sorted(p.name for p in (Path(td)/'a/images/train').iterdir()),sorted(p.name for p in (Path(td)/'b/images/train').iterdir()))
    def test_bad_yolo_geometry_fails(self):
        with tempfile.TemporaryDirectory() as td:
            src=Path(td);self.make_dataset(src,3);(src/'labels/train/frame-000000.txt').write_text('0 0.95 0.5 0.2 0.2\n')
            with self.assertRaises(PipelineError):inspect_dataset(src)
    def test_zip_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            z=Path(td)/'bad.zip'
            with zipfile.ZipFile(z,'w') as out:out.writestr('../escape.txt','x')
            with self.assertRaises(PipelineError):safe_extract_zip(z,Path(td)/'out')
    def test_quality_gate(self):
        good={'overall':{'precision':.9,'recall':.9,'map50':.9,'map5095':.7},'perClass':[{'name':'Bacon','precision':.8,'recall':.8,'map50':.8}]}
        self.assertTrue(quality_gate(good,Thresholds())['passed'])
        bad=json.loads(json.dumps(good));bad['perClass'][0]['recall']=.2
        self.assertFalse(quality_gate(bad,Thresholds())['passed'])
    def test_golden_fingerprint_and_challenger_gate(self):
        with tempfile.TemporaryDirectory() as td:
            src=Path(td)/'src';self.make_dataset(src,10);work=Path(td)/'work';prepare_workspace(src,work,seed=74)
            fingerprint=golden_test_fingerprint(work);self.assertEqual(len(fingerprint),64)
            champion={'goldenTestHash':fingerprint,'overall':{'precision':.80,'recall':.80,'map50':.80,'map5095':.55},'perClass':[{'name':'Bacon','precision':.75,'recall':.75,'map50':.75,'map5095':.5},{'name':'Lettuce','precision':.75,'recall':.75,'map50':.75,'map5095':.5}]}
            challenger={'goldenTestHash':fingerprint,'overall':{'precision':.81,'recall':.81,'map50':.82,'map5095':.56},'perClass':[{'name':'Bacon','precision':.76,'recall':.76,'map50':.77,'map5095':.51},{'name':'Lettuce','precision':.75,'recall':.76,'map50':.76,'map5095':.51}]}
            result=compare_models(champion,challenger,ComparisonPolicy())
            self.assertTrue(result['eligible']);self.assertFalse(result['override']);self.assertTrue(result['summary']['demonstratedGain'])
            regressed=json.loads(json.dumps(challenger));regressed['overall']['precision']=.70
            blocked=compare_models(champion,regressed,ComparisonPolicy());self.assertFalse(blocked['eligible']);self.assertTrue(blocked['regressions'])
            overridden=compare_models(champion,regressed,ComparisonPolicy(),'Recall gain accepted for shadow canary only.')
            self.assertTrue(overridden['eligible']);self.assertTrue(overridden['override'])
            mismatch=json.loads(json.dumps(challenger));mismatch['goldenTestHash']='f'*64
            with self.assertRaises(PipelineError):compare_models(champion,mismatch,ComparisonPolicy())

    def test_release_package_requires_gate_and_emits_metadata(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);onnx=root/'model.onnx';onnx.write_bytes(b'fake-onnx-for-packaging-contract')
            metrics={'overall':{'precision':.9,'recall':.9,'map50':.9,'map5095':.7},'perClass':[{'name':'Bacon','precision':.8,'recall':.8,'map50':.8,'map5095':.6}]}
            out=root/'release';pkg=release_package(onnx,['Bacon'],metrics,out,'food-detector','kitchen-food','1.0.0',640,{'inputName':'images','outputName':'output0','inputLayout':'nchw'},Thresholds())
            self.assertEqual(pkg['metadata']['browserInference']['schema'],'gelato.browser_onnx_detector.v1')
            self.assertEqual(len(pkg['artifactSha256']),64);self.assertTrue((out/pkg['releaseZip']).is_file())

if __name__=='__main__':unittest.main()
