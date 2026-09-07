<?php

namespace App\Services\GuestShell;

use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1445 — SW-11 : le claim Guest → User (Shell Welcome V3 §22).
 *
 * Jamais une auto-creation de User : l'inscription reste l'inscription. Le
 * claim n'a lieu qu'APRES identite et Organization verifiees (email verifie),
 * pour le visiteur pseudonyme de LA MEME Organization que le compte (mono-
 * Organization preservee). Un cookie d'une autre Organization ne resout rien
 * ici : fail-closed silencieux, rien n'est revele. La conversation, les
 * messages et l'attribution (referrer, utm, shortcut) sont CONSERVES et
 * rattaches au compte.
 *
 * Apres le claim, l'ancienne cle ne resout plus rien : le hash du visiteur
 * est brule (remplace par une cle jamais emise) et le navigateur recoit une
 * cle neuve (`rotate()`), vierge de toute identite.
 *
 * Non `final` a dessein : la preuve « un echec de claim ne casse jamais une
 * verification » remplace ce service par un double qui explose.
 */
class GuestClaimService
{
    public function __construct(private readonly GuestVisitorResolver $visitors) {}

    /** @return GuestVisitor|null le visiteur claime, ou null quand il n'y avait rien a claimer */
    public function claim(User $user, Request $request): ?GuestVisitor
    {
        if ($user->organization_id === null || $user->email_verified_at === null) {
            return null;
        }

        $organization = $user->organization;
        if ($organization === null) {
            return null;
        }

        // find() est scope a l'Organization du compte : un cookie d'un autre tenant est NULL ici, sans un mot.
        $visitor = $this->visitors->find($request, $organization);
        if ($visitor === null || $visitor->isClaimed()) {
            return null;
        }

        $claimed = DB::transaction(function () use ($visitor, $user): GuestVisitor {
            $now = now();
            $visitor->forceFill([
                'claimed_user_id' => $user->getKey(),
                'claimed_at' => $now,
                // La cle d'avant ne resout plus rien : brulee, jamais emise.
                'visitor_key_hash' => GuestVisitorResolver::hash(Str::random(GuestVisitorResolver::KEY_LENGTH)),
            ])->save();

            GuestConversation::query()
                ->where('guest_visitor_id', $visitor->getKey())
                ->whereNull('claimed_user_id')
                ->update(['claimed_user_id' => $user->getKey(), 'claimed_at' => $now, 'updated_at' => $now]);

            return $visitor->fresh();
        });

        // Le cookie ne tourne qu'APRES la transaction reussie (MASTER Q73) : si le claim
        // echoue, l'ancien Guest reste resolvable et le navigateur garde sa cle.
        $this->visitors->rotate();

        return $claimed;
    }
}
