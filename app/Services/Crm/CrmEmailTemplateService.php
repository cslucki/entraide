<?php

namespace App\Services\Crm;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Services\EmailerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * TASK-1420 — CRM-7a : les modeles d'email d'une Organization.
 *
 * `email_templates` porte un `slug` UNIQUE GLOBAL et un `organization_id`
 * nullable deduit du contexte par `HasOrganizationId`. Ici rien n'est
 * implicite : l'Organization vient de la route et le slug technique est
 * NAMESPACE (« {org-slug}-{slug} »), pose une fois et jamais recalcule — le
 * nom visible, lui, se renomme librement.
 */
class CrmEmailTemplateService
{
    /** Variables disponibles pour un Contact (allowlist EmailerService + `company`). */
    public const CONTACT_VARIABLES = ['first_name', 'name', 'full_name', 'email', 'organization', 'company'];

    public const EXTRA_ALLOWED_VARS = ['company'];

    public function forOrganization(Organization $organization): Builder
    {
        return EmailTemplate::query()->where('organization_id', $organization->id);
    }

    /** 404 pour un modele global, d'une autre Organization, ou inexistant. */
    public function resolve(Organization $organization, string $id): EmailTemplate
    {
        return $this->forOrganization($organization)->whereKey($id)->firstOrFail();
    }

    public function create(Organization $organization, string $name, string $subject, string $contentHtml): EmailTemplate
    {
        return EmailTemplate::create([
            'organization_id' => $organization->id,
            'slug' => $this->uniqueSlug($organization, $name),
            'name' => trim($name),
            'subject' => trim($subject),
            'content_html' => $contentHtml,
            'variables' => self::CONTACT_VARIABLES,
        ]);
    }

    /** Le slug technique ne bouge pas : un modele renomme garde son identite. */
    public function update(EmailTemplate $template, string $name, string $subject, string $contentHtml): EmailTemplate
    {
        $template->update([
            'name' => trim($name),
            'subject' => trim($subject),
            'content_html' => $contentHtml,
        ]);

        return $template;
    }

    /** Un Contact d'exemple pour la preview : jamais une personne reelle. */
    public function sampleVariables(Organization $organization): array
    {
        return [
            'first_name' => 'Camille',
            'name' => 'Dupont',
            'full_name' => 'Camille Dupont',
            'email' => 'camille.dupont@example.com',
            'organization' => $organization->name,
            'city' => '',
            'company' => 'ACME',
        ];
    }

    private function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($organization->slug.'-'.Str::limit(Str::slug($name), 60, ''));
        $base = $base !== '' ? $base : Str::slug($organization->slug.'-modele');
        $slug = $base;
        $i = 2;

        while (EmailTemplate::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
