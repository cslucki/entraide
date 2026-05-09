import base64
import os

files = [
    ("1. Desktop Homepage", "docs/visual-review/01-desktop-home.png"),
    ("2. Desktop Sticky Navigation", "docs/visual-review/02-desktop-sticky-nav.png"),
    ("3. Mobile Homepage", "docs/visual-review/03-mobile-home.png"),
    ("4. Mobile Menu Open", "docs/visual-review/04-mobile-menu-open.png"),
    ("5. Dark Mode Homepage", "docs/visual-review/05-dark-mode.png"),
    ("6. AI Input Interaction", "docs/visual-review/06-ai-interaction.png"),
    ("7. Hint Selection Behavior", "docs/visual-review/07-ai-hint-selected.png"),
    ("8. Tablet Spacing", "docs/visual-review/08-tablet-spacing.png"),
    ("9. Desktop Profile Dropdown", "docs/visual-review/09-desktop-profile-dropdown.png"),
    ("10. Notification Center", "docs/visual-review/10-notification-center.png")
]

print("### BouclePro Premium UI/UX Refinement - Visual Validation\n")

for title, path in files:
    if os.path.exists(path):
        with open(path, "rb") as f:
            data = base64.b64encode(f.read()).decode("utf-8")
            print(f"#### {title}")
            print(f"IMAGE_START:{title}:data:image/png;base64,{data}:IMAGE_END")
            print("\n---\n")
    else:
        print(f"#### {title} (File not found: {path})\n")
