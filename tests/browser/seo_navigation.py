"""Regression checks for clean city URLs and catalogue metadata after filtering."""
import json
import os
import re
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base = os.environ.get("SEO_BASE_URL", "http://127.0.0.1:8004").rstrip("/")
report = Path(os.environ.get("SEO_REPORT", "output/seo-review/browser.json"))
results = []
with sync_playwright() as p:
    for engine in os.environ.get("SEO_ENGINES", "chromium,webkit,firefox").split(","):
        options = {"headless": True}
        if engine == "chromium" and os.environ.get("BROWSER_EXECUTABLE"):
            options["executable_path"] = os.environ["BROWSER_EXECUTABLE"]
        browser = getattr(p, engine).launch(**options)
        for width in (1440, 390):
            context = browser.new_context(viewport={"width": width, "height": 844}, reduced_motion="reduce")
            page = context.new_page()
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.goto(base + "/kostanay/", wait_until="networkidle")
            expect(page.locator('[data-city-home]').first).to_have_attribute("href", "/kostanay/")
            page.locator('.city-menu summary').click()
            expect(page.locator('[data-city-switch="astana"]')).to_have_attribute("href", "/")
            page.locator('[data-city-switch="astana"]').click()
            expect(page).to_have_url(base + "/")
            expect(page.locator('[data-city-label]')).to_have_text("Астана")
            page.goto(base + "/contacts/?city=kostanay", wait_until="networkidle")
            page.locator('.city-menu summary').click()
            page.locator('[data-city-switch="pavlodar"]').click()
            expect(page).to_have_url(base + "/contacts/?city=pavlodar")
            expect(page.locator('h1')).to_have_text("Контакты Legionautorent")
            page.goto(base + "/cars/kostanay/", wait_until="networkidle")
            expect(page.locator('h1')).to_have_text("Автопарк в Костанае — выбор автомобилей напрокат")
            expect(page.locator('[name="city"]')).to_have_value("kostanay")
            expect(page.locator('.car-card')).to_have_count(14)
            page.locator('.catalog-sidebar summary').click()
            page.locator('[name="city"]').select_option("pavlodar")
            page.locator('[name="max_price"]').fill("60000")
            page.locator('[name="sort"]').select_option("price")
            page.locator('[data-catalog-filter] button[type="submit"]').click()
            expect(page).to_have_url(re.compile(re.escape(base) + r"/cars/pavlodar/\?.*max_price=60000"))
            expect(page.locator('h1')).to_have_text("Автопарк автомобилей для аренды в Павлодаре")
            expect(page).to_have_title("Прокат авто в Павлодаре — автопарк и цены | Legionautorent")
            assert "?city=" not in page.url
            canonical = page.locator('link[rel="canonical"]').get_attribute('href')
            assert canonical.endswith('/cars/pavlodar/'), canonical
            expect(page.locator('[name="city"]')).to_have_value('pavlodar')
            page.locator('.catalog-sidebar summary').click()
            page.locator('[name="max_price"]').fill('40000')
            page.locator('[data-catalog-filter] button[type="submit"]').click()
            expect(page).to_have_url(re.compile(r"max_price=40000"))
            expect(page.locator('meta[name="robots"]')).to_have_attribute('content', 'noindex,nofollow')
            page.locator('.city-menu summary').click()
            city_link = page.locator('[data-city-switch="kostanay"]').get_attribute('href')
            assert '/cars/kostanay/' in city_link and 'max_price=40000' in city_link and 'city=' not in city_link, city_link
            page.locator('[data-city-switch="kostanay"]').click()
            expect(page).to_have_url(re.compile(r"/cars/kostanay/\?.*max_price=40000"))
            page.goto(base + '/en/cars/pavlodar/', wait_until='networkidle')
            expect(page.locator('h1')).to_have_text('Rental cars in Pavlodar — fleet and daily rates')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'horizontal overflow'
            if engine == 'chromium':
                report.parent.mkdir(parents=True, exist_ok=True)
                page.screenshot(path=str(report.parent / f'fleet-{width}.png'), full_page=False)
            assert not errors, errors
            results.append({'engine': engine, 'width': width, 'passed': True})
            context.close()
        browser.close()
report.parent.mkdir(parents=True, exist_ok=True)
report.write_text(json.dumps(results, indent=2), encoding='utf-8')
print(json.dumps(results))
