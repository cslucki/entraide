<?php

use App\Models\Loop;
use App\Models\LoopMember;
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
