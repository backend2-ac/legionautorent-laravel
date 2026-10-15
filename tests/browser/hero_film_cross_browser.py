from pathlib import Path
import os,json
from playwright.sync_api import sync_playwright,expect
out=Path(os.getenv('HERO_REPORT','reports/hero-film'));out.mkdir(parents=True,exist_ok=True)
results=[]
with sync_playwright() as p:
 for name in ['webkit','firefox']:
  browser=getattr(p,name).launch(headless=True)
  context=browser.new_context(viewport={'width':390,'height':844},device_scale_factor=2)
  page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
  page.goto(os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')+'/',wait_until='networkidle')
  root=page.locator('[data-hero-film]')
  root.evaluate('(r)=>scrollTo(0,(r.offsetHeight-r.querySelector(".hero-film__stage").offsetHeight)*.61)');page.wait_for_timeout(1800)
  middle=root.get_attribute('data-frame');assert int(middle)>50
  page.screenshot(path=str(out/f'{name}-mobile.png'))
  root.evaluate('(r)=>scrollTo(0,r.offsetHeight-r.querySelector(".hero-film__stage").offsetHeight)');expect(root).to_have_attribute('data-frame','121',timeout=10000)
  page.emulate_media(reduced_motion='reduce');expect(root).to_have_attribute('data-motion','false');assert root.bounding_box()['height']==844
  assert not errors,errors
  results.append({'browser':name,'middle_frame':middle,'end_frame':121,'reduced_motion':True,'errors':errors});browser.close()
(out/'cross-browser.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
print(json.dumps(results))
