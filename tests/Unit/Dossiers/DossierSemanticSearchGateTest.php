<?php

namespace Tests\Unit\Dossiers;

use App\Services\Dossiers\DossierSemanticSearchGate;
use Tests\TestCase;

class DossierSemanticSearchGateTest extends TestCase
{
    public function test_it_is_disabled_when_global_flag_is_false(): void
    {
        $this->configureGate(enabled: false, organizationIds: ['org-1']);

        $this->assertFalse($this->gate()->isEnabledFor('org-1'));
    }

    public function test_it_is_disabled_when_allowlist_is_empty(): void
    {
        $this->configureGate(enabled: true, organizationIds: []);

        $this->assertFalse($this->gate()->isEnabledFor('org-1'));
    }

    public function test_it_is_disabled_when_organization_is_absent_from_allowlist(): void
    {
        $this->configureGate(enabled: true, organizationIds: ['org-2']);

        $this->assertFalse($this->gate()->isEnabledFor('org-1'));
    }

    public function test_it_is_enabled_when_organization_is_present(): void
    {
        $this->configureGate(enabled: true, organizationIds: ['org-1']);

        $this->assertTrue($this->gate()->isEnabledFor('org-1'));
    }

    public function test_it_supports_multiple_organization_ids(): void
    {
        $this->configureGate(enabled: true, organizationIds: ['org-1', 'org-2', 'org-3']);

        $this->assertTrue($this->gate()->isEnabledFor('org-2'));
    }

    public function test_it_normalizes_spaces_and_case_from_array_config(): void
    {
        $this->configureGate(enabled: true, organizationIds: ['  ABC-123  ']);

        $this->assertTrue($this->gate()->isEnabledFor('abc-123'));
    }

    public function test_it_normalizes_comma_separated_string_config(): void
    {
        $this->configureGate(enabled: true, organizationIds: ' org-1, ORG-2 , ');

        $this->assertTrue($this->gate()->isEnabledFor('org-2'));
    }

    public function test_it_does_not_compare_organization_slugs(): void
    {
        $this->configureGate(enabled: true, organizationIds: ['pilot-organization-slug']);

        $this->assertFalse($this->gate()->isEnabledFor('00000000-0000-0000-0000-000000000001'));
    }

    private function configureGate(bool $enabled, array|string $organizationIds): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', $enabled);
        config()->set('ai.dossiers.semantic_search.organization_ids', $organizationIds);
    }

    private function gate(): DossierSemanticSearchGate
    {
        return new DossierSemanticSearchGate;
    }
}
