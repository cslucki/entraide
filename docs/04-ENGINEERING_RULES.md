# Additional UI & Product Engineering Rules

---

# Layout System

## Containers

Use consistent container widths.

Preferred max widths:

* Narrow reading content: `max-w-3xl`
* Standard app sections: `max-w-5xl`
* Wide dashboard sections: `max-w-7xl`

Avoid:

* full-width text blocks
* inconsistent margins
* random container sizing

---

## Vertical Rhythm

Spacing must follow a predictable rhythm.

Preferred spacing scale:

* 4
* 8
* 12
* 16
* 24
* 32
* 48
* 64

Avoid arbitrary spacing values.

---

# Section Design

Each section must answer ONE primary question.

Avoid:

* mixing multiple objectives
* multiple competing CTAs
* visually overloaded sections

Every section should have:

* clear title
* optional subtitle
* dominant action
* breathing space

---

# Empty States

Empty states are part of the UX.

They must:

* reassure users
* explain what to do next
* reduce friction

Avoid:

* sterile “No results”
* technical language
* dead-end screens

Preferred tone:

* calm
* encouraging
* actionable

---

# Loading States

Loading states should feel:

* fast
* subtle
* intentional

Prefer:

* skeleton loaders
* soft pulse effects
* progressive rendering

Avoid:

* spinners everywhere
* blocking overlays
* aggressive animations

---

# Animations

Animations are OPTIONAL.

If used:

* subtle
* fast
* purposeful

Avoid:

* bouncing
* elastic motion
* exaggerated transitions
* “AI futuristic” effects

Preferred transition duration:

* 150ms to 250ms

---

# Icons

Icons support comprehension.
They must not dominate the interface.

Rules:

* use sparingly
* consistent size
* avoid icon overload
* never rely only on icons without text

Preferred:

* Lucide icons
* outline style
* minimal usage

---

# Tables & Data

Tables must remain readable.

Rules:

* avoid excessive columns
* prioritize mobile readability
* use whitespace generously
* truncate intelligently

On mobile:

* prefer stacked layouts over horizontal overflow

---

# Dashboard Philosophy

Dashboards must NOT feel overwhelming.

Avoid:

* metric overload
* too many cards
* too many colors
* dense admin interfaces

Prioritize:

* one key insight
* progressive exploration
* simple hierarchy

---

# Admin Interfaces

Admin screens should still feel premium.

Avoid:

* old-school enterprise UI
* tiny text
* crowded tables
* checkbox overload

Admin ≠ ugly.

---

# Accessibility

Minimum accessibility rules:

* sufficient contrast
* visible focus states
* keyboard navigation support
* labels for all inputs
* touch-friendly mobile spacing

Minimum mobile tap target:

* 44x44px

---

# Performance Rules

Frontend performance is part of UX.

Avoid:

* unnecessary JS
* large UI libraries
* excessive Alpine watchers
* unnecessary re-renders

Prefer:

* Livewire-native patterns
* server-driven UI
* progressive enhancement

---

# Dependency Policy

Before adding any package:

You MUST explain:

* why it is needed
* why native Laravel/Livewire/Tailwind is insufficient
* frontend impact
* build impact
* maintenance cost

Prefer:

* native browser APIs
* Tailwind utilities
* Alpine minimalism

Avoid:

* UI kits
* animation frameworks
* heavy state managers

---

# Component Naming

Reusable components must have predictable names.

Preferred:

* PrimaryButton
* SecondaryButton
* SectionHeader
* EmptyState
* ConversationInput
* MobileDrawer

Avoid:

* vague naming
* duplicated components
* one-off variants

---

# Dark Mode Principles

Dark mode is NOT:

* “black mode”
* cyberpunk mode
* neon mode

Dark mode should:

* reduce eye fatigue
* preserve hierarchy
* remain elegant

Use:

* soft surfaces
* muted borders
* subtle separation

---

# AI Interaction Philosophy

AI interactions must:

* reduce friction
* simplify choices
* accelerate action

Avoid:

* fake chatbot personalities
* unnecessary conversational loops
* excessive prompts
* gimmicks

AI exists to help users DO things.

---

# Product Consistency Rule

When implementing a new feature:

DO NOT invent a new UI pattern if one already exists.

Before creating:

* button styles
* cards
* inputs
* dropdowns
* modals

Check existing components first.

Consistency is more important than originality.

---

# Validation Process

Before every PR submission:

Required:

* desktop screenshots
* mobile screenshots
* dark mode screenshots
* responsive verification
* Playwright validation

No feature is considered complete without visual validation.

---

# Final Principle

Simplicity scales.
Visual noise does not.

When uncertain:
remove complexity,
not clarity.
