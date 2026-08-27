<?php

namespace App\Support\Ai;

use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TASK-1315 — le fil du Shell, et la seule porte pour l'ecrire ou le lire.
 *
 * C'est CE fil qui survit a la navigation : chaque page est un rechargement
 * complet Laravel — aucune SPA, aucun `wire:navigate` — donc le fil ne peut
 * vivre que cote serveur. Ce qui le rend continu est son `conversation_id`,
 * RETROUVE (jamais recu du client) a chaque montage : meme identifiant, fil
 * relu, PageContext recalcule sur la nouvelle requete. Il est borne — `ai.shell.max_thread_messages` — et
 * l'utilisateur peut l'effacer. Rien n'est resume, rien n'est rappele d'un fil
 * a l'autre : « memoire avancee » reste hors V1.
 *
 * Organization = Tenant : toutes les lectures passent par `forThread()`, qui
 * porte le couple `(organization_id, user_id)`. Changer d'Organization change
 * de fil, sans qu'aucun appelant ait a y penser.
 */
final class AiShellThread
{
    /**
     * Les derniers messages du fil, du plus ancien au plus recent.
     *
     * @return Collection<int, AiShellMessage>
     */
    public function messages(Organization $organization, User $user, ?int $limit = null): Collection
    {
        $limit ??= $this->limit();

        return AiShellMessage::query()
            ->forThread((string) $organization->id, (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * L'identifiant de conversation en cours — celui du dernier message du fil.
     *
     * Il est LU en base, jamais accepte du client : un identifiant fourni de
     * l'exterieur ferait du numero de conversation une cle d'acces, et le Shell
     * n'en a aucune. Un fil vide en ouvre un nouveau ; effacer le fil en ouvre
     * donc un nouveau aussi.
     */
    public function currentConversationId(Organization $organization, User $user): string
    {
        return $this->persistedConversationId($organization, $user) ?? (string) Str::uuid();
    }

    /** L'identifiant reellement inscrit dans le fil, ou null s'il est vide. */
    private function persistedConversationId(Organization $organization, User $user): ?string
    {
        $latest = AiShellMessage::query()
            ->forThread((string) $organization->id, (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('conversation_id');

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    public function isEmpty(Organization $organization, User $user): bool
    {
        return ! AiShellMessage::query()
            ->forThread((string) $organization->id, (string) $user->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    /**
     * @param  string|null  $conversationId  identifiant a utiliser SI le fil est
     *                                       vide — celui que la surface montre
     *                                       deja, pour que le premier tour ne
     *                                       change pas de conversation sous les
     *                                       yeux de l'utilisateur. Un fil qui
     *                                       existe garde toujours le sien.
     * @param  array<string, mixed>  $metadata
     */
    public function appendUser(
        Organization $organization,
        User $user,
        string $content,
        array $metadata = [],
        ?string $conversationId = null,
    ): AiShellMessage {
        return $this->append($organization, $user, AiShellMessage::ROLE_USER, $content, null, $metadata, $conversationId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function appendAssistant(
        Organization $organization,
        User $user,
        string $content,
        AiShellMessage $replyTo,
        array $metadata = [],
    ): AiShellMessage {
        // La reponse appartient a la conversation de son declencheur, jamais a
        // une conversation recalculee entre-temps.
        return $this->append(
            $organization,
            $user,
            AiShellMessage::ROLE_ASSISTANT,
            $content,
            $replyTo->id,
            $metadata,
            (string) $replyTo->conversation_id,
        );
    }

    /**
     * La reponse de CE declencheur, si elle existe deja (T1311 : l'idempotence
     * traite le rejeu, pas la course).
     */
    public function answerFor(AiShellMessage $trigger): ?AiShellMessage
    {
        return AiShellMessage::query()
            ->where('reply_to_id', $trigger->id)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->first();
    }

    /** Efface le fil de cet utilisateur dans CETTE Organization, et lui seul. */
    public function clear(Organization $organization, User $user): int
    {
        return AiShellMessage::query()
            ->forThread((string) $organization->id, (string) $user->id)
            ->delete();
    }

    public function limit(): int
    {
        return max(2, (int) config('ai.shell.max_thread_messages', 40));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function append(
        Organization $organization,
        User $user,
        string $role,
        string $content,
        ?string $replyToId,
        array $metadata,
        ?string $conversationId,
    ): AiShellMessage {
        $message = AiShellMessage::query()->create([
            'organization_id' => (string) $organization->id,
            'user_id' => (string) $user->id,
            // Un fil qui existe impose SON identifiant : la valeur proposee
            // n'est qu'une graine pour un fil vide, jamais un moyen de
            // rebrancher des messages sur une autre conversation.
            'conversation_id' => $this->persistedConversationId($organization, $user)
                ?? ($conversationId ?: (string) Str::uuid()),
            'role' => $role,
            'content' => $content,
            'reply_to_id' => $replyToId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $this->prune($organization, $user);

        return $message->refresh();
    }

    /**
     * Le fil ne grandit pas indefiniment. On coupe au-dela d'une marge
     * confortable au-dessus de la fenetre affichee, pour qu'un elagage ne
     * puisse jamais amputer ce que l'utilisateur vient de lire.
     */
    private function prune(Organization $organization, User $user): void
    {
        $keep = $this->limit() * 2;

        $ids = AiShellMessage::query()
            ->forThread((string) $organization->id, (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($keep)
            ->limit(50)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            AiShellMessage::query()->whereIn('id', $ids)->delete();
        }
    }
}
