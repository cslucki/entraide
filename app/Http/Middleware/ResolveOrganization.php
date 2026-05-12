<?php

namespace App\Http\Middleware;

/**
 * ResolveOrganization is a semantic alias for ResolveCommunity during the
 * Community → Organization migration.
 *
 * This class exists to provide a properly named middleware for organization
 * route groups. It binds both `current_organization` and `current_community`
 * to the same resolved instance, preserving full backward compatibility.
 *
 * @see ResolveCommunity
 */
class ResolveOrganization extends ResolveCommunity {}
