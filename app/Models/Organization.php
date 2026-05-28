<?php

namespace App\Models;

/**
 * Organization is the official tenant concept (Community → Organization migration).
 *
 * This class extends Community as a backward-compatible alias during the migration.
 * Both point to the `organizations` table.
 */
class Organization extends Community
{
    /**
     * Organizations are identified by slug in URLs, not primary key.
     * Enables implicit Eloquent route model binding for {organization} params.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
