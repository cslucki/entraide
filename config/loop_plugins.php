<?php

/*
|--------------------------------------------------------------------------
| Loop plugins — catalogue PLATEFORME
|--------------------------------------------------------------------------
|
| TASK-1614 / SLICE A de la Product Spec V0 « ChatLoop — 3 assistants IA ».
|
| Un plugin de Boucle est une CAPACITE de la plateforme, decrite ici et nulle
| part ailleurs. Le catalogue est un FICHIER, pas une table : ajouter un
| deuxieme plugin doit rester une entree de configuration, comme un cinquieme
| type de Boucle l'est dans config/loop_types.php. Une table de catalogue
| aurait demande une migration, un CRUD et un ecran d'edition pour decrire
| quelque chose que seul un deploiement peut de toute façon livrer.
|
| Ce que ce fichier N'EST PAS :
| - il ne dit PAS si un plugin est disponible quelque part. La disponibilite
|   est une decision du SuperAdmin, PAR Organization, et elle vit en base
|   (`organization_loop_plugins`), lue par LoopPluginAvailabilityService ;
| - il ne dit PAS si une Boucle l'a active — c'est SLICE B ;
| - il n'accorde aucun droit et ne borne aucun tenant.
|
| LA CLE NE BOUGE PLUS
| `key` porte les donnees : les lignes de disponibilite la referencent. Le
| LIBELLE, lui, peut changer — « 3 assistants IA » est un nom fonctionnel
| provisoire, la Product Spec le dit elle-meme. C'est la meme lecon que
| `key = training` (TASK-1116) : renommer la cle aurait fait bouger les
| donnees pour changer un mot.
|
| STATUS
| `experimental` est un ETAT AFFICHE, pas une garde. Il dit au SuperAdmin ce
| qu'il allume. La garde, c'est la disponibilite : fermee par defaut, une
| Organization a la fois.
|
*/

return [

    /*
     * Etats possibles d'un plugin au catalogue.
     *
     * `experimental` : livre, observable, activable Organization par
     * Organization, et retirable aussi vite qu'il a ete donne.
     */
    'statuses' => [
        'experimental',
        'stable',
    ],

    'plugins' => [

        /*
         * Les 3 assistants IA — Aperio, Traverse, Limen.
         *
         * SLICE A ne livre QUE son existence au catalogue et sa disponibilite
         * par Organization. Aucun assistant, aucun prompt, aucun appel
         * provider n'existe encore derriere cette entree : ce que le
         * SuperAdmin autorise ici, c'est le droit pour une Organization de
         * voir arriver la suite, pas une capacite deja rendue.
         */
        'multi_ai_assistants' => [
            'label_key' => 'loops.plugins.multi_ai_assistants.label',
            'description_key' => 'loops.plugins.multi_ai_assistants.description',
            'status' => 'experimental',

            /*
             * TASK-1616 — les trois assistants, et ils sont FIXES en V0.
             *
             * Les cles ne sont pas renommables : elles portent les lignes de
             * `loop_ai_assistants`. Les postures ci-dessous sont les DEFAUTS —
             * une Boucle peut les reecrire, et c'est tout ce qu'elle peut
             * reecrire. Ni le nom, ni l'ordre canonique, ni le modele : la
             * Product Spec V0 les gele, et le provider reste resolu par le
             * mecanisme Organization existant (SLICE C).
             *
             * Une instruction locale ne contourne jamais les couches du
             * dessus — Constitution, Doctrine, Capability. Elle s'y ajoute.
             */
            /*
             * TASK-1621 — le module visible devient « Pour / Contre ».
             *
             * Les CLES TECHNIQUES ne bougent pas : `aperio` porte le role POUR,
             * `traverse` le role CONTRE. Renommer les cles pour un changement
             * d'affichage aurait casse les lignes `loop_ai_assistants` et
             * `loop_plugin_ai_models` deja ecrites, pour un gain nul — meme
             * arbitrage que `key = training` (TASK-1116).
             *
             * `limen` RESTE DECLARE et ses donnees restent en place : il n'est
             * simplement plus lance ni affiche. Aucune migration destructive.
             * Son `order` le place apres les deux roles, ou il dort.
             */
            'assistants' => [
                'aperio' => [
                    // Role POUR. Le libelle est ce que le membre lit.
                    'label' => 'Pour',
                    'order' => 1,
                    'instruction_key' => 'loops.plugins.multi_ai_assistants.assistants.aperio',
                ],
                'traverse' => [
                    // Role CONTRE.
                    'label' => 'Contre',
                    'order' => 2,
                    'instruction_key' => 'loops.plugins.multi_ai_assistants.assistants.traverse',
                ],
                // DORMANT depuis TASK-1621 : ni lance, ni affiche au membre.
                'limen' => [
                    'label' => 'Limen',
                    'order' => 3,
                    'instruction_key' => 'loops.plugins.multi_ai_assistants.assistants.limen',
                    // TASK-1621 — DORMANT, et desormais declare comme tel.
                    //
                    // Le moteur ne le lance plus depuis le pivot, mais les
                    // deux ecrans de reglage continuaient de l'afficher : un
                    // membre lisait « 3 assistants » et pouvait regler une
                    // posture qui ne servait jamais.
                    //
                    // Il RESTE au catalogue, et c'est deliberé : ses lignes
                    // `loop_ai_assistants` et `loop_plugin_ai_models`
                    // existent, les bulles deja publiees portent sa cle, et
                    // `label()` doit encore savoir la rendre. On le retire des
                    // ECRANS, pas des donnees. Aucune migration.
                    'dormant' => true,
                ],
            ],
        ],

    ],

];
