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
                // `mimetypes:` compare le type devine par finfo SUR LE CONTENU. Un
                // .xls ancien (conteneur OLE2) sort selon les versions de
                // libmagic en `application/vnd.ms-excel`, en
                // `application/x-ole-storage` ou en `application/CDFV2` : les
                // trois designent le meme classeur, les deux derniers manquaient
                // et faisaient echouer ce seul fichier au milieu d'un import.
                // TASK-1684 : l'audio et la VIDEO manquaient, alors que l'ecran
                // Dossiers propose deja « Filmer une video » et « Prendre une
                // photo » (`handleMediaFiles`, `accept="video/*"`). L'interface
                // offrait donc un geste que le serveur refusait — rapporte en
                // recette sur une video de moins de 10 Mo.
                //
                // Les doublons ne sont pas du bruit : `mimetypes:` compare le
                // type devine par finfo SUR LE CONTENU, et un meme media sort
                // sous plusieurs noms selon la version de libmagic — un .wav en
                // `audio/wav` ou `audio/x-wav`, un .m4a en `audio/mp4`, parfois
                // `audio/x-m4a`. Omettre un alias fait echouer ce seul fichier.
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/markdown,text/csv,application/vnd.ms-excel,application/x-ole-storage,application/CDFV2,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-zip-compressed,audio/mpeg,audio/mp3,audio/ogg,audio/vorbis,audio/wav,audio/x-wav,audio/vnd.wave,audio/webm,audio/mp4,audio/x-m4a,audio/aac,audio/flac,audio/x-flac,video/mp4,video/quicktime,video/webm,video/ogg,video/x-msvideo,video/x-matroska,video/mpeg,video/3gpp',
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
