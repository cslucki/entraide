<?php

namespace App\Http\Middleware;

/**
 * ResolveOrganization is a semantic alias for ResolveCommunity during the
 * Community → Organization migration.
 *
 * This class exists to provide a properly named middleware for organization
 * route groups. It binds `current_organization` as the sole runtime tenant
 * context, preserving full backward compatibility.
 *
 * @see ResolveCommunity
 */
class ResolveOrganization extends ResolveCommunity {}
