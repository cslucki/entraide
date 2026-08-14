<?php

namespace App\Services;

use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopManifestoSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manifesto publication, and its links to Dossiers documents.
 *
 * Two things are deliberately *not* here: the Manifesto text itself, which is a
 * BlogPost and keeps the Blog editor and its permissions, and the documents,
 * which keep living in Dossiers. This service only owns the designation, the
 * publication transition and the links.
 */
class LoopManifestoService
{
    public const RESULT_ATTACHED = 'attached';

    public const RESULT_ALREADY_ATTACHED = 'already_attached';

    public const RESULT_CROSS_TENANT = 'cross_tenant';

    public const RESULT_NOT_FOUND = 'not_found';

    /**
     * Le document existe, dans la bonne Organization — mais hors du Dossier
     * racine de la Boucle. Le referentiel documentaire d'une Boucle est son
     * Dossier partage : une Card de Boucle ne fait pas entrer un fichier d'un
     * Dossier prive dans le contexte partage. La copie prive -> partage est une
     * fonction future, volontairement absente d'ici.
     */
    public const RESULT_OUTSIDE_ROOT_DOSSIER = 'outside_root_dossier';

    /**
     * Link a Dossiers document as a source of this Loop's Manifesto.
     *
     * No file is copied, moved or re-owned: this writes one row pointing at the
     * document where it already lives.
     *
     * @return array{result: string, source: ?LoopManifestoSource}
     */
    public function attachSource(Loop $loop, string $dossierFileId, User $actor): array
    {
        return DB::transaction(function () use ($loop, $dossierFileId, $actor) {
            $file = DossierFile::find($dossierFileId);

            if (! $file) {
                return ['result' => self::RESULT_NOT_FOUND, 'source' => null];
            }

            // Tenant strictness, checked server-side rather than trusted from the
            // posted id: a document of another Organization is never linkable.
            if ($file->organization_id !== $loop->organization_id) {
                return ['result' => self::RESULT_CROSS_TENANT, 'source' => null];
            }

            // Et le perimetre produit, verifie au meme endroit : seul un
            // document du Dossier racine de la Boucle peut devenir source. Le
            // selecteur ne montre que ceux-la, mais un id forge ne doit pas
            // faire mieux que le selecteur.
            if ($file->dossier_id !== $this->rootDossierId($loop)) {
                return ['result' => self::RESULT_OUTSIDE_ROOT_DOSSIER, 'source' => null];
            }

            $existing = LoopManifestoSource::where('loop_id', $loop->id)
                ->where('dossier_file_id', $file->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['result' => self::RESULT_ALREADY_ATTACHED, 'source' => $existing];
            }

            return [
                'result' => self::RESULT_ATTACHED,
                'source' => LoopManifestoSource::create([
                    'organization_id' => $loop->organization_id,
                    'loop_id' => $loop->id,
                    'dossier_file_id' => $file->id,
                    'added_by' => $actor->id,
                ]),
            ];
        });
    }

    /** Removes the link only — the document itself is untouched. */
    public function detachSource(Loop $loop, string $dossierFileId): bool
    {
        return LoopManifestoSource::where('loop_id', $loop->id)
            ->where('dossier_file_id', $dossierFileId)
            ->delete() > 0;
    }

    /**
     * Sources still resolvable, newest first.
     *
     * A document deleted in Dossiers takes its link with it (cascade), but a row
     * whose file has otherwise become unreadable is filtered here rather than
     * allowed to break the card.
     *
     * @return Collection<int, LoopManifestoSource>
     */
    public function sourcesFor(Loop $loop)
    {
        return $loop->manifestoSources()
            ->with(['dossierFile.dossier', 'addedBy'])
            ->latest()
            ->get()
            ->filter(fn (LoopManifestoSource $s) => $s->dossierFile !== null
                && $s->dossierFile->organization_id === $loop->organization_id)
            ->values();
    }

    /**
     * Publish the Manifesto. Always an explicit human action — nothing here is
     * ever triggered by an edit or by a background process.
     */
    public function publish(Loop $loop): bool
    {
        $manifesto = $loop->manifesto;

        if (! $manifesto || $manifesto->status === 'published') {
            return false;
        }

        $manifesto->update([
            'status' => 'published',
            'published_at' => $manifesto->published_at ?? now(),
        ]);

        return true;
    }

    /** Back to draft: immediately removes it from any public presentation. */
    public function unpublish(Loop $loop): bool
    {
        $manifesto = $loop->manifesto;

        if (! $manifesto || $manifesto->status !== 'published') {
            return false;
        }

        $manifesto->update(['status' => 'draft']);

        return true;
    }

    /**
     * Candidate documents: what lives in this Loop's root Dossier and is not
     * linked yet — and nothing else.
     *
     * Le perimetre etait « tous les Dossiers de l'Organization », ce qui
     * offrait au selecteur les fichiers des **Dossiers prives** de chacun. Le
     * referentiel documentaire d'une Boucle est son Dossier partage : c'est de
     * la que viennent les sources. attachSource() applique la meme regle cote
     * ecriture — le selecteur et la porte disent la meme chose.
     */
    public function candidateFiles(Loop $loop, int $limit = 100)
    {
        $racineId = $this->rootDossierId($loop);

        if ($racineId === null) {
            return collect();
        }

        $linked = LoopManifestoSource::where('loop_id', $loop->id)->pluck('dossier_file_id');

        return DossierFile::where('organization_id', $loop->organization_id)
            ->where('dossier_id', $racineId)
            ->whereNotIn('id', $linked)
            ->with('dossier')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /** L'identifiant du Dossier racine de la Boucle, s'il existe deja. */
    private function rootDossierId(Loop $loop): ?string
    {
        return \App\Models\Dossier::where('loop_id', $loop->id)->value('id');
    }
}
