import asyncio
from playwright.async_api import async_playwright
import os

async def run():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(viewport={'width': 1440, 'height': 900})
        page = await context.new_page()

        # 1. Desktop Light Mode
        await page.goto("http://localhost:8000")
        await page.wait_for_selector('h1:has-text("BouclePro")')
        await page.screenshot(path="/home/jules/verification/final_v2_light.png", full_page=True)
        print("Captured Final Light Mode")

        # 2. Desktop Dark Mode
        await page.click('button:has(svg path[d*="M20.354"])') # Toggle to dark
        await asyncio.sleep(0.5)
        await page.screenshot(path="/home/jules/verification/final_v2_dark.png", full_page=True)
        print("Captured Final Dark Mode")

        # 3. Mobile Check
        mobile_context = await browser.new_context(viewport={'width': 375, 'height': 812}, is_mobile=True)
        mobile_page = await mobile_context.new_page()
        await mobile_page.goto("http://localhost:8000")
        await mobile_page.screenshot(path="/home/jules/verification/final_v2_mobile.png")
        print("Captured Final Mobile UI")

        # 4. Interaction Test: AI Suggestion -> Redirection
        await page.click('button:has-text("I NEED HELP CREATING MY COMPANY")')
        await page.wait_for_load_state("networkidle")
        await page.screenshot(path="/home/jules/verification/final_interaction_redirection.png")
        print(f"Verified redirection to: {page.url}")

        await browser.close()

if __name__ == "__main__":
    asyncio.run(run())
