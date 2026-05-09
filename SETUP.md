# BouclePro Reproducible Setup Guide

This document provides the exact steps and environment details required to reproduce the premium UI/UX refinements on a local machine.

## 1. Environment Requirements
- **PHP**: 8.3 or 8.4 (Tested on 8.3.6)
- **Node.js**: 22.x (Tested on 22.22.1)
- **NPM**: 11.x (Tested on 11.11.0)
- **Composer**: 2.x (Tested on 2.9.5)
- **OS**: WSL2 Ubuntu (Recommended)

## 2. Fresh Installation Steps
If you are starting from a fresh clone of the branch:

```bash
# 1. Install PHP dependencies
composer install

# 2. Setup environment file
cp .env.example .env
php artisan key:generate

# 3. Setup Database (SQLite)
touch database/database.sqlite
php artisan migrate --seed --force

# 4. Install Node dependencies (Strict Tailwind v4 setup)
npm install --ignore-scripts

# 5. Build Assets
npm run build
```

## 3. Maintenance & Reproducibility Commands
If you encounter styling issues, run these commands to clear caches and rebuild:

```bash
# Clear all Laravel caches
php artisan optimize:clear

# Rebuild assets
rm -rf public/build
npm run build
```

## 4. Key Configuration Files
The following files define the UI/UX environment:
- `package.json`: Strictly configured for Tailwind CSS v4 (`@tailwindcss/vite`).
- `vite.config.js`: Uses `tailwindcss()` plugin from `@tailwindcss/vite`.
- `resources/css/app.css`: Uses `@import "tailwindcss";` and defines custom themes via `@theme`.
- `tailwind.config.js`: **Ignored** in Tailwind v4; configuration is now in CSS.

## 5. Visual Validation
To run the same Playwright validation suite used for the screenshots:
```bash
npx playwright test tests/e2e/final-visual-review.spec.ts
```
