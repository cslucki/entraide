# MCP Tooling

### Laravel Exploration Strategy

When MCP Laravel / Laravel Boost is available:

1. ALWAYS use MCP introspection FIRST
2. Use rg ONLY for targeted searches
3. Avoid filesystem crawling
4. Avoid full file reads unless necessary
5. Prefer architecture-aware tools over shell exploration
6. Prefer:

   * routes introspection
   * models introspection
   * policies introspection
   * container introspection
   * migrations introspection

Only fallback to:

* rg
* find
* cat
* grep

when MCP cannot answer the question.


## Browser & Visual Tools

Use browser/visual MCP tools for:
- Livewire debugging
- DOM inspection
- browser console inspection
- screenshots
- Tailwind verification

Do not assume UI behavior without browser verification.

## Filesystem MCP

Use Filesystem MCP for:
- architecture analysis
- project-wide inspection
- relationship discovery
- detecting duplicated logic

## SQLite MCP

Use SQLite MCP for:
- tenant verification
- transaction inspection
- relationship validation
- debugging business rules

