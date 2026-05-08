import asyncio
from playwright.async_api import async_playwright
import os

async def run():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        context = await browser.new_context(viewport={'width': 1280, 'height': 800})
        page = await context.new_page()

        # Step 1: Check Homepage Light Mode
        await page.goto("http://localhost:8000")
        await page.wait_for_selector('h1:has-text("BouclePro")')
        await page.screenshot(path="/home/jules/verification/screenshots/homepage_premium_light.png")
        print("Captured Homepage Light Mode")

        # Step 2: Check Dark Mode Toggle
        await page.click('button:has(svg path[d*="M20.354"])') # Toggle to dark
        await asyncio.sleep(0.5)
        await page.screenshot(path="/home/jules/verification/screenshots/homepage_premium_dark.png")
        print("Captured Homepage Dark Mode")

        # Step 3: Test Suggestion Chip Interaction
        await page.click('button:has-text("I WANT TO HELP PEOPLE WITH EXCEL")')

        # Should redirect to login (since guest) or explorer (if redirected by logic)
        # Note: If guest, it might redirect to register/login depending on route protection
        await page.wait_for_load_state("networkidle")
        print(f"Redirected to: {page.url}")
        await page.screenshot(path="/home/jules/verification/screenshots/redirection_after_suggestion.png")

        await browser.close()

if __name__ == "__main__":
    asyncio.run(run())
