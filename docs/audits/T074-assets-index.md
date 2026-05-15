# T074 — Assets Index

## Purpose

This document clarifies the role of T074 visual assets.

These assets are UX references.
They are not Playwright screenshots.
They are not pixel-perfect implementation specs.
They must guide T074.2 Product Spec and future T074.6 UI implementation.

## Folders

### docs/audits/T074.1-assets/

Purpose:
General UX exploration for ChatLoop mobile, desktop and admin.

Used for:
- secondary flows
- Échanges
- Objectifs
- Actus
- Mes invités
- OrgAdmin Loops Center
- OrgAdmin Message Center

### docs/audits/T074.1A-assets/

Purpose:
Canonical IA-assisted interaction references.

Used for:
- Boucles Center dark
- Qui peut m'aider ?
- Demande clarifiée
- ChatLoop with structured Help Request card
- FakeAIProvider / Lab IA alignment

## Canonical screens for T074.2

1. Boucles Center dark
Path:
docs/audits/T074.1A-assets/000-Mesboucles_Black.png

Role:
Primary visual reference for the mobile Boucles Center beta direction.

2. Qui peut m'aider ?
Path:
docs/audits/T074.1A-assets/01-qui-peut-maider-reference.png

Role:
Primary reference for the fuzzy intention input flow.

3. Demande clarifiée
Path:
docs/audits/T074.1A-assets/02-demande-clarifiee-reference.png

Role:
Primary reference for AI clarification, human validation and suggested Loop.

4. ChatLoop / Réseautage Marseille
Path:
docs/audits/T074.1A-assets/00-reseautage-reference.png

Role:
Primary reference for ChatLoop conversation with structured Help Request card.

## Secondary references

Use docs/audits/T074.1-assets/ for:
- mobile loop list alternatives
- Échanges flow
- Objectifs
- Actus
- Mes invités
- Create Loop
- Add Members
- Desktop ChatLoop
- OrgAdmin Loops Center
- OrgAdmin Message Center

## Duplicate note

If docs/audits/T074.1-assets/00-vision-chatloop-reference.png duplicates the Demande clarifiée screen, keep it for historical continuity but use:
docs/audits/T074.1A-assets/02-demande-clarifiee-reference.png
as the canonical implementation reference.

## Rules

- Do not store these images in ai/playwright/screenshots/.
- ai/playwright/screenshots/ is reserved for real Playwright validation screenshots.
- Do not infer code directly from images.
- T074.2 Product Spec must convert these images into components, states, flows and acceptance criteria.
- Future T074.6 implementation must validate the real UI with Playwright screenshots.

## Current decision

T74-MASTER validates:
- no folder rename now
- no image move now
- no image deletion now
- T074.1-assets and T074.1A-assets remain stable
- T074.2 must include a UX Implementation Brief based on this index
