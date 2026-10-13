// Each small pack covers the whole orbit. A slow or missing HD pack must not
// freeze the playhead; full-size frames replace this temporary preview.
export function createHeroPreview(source,{decode,changed,settled}){
  const encoded=new Map(),images=new Map(),decoding=new Set(),controllers=new Set();
  let generation=0,started=false,indices=[],current=0,active=0,bytesPerSecond=0;
  function stop(){
    generation++;controllers.forEach(controller=>controller.abort());controllers.clear();
    images.forEach(image=>image.close?.());images.clear();encoded.clear();decoding.clear();
    indices=[];started=false;active=0;bytesPerSecond=0;
  }
  function request(index){
    current=index;
    const nearest=[...indices].sort((a,b)=>Math.abs(a-index)-Math.abs(b-index)).slice(0,4);
    for(const candidate of nearest){
      if(active>=2)break;
      if(images.has(candidate)||decoding.has(candidate))continue;
      const version=generation;active++;decoding.add(candidate);
      decode(encoded.get(candidate)).then(image=>{
        if(version!==generation){image.close?.();return;}
        images.set(candidate,image);
        while(images.size>16){
          const oldest=[...images.keys()].sort((a,b)=>Math.abs(b-current)-Math.abs(a-current))[0];
          images.get(oldest).close?.();images.delete(oldest);
        }
        changed();
      }).catch(()=>{}).finally(()=>{
        if(version===generation){active--;decoding.delete(candidate);}
      });
    }
  }
  function nearest(index,direction,drawn){
    const candidates=[...images.keys()].filter(key=>direction>0?key>=drawn:key<=drawn);
    candidates.sort((a,b)=>Math.abs(a-index)-Math.abs(b-index));
    const best=candidates[0];
    return best===undefined?null:{index:best,image:images.get(best),quality:'preview'};
  }
  async function start(){
    if(started)return;started=true;
    const version=generation;
    try{
      for(const pack of source?.packs||[]){
        // A failed preview is optional: continue with the other whole-orbit
        // packs and the normal-resolution stream instead of locking the page.
        let complete=false;
        for(let attempt=0;attempt<2&&!complete;attempt++){
          if(version!==generation)return;
          const controller=typeof AbortController==='function'?new AbortController():{abort(){},signal:undefined};
          controllers.add(controller);let timer;const began=performance.now();
          try{
            const buffer=await Promise.race([
              fetch(pack.url,{signal:controller.signal,priority:'low',cache:'force-cache'}).then(response=>{
                if(!response.ok)throw new Error('Preview response '+response.status);
                return response.arrayBuffer();
              }),
              new Promise((_,reject)=>{timer=setTimeout(()=>{controller.abort();reject(new Error('Preview timeout'));},5000);})
            ]);
            if(version!==generation)return;
            const rate=buffer.byteLength*1000/Math.max(1,performance.now()-began);
            bytesPerSecond=bytesPerSecond?Math.min(bytesPerSecond,rate):rate;
            const view=new DataView(buffer),frames=[];let offset=0;
            for(const index of pack.indices){
              if(offset+4>buffer.byteLength)throw new Error('Incomplete preview');
              const size=view.getUint32(offset,true);offset+=4;
              if(!size||offset+size>buffer.byteLength)throw new Error('Invalid preview');
              frames.push([index,new Blob([new Uint8Array(buffer,offset,size)],{type:source.mime})]);offset+=size;
            }
            if(offset!==buffer.byteLength)throw new Error('Preview length mismatch');
            frames.forEach(([index,blob])=>encoded.set(index,blob));
            indices=[...encoded.keys()];complete=true;request(current);changed();
          }catch{
            if(version!==generation)return;
            // HD has its own slots; a blocked preview must not block it too.
            settled();
          }finally{clearTimeout(timer);controllers.delete(controller);}
        }
      }
    }finally{if(version===generation)settled();}
  }
  return {start,stop,request,nearest,hasFrames:()=>encoded.size>0,timeoutFor:bytes=>bytesPerSecond?Math.min(20000,Math.max(4000,bytes*4000/bytesPerSecond+1000)):4000};
}