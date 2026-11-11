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

export function initInstagramReels(root=document){
  const section=root.querySelector('[data-reels]');
  if(!section)return;
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
