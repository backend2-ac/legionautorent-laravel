export function listenMedia(query,callback){
  if(typeof query.addEventListener==='function'){
    query.addEventListener('change',callback);
    return ()=>query.removeEventListener('change',callback);
  }
  if(typeof query.addListener==='function'){
    query.addListener(callback);
    return ()=>query.removeListener(callback);
  }
  return ()=>{};
}

export function supportsWebp(){
  return new Promise(resolve=>{
    const image=new Image();let done=false;
    const finish=value=>{if(done)return;done=true;clearTimeout(timer);resolve(value);};
    const timer=setTimeout(()=>finish(false),1000);
    image.onload=()=>finish(image.naturalWidth===1);
    image.onerror=()=>finish(false);
    image.src='data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';
  });
}
