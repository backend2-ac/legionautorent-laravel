from pathlib import Path
import os,json
from playwright.sync_api import sync_playwright,expect
out=Path(os.getenv('HERO_REPORT','reports/hero-film'));out.mkdir(parents=True,exist_ok=True);records=[]
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,executable_path=os.getenv('BROWSER_EXECUTABLE') or None)
 for width,height in [(768,800),(882,804),(1440,900),(1920,1080),(1366,600)]:
  page=b.new_page(viewport={'width':width,'height':height})
  page.add_init_script("const draw=CanvasRenderingContext2D.prototype.drawImage;CanvasRenderingContext2D.prototype.drawImage=function(...a){if(this.canvas.classList.contains('hero-film__canvas'))window.lastFilmDraw={x:a[1],y:a[2],w:a[3],h:a[4],cw:this.canvas.width,ch:this.canvas.height};return draw.apply(this,a)}")
  page.goto(os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')+'/#hero-cover',wait_until='networkidle')
  root=page.locator('[data-hero-film]')
  assert page.locator('.hero-film__captions').count()==0
  stage=page.locator('.hero-film__stage').bounding_box()
  assert stage['x']==40 and abs(stage['width']-(width-80))<1,stage
  picture=page.locator('.hero-film__picture').bounding_box()
  assert picture['width']>200 and picture['height']>200,picture
  header=page.locator('.site-header').bounding_box()
  copy=page.locator('.hero-film__copy').bounding_box()
  assert picture['y']>=header['y']+header['height'],(picture,header)
  assert picture['y']+picture['height']<=height,(picture,height)
  assert copy['x']+copy['width']<=picture['x'],(copy,picture)
  assert not page.evaluate('document.documentElement.scrollWidth>innerWidth')
  assert page.locator('.hero-film__book').is_visible()
  for phase in [0.01,0.48,0.99]:
   root.evaluate('(r,p)=>scrollTo({top:r.offsetTop+(r.offsetHeight-r.querySelector(".hero-film__stage").offsetHeight)*p,behavior:"instant"})',phase)
   expect(root).to_have_attribute('data-rendered','true')
   page.wait_for_timeout(1300)
   draw=page.evaluate('window.lastFilmDraw')
   assert draw['w']>0 and draw['h']>0,draw
   assert draw['x']>=-1 and draw['y']>=-1,draw
   assert draw['x']+draw['w']<=draw['cw']+1 and draw['y']+draw['h']<=draw['ch']+1,draw
   if phase==0.01:page.screenshot(path=str(out/f'desktop-fit-{width}.png'))
  page.locator('.hero-film__book').focus();page.keyboard.press('Enter');page.wait_for_timeout(600)
  assert page.url.endswith('#fleet')
  records.append({'width':width,'height':height,'gutters':40,'whole_frame_visible':True,'copy_clear_of_frame':True,'captions_removed':True,'keyboard_booking':True})
  page.close()
 b.close()
(out/'desktop-fit.json').write_text(json.dumps(records,indent=2),encoding='utf-8')
print(json.dumps(records))
