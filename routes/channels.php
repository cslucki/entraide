<?php

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes();

Broadcast::channel('loop.{loopId}', function ($user, string $loopId) {
    $loop = Loop::find($loopId);

    if (! $loop) {
        return false;
    }

    $isActiveMember = LoopMember::where('loop_id', $loopId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();

    if (! $isActiveMember) {
        return false;
    }

    $orgId = $user->organization_id;
    if ($loop->organization_id !== $orgId) {
        return false;
    }

    return ['id' => $user->id];
});

// TASK-1843 — canal PRIVE PAR UTILISATEUR, jamais instancie avant cette
// TASK (audit TASK-1842, section 3.2). Porte le signal « badge de
// notifications internes a rafraichir » (`MemberNotificationCreated`).
//
// Identite : SEUL le proprietaire du canal peut s'y abonner — comparaison
// stricte de chaines, les deux cotes etant des UUID (`HasUuids`).
//
// Organization : une Organization est verifiee en plus de l'identite, pas
// par defense en profondeur decorative mais parce qu'un compte dont
// l'Organization a ete supprimee (`organization_id` devient NULL) ne doit
// plus recevoir AUCUN signal temps reel — meme motif que
// `MemberNotification::forRecipient()`, qui retombe sur une lecture vide
// hors requete HTTP plutot que de faire confiance a un etat perime.
//
// `$user === null` est verifie EXPLICITEMENT : pour un canal prive,
// `PusherBroadcaster::auth()` refuse deja toute requete non authentifiee
// AVANT d'appeler ce callback — mais `Broadcaster::verifyUserCanAccessChannel()`
// (classe de base) n'offre pas la meme garantie pour tout driver, et cette
// fermeture ne doit jamais presumer d'un appelant particulier.
Broadcast::channel('user.{userId}', function ($user, string $userId) {
    if ($user === null) {
        return false;
    }

    if ((string) $user->id !== $userId) {
        return false;
    }

    return $user->organization_id !== null;
});

// TASK-1845 — canal PRIVE PAR CONVERSATION, jamais instancie avant cette
// TASK. Porte le signal « un message vient d'etre ecrit, resynchroniser »
// (`ConversationMessageCreated`) — aucun contenu de conversation dans la
// reponse d'autorisation, qui ne porte que l'identite de l'abonne.
//
// Autorisation EXCLUSIVEMENT serveur : la transaction DOIT exister (une
// conversation supprimee, ou un identifiant invalide, refuse exactement
// comme `loop.{loopId}` refuse une Boucle absente) ; l'utilisateur DOIT
// etre partie a l'echange (`buyer_id` OU `seller_id`) ; son
// `organization_id` COURANT doit correspondre a celui DE LA TRANSACTION
// elle-meme — jamais a `currentOrganization()`, pour la meme raison que
// `NewMessageReceived::urlDeLaConversation()` (TASK-1795) ne s'y est
// jamais fiee : une autorisation de canal n'a pas de contexte de requete
// HTTP fiable, et `currentOrganization()` ne pourrait de toute facon pas
// remplacer une verification sur la ligne elle-meme.
//
// `withoutGlobalScope` : `BelongsToOrganizationScope` filtre sur
// `currentOrganization()`, absent ici par construction — sans ce retrait,
// CHAQUE abonnement serait refuse, y compris pour les deux parties
// legitimes.
//
// LIMITE DOCUMENTEE (mandat, section 5) : ce controle s'execute au moment
// de l'ABONNEMENT, pas en continu. `buyer_id`/`seller_id` sont immuables
// une fois la Transaction creee — aucune revocation de participation
// n'existe aujourd'hui pour cette ressource — mais si un mecanisme futur
// les rendait mutables, un socket deja ouvert continuerait de recevoir le
// signal jusqu'a la prochaine (re)connexion Reverb. Aucune garantie de
// confidentialite ne doit reposer sur ce seul controle d'abonnement : la
// lecture reelle de la conversation reste, elle, protegee a CHAQUE requete
// HTTP par `TransactionPolicy::view()`, inchangee par cette TASK.
Broadcast::channel('conversation.{transactionId}', function ($user, string $transactionId) {
    if ($user === null) {
        return false;
    }

    $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
        ->find($transactionId);

    if ($transaction === null) {
        return false;
    }

    if ($user->id !== $transaction->buyer_id && $user->id !== $transaction->seller_id) {
        return false;
    }

    if ($user->organization_id === null || $user->organization_id !== $transaction->organization_id) {
        return false;
    }

    return ['id' => $user->id];
});
