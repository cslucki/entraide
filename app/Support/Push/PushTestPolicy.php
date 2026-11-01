<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * TASK-1740 — QUI peut recevoir une notification de test, et sur quel appareil.
 *
 * ## Pourquoi tout tient dans UNE classe
 *
 * MASTER a pose trois bornes distinctes — comptes de recette seulement,
 * abonnements crees localement seulement, revalidation au POST. Eparpillees
 * dans le controleur, elles auraient ete trois `if` qu'une refonte d'ecran
 * peut defaire un par un sans que rien ne rougisse.
 *
 * Ici, la liste des destinataires et le filtre d'envoi sont la MEME regle,
 * exprimee une fois. L'ecran ne peut pas proposer quelqu'un que l'envoi
 * refuserait, et l'envoi ne peut pas servir quelqu'un que l'ecran n'aurait pas
 * propose.
 *
 * ## Le discriminant est le TLD `.test`, et c'est verifiable
 *
 * Le jumeau `bouclepro_prod_work` porte de VRAIES adresses — mesure du 01/11 :
 * 21 comptes `gmail.com`, plus yahoo, icloud, orange, hotmail. A cote vivent
 * les comptes de recette en `bouclepro.test` et `test.test`.
 *
 * `.test` est un TLD RESERVE par la RFC 2606 : il ne peut pas etre enregistre,
 * donc aucune adresse reelle ne peut en porter une. Le filtre ne repose ainsi
 * pas sur une liste a tenir a jour — qui aurait oublie un compte un jour —
 * mais sur une propriete que personne ne peut contourner.
 *
 * Une liste explicite reste admise par configuration, pour un compte de
 * recette qui ne serait pas en `.test`.
 */
class PushTestPolicy
{
    /**
     * Les destinataires admis a recevoir un test.
     *
     * @return Builder<User>
     */
    public function destinatairesAdmis(): Builder
    {
        $explicites = $this->comptesExplicites();

        return User::query()
            ->where(function (Builder $q) use ($explicites): void {
                // Le TLD reserve : `…@quelque.chose.test`.
                $q->whereRaw('LOWER(email) LIKE ?', ['%.test']);

                if ($explicites !== []) {
                    $q->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(email)'), $explicites);
                }
            })
            ->orderBy('email');
    }

    /** Ce compte peut-il recevoir un test ? */
    public function destinataireEstAdmis(?User $utilisateur): bool
    {
        if ($utilisateur === null) {
            return false;
        }

        $email = mb_strtolower((string) $utilisateur->email);

        return str_ends_with($email, '.test') || in_array($email, $this->comptesExplicites(), true);
    }

    /**
     * Les abonnements envoyables pour ce destinataire.
     *
     * Deux conditions, et la seconde est celle que MASTER a demandee :
     * l'abonnement doit avoir ete CREE sur cet environnement. Un abonnement
     * importe d'un dump de production porte `production` et ne passe pas ;
     * une ligne anterieure a cette TASK porte `null`, c'est-a-dire « origine
     * inconnue », et ne passe pas non plus.
     *
     * Un `null` ne vaut PAS « locale ». C'est un cout reel — les abonnements
     * pris avant cette TASK doivent etre refaits — et il est prefere a une
     * valeur par defaut qui mentirait.
     *
     * @return Builder<PushSubscription>
     */
    public function abonnementsEnvoyables(User $destinataire): Builder
    {
        return PushSubscription::query()
            ->where('user_id', $destinataire->getKey())
            ->where('created_env', (string) config('app.env'))
            // TASK-1740 — ecarter les abonnements crees avec une clef VAPID
            // qui n'a plus cours. Le fournisseur les accepterait (`201`) et le
            // navigateur jetterait le message EN SILENCE : les proposer a
            // l'envoi, c'est promettre une livraison qui n'aura pas lieu.
            //
            // Un `vapid_key_hash` a `null` reste admis : il signifie « cree
            // avant que cette colonne existe », pas « suspect ».
            ->where(function ($q): void {
                $q->whereNull('vapid_key_hash')
                    ->orWhere('vapid_key_hash', PushSubscription::empreinteCleVapid());
            })
            ->orderByDesc('last_seen_at');
    }

    /**
     * Un reperage lisible de l'appareil, SANS jamais rendre l'endpoint complet
     * ni aucune clef.
     *
     * MASTER : « ne jamais afficher l'endpoint complet ni les cles ». Un
     * endpoint est un jeton de reveil : qui le detient, avec les clefs, peut
     * deposer une notification. L'ecran n'en montre donc que le fournisseur et
     * une empreinte courte, suffisante pour distinguer deux appareils.
     *
     * @return array{fournisseur: string, empreinte: string}
     */
    public function reperageDe(PushSubscription $abonnement): array
    {
        return [
            'fournisseur' => (string) parse_url($abonnement->endpoint, PHP_URL_HOST),
            // Les 8 premiers caracteres de l'empreinte SHA-256 deja stockee :
            // rien de nouveau n'est calcule, et rien de reversible n'est
            // montre.
            'empreinte' => mb_substr((string) $abonnement->endpoint_hash, 0, 8),
        ];
    }

    /** @return list<string> */
    private function comptesExplicites(): array
    {
        $brut = (string) config('webpush.test_recipients', '');

        return array_values(array_filter(array_map(
            static fn (string $e): string => mb_strtolower(trim($e)),
            explode(',', $brut),
        )));
    }
}
