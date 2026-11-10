@props([
    'type' => 'received',
    'time' => null,
    'avatar' => null,
    // TASK-1772 (recette Cyril, 06/11) — l'avatar sort de la bulle et prend
    // sa gouttiere a gauche, comme WhatsApp. Une PROPRIETE et non un choix
    // impose : ce composant sert aussi la Messagerie, qui est un fil a DEUX
    // et ou un avatar par message serait du bruit. Le ChatLoop l'allume, la
    // Messagerie garde sa mise en page.
    'detachedAvatar' => false,
    // L'identite derriere l'avatar, pour l'apercu d'annuaire au clic. `null`
    // laisse l'avatar purement decoratif — c'est le cas quand l'auteur n'est
    // pas affichable dans l'Organization courante.
    'avatarUserId' => null,
    'name' => null,
    'subtitle' => null,
    'isAi' => false,
    'class' => '',
    'replyTo' => null,
    'messageId' => null,
    'showReplyButton' => false,
    'imagePath' => null,
    // TASK-1704 — une piece jointe qui n'est pas une image. Tableau
    // {name, size, mime, downloadUrl, previewUrl}, ou null. Les URL sont
    // calculees cote appelant : cette bulle sert aussi les ChatLoops, qui ne
    // nomment pas les memes routes.
    'attachment' => null,
    'urlPreview' => null,
    'showPinButton' => false,
    'showEditButton' => false,
    'showDeleteButton' => false,
    'showCopyButton' => false,
    'isPinned' => false,
    'isEdited' => false,
    'showReactions' => false,
    // TASK-1772 — les noms EXACTS mentionnes dans ce message, deja resolus
    // par l'appelant. La bulle ne cherche aucun nom : on les lui donne, sans
    // quoi chaque bulle ferait sa requete (N+1 sur un fil de trente).
    'mentionNames' => [],
    // TASK-1801 correctif 03 — `nom => identifiant`, pour les SEULES mentions
    // qui designent une vraie personne (le jeton `@tous` n'y figure jamais :
    // rien a ouvrir pour lui). Meme provenance que `mentionNames`, deja en
    // memoire cote appelant — aucune requete de plus.
    'mentionUserIds' => [],
    'reactionCounts' => [],
    'myReaction' => null,
    'sources' => null,
    // TASK-1309 : documents dont le CONTENU a ete lu sans qu'aucune citation
    // valide n'en sorte. Rendus sous « Sources consultées », et seulement
    // quand il n'y a aucune source utilisee — jamais melanges aux deux.
    'consultedSources' => null,
    // TASK-1312 : moteur de la reponse — `llm`, `rag` ou `llm_rag`. Vient de
    // `metadata['ai_mode']`, la provenance canonique du message : JAMAIS deduit
    // d'une couleur, d'une classe CSS ou du texte de la bulle. `null` sur toute
    // bulle humaine — il n'y a pas de badge « Humain ».
    'aiMode' => null,
    // TASK-1619 — un libelle de badge EXPLICITE, quand le mode seul ne suffit
    // pas a le nommer. Les « 3 assistants IA » partagent un mode (`multi_ai`)
    // et se distinguent par leur assistant : la carte fermee ci-dessous ne
    // peut pas le savoir, et l'elargir aurait demande d'y faire entrer une
    // notion qui n'est pas un mode.
    'aiModeLabel' => null,
    // TASK-1621 — la CLE technique du role « Pour / Contre » (`aperio` /
    // `traverse`), lue dans `metadata['assistant_key']`. Elle donne sa couleur
    // a la bulle. Jamais deduite du libelle : celui-ci est traduit, il change
    // de langue, et une couleur ne doit pas dependre d'une locale.
    'aiAssistantKey' => null,
    // TASK-1621 — le modele qui a reellement repondu (`metadata['model']`),
    // affiche DISCRETEMENT a cote du badge. Un slug entier serait du jargon :
    // seule la famille est rendue, sans le fournisseur ni le suffixe de
    // palier. `null` sur toute bulle qui n'en porte pas.
    'aiModel' => null,
    // TASK-1621 — la reponse a-t-elle ete COUPEE ? Vient de
    // `metadata['partial']`, ecrit par le publisher quand le modele a ete
    // arrete par son budget de sortie ou par notre plafond de caracteres.
    // Ne JAMAIS le deduire de la longueur du texte ou d'un point final
    // manquant : une bulle ne doit pas diagnostiquer sa propre troncature.
    'aiTruncated' => false,
    // TASK-1621 — la largeur du conteneur, surchargeable.
    //
    // Par defaut, une bulle plafonne pour ne pas s'etaler sur tout le fil.
    // Dans la carte de debat « Pour / Contre », elle occupe au contraire une
    // COLONNE de grille : le plafond la raboterait a l'interieur d'un espace
    // deja borne. Une prop, pas un refactor.
    // TASK-1772 (recette Cyril, 06/11) — « les bulles sont trop larges et du
    // coup, dans certains cas, on ne voit plus l'icone pour les reactions ».
    // Le bouton ☺ est pose 1,75 rem HORS de la bulle, et la liste coupe ce qui
    // deborde depuis la recette 6 (`overflow-x-hidden`) : une bulle a 90 % du
    // fil, dans une rangee qui porte deja la gouttiere d'avatar, le poussait
    // hors champ. 78 % lui laisse sa place sur tout telephone ; au-dela de
    // `sm`, les bornes fixes gouvernent deja et ne changent pas.
    'widthClass' => 'max-w-[78%] sm:max-w-md md:max-w-lg',
    // TASK-1316 : l'humain qui a demande cette reponse IA — ['id' => ..., 'name' => ...],
    // lu depuis `metadata.requested_by`, JAMAIS reconstruit en analysant un texte.
    // `null` sur toute bulle qui n'est pas une reponse IA a une demande nommee.
    'requestedBy' => null,
    // Badge sur la bulle HUMAINE elle-meme : le mode IA/documentaire choisi au
    // moment de l'envoi (`metadata['requested_mode']`), pas le toggle courant
    // du composeur. Vient de `LoopMessage.metadata['requested_mode']`, ecrit
    // par LoopChat::sendMessage AVANT toute generation. `null` sur tout
    // message envoye en mode normal — pas de badge « Normal ».
    'requestedMode' => null,
])

@php
$isSent = $type === 'sent';

// TASK-1772 — `items-start` et `gap` servent la gouttiere d'avatar. Ils sont
// poses dans les DEUX cas : une rangee sans gouttiere n'a rien a aligner ni a
// espacer, et deux listes de classes divergentes finiraient par diverger pour
// de bon.
$containerClasses = $isSent
    ? 'flex items-start gap-2 justify-end'
    : 'flex items-start gap-2 justify-start';

// L'avatar ne se detache que du cote RECU. Sur ses propres messages, on sait
// qui parle — WhatsApp ne les decore pas davantage, et la gouttiere y mangerait
// de la largeur de texte pour ne rien dire.
$_avatarDetache = $detachedAvatar && ! $isSent && ($avatar !== null || $isAi);

// TASK-1621 — les bulles « Pour / Contre » ont LEUR couleur.
//
// Demande de recette : elles se confondaient avec les autres bulles IA, toutes
// violettes. Le module a son identite (le teal du selecteur et du badge), et
// ses bulles la portent : un debat se repere d'un coup d'oeil dans un fil.
//
// Et les DEUX roles ne partagent PAS la meme : « Pour » tire vers le vert,
// « Contre » vers le rouge (arbitrage produit). La teinte porte donc le sens
// du role, et le badge le nomme — l'un n'est pas la couleur de l'autre.
//
// La cle TECHNIQUE decide, jamais le libelle : celui-ci est traduit.
$isPourContre = $isAi && $aiMode === 'multi_ai';
$roleContre = $isPourContre && $aiAssistantKey === 'traverse';
$rolePour = $isPourContre && ! $roleContre;

$pourContreBubble = $roleContre
    ? 'bg-rose-50 dark:bg-rose-950 ring-1 ring-rose-200 dark:ring-rose-800 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm'
    : 'bg-emerald-50 dark:bg-emerald-950 ring-1 ring-emerald-200 dark:ring-emerald-800 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm';

$bubbleClasses = $isSent
    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
    : ($isPourContre
        ? $pourContreBubble
        : ($isAi
            ? 'bg-violet-50 dark:bg-violet-900 ring-1 ring-violet-200 dark:ring-violet-800 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm'
            : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm'));

// TASK-1772 (recette Cyril, telephone) — le nom passe a 13 px semi-gras sur
// telephone, comme WhatsApp ; le bureau garde ses 12 px (`md:`). Le nom d'un
// humain RECU prend en plus la couleur d'action du theme (`bp-bulle-nom`,
// app.css, sous 768 px seulement) : c'est ce qui, sur WhatsApp, fait
// reconnaitre qui parle avant meme de lire.
$_nom = 'text-[13px] font-semibold md:text-xs md:font-medium';
$nameClasses = $isSent
    ? $_nom.' text-indigo-200'
    : ($isPourContre
        ? ($roleContre
            ? $_nom.' text-rose-700 dark:text-rose-300'
            : $_nom.' text-emerald-700 dark:text-emerald-300')
        : ($isAi
            ? $_nom.' text-violet-600 dark:text-violet-300'
            : $_nom.' bp-bulle-nom text-gray-500 dark:text-gray-400'));

// Le modele, reduit a ce qui se lit. `inclusionai/ling-3.0-flash-vl:free`
// devient `ling-3.0-flash-vl` : le fournisseur et le palier tarifaire sont du
// jargon d'administration, pas une information pour un membre.
$aiModelLabel = null;
if ($isPourContre && is_string($aiModel) && trim($aiModel) !== '') {
    $aiModelLabel = trim(explode(':', basename(trim($aiModel)))[0]);
    $aiModelLabel = $aiModelLabel === '' ? null : $aiModelLabel;
}

$timeClasses = $isSent
    ? 'text-indigo-200'
    : 'text-gray-400';

// TASK-1772 (recette Cyril) — l'infobulle `title` qui suivait la souris sur
// chaque pastille est retiree : elle affichait la CLE brute (« seen »,
// « thumbs_up »), jamais traduite. `aria-label` reste, lui : il nomme le
// geste pour un lecteur d'ecran sans rien peindre a l'ecran.
$reactionTypes = App\Models\Reaction::REACTION_TYPES;
$primaryReactionTypes = ['thumbs_up', 'heart', 'thanks', 'surprised', 'sad'];
$secondaryReactionTypes = array_values(array_diff($reactionTypes, $primaryReactionTypes));
$reactionEmojis = App\Models\Reaction::emojiMap();
$visibleReactionCounts = array_filter($reactionCounts, fn ($count) => $count > 0);

// TASK-1312 : le badge n'existe QUE pour une bulle IA portant un mode connu.
// Un mode inconnu ne produit rien plutot qu'un badge menteur.
$aiModeLabels = [
    'llm' => __('loops.ia_mode_label'),
    'rag' => __('loops.dossiers_mode_label'),
    'llm_rag' => __('loops.hybrid_mode_label'),
];
$aiModeBadge = $isAi
    ? (is_string($aiModeLabel) && trim($aiModeLabel) !== ''
        ? $aiModeLabel
        : (is_string($aiMode) ? ($aiModeLabels[$aiMode] ?? null) : null))
    : null;

// Badge « mode demande » sur la bulle HUMAINE. Memes libelles que le badge de
// la bulle IA (ia_mode_label / dossiers_mode_label / hybrid_mode_label) — le
// mot que lit un membre est le meme des deux cotes de l'echange, seul son
// vocabulaire de cle differe ('ia_dossiers' cote humain vs 'llm_rag' cote
// reponse) parce que ce sont deux enumerations distinctes en base.
$requestedModeLabels = [
    'ia' => __('loops.ia_mode_label'),
    'dossiers' => __('loops.dossiers_mode_label'),
    'ia_dossiers' => __('loops.hybrid_mode_label'),
];
$requestedModeBadge = (! $isAi && is_string($requestedMode)) ? ($requestedModeLabels[$requestedMode] ?? null) : null;
// Arbitrage Cyril 29/08 : le badge vit en BAS A GAUCHE de la bulle, sur la
// ligne d'actions existante — dans le header, la pastille arrondie a deux
// lettres (« IA ») se confondait avec l'avatar-initiales d'un membre sans
// photo (constate en recette mobile). Forme d'ETIQUETTE (coins peu arrondis,
// icone sparkle + libelle), jamais une pastille : plus aucune ressemblance
// avec un avatar. Deux teintes selon le fond de bulle. Jamais color-only :
// le libelle porte l'information.
$requestedModeBadgeClasses = $isSent
    ? 'bg-white/10 text-indigo-100 ring-1 ring-white/20'
    : 'bg-indigo-50 text-indigo-600 ring-1 ring-indigo-200/70 dark:bg-indigo-900/30 dark:text-indigo-300 dark:ring-indigo-800';

// Arbitrage Cyril 29/08 : le badge de mode vit en bas a gauche pour TOUTES
// les bulles — humaines (mode demande, indigo) comme IA (moteur, violet).
// Un seul des deux existe jamais ($isAi les rend exclusifs). L'attribut
// canonique reste distinct : `data-ai-mode` (moteur constate) n'est PAS
// `data-requested-mode` (intention a l'envoi) — deux provenances, deux noms.
$bottomModeBadge = $isAi ? $aiModeBadge : $requestedModeBadge;
$bottomModeBadgeAttr = $isAi ? 'data-ai-mode' : 'data-requested-mode';
$bottomModeBadgeAttrValue = $isAi ? $aiMode : $requestedMode;
$bottomModeBadgeClasses = $isPourContre
    ? ($roleContre
        ? 'bg-rose-100 text-rose-800 ring-1 ring-rose-200 dark:bg-rose-800/60 dark:text-rose-100 dark:ring-rose-700'
        : 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-800/60 dark:text-emerald-100 dark:ring-emerald-700')
    : ($isAi
        ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-200 dark:bg-violet-800/60 dark:text-violet-100 dark:ring-violet-700'
        : $requestedModeBadgeClasses);

$escapePlainMarkdown = static function (string $text): string {
    $text = str_replace('\\', '\\\\', $text);

    return preg_replace('/([`*_{}\[\]()#+\-.!|>])/', '\\\\$1', $text) ?? $text;
};

$renderableBody = preg_replace_callback(
    '/```[a-z0-9_+-]*[ \t]*\r?\n([\s\S]*?)```[ \t]*/i',
    static fn (array $match): string => "\n".$escapePlainMarkdown(trim($match[1]))."\n",
    (string) $slot,
) ?? (string) $slot;

$renderableBody = preg_replace_callback(
    '/`([^`\n]+)`/',
    static fn (array $match): string => $escapePlainMarkdown($match[1]),
    $renderableBody,
) ?? $renderableBody;

$renderableBody = preg_replace_callback(
    '/(?:^|\n)([^\n]*\|[^\n]*\n[ \t]*\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*(?:\n[^\n]*\|[^\n]*)*)/m',
    static function (array $match) use ($escapePlainMarkdown): string {
        return "\n".collect(explode("\n", trim($match[1])))
            ->map(static fn (string $line): string => $escapePlainMarkdown($line))
            ->implode("\n");
    },
    $renderableBody,
) ?? $renderableBody;

// TASK-1772 — les mentions en GRAS (arbitrage Cyril, D3 option B).
//
// APRES les trois passes de neutralisation ci-dessus, et AVANT `markdown()` :
// l'ordre n'est pas indifferent. Avant les neutralisations, un `@Nom` ecrit
// dans un bloc de code aurait ete transforme puis re-echappe ; apres
// `markdown()`, il faudrait injecter du HTML, ce que `html_input => 'escape'`
// interdit — et c'est tant mieux.
//
// `str_replace` sur les noms REELLEMENT enregistres, et aucune expression
// reguliere qui devinerait ce qu'est une mention : on ne met en valeur que ce
// que le serveur a retenu. Un nom non mentionne, meme precede d'un `@`, reste
// du texte ordinaire.
//
// Effet de bord assume et mineur, nomme au CDC : un `@Nom` ecrit dans un bloc
// de code sera gras lui aussi.
//
// TASK-1772 (recette Cyril, 06/11) — « changer l'apparence du pseudo de la
// personne mentionnee : changer de couleur pour qu'on voit que c'est une
// mention ». Le gras seul ne suffisait pas : dans un fil, il se confond avec
// un simple appui de ton.
//
// ## Pourquoi des SENTINELLES et pas une classe posee directement
//
// `markdown()` tourne avec `html_input => 'escape'` : tout HTML ecrit ici
// ressortirait en `&lt;span&gt;`. Et le faire APRES le rendu demanderait de
// retrouver le nom dans la sortie — or CommonMark n'echappe pas comme `e()`
// (l'apostrophe, notamment), donc un `str_replace` sur `e($nom)` raterait
// « L'Hopital » EN SILENCE.
//
// On encadre donc la mention de deux caracteres de la zone a usage prive
// d'Unicode, que CommonMark traverse sans y toucher, et on les convertit en
// balise APRES le rendu. Le contenu entre les deux n'a plus besoin d'etre
// devine : quel qu'il soit, c'est la mention.
//
// Zone a usage prive d'Unicode (U+E000 / U+E001) : aucun caractere assigne,
// donc rien de legitime ne les porte, et CommonMark les laisse passer.
$_mentionDebut = "\u{E000}";
$_mentionFin = "\u{E001}";
// TASK-1801 correctif 03 — un TROISIEME caractere de la meme zone, pour
// separer l'identifiant (facultatif) du texte visible A L'INTERIEUR du
// meme couple de sentinelles. Un identifiant systeme (ULID), jamais du
// texte libre : rien dans son alphabet ne ressemble a `@`, `*` ou `_`, donc
// CommonMark ne peut pas le couper en deux.
$_mentionIdSep = "\u{E002}";

$_mentionNames = array_values(array_filter(array_map('strval', (array) $mentionNames)));

if ($_mentionNames !== []) {
    // Un membre pourrait coller ces caracteres dans son message. Ils sont
    // retires AVANT la passe : sans cela, une sentinelle orpheline ferait
    // teindre tout le texte jusqu'a la mention suivante.
    $renderableBody = str_replace([$_mentionDebut, $_mentionFin, $_mentionIdSep], '', $renderableBody);

    // Du plus long au plus court : sans cela, « @Anne » mis en gras en
    // premier couperait « @Anne Agricola » en deux.
    usort($_mentionNames, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    foreach ($_mentionNames as $_nom) {
        if ($_nom === '') {
            continue;
        }

        // TASK-1801 correctif 03 — l'identifiant de la personne, QUAND on en
        // connait un : absent pour le jeton `@tous` (personne a ouvrir) et
        // pour toute mention dont l'appelant n'a pas fourni l'identifiant.
        $_mentionId = (string) ($mentionUserIds[$_nom] ?? '');
        $_mentionContenu = $_mentionId !== ''
            ? $_mentionId.$_mentionIdSep.'@'.$_nom
            : '@'.$_nom;

        $renderableBody = str_replace(
            '@'.$_nom,
            // Les sentinelles vont DEDANS, pas autour. CommonMark exige
            // qu'un `**` ouvrant soit precede d'une espace ou d'une
            // ponctuation ; un caractere de la zone privee compte comme une
            // LETTRE, et le gras ne se formait pas — mesure du 06/11, le
            // message rendait « **@Bob Durand** » en clair.
            '**'.$_mentionDebut.$_mentionContenu.$_mentionFin.'**',
            $renderableBody,
        );
    }
}

/**
 * Convertit les sentinelles en balise, APRES `markdown()`.
 *
 * Le contenu entre les deux sentinelles est deja echappe par CommonMark : on
 * ne le touche pas, on ne fait que l'envelopper.
 *
 * TASK-1801 correctif 03 — « comme on vient de le faire quand on mentionne
 * quelqu'un [correctif 02, les liens], on va faire un lien qui affiche la
 * card de l'utilisateur ». Quand un identifiant accompagne la mention
 * (separe par `$_mentionIdSep`), la mention devient l'exact declencheur deja
 * pose sur l'avatar (`apercu-membre`, TASK-1772) : MEME evenement, MEME
 * panneau, aucun mecanisme nouveau. L'identifiant est un ULID systeme, pas
 * une saisie libre — mais il entre desormais dans un attribut, donc
 * echappe comme `@js()` le ferait (`Illuminate\Support\Js`), par coherence
 * avec le reste de ce fichier.
 *
 * Le `str_replace` final retire toute sentinelle restee seule (corps tronque,
 * mention coupee par une balise). Mieux vaut un gras sans couleur qu'un
 * caractere invisible dans le presse-papier.
 */
$_teindreLesMentions = static function (string $html) use ($_mentionDebut, $_mentionFin, $_mentionIdSep): string {
    $html = preg_replace_callback(
        '/'.preg_quote($_mentionDebut, '/').'(.*?)'.preg_quote($_mentionFin, '/').'/us',
        static function (array $m) use ($_mentionIdSep): string {
            $_position = strpos($m[1], $_mentionIdSep);

            if ($_position === false) {
                return '<span class="bp-mention">'.$m[1].'</span>';
            }

            $_id = substr($m[1], 0, $_position);
            $_texte = substr($m[1], $_position + strlen($_mentionIdSep));
            $_idAttr = htmlspecialchars($_id, ENT_QUOTES, 'UTF-8');
            $_idJs = \Illuminate\Support\Js::from($_id)->toHtml();

            return '<span class="bp-mention" role="button" tabindex="0"'
                .' data-mention-user-id="'.$_idAttr.'"'
                .' x-on:click.stop="$dispatch(\'apercu-membre\', { userId: '.$_idJs.' })"'
                .' x-on:keydown.enter.prevent.stop="$dispatch(\'apercu-membre\', { userId: '.$_idJs.' })"'
                .' x-on:keydown.space.prevent.stop="$dispatch(\'apercu-membre\', { userId: '.$_idJs.' })"'
                .'>'.$_texte.'</span>';
        },
        $html,
    ) ?? $html;

    return str_replace([$_mentionDebut, $_mentionFin, $_mentionIdSep], '', $html);
};

// =====================================================================
// TASK-1770 — les actions passent derriere un appui long, sur telephone.
//
// Mesure du 06/11 a 390x844 : le pied de bulle qui porte les cinq boutons
// coute 28 px PAR MESSAGE (24 de rangee + 4 de `mt-1`), soit 840 px sur les
// 30 messages charges — plus qu'un ecran entier, dont le fil ne fait que
// 799. C'est, en volume, le premier poste de gaspillage vertical de
// l'ecran, devant tout ce que T1769 a gagne sur le chrome.
//
// Le geste n'est pas invente : un appui long de 450 ms EXISTE deja dans ce
// composant, avec ses quatre gardes (touchend / touchmove / touchcancel /
// contextmenu). Il ouvrait le menu de reactions. On l'etend au menu
// complet plutot que d'en cabler un second a cote — deux detecteurs
// d'appui long sur le meme element finiraient par se disputer l'evenement.
// =====================================================================
$_actionsDuMessage = $messageId && ($showReplyButton || $showPinButton || $showCopyButton || $showEditButton || $showDeleteButton);
$_menuAppuiLong = $_actionsDuMessage || ($showReactions && $messageId);

// Le pied de bulle porte-t-il autre chose que les actions ? S'il ne porte
// qu'elles, il doit disparaitre ENTIER sur telephone : le masquer a moitie
// laisserait ses 4 px de `mt-1` sur chacun des trente messages.
$_piedPorteAutreChose = (bool) ($bottomModeBadge || $aiTruncated || $aiModelLabel);
@endphp

<div
    {{ $attributes->merge(['class' => $containerClasses . ' group ' . $class]) }}
    {{-- TASK-1772 (recette Cyril, 06/11) — LA SELECTION TIENT A LA BULLE.

         « Quand on selectionne un message, c'est toute la ligne qui est
         selectionnee. Du coup, on ne peut pas deselectionner en cliquant en
         dehors de la bulle. Il faut vraiment qu'on copie WhatsApp. »

         T1771 teintait la RANGEE entiere et posait le geste dessus : la
         gouttiere vide a cote d'une bulle courte faisait donc partie de la
         cible, et un appui a cet endroit — le geste naturel pour « sortir de
         la » — RESELECTIONNAIT le message. Plus aucun endroit du fil ne
         deselectionnait.

         Le geste et la marque descendent sur la COLONNE de la bulle. Ce qui
         est a cote d'elle est desormais « dehors », et `fermerSiDehors` y
         rend la main. --}}
    {{-- =================================================================
         TASK-1771 — SELECTION PAR SIMPLE APPUI. Rien d'autre.
         =================================================================
         Demande de Cyril apres trois recettes : « je prefere un mecanisme
         rudimentaire, mais au moins qui marche ».

         L'appui long est retire ENTIEREMENT — minuteur, seuil de mouvement,
         `contextmenu`, drapeau de `click` de synthese. Chaque piece fermait
         le defaut de la precedente et en ouvrait un autre :

           450 ms  -> le defilement declenchait le menu ;
           + gardes de mouvement -> corrige, mais 500 ms courait contre la
             selection de texte d'iOS, qui emet `touchcancel` et gagnait ;
           400 ms  -> corrige, mais le `click` de synthese qui suit un appui
             long deselectionnait aussitot : « le menu s'affiche et repart ».

         Un `click` n'a aucun de ces problemes. Le navigateur ne l'emet PAS
         apres un geste de defilement — c'est LUI qui fait le tri, et il le
         fait mieux que n'importe quel seuil en pixels. Aucun minuteur, donc
         aucune course avec le systeme.

         Le geste vit sur la RANGEE et non sur la bulle : « les messages ne
         sont pas faciles a attraper avec le pouce, il faut le faire avec
         l'index ». Une bulle courte n'offre qu'une cible etroite, posee du
         cote oppose au pouce pour la moitie des messages. La rangee fait
         toute la largeur — et c'est la LIGNE qui se surligne, comme sur
         WhatsApp. --}}
    @if($_menuAppuiLong)
    x-on:bp-selection-action.window="if ($event.detail.id === id) executerAction($event.detail.action)"
    @endif
    x-data="{
        id: @js((string) $messageId),
        open: false,
        /**
         * TASK-1772 (recette Cyril, 06/11) — LE MENU DE REACTIONS AU TELEPHONE.
         *
         * « Le menu reaction doit etre centre, car quand on l'affiche il est
         * tronque car trop sur le cote. »
         *
         * Le panneau etait ancre au bouton ☺, lui-meme pose HORS de la bulle
         * (`right: -1.75rem`), puis decale de la moitie de sa largeur : sur un
         * ecran de 390 px il debordait forcement, et le debordement etait
         * COUPE depuis que la liste porte `overflow-x-hidden` (recette 6).
         *
         * Au telephone, il quitte donc l'ancrage : `position: fixed`, centre
         * sur l'ecran, pose juste au-dessus de la bulle. A partir de `md`,
         * rien ne change — la souris survole une bulle precise, et le panneau
         * doit rester attache a elle.
         */
        menuStyleBureau: @js($isSent
            ? 'top: 50%; left: -1rem; transform: translate(-50%, calc(-100% - 1rem));'
            : 'top: 50%; right: -1rem; transform: translate(50%, calc(-100% - 1rem));'),
        menuStyle: '',
        surTelephone() {
            return ! window.matchMedia('(min-width: 768px)').matches;
        },
        ouvrirReactions() {
            if (this.open) { this.open = false; this.showMore = false; return; }
            this.$dispatch('reaction-menu-opened', { id: this.id });
            this.open = true;
            this.$nextTick(() => this.placerMenuReactions());
        },
        placerMenuReactions() {
            const panneau = this.$refs.menuReactions;
            if (! panneau) { return; }

            if (! this.surTelephone()) { this.menuStyle = this.menuStyleBureau; return; }

            // La bulle, mesuree A L'ECRAN : c'est le parent positionne du
            // panneau, donc exactement la colonne du message.
            const bulle = panneau.parentElement.getBoundingClientRect();
            const hauteur = panneau.offsetHeight || 44;
            // Au-dessus de la bulle ; si elle touche le haut du fil, dessous.
            let haut = bulle.top - hauteur - 8;
            if (haut < 8) { haut = Math.min(bulle.bottom + 8, window.innerHeight - hauteur - 8); }

            this.menuStyle = 'position: fixed; z-index: 50; left: 50%; right: auto; '
                + 'top: ' + Math.round(haut) + 'px; transform: translateX(-50%);';
        },
        hover: false,
        showMore: false,
        /**
         * TASK-1771 — entrer (ou sortir) du mode selection.
         *
         * La bulle publie CE QU'ELLE SAIT FAIRE. C'est elle qui connait les
         * droits — la barre, elle, ne fait qu'afficher ce qu'on lui donne.
         */
        selectionner() {
            this.$store.bpSelection.basculer(
                this.id,
                @js([
                    'reply' => (bool) $showReplyButton,
                    'pin' => (bool) $showPinButton,
                    'pinned' => (bool) $isPinned,
                    'copy' => (bool) $showCopyButton,
                    'edit' => (bool) $showEditButton,
                    'delete' => (bool) $showDeleteButton,
                ]),
                (this.$refs.copyContent?.innerText || '').replace(/\n{3,}/g, '\n\n').trim(),
                @js((string) ($name ?? '')),
            );
        },
        /**
         * La barre dit QUOI ; la bulle sait COMMENT.
         *
         * Chaque branche reutilise le gestionnaire qui marchait deja dans la
         * rangee de bureau — y compris `deleteOpen`, qui ouvre la
         * confirmation existante au lieu de supprimer sechement. C'est ce qui
         * permet a la meme barre de servir le ChatLoop et la Messagerie, dont
         * les composants Livewire n'exposent PAS les memes methodes.
         */
        executerAction(action) {
            // TASK-1772 (recette Cyril) — on SORT du mode selection AVANT
            // d'executer : si l'action echoue en route, la barre ne reste pas
            // « scotchee » sur le message. L'identifiant est deja dans `this.id`.
            this.$store.bpSelection.fermer();

            if (action === 'reply') {
                this.$dispatch('reply-to-message', { messageId: this.id });
                // TASK-1772 (recette Cyril) — « le bouton repondre ne
                // fonctionne pas » : la citation se posait bien dans le
                // composeur, mais a l'autre bout de l'ecran, et sans clavier.
                // Sur telephone, repondre = le clavier s'ouvre sur la citation,
                // comme sur WhatsApp. Le `focus()` doit etre SYNCHRONE dans le
                // geste : hors de l'appui, Android n'ouvre pas le clavier.
                // Le champ est `wire:ignore.self`, le re-rendu ne le lui
                // reprend pas.
                document.querySelector('[data-composer-input]')?.focus();
            } else if (action === 'pin') {
                @if($isPinned) this.$wire.unpinMessage(); @else this.$wire.pinMessage(this.id); @endif
            } else if (action === 'edit') {
                this.$wire.editMessage(this.id);
            } else if (action === 'delete') {
                this.deleteOpen = true;
            }
        },
        copied: false,
        deleteOpen: false,
        copyTimer: null,
        async copyMessage() {
            const text = (this.$refs.copyContent?.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
            if (!text) return;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }

                this.copied = true;
                clearTimeout(this.copyTimer);
                this.copyTimer = setTimeout(() => this.copied = false, 1400);
            } catch (error) {
                this.$dispatch('chatloop-copy-failed');
            }
        },
    }"
    @if($showReactions && $messageId)
    x-on:mouseover="hover = true"
    x-on:mouseleave="hover = false"
    x-on:reaction-menu-opened.window="if ($event.detail.id !== id) { open = false; showMore = false }"
    x-on:keydown.escape.window="open = false; showMore = false"
    {{-- TASK-1772 — au telephone le panneau est FIXE : il ne suit pas le fil
         qui defile, donc un defilement du fil le ferme. `.capture`, parce
         qu'un `scroll` d'element ne remonte pas jusqu'a la fenetre.

         MAIS le panneau defile LUI AUSSI, horizontalement (c'est la demande
         de Cyril). Sans cette garde, faire glisser le rang d'emojis fermait
         le menu aussitot — mesure a 320 px, ou le rang deborde vraiment.
         Le defilement NE du panneau ne le ferme donc pas. --}}
    x-on:scroll.window.capture="if (open && surTelephone() && ! ($event.target instanceof Element && $event.target.closest('[x-ref=menuReactions]'))) { open = false; showMore = false }"
    x-on:click.outside="open = false; showMore = false"
    @endif
>
    {{-- TASK-1309 (recette mobile) : `min-w-0` sur l'element flex.
         Sans lui, sa taille MIN-CONTENT est celle de son plus long descendant
         non secable — et l'apercu de reply (`truncate`, donc `nowrap`) en est
         un : une question un peu longue poussait la bulle a 461 px dans un
         conteneur de 358, et `max-w-[90%]`, calcule sur ce parent deja trop
         large, n'y pouvait rien. Le texte etait alors coupe a droite, sur
         mobile, pour TOUTE bulle portant un reply — defaut PREEXISTANT,
         mesure sur le banc reel a l'identique sur une bulle « Dossiers »
         anterieure a cette TASK ; la recette T1309 l'a rendu visible. --}}
    {{-- TASK-1329 : les limites de largeur vivent ICI, sur le conteneur —
         plus jamais sur la bulle elle-meme. `max-w-[90%]` pose sur la bulle
         se resolvait contre CE conteneur, qui retrecit deja a la largeur du
         contenu : le navigateur rabotait donc ~10% de la largeur naturelle
         du texte, et une phrase courte (« Bonjour tout le monde ! ») passait
         sur deux lignes (constate en recette mobile). Ici, le pourcentage se
         resout contre le fil (pleine largeur) : une bulle courte garde sa
         ligne, une longue plafonne a 90% / md / lg. --}}
    {{-- TASK-1772 (recette Cyril, 06/11) — LA GOUTTIERE D'AVATAR.

         « Je voudrais que cela ressemble plus a WhatsApp : que l'avatar du
         pseudo soit detache de la bulle de chat. Et que quand on clique sur
         cet avatar on puisse avoir un pop-up. »

         Aligne en HAUT (`items-start` sur la rangee) : c'est ce que fait
         WhatsApp Web, et c'est ce qui place la pastille en face du nom plutot
         qu'en face de la derniere ligne d'un long message.

         Bouton SEULEMENT quand on sait qui c'est. Sans `avatarUserId`, la
         pastille reste une image decorative : un bouton qui n'ouvre rien est
         pire qu'une image, il promet un geste qui ne vient pas. --}}
    @if($_avatarDetache)
        @if($avatarUserId)
        <button type="button"
                data-member-avatar="{{ $avatarUserId }}"
                x-on:click.stop="$dispatch('apercu-membre', { userId: @js((string) $avatarUserId) })"
                aria-label="{{ __('messages.member_preview_open', ['name' => $name ?? '']) }}"
                class="mt-0.5 shrink-0 rounded-full ring-0 transition hover:ring-2 hover:ring-[var(--bp-accent)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-accent)]">
            <x-conversation.avatar-mark :avatar="$avatar" :is-ai="$isAi" size="h-8 w-8" icon-size="h-5 w-5" />
        </button>
        @else
        <div class="mt-0.5 shrink-0">
            <x-conversation.avatar-mark :avatar="$avatar" :is-ai="$isAi" size="h-8 w-8" icon-size="h-5 w-5" />
        </div>
        @endif
    @endif

    <div class="relative min-w-0 {{ $widthClass }}"
        {{-- TASK-1772 — la cible du geste, et la frontiere du « dehors ».
             Cette colonne se reduit a la largeur de la bulle (elle est un
             element flex a largeur automatique) : elle est donc exactement ce
             que l'oeil appelle « le message », gouttiere exclue. --}}
        @if($_menuAppuiLong)
        data-bp-selectable
        x-on:click="if (! $event.target.closest('a,button,input,textarea,select,[role=button]')) selectionner()"
        @endif
    >
    {{-- TASK-1771 — `select-none` et `-webkit-touch-callout: none` sur
         telephone : sans eux, un appui long sur le texte lance la selection
         native d'iOS et sa bulle « Copier / Tout selectionner », qui recouvre
         la notre ET annule notre geste. WhatsApp fait le meme choix — le
         texte ne se selectionne pas au doigt, c'est « Copier » qui s'en
         charge, et il est dans la barre.
         A partir de `md`, la selection de texte redevient normale : au
         clavier-souris, personne ne fait d'appui long. --}}
    <div
        {{-- TASK-1772 (recette Cyril, telephone) — la bulle respire comme
             WhatsApp : 6 x 10 px de marge interne au lieu de 8 x 12, pour
             un corps passe de 14 a 16 px. Le bureau ne bouge pas (`md:`). --}}
        class="{{ $bubbleClasses }} px-2.5 py-1.5 md:px-3 md:py-2 select-none [-webkit-touch-callout:none] md:select-auto"
        {{-- TASK-1772 — la bulle ENVOYEE a un fond indigo-600 plein, dans les
             deux themes. Une mention en indigo y serait illisible : la regle
             CSS a besoin de savoir de quel cote de la conversation elle est.
             Un attribut explicite plutot qu'un selecteur sur `.bg-indigo-600` :
             le jour ou la couleur de bulle change, le marqueur reste juste. --}}
        @if($isSent) data-bulle-envoyee @endif
        {{-- TASK-1772 — deux marques, toutes deux sur la bulle : l'anneau
             interieur dit LEQUEL des deux messages colles l'un a l'autre est
             pris, le halo exterieur le fait voir d'un coup d'oeil sur un fil
             dense. C'est ce que la teinte de rangee de T1771 apportait, sans
             manger la gouttiere. --}}
        {{-- Style EN LIGNE : `ring-[var(--bp-primary)]` et
             `bg-[var(--bp-primary)]/10` rendent une couleur INVALIDE, donc
             rien du tout — Tailwind ne sait pas appliquer un modificateur
             d'opacite a une variable CSS (piege paye en T1475, remesure en
             T1771 : couleur calculee `rgba(0, 0, 0, 0)`). `color-mix` fait le
             melange dans le navigateur, avec le jeton tel quel. --}}
        x-bind:style="$store.bpSelection.id === id ? 'box-shadow: inset 0 0 0 2px var(--bp-primary), 0 0 0 4px color-mix(in srgb, var(--bp-primary) 22%, transparent)' : ''"
    >
        @if($avatar || $name || $subtitle || $requestedBy || $time || $isEdited || $isAi)
        <div class="mb-0.5 md:mb-1 flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                {{-- TASK-1772 — quand l'avatar est detache, la pastille vit
                     dans la gouttiere de gauche : la laisser ici AUSSI la
                     montrerait deux fois sur la meme rangee. --}}
                @unless($_avatarDetache)
                <x-conversation.avatar-mark :avatar="$avatar" :is-ai="$isAi" />
                @endunless
                <div class="min-w-0">
                    @if($name || $aiModeBadge)
                    {{-- TASK-1312 : l'identite tenant (le nom) et le MOTEUR (le
                         badge) sont deux informations distinctes, et cessent
                         d'etre concatenees dans une seule chaine.

                         Le separateur « · » reste rendu comme TEXTE entre les
                         deux : le contenu textuel de la bulle demeure
                         « Organization · Mode », exactement ce que le parcours
                         E2E canonique verifie par `toContainText`. Le scinder
                         sans lui aurait impose de rejouer l'E2E — donc de
                         depenser des appels provider — pour un changement
                         purement visuel. --}}
                    <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                        @if($name)
                        <span class="{{ $nameClasses }} truncate">{{ $name }}</span>
                        @endif
                        @if($aiModeBadge)
                        {{-- TASK-1329 : le badge moteur est desormais VISUEL en
                             bas a gauche de la bulle (comme le badge de mode des
                             bulles humaines). Ici, l'identite complete
                             « Organization · Mode » reste un TEXTE contigu pour
                             les lecteurs d'ecran — et pour le parcours E2E
                             canonique, qui la verifie par `toContainText`
                             (textContent) : la scinder visuellement ne doit pas
                             imposer de rejouer l'E2E, donc de depenser des
                             appels provider. --}}
                        {{-- Deux spans distincts : le separateur garde son
                             propre element (`>·</span>`), la forme exacte que
                             T1312 verifie. --}}
                        <span class="sr-only">·</span>
                        <span class="sr-only">{{ $aiModeBadge }}</span>
                        @endif
                    </span>
                    @endif
                    @if($subtitle)
                        @if($aiModeBadge)
                        {{-- Le badge porte deja le mode ; le sous-titre reste
                             dans le DOM (lecteurs d'ecran, assertions serveur)
                             sans consommer une ligne. --}}
                        <span class="sr-only">{{ $subtitle }}</span>
                        @else
                        <span class="block truncate text-[10px] text-gray-400 dark:text-gray-500">{{ $subtitle }}</span>
                        @endif
                    @endif
                    {{-- TASK-1316 : l'attribution humaine a sa PROPRE ligne.
                         Concatenee au sous-titre, elle tombait sous le `truncate`
                         ci-dessus : sur mobile, « Réponse croisée entre l'IA et les
                         connaissances de cette Boucle » consomme deja toute la
                         largeur, et « Demandé par … » — la seule information qui
                         dise a un groupe QUI a engage la depense — disparaissait
                         en silence.

                         `data-ai-requested-by` porte l'identifiant de la personne,
                         jamais son nom traduit : c'est lui qu'un test asserte. --}}
                    @if($requestedBy)
                    <span data-ai-requested-by="{{ $requestedBy['id'] }}"
                          class="mt-0.5 block text-[10px] font-medium leading-tight text-violet-700 dark:text-violet-300">{{ __('loops.ai_requested_by', ['name' => $requestedBy['name']]) }}</span>
                    @endif
                </div>
            </div>
            @if($time || $isEdited)
            <div class="shrink-0 text-right leading-none">
                @if($time)
                <span class="block text-[11px] md:text-[10px] {{ $timeClasses }}">{{ $time }}</span>
                @endif
                @if($isEdited)
                <span class="mt-0.5 block text-[10px] {{ $timeClasses }}">{{ __('messages.edited') }}</span>
                @endif
            </div>
            @endif
        </div>
        @endif

        @if($replyTo)
        <div class="{{ $isSent ? 'bg-indigo-500/40' : 'bg-gray-200 dark:bg-gray-600' }} rounded px-2.5 py-1 mb-1.5 text-xs">
            <span class="font-medium">{{ $replyTo['sender_name'] ?? '' }}</span>
            <p class="truncate {{ $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400' }}">{{ $replyTo['body'] }}</p>
        </div>
        @endif

        @if($imagePath)
        <button
            x-on:click="$dispatch('open-image', { url: '{{ $imagePath }}' })"
            class="block mb-1.5 w-full max-w-[200px] rounded-lg overflow-hidden focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
            <img src="{{ $imagePath }}" alt="{{ __('messages.image_alt') }}" class="w-full h-auto object-cover rounded-lg hover:opacity-90 transition">
        </button>
        @endif

        {{-- TASK-1621 — `bp-bulle-markdown` : les listes Markdown d'une bulle.
             CommonMark emet `<ul>\n<li>...` ; `whitespace-pre-wrap` rendait
             chaque saut de ligne SOURCE en ligne vide, et le preflight
             Tailwind retire `list-style` — trois puces s'affichaient donc en
             paragraphes espaces SANS marqueurs (capture Cyril, 22/09). La
             regle vit dans app.css et redonne aux listes leurs puces et un
             interligne compact, sans toucher au reste du texte. --}}
        {{-- TASK-1772 (recette Cyril, telephone) — `whitespace-pre-wrap` RETIRE.
             CommonMark termine chaque rendu par « \n » et ecrit un saut de
             ligne logiciel « <br />\n » : en pre-wrap, le premier faisait une
             LIGNE VIDE au bas de CHAQUE bulle (20 px mesures, bulle de 60 px
             pour 40 de texte), le second DOUBLAIT tout retour a la ligne.
             C'est le Markdown qui porte les sauts (`<br>`, `<p>`), et app.css
             qui espace les paragraphes. Le corps passe a 16 px / 22 px sur
             telephone — la taille de WhatsApp — et garde 14 px au bureau. --}}
        <div x-ref="copyContent" class="bp-bulle-markdown min-w-0 max-w-full cursor-default overflow-hidden break-words text-[16px] leading-5 md:text-sm [overflow-wrap:anywhere]" style="caret-color: transparent; word-break: break-word;">{!! $_teindreLesMentions(markdown($renderableBody)) !!}</div>

        @if($attachment)
            <x-conversation.file-attachment-card
                :name="$attachment['name'] ?? ''"
                :size="$attachment['size'] ?? null"
                :mime="$attachment['mime'] ?? null"
                :download-url="$attachment['downloadUrl'] ?? ''"
                :preview-url="$attachment['previewUrl'] ?? null"
                :is-sent="$isSent"
            />
        @endif

        @if($urlPreview)
            <x-conversation.url-preview-card :preview="$urlPreview" :is-sent="$isSent" />
        @endif

        {{-- TASK-1301 : sources d'une reponse IA, forme publique ecrite cote
             serveur (publicSource, T1297) — l'URL est rendue TELLE QUELLE,
             jamais re-derivee ici. title/dossier_name/excerpt sont du contenu
             de document uploade : rendu ECHAPPE ({{ }}), jamais {!! !!}.
             Presentation reprise du panneau knowledge de loops/show. --}}
        {{-- TASK-1309 : DEUX etats mutuellement exclusifs, jamais confondus —
             les sources qui ont REELLEMENT soutenu une affirmation
             (« Sources utilisées ») ou, a defaut, les documents dont le
             contenu a ete lu sans qu'aucune citation n'en sorte
             (« Sources consultées »). Un document consulte n'est jamais
             presente comme un appui. --}}
        @php
            $shownSources = $sources ?: ($consultedSources ?: null);
            $shownSourcesAreCited = (bool) $sources;
        @endphp
        @if($shownSources)
        {{-- TASK-1312 : replie par defaut. Une reponse documentaire peut citer
             cinq a dix sources ; deployees, elles reléguaient la REPONSE hors
             de l'ecran — le contenu se retrouvait enseveli sous sa propre
             provenance.

             `<details>`/`<summary>` est natif : deployable au clavier, annonce
             comme tel par les lecteurs d'ecran, et il fonctionne sans une seule
             ligne de JavaScript. Un accordeon maison aurait demande du JS, un
             `aria-expanded` a tenir a jour, et un piege de plus dans une bulle
             qui en a deja coute trois (T1310).

             Les references, les URLs et le distinguo T1309
             « utilisees / consultees » sont INCHANGES : seul l'etat initial du
             bloc change. --}}
        {{-- TASK-1312 (bug de recette) : `wire:ignore.self` est ce qui empeche le
             bloc de se REFERMER TOUT SEUL.

             Le ChatLoop porte `wire:poll.3s`. A chaque cycle, le morph de
             Livewire realigne les attributs de chaque element sur le HTML rendu
             par le SERVEUR — lequel ignore evidemment que l'utilisateur vient
             de deplier ce bloc. L'attribut `open`, pose cote client par le
             navigateur, etait donc efface au premier poll : ouvert, puis referme
             trois secondes plus tard, sans que personne n'ait rien touche.
             Constate en recette : `open` vrai apres le clic, faux apres 12 s et
             4 requetes `/update`.

             `.self` ne gele QUE cet element — ses enfants (le resume et la liste
             des sources) continuent d'etre morphes normalement. Ce n'est donc
             pas un `wire:ignore` global.

             Et ce qui est gele ici ne bouge jamais : `data-sources-kind` et
             `data-sources-count` derivent de `metadata['sources']`, ecrit UNE
             fois a la publication. Une bulle IA n'est pas editable —
             `LoopMessage::isEditableBy()` n'accepte que `user` et
             `member_agent`. Verifie, pas suppose. --}}
        <details wire:ignore.self class="group mt-2 border-t border-violet-200/70 pt-2 dark:border-violet-800/70"
                 data-message-sources
                 data-sources-kind="{{ $shownSourcesAreCited ? 'used' : 'consulted' }}"
                 data-sources-count="{{ count($shownSources) }}">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 marker:content-none dark:text-gray-400 [&::-webkit-details-marker]:hidden">
                {{-- Le chevron doit DIRE dans quel etat on est : pointe a droite
                     replie, vers le bas deplie. `group-open:` est la forme que
                     Tailwind genere reellement — un variant arbitraire imbrique
                     du type `[details[open]_&]` n'est pas produit, et le chevron
                     restait alors identique dans les deux etats (constate a
                     l'ecran). Le `group` correspondant est sur le `<details>`. --}}
                <svg class="h-3 w-3 shrink-0 text-violet-500 transition-transform duration-200 group-open:rotate-90 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span>{{ $shownSourcesAreCited ? __('loops.knowledge_sources_title') : __('loops.knowledge_consulted_title') }}</span>
                <span class="font-normal normal-case tracking-normal text-gray-400 dark:text-gray-500">· {{ count($shownSources) }}</span>
            </summary>
            <ul class="mt-1.5 space-y-2">
                @foreach($shownSources as $source)
                <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-2.5 text-xs" data-message-source>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <span class="font-mono text-[10px] text-sky-700 dark:text-sky-300">[{{ $source['ref'] ?? '' }}]</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $source['title'] ?? '' }}</span>
                            @if(!empty($source['dossier_name']))
                            <span class="text-gray-500 dark:text-gray-400"> · {{ $source['dossier_name'] }}</span>
                            @endif
                            {{-- TASK-1391 : dire ce que la source a fourni.
                                 Le manifeste REPERTORIE un document sans en
                                 lire une ligne. Presente comme les autres, il
                                 laissait croire qu'il avait soutenu la
                                 reponse. --}}
                            @if(($source['type'] ?? null) === 'manifest')
                            <span data-source-listed-only class="ml-1 whitespace-nowrap rounded-full border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:border-amber-700/60 dark:bg-amber-900/30 dark:text-amber-200">{{ __('loops.knowledge_source_listed_only') }}</span>
                            @endif
                        </div>
                        @if(!empty($source['url']))
                        <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="flex-shrink-0 text-sky-700 dark:text-sky-300 hover:underline">{{ __('loops.knowledge_open_source') }}</a>
                        @endif
                    </div>
                    @if(!empty($source['excerpt']))
                    <p class="mt-1 text-gray-600 dark:text-gray-300 italic">{{ $source['excerpt'] }}</p>
                    @endif
                </li>
                @endforeach
            </ul>
        </details>
        @endif

        {{-- TASK-1310 : pied de bulle, rendu tel quel APRES les sources.
             Le slot par defaut, lui, traverse le pipeline markdown
             (`$renderableBody`) : y glisser du HTML d'action le ferait avaler
             — constate en recette, l'action « Ajouter au Dossier » etait
             invisible alors que le serveur l'autorisait. Un slot nomme est le
             seul endroit ou du balisage survit intact. --}}
        @isset($footer)
        {{ $footer }}
        @endisset

        @if(($messageId && ($showReplyButton || $showPinButton || $showCopyButton || $showEditButton || $showDeleteButton || $showReactions)) || $bottomModeBadge)
        {{-- TASK-1770 — `hidden md:flex` et non `flex ... hidden` : deux
             utilitaires de `display` au meme niveau, c'est l'ordre de la
             feuille compilee qui tranche, pas l'ordre dans l'attribut. --}}
        <div class="mt-1 {{ $_piedPorteAutreChose ? 'flex' : 'hidden md:flex' }} items-center justify-end gap-3">
            {{-- TASK-1329 : badge de mode en bas a GAUCHE de la bulle —
                 `mr-auto` le cale a gauche, les actions gardent leur `ml-auto`
                 a droite, sur la ligne qui existe deja. Bulle HUMAINE : le mode
                 DEMANDE a l'envoi (`data-requested-mode`, indigo). Bulle IA :
                 le moteur constate (`data-ai-mode`, violet) — deux provenances,
                 deux attributs canoniques, c'est EUX qu'un test asserte, jamais
                 le libelle traduit. Etiquette (coins peu arrondis, icone
                 sparkle + libelle), jamais une pastille : une pastille « IA »
                 se confondait avec l'avatar-initiales d'un membre sans photo.
                 Aucun badge => cette ligne garde son rendu d'aujourd'hui. --}}
            @if($bottomModeBadge)
            <span {{ $bottomModeBadgeAttr }}="{{ $bottomModeBadgeAttrValue }}"
                  @if($isAi && $subtitle) title="{{ $subtitle }}" @endif
                  {{-- `mr-auto` cale le groupe a gauche. Il doit etre porte par
                       le DERNIER element du groupe : sur le badge alors qu'un
                       modele le suit, il repousserait ce modele a droite,
                       contre les actions. --}}
                  class="{{ ($aiModelLabel || $aiTruncated) ? '' : 'mr-auto ' }}inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide {{ $bottomModeBadgeClasses }}">
                <svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.091ZM18.25 8.25 18 9.25l-.25-1a2.5 2.5 0 0 0-1.75-1.75L15 6.25l1-.25a2.5 2.5 0 0 0 1.75-1.75l.25-1 .25 1A2.5 2.5 0 0 0 20 6l1 .25-1 .25a2.5 2.5 0 0 0-1.75 1.75Z"/>
                </svg>
                {{-- Bulle IA : le mode est deja annonce dans l'en-tete
                     (sr-only « · Mode ») — sans aria-hidden, un lecteur
                     d'ecran l'entendrait deux fois. Bulle humaine : ce badge
                     est la SEULE occurrence, il reste annonce. --}}
                <span @if($isAi) aria-hidden="true" @endif>{{ $bottomModeBadge }}</span>
            </span>
            @endif
            @if($aiTruncated)
            {{-- TASK-1621 — une reponse coupee ne se publie jamais en
                 silence. Le badge est ambre, la famille d'avertissement deja
                 employee par les encarts d'echec du module : le membre lit
                 « il manque la fin », pas « quelque chose a casse ». Le bouton
                 « Reessayer » vit dans le panneau d'etat, deja present. --}}
            <span data-ai-truncated
                  class="{{ $aiModelLabel ? '' : 'mr-auto ' }}inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide bg-amber-100 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-800/60 dark:text-amber-100 dark:ring-amber-700">
                <svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                {{ __('loops.plugins_multi_ai_truncated') }}
            </span>
            @endif
            @if($aiModelLabel)
            {{-- TASK-1621 — le modele qui a repondu, a DROITE du badge.
                 Discret a dessein : casse normale, gris, pas de fond, pas de
                 majuscules. Il donne une garantie (ce n'est pas « une IA »
                 anonyme) sans devenir une information technique de plus. --}}
            <span data-ai-model="{{ $aiModel }}"
                  class="-ml-0.5 mr-auto min-w-0 truncate text-[9px] font-normal normal-case tracking-normal text-gray-400 dark:text-gray-500">{{ $aiModelLabel }}</span>
            @endif
            @if($messageId && ($showReplyButton || $showPinButton || $showCopyButton || $showEditButton || $showDeleteButton))
            <div class="ml-auto hidden items-center gap-1 md:flex">
                @if($showReplyButton)
                <button
                    {{-- TASK-1772 — meme contrat que la barre de selection : repondre, c'est ecrire. --}}
                    x-on:click="$dispatch('reply-to-message', { messageId: '{{ $messageId }}' }); document.querySelector('[data-composer-input]')?.focus()"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.reply') }}"
                    aria-label="{{ __('messages.reply') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.reply') }}</span>
                </button>
                @endif
                @if($showPinButton)
                    @if($isPinned)
                    <button
                        wire:click="unpinMessage"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-amber-500 transition {{ $isSent ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-900/30 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.unpin') }}"
                        aria-label="{{ __('messages.unpin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H8.5M12 18v3"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.unpin') }}</span>
                    </button>
                    @else
                    <button
                        wire:click="pinMessage('{{ $messageId }}')"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-amber-600 dark:hover:bg-gray-800 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.pin') }}"
                        aria-label="{{ __('messages.pin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H6l2-3V6.5M12 18v3"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.pin') }}</span>
                    </button>
                    @endif
                @endif
                @if($showCopyButton)
                <button
                    type="button"
                    x-on:click="copyMessage"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-emerald-600 dark:hover:bg-gray-800 dark:hover:text-emerald-300' }}"
                    title="{{ __('messages.copy') }}"
                    aria-label="{{ __('messages.copy') }}"
                    x-bind:title="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                    x-bind:aria-label="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                >
                    <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="4" y="4" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <span class="sr-only" x-text="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"></span>
                </button>
                @endif
                @if($showEditButton)
                <button
                    wire:click="editMessage('{{ $messageId }}')"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.edit') }}"
                    aria-label="{{ __('messages.edit') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.edit') }}</span>
                </button>
                @endif
                @if($showDeleteButton)
                <button
                    type="button"
                    x-on:click="deleteOpen = true"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400' }}"
                    title="{{ __('messages.delete') }}"
                    aria-label="{{ __('messages.delete') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.delete') }}</span>
                </button>
                @endif
            </div>
            @endif
        </div>
        @endif

        @if(!empty($visibleReactionCounts))
        <div class="flex items-center gap-1 mt-1 flex-wrap {{ $isSent ? 'justify-end' : 'justify-start' }}">
            @foreach($reactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji && !empty($visibleReactionCounts[$type]))
                <button
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    class="inline-flex items-center gap-0.5 text-[11px] leading-none px-1.5 py-0.5 rounded-full transition
                        {{ $myReaction === $type
                            ? ($isSent ? 'bg-indigo-500/30 ring-1 ring-indigo-300/50' : 'bg-indigo-100 dark:bg-indigo-900/40 ring-1 ring-indigo-300 dark:ring-indigo-600')
                            : ($isSent ? 'bg-indigo-500/20 hover:bg-indigo-500/30' : 'bg-white/70 hover:bg-white dark:bg-gray-800/60 dark:hover:bg-gray-800') }}"
                >
                    <span class="text-xs leading-none">{{ $emoji }}</span>
                    <span class="{{ $isSent ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $visibleReactionCounts[$type] }}</span>
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>

    @if($messageId && $showDeleteButton)
    <template x-teleport="body">
        <div
            x-show="deleteOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center px-4"
            x-on:keydown.escape.window="deleteOpen = false"
        >
            <div class="fixed inset-0 bg-gray-950/50 backdrop-blur-sm" x-on:click="deleteOpen = false"></div>
            <div class="relative w-full max-w-sm rounded-2xl border border-white/70 bg-white p-5 shadow-2xl shadow-gray-950/20 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-gray-100">{{ __('messages.delete_modal_title') }}</h3>
                        <p class="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{{ __('messages.delete_modal_body') }}</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" x-on:click="deleteOpen = false" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        {{ __('messages.cancel_edit') }}
                    </button>
                    <button type="button" x-on:click="$wire.deleteMessage('{{ $messageId }}'); deleteOpen = false" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700">
                        {{ __('messages.delete') }}
                    </button>
                </div>
            </div>
        </div>
    </template>
    @endif

        @if($showReactions && $messageId)
        <button
            type="button"
            x-on:click.stop="ouvrirReactions()"
            x-bind:style="'top: 50%; transform: translateY(-50%); {{ $isSent ? 'left: -1.75rem;' : 'right: -1.75rem;' }} ' + ((hover || open) ? 'opacity: 1;' : 'opacity: 0; pointer-events: none;')"
            x-bind:tabindex="(hover || open) ? 0 : -1"
            x-on:focus="hover = true"
            x-on:blur="if (!open) hover = false"
            class="inline-flex absolute h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm ring-1 ring-gray-200 transition hover:text-indigo-600 hover:ring-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:text-indigo-300"
            aria-label="{{ __('messages.react_to_message') }}"
        >
            <span class="text-lg leading-none">☺</span>
        </button>

        <div
            x-show="open"
            x-cloak
            x-ref="menuReactions"
            {{-- TASK-1772 — le placement est calcule a l'ouverture : ancre a la
                 bulle au bureau, centre sur l'ecran au telephone. --}}
            x-bind:style="menuStyle || menuStyleBureau"
            {{-- TASK-1772 (recette Cyril) — « une fois deplie, le menu devient
                 une espece de bulle tres tres haute. Il faut qu'on puisse
                 scroller horizontalement le type de reaction, comme sur
                 WhatsApp. »
                 Donc UN SEUL RANG, toujours : trop large pour l'ecran, il
                 DEFILE au doigt au lieu de s'empiler. La barre de defilement
                 est masquee — WhatsApp ne la montre pas, et sur un panneau de
                 44 px de haut elle en mangerait le tiers. --}}
            class="absolute z-30 max-w-[calc(100vw-1rem)] overflow-x-auto overscroll-x-contain rounded-full border border-gray-200 bg-white px-2 py-1.5 shadow-lg [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:max-w-none md:overflow-visible dark:border-gray-700 dark:bg-gray-800"
        >
            {{-- `mx-auto` + `w-max` : le rang se CENTRE tant qu'il tient, et
                 reste atteignable d'un bout a l'autre quand il deborde.
                 `justify-center` sur le conteneur qui defile rendrait le DEBUT
                 du rang inatteignable — le debordement se ferait des deux
                 cotes, et rien ne ramene a gauche. --}}
            <div class="mx-auto flex w-max items-center gap-1">
            @foreach($primaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false"
                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
            @if(!empty($secondaryReactionTypes))
            <button
                type="button"
                x-on:click.stop="showMore = !showMore; $nextTick(() => placerMenuReactions())"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-base text-gray-900 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:text-white dark:hover:bg-gray-700"
                aria-label="{{ __('messages.more_reactions') }}"
            >
                ›
            </button>
            @endif
            @foreach($secondaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    x-show="showMore"
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false; showMore = false"
                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
            </div>
        </div>
        @endif

    {{-- TASK-1771 — la feuille basse de T1770 est remplacee par la BARRE DU
         HAUT (`<x-conversation.selection-bar />`), sur le modele du
         « contextual action bar » d'Android que WhatsApp applique. La bulle
         ne rend donc plus de menu du tout : elle SELECTIONNE, et c'est la
         barre qui propose. --}}
    </div>
</div>
