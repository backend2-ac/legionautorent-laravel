from pathlib import Path
import json,os,time,re
from playwright.sync_api import sync_playwright,expect

base=os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')
out=Path(os.getenv('HERO_REPORT','output/hero-network'));out.mkdir(parents=True,exist_ok=True)
probe='''async ({duration,reverse})=>{
 const root=document.querySelector('[data-mercedes-preview]');
 const distance=root.querySelector('.mercedes-pin').offsetHeight-root.querySelector('.mercedes-stage').offsetHeight;
 const start=root.getBoundingClientRect().top+scrollY;let begin;const samples=[];
 await new Promise(resolve=>{function step(t){begin??=t;const p=Math.min(1,(t-begin)/duration);scrollTo({top:start+distance*(reverse?1-p:p),behavior:'instant'});samples.push({t:Math.round(t-begin),frame:Number(root.dataset.frame||0),quality:root.dataset.quality,progress:reverse?1-p:p});if(p<1)requestAnimationFrame(step);else resolve();}requestAnimationFrame(step);});
 return {samples,state:{...root.dataset}};
}'''
def summarize(data):
 s=data['samples'];changes=0;hold=s[0]['t'];prev=s[0]['frame'];maxhold=0;movinghold=0;first=None;lags=[]
 for row in s[1:]:
  if row['frame']!=prev:
   changes+=1;maxhold=max(maxhold,row['t']-hold)
   if first is not None:movinghold=max(movinghold,row['t']-hold)
   else:first=row['t']
   hold=row['t'];prev=row['frame']
  if row['t']>2000:lags.append(abs(row['progress']*595-row['frame']))
 maxhold=max(maxhold,s[-1]['t']-hold);movinghold=max(movinghold,s[-1]['t']-hold)
 return {'changes':changes,'first_change_ms':first,'max_hold_ms':maxhold,'max_moving_hold_ms':movinghold,'mean_frame_distance':round(sum(lags)/max(1,len(lags)),1),'end_frame':s[-1]['frame'],'state':data['state']}

profiles=[('desktop',1440,900,1,None,0,''),('mobile',390,844,3,None,0,''),('mobile-5mbps',390,844,3,625000,150,''),('desktop-5mbps',1440,900,1,625000,150,''),('mobile-512kbps',390,844,3,64000,300,''),('two-stalled-hd',1440,900,1,None,0,'stall'),('preview-unavailable',390,844,3,None,0,'preview-failure'),('midpoint-cold',1440,900,1,625000,150,'midpoint')]
selected=os.getenv('HERO_PROFILES','').split(',');profiles=[p for p in profiles if not selected[0] or p[0] in selected]
results=[]
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path=os.getenv('BROWSER_EXECUTABLE') or None)
 for name,width,height,dpr,speed,latency,patch in profiles:
  context=browser.new_context(viewport={'width':width,'height':height},device_scale_factor=dpr,is_mobile=width<900,has_touch=width<900)
  page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  if speed:
   cdp=context.new_cdp_session(page);cdp.send('Network.enable');cdp.send('Network.emulateNetworkConditions',{'offline':False,'latency':latency,'downloadThroughput':speed,'uploadThroughput':speed/4,'connectionType':'cellular4g'})
  if patch=='stall':
   page.add_init_script("""const fetchOriginal=window.fetch;let delayed=0;window.fetch=(url,options)=>{if(String(url).includes('/fullhd-')&&delayed++<2){return new Promise((resolve,reject)=>{options.signal?.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')));});}return fetchOriginal(url,options);};""")
  if patch=='preview-failure':page.route('**/preview-*.pack',lambda route:route.abort())
  record={'profile':name,'phases':{},'passed':False}
  try:
   page.goto(base+'/',wait_until='domcontentloaded',timeout=90000)
   hero=page.locator('[data-mercedes-preview]');page.locator('[data-mercedes-preview][data-variant]').wait_for(state='attached',timeout=30000)
   if patch=='midpoint':
    hero.evaluate("e=>scrollTo({top:(e.querySelector('.mercedes-pin').offsetHeight-e.querySelector('.mercedes-stage').offsetHeight)*.5,behavior:'instant'})")
    expect(hero).to_have_attribute('data-quality','preview',timeout=10000)
    expect(hero).to_have_attribute('data-frame',re.compile(r'^(297|298)$'),timeout=20000)
    expect(hero).to_have_attribute('data-quality','full',timeout=15000)
    record['midpoint']=hero.evaluate('e=>({...e.dataset})')
   for phase,reverse in ([] if patch=='midpoint' else [('forward',False),('reverse',True)]):
    data=page.evaluate(probe,{'duration':15000 if speed==64000 else 10000,'reverse':reverse});summary=summarize(data)
    (out/f'{name}-{phase}.json').write_text(json.dumps(data),encoding='utf-8');record['phases'][phase]=summary
    print(json.dumps({'profile':name,'phase':phase,**summary}),flush=True)
    assert summary['changes']>=30 if speed==64000 else summary['changes']>=80,summary
    assert summary['max_moving_hold_ms']<1500 if speed==64000 else summary['max_moving_hold_ms']<700,summary
    expect(hero).to_have_attribute('data-frame','0' if reverse else '595',timeout=20000)
    expect(hero).to_have_attribute('data-quality','full',timeout=30000 if speed==64000 else 15000)
   assert not errors,errors
   assert not page.evaluate('document.documentElement.scrollWidth>innerWidth')
   page.screenshot(path=str(out/f'{name}.png'))
   record['passed']=True
  except Exception as e:
   record['error']=str(e);page.screenshot(path=str(out/f'{name}-failure.png'))
  record['resources']=page.evaluate("performance.getEntriesByType('resource').filter(r=>r.name.includes('.pack')).map(r=>({url:r.name,duration:r.duration,bytes:r.transferSize}))")
  record['errors']=errors;results.append(record);(out/'results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps({'profile':name,'passed':record['passed'],'error':record.get('error')}),flush=True);context.close()
 browser.close()
raise SystemExit(0 if all(r['passed'] for r in results) else 1)