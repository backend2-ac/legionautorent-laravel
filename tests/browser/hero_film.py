from pathlib import Path
import json,os,time
from playwright.sync_api import sync_playwright,expect
out=Path(os.getenv('HERO_REPORT','reports/hero-film'));out.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path=os.getenv('BROWSER_EXECUTABLE') or None)
 records=[]
 for width in [360,768,1440]:
  page=browser.new_page(viewport={'width':width,'height':900 if width==1440 else 800},device_scale_factor=2 if width==360 else 1)
  errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  frame_requests=[];page.on('request',lambda r:frame_requests.append(r.url) if '/hero-film/' in r.url and ('/mobile/' in r.url or '/desktop/' in r.url) else None)
  page.add_init_script("window.longTasks=[];new PerformanceObserver(l=>window.longTasks.push(...l.getEntries().map(e=>({start:e.startTime,duration:e.duration})))).observe({type:'longtask',buffered:true});")
  page.goto(os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')+'/',wait_until='networkidle')
  root=page.locator('[data-hero-film]')
  assert page.locator('.hero-film__picture img').evaluate('i=>i.complete&&i.naturalWidth>0')
  assert not frame_requests,frame_requests
  page.screenshot(path=str(out/f'chrome-{width}-start.png'))
  assert not page.evaluate('document.documentElement.scrollWidth>innerWidth')
  assert page.locator('.city-menu').is_visible()
  before=page.locator('.hero-film__copy h1').bounding_box()
  count=page.evaluate("JSON.parse(document.querySelector('#hero-film-manifest').textContent).count")
  phases=[]
  for part in [.16,.31,.46,.61,.76,.93,1,0]:
   root.evaluate('(r,p)=>scrollTo({top:r.offsetTop+(r.offsetHeight-r.querySelector(".hero-film__stage").offsetHeight)*p,behavior:"instant"})',part)
   page.wait_for_timeout(1300)
   phases.append({'progress':part,'frame':root.get_attribute('data-frame'),'caption':page.locator('[data-film-caption].is-current').inner_text()})
   box=page.locator('.hero-film__copy h1').bounding_box();assert abs(before['y']-box['y'])<2,(width,part,before,box)
   if part==.61:page.screenshot(path=str(out/f'chrome-{width}-interior.png'))
  expect(root).to_have_attribute('data-frame','0')
  assert phases[-2]['frame']==str(count-1),phases
  page.evaluate('window.scrollTestStart=performance.now();window.longTasks=[]')
  samples=page.evaluate('''async()=>{const root=document.querySelector('[data-hero-film]');const distance=root.offsetHeight-root.querySelector('.hero-film__stage').offsetHeight;const samples=[];let start;await new Promise(resolve=>{function step(t){start??=t;const p=Math.min(1,(t-start)/6000);scrollTo({top:root.offsetTop+distance*p,behavior:'instant'});samples.push(Number(root.dataset.frame||0));if(p<1)requestAnimationFrame(step);else resolve();}requestAnimationFrame(step);});return samples;}''')
  page.wait_for_timeout(1200)
  tasks=page.evaluate('window.longTasks')
  assert not errors,errors
  records.append({'browser':'Chrome','width':width,'phases':phases,'initial_frame_requests':0,'scroll_frame_changes':len(set(samples)),'scroll_long_tasks':tasks,'errors':errors})
  page.close()
 for mode in ['reduced-motion','no-js','no-bitmap','failed-frames']:
  context=browser.new_context(viewport={'width':360,'height':800},java_script_enabled=mode!='no-js',reduced_motion='reduce' if mode=='reduced-motion' else 'no-preference')
  page=context.new_page()
  if mode=='no-bitmap':page.add_init_script('window.createImageBitmap=undefined')
  if mode=='failed-frames':page.route('**/hero-film/**/mobile/*.webp',lambda r:r.abort())
  page.goto(os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')+'/',wait_until='networkidle')
  assert page.locator('.hero-film__picture img').evaluate('i=>i.complete&&i.naturalWidth>0')
  assert page.locator('.hero-film__book').is_visible()
  if mode in ['reduced-motion','no-js']:assert page.locator('[data-hero-film]').bounding_box()['height']==800
  if mode in ['no-bitmap','failed-frames']:
   page.evaluate('scrollTo(0,20)');page.wait_for_timeout(1000)
  if mode=='no-bitmap':expect(page.locator('[data-hero-film]')).to_have_attribute('data-rendered','true')
  if mode=='failed-frames':assert page.locator('[data-hero-film]').get_attribute('data-rendered')!='true'
  page.screenshot(path=str(out/f'{mode}.png'));records.append({'mode':mode,'passed':True});context.close()
 browser.close()
(out/'browser-checks.json').write_text(json.dumps(records,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(records,ensure_ascii=False))
