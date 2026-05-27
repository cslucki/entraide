# T145-RUNTIME-AUDIT.md — Runtime Audit

**READ-ONLY.** Sources de vérité pour comprendre le comportement runtime actuel.

## Middleware Chain

1. `ResolveCommunity` — web middleware group
2. `ResolveOrganization` — alias (extends ResolveCommunity)
3. `ResolveUrlOrganization` — route-specific
4. `ResolveApiOrganization` — api middleware group

## Résolution actuelle

```php
$organization = app()->bound('current_organization')
    ? app('current_organization')
    : (app()->bound('current_community')
        ? app('current_community')
        : null);
```

## Problème soupçonné

Sur routes racine `/membres`, `/explorer`, `/blog`, le middleware `ResolveCommunity` ne trouve pas de paramètre route `{community}` ou `{organization}` → ne bind pas `current_organization` → les scopes (BelongsToTenantScope) qui filtrent par `organization_id` ne trouvent rien → les controllers reçoivent `current_organization = null` → comportement indéfini.

## Pages à inspecter

| Path | Controller | Middleware | Dépend de current_organization |
|------|------------|------------|-------------------------------|
| `/` | HomeController | web | Oui (counters) |
| `/membres` | HomeController@members | web | Oui |
| `/explorer` | ExplorerController | web | Oui |
| `/blog` | BlogController | web | Oui |
| `/services` | ServiceController | web | Oui |
| `/requests` | RequestController | web | Oui |
| `/login` | AuthenticatedSessionController | web (guest) | Non |
| `/dashboard` | DashboardController | web + auth | Oui |
