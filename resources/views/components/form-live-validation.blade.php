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
            var existant = document.getElementById(champ.name + '-error');
            if (existant) { return existant; }

            // Aucun bloc serveur sous ce champ : on en pose un, au meme
            // endroit et dans le meme style, pour que les deux origines de
            // message soient indiscernables a l'oeil.
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
                if (bloc.hasAttribute('data-live-error')) { bloc.remove(); }
                return;
            }

            champ.setAttribute('aria-invalid', 'true');
            champ.setAttribute('aria-describedby', champ.name + '-error');
            // `validationMessage` est rendu par le navigateur dans la langue
            // de l'utilisateur : rien a traduire, rien a maintenir.
            bloc.textContent = '';
            var ligne = document.createElement('li');
            ligne.textContent = champ.validationMessage;
            bloc.appendChild(ligne);
            bloc.setAttribute('data-live-error', '');
        }

        form.querySelectorAll('input, select, textarea').forEach(function (champ) {
            champ.addEventListener('blur', function () {
                signaler(champ);
            });

            champ.addEventListener('input', function () {
                // Seulement si le champ a DEJA ete signale : on corrige en
                // direct, on ne sanctionne pas une saisie en cours.
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
                premier.scrollIntoView({ block: 'center', behavior: 'smooth' });
                premier.focus({ preventScroll: true });
            }
        });
    })();
</script>
