"""Regression checks for delayed startup, motion preferences and recovery."""
import asyncio
import json
import os
import re
from pathlib import Path
from playwright.async_api import async_playwright, expect

BASE = os.getenv("HERO_BASE_URL", "http://127.0.0.1:8004")
OUT = Path(os.getenv("HERO_REPORT", "output/hero-startup"))
OUT.mkdir(parents=True, exist_ok=True)
SCENARIOS = os.getenv("HERO_SCENARIOS", "normal,slow-main,slow-module,late-catalogue,reduced,network-retry,script-failure,no-js,kk,en").split(",")
ENGINES = os.getenv("HERO_ENGINES", "chromium").split(",")

async def check(browser, name, engine):
    reduced = name in ["reduced", "kk", "en"]
    width = int(os.getenv("HERO_WIDTH", "1366"))
    context = await browser.new_context(viewport={"width": width, "height": 768 if width > 899 else 844}, reduced_motion="reduce" if reduced else "no-preference", java_script_enabled=name != "no-js")
    page = await context.new_page()
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    record = {"engine": engine, "scenario": name, "width": width, "passed": False}
    try:
        if name in ["slow-main", "slow-module", "late-catalogue"]:
            async def delay(route):
                await asyncio.sleep(11)
                await route.continue_()
            await page.route("**/main-*.js" if name == "slow-main" else "**/mercedes-preview-*.js", delay)
        if name == "script-failure":
            await page.route("**/main-*.js", lambda route: route.abort())
        if name == "network-retry":
            await page.route("**/*.pack", lambda route: route.abort())
        await page.goto(BASE + ("/" + name + "/" if name in ["kk", "en"] else "/"), wait_until="commit", timeout=60000)
        hero = page.locator("[data-mercedes-preview]")
        retry = hero.locator("[data-hero-retry]")
        await expect(hero).to_be_attached()
        if name in ["slow-main", "slow-module", "late-catalogue"]:
            await expect(hero).to_have_attribute("data-failure", "script-timeout", timeout=10000)
            await expect(hero).to_have_attribute("data-mode", "static")
            if name == "late-catalogue":
                await page.locator("#fleet").scroll_into_view_if_needed()
                before = await page.locator("#fleet").evaluate("e=>e.getBoundingClientRect().top")
        if name not in ["no-js", "script-failure"]:
            await expect(hero).to_have_attribute("data-initialized", "true", timeout=20000)
        if name in ["no-js", "script-failure"]:
            await expect(hero).to_have_attribute("data-mode", "static")
            await expect(retry).to_be_hidden()
            await expect(hero.locator("h1")).to_be_visible()
            await expect(hero.locator("[data-hero-actions] a").first).to_be_visible()
        else:
            if reduced or name in ["late-catalogue", "network-retry"]:
                await expect(hero).to_have_attribute("data-mode", "static", timeout=20000)
                if reduced:
                    await expect(hero).to_have_attribute("data-failure", "reduced-motion")
                    assert await page.evaluate("performance.getEntriesByType('resource').filter(r=>r.name.includes('.pack')).length") == 0
                    expected = {"kk": "Анимацияны қосу", "en": "Enable animation"}.get(name, "Включить анимацию")
                    await expect(retry).to_have_text(expected)
                if name == "late-catalogue":
                    await expect(hero).to_have_attribute("data-failure", "late-script")
                    after = await page.locator("#fleet").evaluate("e=>e.getBoundingClientRect().top")
                    assert abs(after - before) < 2, (before, after)
                if name == "network-retry":
                    await page.unroute("**/*.pack")
                await expect(retry).to_be_visible()
                if reduced:
                    await page.screenshot(path=str(OUT / f"{engine}-{name}-static.png"))
                await retry.click()
            await expect(hero).to_have_attribute("data-mode", "cinematic", timeout=20000)
            await expect(hero).to_have_attribute("data-state", "ready", timeout=20000)
            await expect(retry).to_be_hidden()
            distance = await hero.evaluate("e=>e.querySelector('.mercedes-pin').offsetHeight-e.querySelector('.mercedes-stage').offsetHeight")
            assert distance > 1000, distance
            await page.evaluate("d=>scrollTo({top:d*.5,behavior:'instant'})", distance)
            await expect(hero).to_have_attribute("data-frame", re.compile(r"^(297|298)$"), timeout=20000)
            await expect(hero).to_have_attribute("data-quality", "full", timeout=20000)
            await page.evaluate("scrollTo({top:0,behavior:'instant'})")
            await expect(hero).to_have_attribute("data-frame", "0", timeout=20000)
            if name == "normal":
                await page.emulate_media(reduced_motion="reduce")
                await expect(hero).to_have_attribute("data-mode", "static")
                await page.emulate_media(reduced_motion="no-preference")
                await expect(hero).to_have_attribute("data-mode", "cinematic")
            if name == "reduced":
                await page.emulate_media(reduced_motion="no-preference")
                await page.evaluate("()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))")
                await page.emulate_media(reduced_motion="reduce")
                await expect(hero).to_have_attribute("data-mode", "static")
        await expect(hero.locator(".mercedes-picture img")).to_have_js_property("complete", True)
        assert await hero.locator(".mercedes-picture img").evaluate("e=>e.naturalWidth>0")
        assert not await page.evaluate("document.documentElement.scrollWidth>innerWidth")
        assert not errors, errors
        record["state"] = await hero.evaluate("e=>({...e.dataset})")
        record["passed"] = True
    except Exception as error:
        record["error"] = str(error)
        record["js_errors"] = errors
        await page.screenshot(path=str(OUT / f"{engine}-{name}-failure.png"))
    finally:
        print(json.dumps(record, ensure_ascii=False), flush=True)
        await context.close()
    return record

async def main():
    records = []
    async with async_playwright() as playwright:
        for engine in ENGINES:
            options = {"headless": True}
            if engine == "chromium" and os.getenv("BROWSER_EXECUTABLE"):
                options["executable_path"] = os.environ["BROWSER_EXECUTABLE"]
            browser = await getattr(playwright, engine).launch(**options)
            print(json.dumps({"engine": engine, "version": browser.version}), flush=True)
            for name in SCENARIOS:
                records.append(await check(browser, name, engine))
                (OUT / "results.json").write_text(json.dumps(records, ensure_ascii=False, indent=2), encoding="utf-8")
            await browser.close()
    raise SystemExit(0 if all(record["passed"] for record in records) else 1)

asyncio.run(main())
