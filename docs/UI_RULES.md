# BouclePro Design System Foundation (v1.0)

## 1. Core Principles
- **Clarity & Simplicity**: Every element must serve a clear purpose. If it's not useful, remove it.
- **Breathing Space**: Whitespace is intentional. It reduces cognitive load and creates a premium feel.
- **Onboarding Speed**: The "Aha!" moment should happen within seconds. "I can simply describe what I want."
- **AI-Native Interface**: The UI should feel like a conversation partner, not a complex dashboard.
- **Mobile-First Priority**: Mobile is the primary device. Mobile screens must feel lighter, cleaner, and more focused than desktop.

## 2. Visual Hierarchy & Noise Reduction
- **One Dominant Action**: There should be one primary action per viewport.
- **CTA Hierarchy**: Maximum 2 primary CTAs per section.
- **Typography Hierarchy**: Clear distinction between headings and body.
  - Title (Hero): `text-4xl` to `text-6xl`, `font-extrabold`, `tracking-tight`.
  - Section Headings: `text-2xl` to `text-3xl`, `font-bold`.
  - Body: `text-base` or `text-lg`, `leading-relaxed`.
  - Muted: `text-sm`, `text-gray-500` or `text-zinc-500`.
- **No All-Caps Buttons**: Use standard sentence or title case for readability.
- **Avoid Visual Noise**: Minimize borders, excessive icons, and unnecessary dividers. Use shadows and whitespace for separation instead.

## 3. Spacing & Grid
- **Scale**: 4px base (Tailwind default).
- **Container Widths**:
  - Global: `max-w-7xl`.
  - Content: `max-w-6xl`.
  - Focused (AI/Forms): `max-w-4xl`.
- **Padding**: Large vertical padding for sections (`py-12` to `py-24`) to provide breathing room.

## 4. Colors & Dark Mode
- **Palette**: Premium Zinc, White, and Indigo.
- **Primary Color**: Indigo-600 (Light) / Indigo-500 (Dark).
- **Accent**: Emerald/Amber for specific success/warning states, but sparingly.
- **Dark Mode Principles**:
  - High contrast but avoid pure #000 black (use Zinc-900/950).
  - Surface colors: Zinc-800 or Slate-900.
  - Never use dark text on dark buttons.
- **Gradients**: Allowed only on the Hero section and very subtle accent elements. No glassmorphism overload.

## 5. Mobile Behavior Rules
- **Critical Requirement**: Remove non-essential sections on mobile (e.g., heavy info blocks, repetitive stats).
- **Navigation**: Persistent sticky header with a simple hamburger menu.
- **Drawer**: Mobile drawer should feel light and contain "Se connecter" and essential links.

## 6. Component Conventions
- **HeroSection**: Large, bold typography, simple secondary text, primary CTA, and subtle background decoration.
- **AIInputCard / ConversationInput**:
  - Centered layout.
  - Rounded-3xl corners.
  - Large textarea with dynamic height.
  - **Send button INSIDE the input container.**
  - Clear loading/processing states.
- **IntentHintButtons**: Rounded-full buttons below the AI input to guide the user.
- **PrimaryButton**: Rounded-xl or 2xl, solid background, high contrast text, transform-on-hover.
- **SecondaryButton**: Ghost or subtle border, rounded-xl.
- **StatsCard**: Minimalist, bold numbers, muted labels.

## 7. Interaction Rules
- **Empty States**: Must be welcoming and provide a clear path forward (usually pointing back to the AI input).
- **Loading**: Use subtle pulse or spinners, never jarring.
- **Feedback**: Success/Error toasts should be clear but non-intrusive.
