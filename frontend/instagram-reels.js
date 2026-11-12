// Official Instagram embeds load only near the reviews, so the page stays fast.
// If Instagram is blocked, each poster simply opens the reel in a new tab.
let loader;
function loadEmbedScript(){
  if(window.instgrm?.Embeds)return Promise.resolve();
  loader||=new Promise((resolve,reject)=>{
    const script=document.createElement('script');
    script.src='https://www.instagram.com/embed.js';script.async=true;
    const timer=setTimeout(()=>reject(new Error('Instagram timed out')),12000);
    script.onload=()=>{clearTimeout(timer);window.instgrm?.Embeds?resolve():reject(new Error('Instagram unavailable'));};
    script.onerror=()=>{clearTimeout(timer);reject(new Error('Instagram blocked'));};
    document.head.append(script);
  }).catch(error=>{loader=null;throw error;});
  return loader;
}

// Arrows page by one card; touch swipes natively; a mouse can drag the row.
function initReelsSlider(section){
  const track=section.querySelector('[data-reels-track]');
  const nav=section.querySelector('[data-reels-nav]');
  if(!track||!nav)return;
  const prev=nav.querySelector('[data-reels-prev]');
  const next=nav.querySelector('[data-reels-next]');
  const step=()=>{const card=track.querySelector('.reel-card');return card?card.getBoundingClientRect().width+parseFloat(getComputedStyle(track).columnGap||'0'):track.clientWidth;};
  const update=()=>{
    const max=track.scrollWidth-track.clientWidth;
    nav.hidden=max<=4;
    prev.disabled=track.scrollLeft<=4;
    next.disabled=track.scrollLeft>=max-4;
  };
  let target=null;
  const go=direction=>{
    const card=step();
    const from=target??track.scrollLeft;
    target=Math.max(0,Math.min(track.scrollWidth-track.clientWidth,(Math.round(from/card)+direction)*card));
    track.scrollTo({left:target,behavior:'smooth'});
    clearTimeout(go.timer);go.timer=setTimeout(()=>{target=null;},450);
  };
  prev.addEventListener('click',()=>go(-1));
  next.addEventListener('click',()=>go(1));
  track.addEventListener('scroll',()=>requestAnimationFrame(update),{passive:true});
  window.addEventListener('resize',update,{passive:true});
  track.addEventListener('keydown',event=>{
    if(event.key==='ArrowRight'){event.preventDefault();next.click();}
    if(event.key==='ArrowLeft'){event.preventDefault();prev.click();}
  });
  let drag=null;
  let suppressClickUntil=0;
  track.addEventListener('click',event=>{if(performance.now()<suppressClickUntil){event.preventDefault();event.stopPropagation();}},{capture:true});
  track.addEventListener('pointerdown',event=>{
    if(event.pointerType!=='mouse'||event.button!==0)return;
    drag={x:event.clientX,left:track.scrollLeft,moved:false,id:event.pointerId};
  });
  track.addEventListener('pointermove',event=>{
    if(!drag||event.pointerId!==drag.id)return;
    const dx=event.clientX-drag.x;
    if(!drag.moved&&Math.abs(dx)<6)return;
    if(!drag.moved){drag.moved=true;track.classList.add('is-dragging');track.setPointerCapture(drag.id);}
    track.scrollLeft=drag.left-dx;
  });
  const end=()=>{
    if(!drag)return;
    if(drag.moved){
      track.classList.remove('is-dragging');
      const card=step();
      track.scrollTo({left:Math.round(track.scrollLeft/card)*card,behavior:'smooth'});
      suppressClickUntil=performance.now()+300;
    }
    drag=null;
  };
  track.addEventListener('pointerup',end);
  track.addEventListener('pointercancel',end);
  update();
}

export function initInstagramReels(root=document){
  const section=root.querySelector('[data-reels]');
  if(!section)return;
  initReelsSlider(section);
  const frames=[...section.querySelectorAll('[data-reel]')];
  let started=false;
  const fallback=()=>frames.forEach(frame=>{
    const poster=frame.querySelector('[data-reel-play]');
    poster.onclick=()=>window.open(frame.dataset.reel,'_blank','noopener');
    frame.dataset.state='link';
  });
  const start=()=>{
    if(started)return;started=true;
    section.dataset.state='loading';
    loadEmbedScript().then(()=>{
      frames.forEach(frame=>{frame.querySelector('blockquote').hidden=false;frame.dataset.state='embed';});
      window.instgrm.Embeds.process();
      section.dataset.state='ready';
    }).catch(()=>{section.dataset.state='fallback';fallback();});
  };
  frames.forEach(frame=>frame.querySelector('[data-reel-play]').addEventListener('click',start));
  if(typeof window.IntersectionObserver==='function'){
    const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){observer.disconnect();start();}},{rootMargin:'400px 0px'});
    observer.observe(section);
  }
}
