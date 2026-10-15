from pathlib import Path
import os,json
from playwright.sync_api import sync_playwright,expect
out=Path(os.getenv('HERO_REPORT','reports/hero-film'));out.mkdir(parents=True,exist_ok=True);records=[]
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,executable_path=os.getenv('BROWSER_EXECUTABLE') or None)
 for mode in ['contain','cover']:
  page=b.new_page(viewport={'width':1440,'height':900})
  page.goto(os.getenv('HERO_BASE_URL','http://127.0.0.1:8004')+'/#hero-'+mode,wait_until='networkidle')
  root=page.locator('[data-hero-film]');expect(root).to_have_attribute('data-fit',mode);page.evaluate('scrollTo(0,20)');expect(root).to_have_attribute('data-rendered','true')
  page.screenshot(path=str(out/f'fullscreen-{mode}.png'))
  assert page.locator('.hero-film__canvas').bounding_box()['width']==1440
  page.locator('.hero-film__book').focus();page.keyboard.press('Enter');page.wait_for_timeout(1000)
  assert page.url.endswith('#fleet')
  records.append({'mode':mode,'canvas_width':1440,'keyboard_booking':True})
  page.close()
 b.close()
(out/'fullscreen-variants.json').write_text(json.dumps(records,indent=2),encoding='utf-8')
