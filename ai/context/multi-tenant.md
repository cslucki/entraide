# Multi-Tenant Rules

## Tenant Isolation

- All tenant-scoped models must respect BelongsToTenantScope
- Community isolation is mandatory
- Never bypass tenant filtering manually
- Community slug resolution must remain stable

## Critical Models

Tenant-sensitive models:
- User
- Service
- ServiceRequest
- Transaction

## Validation Rules

Before modifying tenant logic:
1. Verify routes
2. Verify policies
3. Verify scopes
4. Verify database consistency
5. Verify UI behavior