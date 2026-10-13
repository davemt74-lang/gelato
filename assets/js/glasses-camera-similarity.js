(() => {
  'use strict';
  if(window.GelatoCameraSimilarity)return;
  function normalizeHash(value){const s=String(value||'').toLowerCase();return /^[0-9a-f]{16}$/.test(s)?s:null;}
  function bitCount(a,b){
    const x=normalizeHash(a),y=normalizeHash(b);if(!x||!y)return null;
    let n=0;
    for(let i=0;i<16;i++){
      let xor=parseInt(x[i],16)^parseInt(y[i],16);
      while(xor){n+=xor&1;xor>>=1;}
    }
    return n;
  }
  function similarity(hash,reference) {
    const distance=bitCount(hash,reference);
    if(distance===null)return null;
    return Math.round(100*(1-distance/64));
  }
  function compare(hash,refs,{slot=null}={}){
    if(!normalizeHash(hash))return [];
    return (Array.isArray(refs)?refs:[]).filter(r=>r?.isReference&&normalizeHash(r.hash))
      .map(r=>({...r,similarityScore:similarity(hash,r.hash),sameCamera:r.slot===slot}))
      .sort((a,b)=>(Number(b.sameCamera)-Number(a.sameCamera))||(b.similarityScore-a.similarityScore))
      .slice(0,5);
  }
  async function fingerprint(blob){
    if(!blob||typeof createImageBitmap!=='function')throw new Error('Image decoding is unavailable in this browser.');
    const bitmap=await createImageBitmap(blob);
    try{
      const canvas=document.createElement('canvas');canvas.width=9;canvas.height=8;
      const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(bitmap,0,0,9,8);
      const pix=ctx.getImageData(0,0,9,8).data;
      const values=[];
      for(let y=0;y<8;y++)for(let x=0;x<9;x++){
        const i=(y*9+x)*4;
        values.push(.2126*pix[i]+.7152*pix[i+1]+.0722*pix[i+2]);
      }
      let hex='';
      for(let i=0;i<64;i+=4){
        let n=0;
        for(let b=0;b<4;b++){
          const k=i+b,row=Math.floor(k/8),col=k%8;
          if(values[row*9+col]>values[row*9+col+1])n|=1<<(3-b);
        }
        hex+=n.toString(16);
      }
      const sample=document.createElement('canvas');sample.width=64;sample.height=64;
      const dctx=sample.getContext('2d',{willReadFrequently:true});dctx.drawImage(bitmap,0,0,64,64);
      const pixels=dctx.getImageData(0,0,64,64).data;
      const lum=new Float32Array(4096);
      let sum=0,sum2=0,edge=0,edgeN=0;
      for(let i=0;i<4096;i++){
        const p=i*4,v=(pixels[p]*.2126+pixels[p+1]*.7152+pixels[p+2]*.0722)/255;
        lum[i]=v;sum+=v;sum2+=v*v;
      }
      for(let y=1;y<63;y++)for(let x=1;x<63;x++){
        const i=y*64+x;
        edge+=Math.abs(4*lum[i]-lum[i-1]-lum[i+1]-lum[i-64]-lum[i+64]);edgeN++;
      }
      const brightness=sum/4096;
      const contrast=Math.sqrt(Math.max(0,sum2/4096-brightness*brightness));
      const sharpness=edgeN?edge/edgeN:0;
      // These are descriptive capture quality heuristics, not ML confidence.
      const exposure=Math.max(0,1-Math.abs(brightness-.52)/.52);
      const contrastPart=Math.min(1,contrast/.17);
      const sharpnessPart=Math.min(1,sharpness/.11);
      const qualityScore=Math.max(0,Math.min(100,Math.round(100*(exposure*.3+contrastPart*.3+sharpnessPart*.4))));
      return {hash:hex,qualityScore,brightnessMean:Number(brightness.toFixed(5)),
        contrastMean:Number(contrast.toFixed(5)),blurScore:Number(sharpness.toFixed(5)),
        width:bitmap.width,height:bitmap.height};
    }finally{bitmap.close?.();}
  }
  window.GelatoCameraSimilarity={normalizeHash,bitCount,similarity,compare,fingerprint};
})();