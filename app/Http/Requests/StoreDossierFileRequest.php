<?php

namespace App\Http\Requests;

use App\Models\Dossier;
use App\Support\Files\TypesDeFichiersAcceptes;
use Illuminate\Foundation\Http\FormRequest;

class StoreDossierFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dossierId = $this->route('dossier');

        if (! $dossierId) {
            return false;
        }

        $dossier = Dossier::query()->whereKey($dossierId)->first();

        if (! $dossier) {
            return false;
        }

        $organization = currentOrganization();

        if (! $organization || $dossier->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $this->user();

        if (! $user || $user->organization_id !== $organization->id) {
            abort(404);
        }

        // TASK-1684 : DEPOSER, pas administrer. Voir DossierPolicy::uploadFiles().
        return $user->can('uploadFiles', $dossier);
    }

    public function rules(): array
    {
        return [
            // 20, comme la limite du client : une regle serveur plus basse
            // que ce que l'interface propose ne protege rien, elle contredit.
            'files' => ['required', 'array', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'max:51200',
                // La liste vit dans `TypesDeFichiersAcceptes` depuis TASK-1704,
                // ou la messagerie accepte desormais des fichiers elle aussi.
                // Elle y est accompagnee de l'histoire qui explique ses
                // doublons apparents (les alias libmagic) : la recopier ici
                // aurait donne deux regles pour une idee, et c'est la copie
                // qu'on oublie de completer. La chaine produite est identique
                // a l'octet a celle qui figurait ici.
                TypesDeFichiersAcceptes::regleMimetypes(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => __('dossiers.file_required'),
            'files.max' => __('dossiers.file_max_count'),
            'files.*.max' => __('dossiers.file_max_size'),
            'files.*.mimetypes' => __('dossiers.file_invalid_type'),
        ];
    }
}
