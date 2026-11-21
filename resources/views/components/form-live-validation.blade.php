{{-- TASK-1871 — dire ce qui ne va pas AVANT la soumission.

     Demande de Cyril, 20/11 : « que la validation des champs ne se fasse pas
     à la soumission ». Aujourd'hui le formulaire porte deja `required` et
     `type="email"` : le navigateur bloque donc a l'envoi, avec sa bulle
     native, apres que tout a ete rempli. C'est exactement le moment ou le
     retour sert le moins.

     ## Le moment choisi, et pourquoi

     Au `blur` — quand on QUITTE le champ. C'est la pratique retenue partout
     (GOV.UK, Nielsen Norman) sous le nom « reward early, punish late » :

     - valider pendant la premiere frappe signale en faute quelqu'un qui n'a
       pas fini de taper son nom. C'est le reproche classique fait a ce
       procede, et c'est pourquoi on ne le fait pas ici ;
     - une fois le champ DEJA signale, en revanche, on revalide a la frappe :
       le message doit disparaitre des que la saisie devient correcte, sans
       attendre de quitter le champ une seconde fois.

     ## Ce qui fait autorite

     Rien ici. On lit `checkValidity()`, l'API native du navigateur, donc
     aucune regle metier n'est RECOPIEE cote client — c'est la seule facon de
     ne pas voir les deux versions diverger au premier changement. Le serveur
     reste la seule autorite, et ses messages a lui s'affichent dans le meme
     emplacement.

     `novalidate` desactive la bulle native, pas la validation : les
     contraintes HTML restent lues par `checkValidity()`. Sans JavaScript, le
     formulaire retrouve son comportement d'avant — le navigateur reprend ses
     bulles, et le serveur refuse de toute facon. --}}
<script>
    (function () {
        /* TASK-1871, correctif MASTER — SON formulaire, et aucun autre.
           ===============================================================
           Ce bloc est rendu A L'INTERIEUR du formulaire, juste avant sa
           fermeture : les champs sont donc tous la, et `closest()` remonte
           au formulaire PORTEUR.

           Aucun repli vers `document.querySelector` : il aurait pris le
           premier formulaire marque de la page, pas forcement celui-ci. Le
           jour ou deux formulaires coexistent, le second aurait arme les
           ecouteurs du premier — et ce genre de confusion ne se voit pas,
           elle se subit. Si le rattachement echoue, on ne fait rien : le
           navigateur garde ses propres bulles et le serveur refuse de toute
           facon. */
        var script = document.currentScript;
        var form = script && script.closest('[data-live-validation]');
        if (! form) { return; }

        // A partir d'ici le JavaScript prend la main : on peut retirer la
        // bulle native sans laisser le formulaire sans garde-fou.
        form.setAttribute('novalidate', 'novalidate');

        function blocMessage(champ) {
            if (! champ.name) { return null; }

            /* TASK-1872 correctif 03 — on PREND l'emplacement, on ne le choisit
               plus.
               ================================================================
               `x-input-error` emet desormais un bloc vide et masque sous chaque
               champ nomme, a la place voulue par la vue. C'est lui qu'on
               remplit.

               Le repli ci-dessous ne sert plus que les formulaires qui n'ont
               pas cette ancre. Il garde son defaut connu — `afterend` tombe
               dans la rangee quand le champ vit dans un conteneur `flex` — et
               c'est exactement pour cela qu'il n'est plus le chemin normal. */
            var existant = document.getElementById(champ.name + '-error');
            if (existant) { return existant; }

            var cree = document.createElement('ul');
            cree.id = champ.name + '-error';
            cree.className = 'mt-2 text-sm text-red-600 dark:text-red-400 space-y-1';
            cree.setAttribute('data-live-error', '');
            champ.insertAdjacentElement('afterend', cree);
            return cree;
        }

        function signaler(champ) {
            var bloc = blocMessage(champ);
            if (! bloc) { return; }

            /* TASK-1871, correctif MASTER — UN REFUS DU SERVEUR NE S'EFFACE
               PAS D'ICI.
               =========================================================
               `checkValidity()` ne connait que les contraintes HTML. Un slug
               deja pris par quelqu'un d'autre lui parait parfaitement
               valide : seul le serveur sait qu'il ne l'est pas.

               Sans cette garde, la branche ci-dessous effacait le message
               SERVEUR des que la FORME du champ redevenait correcte. La
               personne voyait l'erreur disparaitre, croyait avoir corrige,
               et ne reapprenait le refus qu'au prochain envoi. C'est le
               defaut releve par MASTER a la revue, et il etait reel.

               Tant que le serveur n'a pas reexamine le champ, son verdict
               reste donc affiche. Il ne sera leve que par la prochaine
               soumission — par lui, pas par nous. */
            if (champ.hasAttribute('data-server-invalid')) { return; }

            if (champ.checkValidity()) {
                champ.removeAttribute('aria-invalid');

                /* TASK-1872 correctif 03 — vider et MASQUER, plutot que
                   detruire. L'emplacement appartient maintenant a la vue : le
                   retirer obligerait a le reinventer au prochain message, donc
                   a retomber dans le placement approximatif qu'on vient de
                   corriger. Seul un bloc que CE script a cree est supprime. */
                bloc.textContent = '';

                if (bloc.hasAttribute('data-live-error')) {
                    bloc.remove();
                } else {
                    bloc.hidden = true;
                }

                return;
            }

            champ.setAttribute('aria-invalid', 'true');
            champ.setAttribute('aria-describedby', champ.name + '-error');

            /* TASK-1872 correctif 02 — « Veuillez respecter le format
               demande » ne dit pas QUEL format.
               ===================================================
               `validationMessage` est excellent pour `required`, `minlength`
               ou `type="email"` : le navigateur le rend dans la langue de la
               personne, et il est precis. Pour `pattern`, en revanche, il se
               reduit a une phrase generique qui n'enonce aucune regle.

               Un champ peut donc fournir sa propre phrase. On la prefere
               SEULEMENT quand c'est la forme qui est en cause
               (`patternMismatch`) : partout ailleurs le navigateur reste
               meilleur, et surtout deja traduit.

               Cette phrase n'est pas une seconde redaction : le champ la
               recoit depuis la MEME clef de langue que le refus du serveur
               (`profile.slug_invalid`). Une seule formulation, deux moments.*/
            var message = champ.validationMessage;

            if (champ.validity && champ.validity.patternMismatch) {
                message = champ.getAttribute('data-message-forme') || message;
            }

            bloc.textContent = '';
            var ligne = document.createElement('li');
            ligne.textContent = message;
            bloc.appendChild(ligne);
            // TASK-1872 correctif 03 — l'emplacement emis par la vue arrive
            // masque : il faut le rendre visible pour qu'il porte le message.
            bloc.hidden = false;
        }

        /* TASK-1872 correctif 01 — AMENER A L'ECRAN CE QUI EST ATTEIGNABLE.
           ==================================================================
           La presentation est devenue obligatoire, et son champ est un
           `<textarea>` que TipTap MASQUE au montage
           (`textarea.classList.add('hidden')`). Un champ masque ne recoit pas
           le focus et ne se defile pas : sans cette resolution, « Enregistrer »
           sur une presentation vide n'aurait RIEN fait du tout — ni message
           visible, ni deplacement. Le pire des resultats : un bouton inerte.

           On remonte donc au conteneur de l'editeur et on vise sa zone
           d'edition, qui est, elle, bien visible et focusable. `offsetParent`
           a `null` est le test le plus sur de « ce n'est pas rendu » : il
           couvre `display:none` pose sur le champ comme sur n'importe lequel
           de ses parents.

           Repli final sur le champ lui-meme : au pire on ne bouge pas, ce qui
           reste moins faux que de viser un element inexistant. */
        function cibleAtteignable(champ) {
            if (champ.offsetParent !== null) { return champ; }

            var editeur = champ.closest('[data-tiptap-container]');

            return (editeur && editeur.querySelector('.ProseMirror')) || editeur || champ;
        }

        form.querySelectorAll('input, select, textarea').forEach(function (champ) {
            /* TASK-1872 correctif 02 — UN CHAMP MASQUE NE PERD JAMAIS LE
               FOCUS, donc il ne se signale jamais.
               ====================================================
               Demande de Cyril, 21/11 : l'alerte sur « Présentation » doit
               paraitre AVANT la soumission. Elle ne paraissait pas, et la
               raison n'etait pas le script : le `<textarea>` de la
               presentation est masque par TipTap. Un element masque ne recoit
               pas le focus, donc n'emet pas de `blur` — l'ecouteur ci-dessous
               ne pouvait tout simplement jamais partir. Le seul retour
               possible restait la soumission.

               La zone d'edition, elle, prend bien le focus. On ecoute donc
               `focusout` sur le conteneur de l'editeur : quitter la zone de
               redaction vaut quitter le champ. C'est le meme moment, sur le
               seul element qui le connaisse.

               Le `blur` reste pose sur le champ lui-meme : si TipTap ne monte
               pas (JavaScript partiel, erreur de bundle), le `<textarea>`
               demeure visible et c'est lui qui emettra. Les deux chemins
               appellent `signaler()`, qui est idempotent.

               ⚠️ Le rattachement ne teste PAS la visibilite : ce script
               s'execute a l'analyse de la page, donc AVANT que TipTap ne
               monte et ne masque le champ. A cet instant le `<textarea>` est
               encore visible, et un test sur `offsetParent` aurait toujours
               repondu « visible » — l'ecouteur n'aurait jamais ete pose. On
               se fie donc a la seule chose qui soit vraie des l'analyse : le
               champ est-il DANS un conteneur d'editeur ? */
            var editeur = champ.closest('[data-tiptap-container]');

            if (editeur) {
                editeur.addEventListener('focusout', function (e) {
                    /* On quitte l'editeur, ou on se deplace DEDANS ?
                       -------------------------------------------
                       `focusout` remonte : passer de la zone de texte a un
                       bouton de la barre d'outils le declenche aussi. Signaler
                       la ferait surgir une alerte « obligatoire » au moment
                       precis ou l'on clique sur « gras » pour commencer a
                       ecrire — l'inverse de ce qu'on cherche.
                       `relatedTarget` porte l'element qui PREND le focus ;
                       `null` (clic hors de toute cible focusable) compte comme
                       une sortie. */
                    if (e.relatedTarget && editeur.contains(e.relatedTarget)) { return; }

                    signaler(champ);
                });
            }

            champ.addEventListener('blur', function () {
                signaler(champ);
            });

            champ.addEventListener('input', function () {
                // Seulement si le champ a DEJA ete signale : on corrige en
                // direct, on ne sanctionne pas une saisie en cours.
                //
                // TASK-1872 correctif 02 — cela vaut aussi pour la
                // presentation : TipTap repercute chaque frappe sur le champ
                // masque par un `dispatchEvent(new Event('input'))`, donc le
                // message disparait des que la saisie devient correcte, sans
                // attendre de quitter l'editeur.
                if (champ.getAttribute('aria-invalid') === 'true') {
                    signaler(champ);
                }
            });
        });

        form.addEventListener('submit', function (e) {
            if (form.checkValidity()) { return; }

            // Inutile de faire un aller-retour serveur pour apprendre ce que
            // le navigateur sait deja : on s'arrete, on signale tout, et on
            // amene au premier champ fautif — le meme geste qu'au retour du
            // serveur.
            e.preventDefault();

            var premier = null;
            form.querySelectorAll('input, select, textarea').forEach(function (champ) {
                signaler(champ);
                // Un champ refuse par le serveur compte comme fautif, meme si
                // sa forme est irreprochable : c'est tout l'objet de la garde
                // ci-dessus.
                var fautif = champ.hasAttribute('data-server-invalid') || ! champ.checkValidity();
                if (! premier && fautif) { premier = champ; }
            });

            if (premier) {
                var cible = cibleAtteignable(premier);
                cible.scrollIntoView({ block: 'center', behavior: 'smooth' });
                cible.focus({ preventScroll: true });
            }
        });
    })();
</script>
