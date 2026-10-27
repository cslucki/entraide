<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Transaction;
use App\Services\Dossiers\DossierFileTransport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-1704 — la SEULE porte vers une piece jointe de conversation.
 *
 * Cyril a tranche : « disque prive + route gardee ». Le disque `message_files`
 * ne porte ni `serve` ni `url` : aucune URL ne mene au fichier en dehors d'ici.
 *
 * ## Les trois gardes, et pourquoi aucune ne suffit
 *
 * 1. **`authorize('view', $transaction)`** — `TransactionPolicy::view()` exige
 *    DEUX choses : que l'echange appartienne a l'Organization courante, et que
 *    le demandeur en soit l'acheteur ou le vendeur. C'est exactement la garde
 *    qui protege la conversation elle-meme (`MessageController::show()`) : une
 *    piece jointe ne doit pas etre plus accessible que le fil qui la porte.
 *
 * 2. **Le message appartient-il a CET echange ?** Sans cette verification, les
 *    deux identifiants seraient independants : il suffirait d'etre partie a
 *    une conversation quelconque pour en citer une autre et en lire les
 *    pieces. La garde 1 ne l'attrape pas — elle ne regarde que l'echange
 *    nomme dans l'URL, celui dont on est bien membre.
 *
 * 3. **Le message porte-t-il un fichier ?** Un 404 franc, plutot qu'une
 *    exception de lecture rattrapee plus loin.
 *
 * ## Les codes rendus, et pourquoi ils different
 *
 * La garde 1 est DELEGUEE a la policy, donc elle rend ce que rend deja
 * `MessageController::show()` : **403** pour un membre du tenant qui n'est pas
 * partie a l'echange, **404** pour une conversation d'un autre tenant
 * (`resourceBelongsToCurrentOrganization`). Une piece jointe repond ainsi
 * exactement comme le fil qui la porte — inventer ici une seconde doctrine
 * aurait donne deux reponses a la meme question.
 *
 * Les gardes 2 et 3, elles, rendent **404** : il ne s'agit plus d'un droit
 * refuse mais d'un objet qui n'existe pas DANS cet echange.
 */
class MessageAttachmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private DossierFileTransport $transport) {}

    /** Telecharger la piece. */
    public function download(Transaction $transaction, Message $message): RedirectResponse|StreamedResponse
    {
        $message = $this->piece($transaction, $message);

        return $this->transport->telechargerDepuis(
            $message->file_disk,
            $message->file_path,
            $message->fileDisplayName(),
            $message->file_mime,
        );
    }

    /** L'ouvrir dans l'onglet, quand le navigateur sait la rendre. */
    public function preview(Transaction $transaction, Message $message): RedirectResponse|StreamedResponse
    {
        $message = $this->piece($transaction, $message);

        return $this->transport->apercuDepuis(
            $message->file_disk,
            $message->file_path,
            $message->fileDisplayName(),
            $message->file_mime,
        );
    }

    /** Les trois gardes, dans l'ordre, avant tout acces au disque. */
    private function piece(Transaction $transaction, Message $message): Message
    {
        $this->authorize('view', $transaction);

        abort_if((string) $message->transaction_id !== (string) $transaction->id, 404);
        abort_if(! $message->hasFile(), 404);

        // Le disque est celui ECRIT avec la piece, mais il doit rester un
        // disque que l'application connait : une valeur inattendue en base ne
        // doit pas devenir un chemin de lecture arbitraire.
        abort_if(config("filesystems.disks.{$message->file_disk}") === null, 404);

        return $message;
    }

    /** Les jumeaux prefixes : `{organization}` arrive en PREMIER argument. */
    public function orgDownload(string $org, Transaction $transaction, Message $message): RedirectResponse|StreamedResponse
    {
        return $this->download($transaction, $message);
    }

    public function orgPreview(string $org, Transaction $transaction, Message $message): RedirectResponse|StreamedResponse
    {
        return $this->preview($transaction, $message);
    }
}
