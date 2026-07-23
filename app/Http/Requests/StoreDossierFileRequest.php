<?php

namespace App\Http\Requests;

use App\Models\Dossier;
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

        return $user->can('manageFiles', $dossier);
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:5'],
            'files.*' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-zip-compressed',
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
