<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Archiver et reactiver une Boucle — le seul endroit qui ecrit `loops.status`.
 *
 * Trois ecrans faisaient la meme mutation a la main : la page plateforme, la
 * page Organization, et bientot le workspace. Deux d'entre elles ne verifiaient
 * aucune permission, et aucune ne tracait l'auteur. La regle metier vit ici
 * desormais, et les controleurs se contentent de l'appeler.
 *
 * Archiver n'est jamais supprimer : rien n'est efface, ni membres, ni Cards, ni
 * Dossier racine, ni document racine, ni Roadmap, ni historique ChatLoop. La
 * reactivation ne recree rien et ne rejoue aucun preset — la composition
 * retrouvee est exactement celle qu'on avait laissee.
 */
class LoopLifecycleService
{
    public const RESULT_OK = 'ok';

    public const RESULT_DENIED = 'denied';

    public const RESULT_ALREADY = 'already';

    public function __construct(private LoopPermissionResolver $permissions) {}

    /**
     * Qui peut archiver ou reactiver : proprietaire actif, Admin de
     * l'Organization, super-admin.
     *
     * Deleguee au resolveur, qui connait deja ces trois autorites — et pas
     * reecrite ici, sinon une Organization qui affine ses regles verrait le
     * workspace et l'administration diverger.
     */
    public function canArchive(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'loops.archive');
    }

    /**
     * Ce que l'archivage va toucher, pour l'annoncer avant de le faire.
     *
     * `last_active` est la question qui merite une confirmation renforcee :
     * l'Organization se retrouverait sans aucune Boucle active.
     *
     * @return array<string, int|bool>
     */
    public function impactOf(Loop $loop): array
    {
        $siblings = Loop::where('organization_id', $loop->organization_id)
            ->where('status', 'active')
            ->whereKeyNot($loop->id)
            ->count();

        return [
            'members' => $loop->activeMembers()->count(),
            'messages' => $loop->messages()->count(),
            'cards' => $loop->cards()->where('enabled', true)->count(),
            'last_active' => $loop->isActive() && $siblings === 0,
        ];
    }

    /**
     * @return self::RESULT_*
     */
    public function archive(User $user, Loop $loop): string
    {
        if (! $this->canArchive($user, $loop)) {
            return self::RESULT_DENIED;
        }

        return DB::transaction(function () use ($user, $loop) {
            // Relu sous verrou : deux archivages concurrents ne doivent pas
            // ecraser la trace du premier.
            $fresh = Loop::whereKey($loop->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->isArchived()) {
                return self::RESULT_ALREADY;
            }

            $fresh->update([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $user->id,
            ]);

            $loop->forceFill($fresh->only(['status', 'archived_at', 'archived_by']));

            return self::RESULT_OK;
        });
    }

    /**
     * @return self::RESULT_*
     */
    public function reactivate(User $user, Loop $loop): string
    {
        if (! $this->canArchive($user, $loop)) {
            return self::RESULT_DENIED;
        }

        return DB::transaction(function () use ($loop) {
            $fresh = Loop::whereKey($loop->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->isActive()) {
                return self::RESULT_ALREADY;
            }

            // La trace est effacee : elle decrit l'archivage en cours, et il n'y
            // en a plus. Rien d'autre n'est touche — aucun preset rejoue, aucune
            // Card modifiee, aucun document recree.
            $fresh->update([
                'status' => 'active',
                'archived_at' => null,
                'archived_by' => null,
            ]);

            $loop->forceFill($fresh->only(['status', 'archived_at', 'archived_by']));

            return self::RESULT_OK;
        });
    }

    /**
     * La garde de lecture seule, pour les chemins d'ecriture qui n'interrogent
     * pas le resolveur de permissions.
     *
     * Ils sont peu nombreux et recenses : l'envoi d'un message ChatLoop, la
     * creation d'une action de Roadmap par un membre simple, le depot d'un
     * fichier dans le Dossier racine. Tout le reste passe par le resolveur, qui
     * refuse deja les ecritures sur une Boucle archivee — cette methode ne
     * duplique donc pas la regle, elle la porte la ou le resolveur n'est pas
     * appele.
     */
    public function assertWritable(Loop $loop): void
    {
        if ($loop->isArchived()) {
            throw new LoopArchivedException(__('loops.archive_read_only'));
        }
    }

    public function isWritable(Loop $loop): bool
    {
        return ! $loop->isArchived();
    }
}
