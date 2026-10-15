export function initHeroFilm(root) {
  const manifest=JSON.parse(root.querySelector('#hero-film-manifest').textContent);
  if(!manifest || !('IntersectionObserver' in window) || !window.fetch)return;
  const reduced=matchMedia('(prefers-reduced-motion: reduce)');
  const stage=root.querySelector('.hero-film__stage'),canvas=root.querySelector('.hero-film__canvas');
  const background=root.querySelector('.hero-film__background');
  const context=canvas.getContext('2d'),back=background.getContext('2d',{alpha:false});
  if(!context || !back){root.dataset.motion='false';return;}
  // Variant is selected once: resize never downloads a second sequence.
  const variant=manifest[matchMedia('(max-width:767px)').matches?'mobile':'desktop'];
  const dots=[...root.querySelectorAll('.hero-film__dots span')];
  const blobs=new Map(),decoded=new Map(),fetching=new Set(),decoding=new Set(),failed=new Map();
  const radius=5,limit=14;
  let loaded=document.readyState==='complete',visible=false,raf=0,current=0,target=0,painted=-1,chapter=-1;
  let top=0,distance=1,dirty=true,disposed=false,generation=0,started=false;
  const enabled=()=>!disposed&&!reduced.matches&&visible&&!document.hidden;
  const chapterAt=index=>Math.max(0,manifest.chapters.findIndex(item=>index>=item.start&&index<=item.end));
  const url=index=>variant.base+String(index).padStart(4,'0')+'.webp';
  const release=image=>{if(image&&typeof image.close==='function')image.close();};
  const trim=()=>{
    const center=Math.round(target);
    [...decoded.keys()].sort((a,b)=>Math.abs(b-center)-Math.abs(a-center)).forEach(index=>{
      if(index!==painted&&(Math.abs(index-center)>radius+2 || decoded.size>limit)){release(decoded.get(index));decoded.delete(index);}
    });
  };
  const wake=()=>{if(enabled()&&!raf)raf=requestAnimationFrame(tick);};
  const decode=async index=>{
    if(!enabled()||!loaded||decoding.has(index)||decoded.has(index)||!blobs.has(index)||decoding.size>=2)return;
    decoding.add(index);const token=generation;
    try{
      let bitmap;
      if(typeof createImageBitmap==='function')bitmap=await createImageBitmap(blobs.get(index));
      else {
        const src=URL.createObjectURL(blobs.get(index));
        try{bitmap=await new Promise((resolve,reject)=>{const image=new Image();image.onload=()=>resolve(image);image.onerror=reject;image.src=src;});}
        finally{URL.revokeObjectURL(src);}
      }
      if(token!==generation||!enabled()||Math.abs(index-target)>radius+2)release(bitmap);
      else {decoded.set(index,bitmap);trim();wake();}
    }catch{failed.set(index,Date.now());blobs.delete(index);}
    finally{decoding.delete(index);if(enabled())pump();}
  };
  const fetchFrame=async index=>{
    fetching.add(index);
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const response=await fetch(url(index),{cache:'force-cache',credentials:'same-origin',signal:controller.signal,priority:'low'});
      if(!response.ok)throw Error('Frame response');
      blobs.set(index,await response.blob());failed.delete(index);
    }catch{failed.set(index,Date.now());}
    finally{clearTimeout(timeout);fetching.delete(index);if(enabled())pump();}
  };
  function pump(){
    if(!enabled()||!loaded||!started)return;
    const center=Math.round(target),near=[];
    for(let offset=0;offset<=radius;offset++){
      for(const index of offset?[center+offset,center-offset]:[center])if(index>=0&&index<manifest.count)near.push(index);
    }
    trim();near.forEach(index=>{if(decoding.size<2)decode(index);});
    // Keep the poster until scrolling; fetch only the current and next chapter.
    const active=chapterAt(center);
    const queue=Array.from({length:manifest.count},(_,i)=>i).filter(i=>chapterAt(i)>=active&&chapterAt(i)<=active+1).sort((a,b)=>Math.abs(chapterAt(a)-active)-Math.abs(chapterAt(b)-active)||Math.abs(a-center)-Math.abs(b-center));
    for(const index of [...near,...queue]){
      if(fetching.size>=3)break;
      if(!blobs.has(index)&&!fetching.has(index)&&(!failed.has(index)||Date.now()-failed.get(index)>15000))fetchFrame(index);
    }
  }
  const measure=()=>{
    top=root.getBoundingClientRect().top+scrollY;
    distance=Math.max(1,root.offsetHeight-stage.offsetHeight);
    const bounds=canvas.getBoundingClientRect(),dpr=Math.min(devicePixelRatio||1,2);
    const width=Math.round(bounds.width*dpr),height=Math.round(bounds.height*dpr);
    if(canvas.width!==width||canvas.height!==height){canvas.width=width;canvas.height=height;dirty=true;}
    update();
  };
  function update(){
    const progress=Math.min(1,Math.max(0,(scrollY-top)/distance));
    // Give each chapter one viewport of scroll, independent of source duration.
    const phase=Math.min(manifest.chapters.length-0.000001,progress*manifest.chapters.length);
    const part=Math.floor(phase),scene=manifest.chapters[part];
    const end=manifest.chapters[part+1]?.start??(manifest.count-1);
    target=progress===1?manifest.count-1:scene.start+(end-scene.start)*(phase-part);
    if(scrollY>top+8){started=true;root.dataset.scrolled='true';}
    pump();wake();
  }
  function draw(image,index){
    const fit=innerWidth<768&&!root.classList.contains('hero-film--landscape')?Math.max:Math.min;
    const scale=fit(canvas.width/image.width,canvas.height/image.height);
    context.imageSmoothingQuality='high';
    context.clearRect(0,0,canvas.width,canvas.height);
    const width=image.width*scale,height=image.height*scale;
    context.drawImage(image,(canvas.width-width)/2,(canvas.height-height)/2,width,height);
    back.drawImage(image,0,0,background.width,background.height);
    root.dataset.rendered='true';root.dataset.frame=String(index);
    painted=index;dirty=false;
    const next=chapterAt(index);
    if(next!==chapter){chapter=next;dots.forEach((item,i)=>item.classList.toggle('is-current',i===chapter));}
  }
  function tick(){
    raf=0;if(!enabled())return;
    current+= (target-current)*.12;
    if(Math.abs(target-current)<.04)current=target;
    const index=Math.round(current);
    let ready=decoded.has(index)?index:null;
    if(ready===null){
      const nearest=[...decoded.keys()].sort((a,b)=>Math.abs(a-index)-Math.abs(b-index))[0];
      if(nearest!==undefined&&(painted<0||Math.abs(nearest-index)<Math.abs(painted-index)))ready=nearest;
    }
    if(ready!==null&&(ready!==painted||dirty))draw(decoded.get(ready),ready);
    else if(dirty&&decoded.has(painted))draw(decoded.get(painted),painted);
    if(current!==target)wake();
  }
  const pause=()=>{cancelAnimationFrame(raf);raf=0;};
  const motion=()=>{
    root.dataset.motion=String(!reduced.matches);
    if(reduced.matches){pause();generation++;decoded.forEach(release);decoded.clear();root.dataset.rendered='false';painted=-1;chapter=-1;dots.forEach((el,i)=>el.classList.toggle('is-current',i===0));}
    measure();
  };
  const observer=new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;if(visible){measure();pump();wake();}else pause();});
  observer.observe(root);
  const resize=typeof ResizeObserver==='function'?new ResizeObserver(measure):null;
  resize?.observe(stage);
  window.addEventListener('resize',measure,{passive:true});
  window.addEventListener('scroll',update,{passive:true});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)pause();else update();});
  if(reduced.addEventListener)reduced.addEventListener('change',motion);else reduced.addListener(motion);
  window.addEventListener('load',()=>{loaded=true;measure();pump();},{once:true});
  window.addEventListener('pagehide',event=>{pause();if(!event.persisted){disposed=true;generation++;decoded.forEach(release);decoded.clear();observer.disconnect();resize?.disconnect();}});
  window.addEventListener('pageshow',()=>{measure();pump();wake();});
  motion();
}
