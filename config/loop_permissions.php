<?php

/*
|--------------------------------------------------------------------------
| Loop permissions
|--------------------------------------------------------------------------
|
| Two sections that must not be confused.
|
| `invariants` is DESCRIPTIVE ONLY. These are security preconditions, enforced
| in services, policies, scopes and tenant checks — never resolved as a
| permission, never persisted, never surfaced as a toggle. They are listed here
| so an administration screen can show them with a padlock and so this file
| documents the whole picture. There is no code path that can store
| `tenant.isolation = false`.
|
| `permissions` are real business capabilities. `locked: true` means the
| capability exists and is shown, but neither a global setting nor an
| Organization override may change it.
|
| `role_defaults` is THE BASELINE, expressed per role. `type_overrides` holds
| ONLY a type's differences from that baseline — a type absent from it inherits
| entirely. Adding `learning` later declares its differences and nothing else;
| it never copies a matrix.
|
| Resolution order lives in LoopPermissionResolver, not here.
|
*/

return [

    /*
     * Security preconditions. Descriptive only — see the header.
     */
    'invariants' => [
        'tenant.isolation' => 'Une Boucle appartient à une seule Organization ; aucun accès ni rattachement cross-Organization.',
        'account.active' => 'Le compte doit être actif et non banni.',
        'membership.active' => 'Hors autorités plateforme et Organization, une adhésion active est requise.',
        'loop.last_owner' => 'Une Boucle conserve en permanence au moins un propriétaire actif.',
        'loop.private_confidentiality' => 'Le contenu d\'une Boucle privée n\'est jamais exposé à un non-membre.',
        'transaction.integrity' => 'Les transitions de rôle sont atomiques et verrouillées.',
    ],

    /*
     * Business capabilities.
     *
     * `requires_card` is set ONLY where the capability genuinely depends on a
     * card being active, and is never inferred from the permission name — a
     * structural capability such as loops.manage_owners depends on none. Keys
     * are validated against config/loop_cards.php.
     *
     * `read` marks a capability that only consults. It is what an archived Loop
     * still allows: the resolver refuses every other capability once a Loop is
     * archived, super-admin included, with `loops.archive` as the single
     * exception — it is what reactivates.
     *
     * The default is deliberately the strict one. A capability added later
     * without `read` is treated as a write and denied on an archived Loop:
     * forgetting the flag costs a false refusal, never a silent write into an
     * archive.
     */
    'permissions' => [

        // ── Boucle ──────────────────────────────────────────────────────────
        'loops.view' => [
            'module' => 'loops', 'label_fr' => 'Consulter la Boucle', 'label_en' => 'View the Loop',
            'description' => 'Accéder au workspace de la Boucle.', 'locked' => false,
            'read' => true,
        ],
        'loops.update_identity' => [
            'module' => 'loops', 'label_fr' => 'Modifier l\'identité', 'label_en' => 'Edit identity',
            'description' => 'Nom, accroche, description, image de couverture.', 'locked' => false,
        ],
        'loops.change_type' => [
            'module' => 'loops', 'label_fr' => 'Changer le type', 'label_en' => 'Change the type',
            'description' => 'Applique le socle de Cards du nouveau type, sans rien supprimer.', 'locked' => false,
        ],
        'loops.manage_cards' => [
            'module' => 'loops', 'label_fr' => 'Gérer les Cards', 'label_en' => 'Manage cards',
            'description' => 'Activer ou désactiver les composants de la Boucle.', 'locked' => false,
        ],
        'loops.archive' => [
            'module' => 'loops', 'label_fr' => 'Archiver la Boucle', 'label_en' => 'Archive the Loop',
            'description' => 'Rendre la Boucle inactive sans la supprimer.', 'locked' => false,
        ],
        'loops.manage_owners' => [
            'module' => 'loops', 'label_fr' => 'Gérer les propriétaires', 'label_en' => 'Manage owners',
            'description' => 'Nommer ou rétrograder un propriétaire. Le dernier propriétaire actif reste protégé.',
            'locked' => true,
        ],
        'loops.manage_facilitators' => [
            'module' => 'loops', 'label_fr' => 'Gérer les Animateurs', 'label_en' => 'Manage facilitators',
            'description' => 'Nommer ou rétrograder un Animateur.', 'locked' => false,
        ],

        // ── Membres ─────────────────────────────────────────────────────────
        'loop_members.view' => [
            'module' => 'members', 'label_fr' => 'Voir les membres', 'label_en' => 'View members',
            'description' => 'Consulter la liste des membres de la Boucle.', 'locked' => false,
            'read' => true,
            'requires_card' => 'core.members',
        ],
        'loop_members.invite' => [
            'module' => 'members', 'label_fr' => 'Inviter', 'label_en' => 'Invite',
            'description' => 'Envoyer une invitation à rejoindre la Boucle.', 'locked' => false,
        ],
        'loop_members.add' => [
            'module' => 'members', 'label_fr' => 'Ajouter un membre', 'label_en' => 'Add a member',
            'description' => 'Ajouter une personne déjà présente dans l\'Organization.', 'locked' => false,
        ],
        'loop_members.remove' => [
            'module' => 'members', 'label_fr' => 'Retirer un membre', 'label_en' => 'Remove a member',
            'description' => 'Retirer une personne de la Boucle.', 'locked' => false,
        ],
        'loop_members.review_join_requests' => [
            'module' => 'members', 'label_fr' => 'Traiter les demandes d\'adhésion', 'label_en' => 'Review join requests',
            'description' => 'Accepter ou refuser une demande.', 'locked' => false,
        ],
        'loop_members.change_role' => [
            'module' => 'members', 'label_fr' => 'Changer un rôle', 'label_en' => 'Change a role',
            'description' => 'Modifier le rôle d\'un membre dans la Boucle.', 'locked' => false,
        ],

        // ── Manifeste ───────────────────────────────────────────────────────
        'manifesto.view' => [
            'module' => 'manifesto', 'label_fr' => 'Consulter le Manifeste', 'label_en' => 'View the Manifesto',
            'description' => 'Lire le texte fondateur.', 'locked' => false,
            'read' => true,
            'requires_card' => 'core.manifesto',
        ],
        'manifesto.update' => [
            'module' => 'manifesto', 'label_fr' => 'Modifier le Manifeste', 'label_en' => 'Edit the Manifesto',
            'description' => 'Rédiger et désigner le texte fondateur.', 'locked' => false,
            'requires_card' => 'core.manifesto',
        ],
        'manifesto.publish' => [
            'module' => 'manifesto', 'label_fr' => 'Publier le Manifeste', 'label_en' => 'Publish the Manifesto',
            'description' => 'Rendre le Manifeste visible sur la fiche de présentation. La confidentialité d\'une Boucle privée reste prioritaire.',
            'locked' => false,
            'requires_card' => 'core.manifesto',
        ],
        'manifesto.manage_sources' => [
            'module' => 'manifesto', 'label_fr' => 'Gérer les sources', 'label_en' => 'Manage sources',
            'description' => 'Rattacher ou détacher des documents de Dossiers.', 'locked' => false,
            'requires_card' => 'core.manifesto',
        ],

        // ── Roadmap ─────────────────────────────────────────────────────────
        'roadmap.view' => [
            'module' => 'roadmap', 'label_fr' => 'Consulter la Roadmap', 'label_en' => 'View the roadmap',
            'description' => 'Voir les éléments de la feuille de route.', 'locked' => false,
            'read' => true,
            'requires_card' => 'core.roadmap',
        ],
        'roadmap.manage' => [
            'module' => 'roadmap', 'label_fr' => 'Gérer la Roadmap', 'label_en' => 'Manage the roadmap',
            'description' => 'Créer, déplacer, modérer les éléments.', 'locked' => false,
            'requires_card' => 'core.roadmap',
        ],

        // ── ChatLoop ────────────────────────────────────────────────────────
        /*
         * Le Support de cours.
         *
         * Deux capacites, pas six. Modules et Sequences n'ont pas de permission
         * a eux : ce ne sont pas des Cards, et ce qu'on a le droit d'en faire
         * depend entierement de la Card qui les porte. Une permission par
         * niveau de contenu aurait laisse croire qu'on peut gerer les Sequences
         * sans gerer le Support de cours.
         *
         * `requires_card` est bien la : desactiver la Card ferme reellement la
         * capacite — ici la dependance est genuine, la capacite n'existe pas
         * sans elle.
         */
        'course_material.view' => [
            'module' => 'course_material', 'label_fr' => 'Consulter le Support de cours', 'label_en' => 'View the course material',
            'description' => 'Voir les Modules et les Séquences du Support de cours.', 'locked' => false,
            'read' => true,
            'requires_card' => 'training.course_material',
        ],

        'course_material.manage' => [
            'module' => 'course_material', 'label_fr' => 'Gérer le Support de cours', 'label_en' => 'Manage the course material',
            'description' => 'Créer, modifier, classer et supprimer les Modules et les Séquences.', 'locked' => false,
            'requires_card' => 'training.course_material',
        ],

        /*
         * La Progression.
         *
         * `view` est **sa propre** progression : tout stagiaire l'a. `manage`
         * est le tableau de bord de l'Animateur — voir celle de tout le monde,
         * valider, demander une reprise, debloquer. Deux capacites, parce que
         * ce sont deux gestes : suivre son parcours et conduire celui des
         * autres.
         */
        'progression.view' => [
            'module' => 'progression', 'label_fr' => 'Suivre sa progression', 'label_en' => 'Track own progress',
            'description' => 'Voir son propre parcours et déclarer ses étapes terminées.', 'locked' => false,
            'read' => true,
            'requires_card' => 'training.progression',
        ],

        /*
         * Meme raison qu'`assignments.submit` : declarer une etape terminee est
         * une **ecriture**, et une Boucle archivee ne doit pas l'accepter.
         */
        'progression.complete' => [
            'module' => 'progression', 'label_fr' => 'Avancer dans son parcours', 'label_en' => 'Advance own progress',
            'description' => 'Ouvrir une étape et la déclarer terminée.', 'locked' => false,
            'requires_card' => 'training.progression',
        ],

        'progression.manage' => [
            'module' => 'progression', 'label_fr' => 'Suivre la progression de tous', 'label_en' => 'Track everyone\'s progress',
            'description' => 'Voir la progression de chaque membre, valider, demander une reprise, débloquer.', 'locked' => false,
            'requires_card' => 'training.progression',
        ],

        /*
         * Les Travaux a rendre.
         *
         * `view` : voir les Travaux et **rendre les siens**. `manage` : les
         * creer, les modifier, lire les remises de tout le monde, valider,
         * demander une reprise. Deux capacites parce que ce sont deux gestes.
         */
        'assignments.view' => [
            'module' => 'assignments', 'label_fr' => 'Rendre ses Travaux', 'label_en' => 'Submit own assignments',
            'description' => 'Voir les Travaux à rendre et remettre les siens.', 'locked' => false,
            'read' => true,
            'requires_card' => 'training.assignments',
        ],

        /*
         * **Remettre est une ecriture.** Elle a donc sa propre permission, sans
         * `read`, et non `assignments.view`.
         *
         * La raison n'est pas cosmetique : le resolveur refuse toute capacite
         * non-`read` sur une Boucle archivee. Garder une ecriture derriere une
         * permission de lecture defaisait cet invariant en silence — une Boucle
         * archivee acceptait encore les remises.
         */
        'assignments.submit' => [
            'module' => 'assignments', 'label_fr' => 'Remettre un Travail', 'label_en' => 'Submit an assignment',
            'description' => 'Déposer ou modifier sa propre remise.', 'locked' => false,
            'requires_card' => 'training.assignments',
        ],

        'assignments.manage' => [
            'module' => 'assignments', 'label_fr' => 'Gérer les Travaux', 'label_en' => 'Manage assignments',
            'description' => 'Créer et modifier les Travaux, lire les remises, valider, demander une reprise.', 'locked' => false,
            'requires_card' => 'training.assignments',
        ],

        'quiz.view' => [
            'module' => 'quiz', 'label_fr' => 'Consulter les QCM', 'label_en' => 'View quizzes',
            'description' => 'Voir les QCM et ses propres résultats.', 'locked' => false,
            'read' => true,
            'requires_card' => 'training.quiz',
        ],

        /*
         * Passer un QCM est une **ecriture** : elle a donc sa propre
         * permission, sans `read`. Une Boucle archivee ne recoit plus de
         * tentative — c'est le meme invariant que pour les remises.
         */
        'quiz.attempt' => [
            'module' => 'quiz', 'label_fr' => 'Passer un QCM', 'label_en' => 'Take a quiz',
            'description' => 'Soumettre une tentative.', 'locked' => false,
            'requires_card' => 'training.quiz',
        ],

        'quiz.manage' => [
            'module' => 'quiz', 'label_fr' => 'Gérer les QCM', 'label_en' => 'Manage quizzes',
            'description' => 'Créer et modifier les QCM, voir les résultats de chacun, ouvrir le détail.', 'locked' => false,
            'requires_card' => 'training.quiz',
        ],

        'journal.view' => [
            'module' => 'journal', 'label_fr' => 'Lire le Journal', 'label_en' => 'Read the journal',
            'description' => 'Voir les entrées du Journal de la Boucle.', 'locked' => false,
            'read' => true,
            'requires_card' => 'core.journal',
        ],

        /*
         * Ecrire dans le Journal est une **ecriture** : sa permission ne porte
         * donc pas `read`, et une Boucle archivee la refuse. C'est l'invariant
         * pose apres la revue de TASK-1100.
         */
        'journal.write' => [
            'module' => 'journal', 'label_fr' => 'Écrire dans le Journal', 'label_en' => 'Write in the journal',
            'description' => 'Ajouter une entrée, ou garder un message du ChatLoop.', 'locked' => false,
            'requires_card' => 'core.journal',
        ],

        'journal.manage' => [
            'module' => 'journal', 'label_fr' => 'Gérer le Journal', 'label_en' => 'Manage the journal',
            'description' => 'Corriger ou retirer les entrées de tout le monde.', 'locked' => false,
            'requires_card' => 'core.journal',
        ],

        'chatloop.view' => [
            'module' => 'chatloop', 'label_fr' => 'Consulter ChatLoop', 'label_en' => 'View ChatLoop',
            'description' => 'Lire la conversation de la Boucle.', 'locked' => false,
            'read' => true,
        ],
        'chatloop.post' => [
            'module' => 'chatloop', 'label_fr' => 'Publier dans ChatLoop', 'label_en' => 'Post in ChatLoop',
            'description' => 'Écrire un message.', 'locked' => false,
        ],
        'chatloop.manage' => [
            'module' => 'chatloop', 'label_fr' => 'Animer ChatLoop', 'label_en' => 'Moderate ChatLoop',
            'description' => 'Épingler, masquer ou supprimer un message.', 'locked' => false,
        ],

        // ── Evenements ──────────────────────────────────────────────────────
        //
        // `events.publish_organization` ne suffit jamais a elle seule : le
        // service exige aussi que la Boucle ne soit pas privee. Une Boucle
        // privee ne remonte pas dans l'agenda d'Organization, quel que soit le
        // rang de qui demande.
        'events.view' => [
            'module' => 'events', 'label_fr' => 'Consulter les Evenements', 'label_en' => 'View events',
            'description' => 'Voir l\'agenda de la Boucle et le detail des rencontres.',
            'locked' => false,
            'read' => true,
            'requires_card' => 'core.events',
        ],
        'events.create' => [
            'module' => 'events', 'label_fr' => 'Proposer une rencontre', 'label_en' => 'Propose an event',
            'description' => 'Creer un Evenement dans la Boucle. Tout membre actif le peut par defaut.',
            'locked' => false,
            'requires_card' => 'core.events',
        ],
        'events.respond' => [
            'module' => 'events', 'label_fr' => 'Repondre a une invitation', 'label_en' => 'Respond to an event',
            'description' => 'Indiquer si l\'on participe, et changer d\'avis tant que l\'Evenement tient.',
            'locked' => false,
            'requires_card' => 'core.events',
        ],
        'events.manage' => [
            'module' => 'events', 'label_fr' => 'Gerer tous les Evenements', 'label_en' => 'Manage all events',
            'description' => 'Modifier, annuler ou supprimer un Evenement dont on n\'est pas l\'auteur. Chacun garde la main sur les siens.',
            'locked' => false,
            'requires_card' => 'core.events',
        ],
        'events.publish_organization' => [
            'module' => 'events', 'label_fr' => 'Publier a l\'Organization', 'label_en' => 'Publish to the Organization',
            'description' => 'Rendre une rencontre visible par tous les membres de l\'Organization. Impossible depuis une Boucle privee.',
            'locked' => false,
            'requires_card' => 'core.events',
        ],

        // ── Dossiers ────────────────────────────────────────────────────────
        //
        // La Card Dossiers n'a pas de regle a elle : elle expose le Dossier
        // racine de la Boucle, dont DossierPolicy tient deja les droits. Ces
        // trois capacites servent a une seule chose — permettre au resolveur de
        // refuser quand la Card est eteinte, via `requires_card`. Le reste des
        // verifications reste ou il est.
        'dossiers.view' => [
            'module' => 'dossiers', 'label_fr' => 'Consulter le Dossier', 'label_en' => 'View the Dossier',
            'description' => 'Voir les Articles, Series et fichiers du Dossier racine de la Boucle.',
            'locked' => false,
            // La seule lecture : la seule qui survive a l'archivage.
            'read' => true,
            'requires_card' => 'core.dossiers',
        ],
        'dossiers.create_article' => [
            'module' => 'dossiers', 'label_fr' => 'Ecrire un Article', 'label_en' => 'Write an article',
            'description' => 'Creer un Article dans le Dossier racine. Le droit reel vient de DossierPolicy, qui delegue a LoopPolicy::update : proprietaire ou admin d\'Organization. Cette entree sert la porte `requires_card`.',
            'locked' => false,
            'requires_card' => 'core.dossiers',
        ],
        'dossiers.upload_file' => [
            'module' => 'dossiers', 'label_fr' => 'Deposer un fichier', 'label_en' => 'Upload a file',
            'description' => 'Ajouter un fichier au Dossier racine. Meme porte que l\'ecriture d\'Article : DossierPolicy delegue a LoopPolicy::update.',
            'locked' => false,
            'requires_card' => 'core.dossiers',
        ],

        // ── Sondages ────────────────────────────────────────────────────────
        //
        // `requires_card` fait tout le travail de la Card desactivee : sans la
        // Card, ces quatre capacites sont refusees, y compris sur une route
        // appelee directement. Aucun `if` n'est ajoute nulle part.
        'polls.view' => [
            'module' => 'polls', 'label_fr' => 'Consulter les Sondages', 'label_en' => 'View polls',
            'description' => 'Voir les Sondages de la Boucle et leurs resultats selon les regles de visibilite.',
            'locked' => false,
            // La seule lecture : la seule qui survive a l'archivage.
            'read' => true,
            'requires_card' => 'core.polls',
        ],
        'polls.create' => [
            'module' => 'polls', 'label_fr' => 'Creer un Sondage', 'label_en' => 'Create a poll',
            'description' => 'Poser une question aux membres. Tout membre actif le peut par defaut.',
            'locked' => false,
            'requires_card' => 'core.polls',
        ],
        'polls.vote' => [
            'module' => 'polls', 'label_fr' => 'Voter', 'label_en' => 'Vote',
            'description' => 'Repondre a un Sondage ouvert, et changer d\'avis tant qu\'il l\'est.',
            'locked' => false,
            'requires_card' => 'core.polls',
        ],
        'polls.manage' => [
            'module' => 'polls', 'label_fr' => 'Gerer tous les Sondages', 'label_en' => 'Manage all polls',
            'description' => 'Modifier, cloturer ou supprimer un Sondage dont on n\'est pas l\'auteur. Chacun garde la main sur les siens.',
            'locked' => false,
            'requires_card' => 'core.polls',
        ],

        // ── Invitations ─────────────────────────────────────────────────────
        'invitations.view' => [
            'module' => 'invitations', 'label_fr' => 'Voir les invitations', 'label_en' => 'View invitations',
            'description' => 'Consulter les invitations émises pour la Boucle.', 'locked' => false,
            'read' => true,
        ],
        'invitations.revoke' => [
            'module' => 'invitations', 'label_fr' => 'Révoquer une invitation', 'label_en' => 'Revoke an invitation',
            'description' => 'Annuler une invitation en attente.', 'locked' => false,
        ],
    ],

    /*
     * THE BASELINE. Everything a role can do unless a type says otherwise.
     * A permission absent from a role's list is denied.
     */
    'role_defaults' => [

        /*
         * Le proprietaire mene sa Boucle : il l'archive, la reactive, en change
         * le type, nomme ses pairs. Il ne configure pas ses Cards.
         *
         * `loops.manage_cards` lui etait accordee depuis TASK-1079 alors que ses
         * deux seuls consommateurs sont des ecrans administratifs — la matrice
         * annoncait donc un droit que l'interface ne donnait pas. La composition
         * locale reste au SuperAdmin et a l'Admin d'Organization (TASK-1083), qui
         * l'obtiennent par les etapes 3 et 4 du resolveur et non par ce socle.
         */
        'owner' => [
            'loops.view', 'loops.update_identity', 'loops.change_type',
            'loops.archive', 'loops.manage_owners', 'loops.manage_facilitators',
            'loop_members.view', 'loop_members.invite', 'loop_members.add', 'loop_members.remove',
            'loop_members.review_join_requests', 'loop_members.change_role',
            'manifesto.view', 'manifesto.update', 'manifesto.publish', 'manifesto.manage_sources',
            'roadmap.view', 'roadmap.manage',
            'chatloop.view', 'chatloop.post', 'chatloop.manage',
            'invitations.view', 'invitations.revoke',
            'polls.view', 'polls.create', 'polls.vote', 'polls.manage',
            'events.view', 'events.create', 'events.respond', 'events.manage',
            'events.publish_organization',
            'dossiers.view', 'dossiers.create_article', 'dossiers.upload_file',
            'course_material.view', 'course_material.manage',
            'progression.view', 'progression.complete', 'progression.manage',
            'assignments.view', 'assignments.submit', 'assignments.manage',
            'quiz.view', 'quiz.attempt', 'quiz.manage',
            'journal.view', 'journal.write', 'journal.manage',
        ],

        /*
         * The facilitator runs the Loop's daily life but does not own it:
         * no naming of owners or facilitators, no type change, no structural
         * cards, no archiving — and no publishing of the Manifesto, which
         * stays an owner's decision by default while remaining configurable.
         */
        'facilitator' => [
            'loops.view',
            'loop_members.view', 'loop_members.invite', 'loop_members.review_join_requests',
            'manifesto.view', 'manifesto.update', 'manifesto.manage_sources',
            'roadmap.view', 'roadmap.manage',
            'chatloop.view', 'chatloop.post', 'chatloop.manage',
            'invitations.view', 'invitations.revoke',
            'polls.view', 'polls.create', 'polls.vote', 'polls.manage',
            'events.view', 'events.create', 'events.respond', 'events.manage',
            'events.publish_organization',
            'dossiers.view',
            // Le Support de cours, monte et tenu : dans une Boucle Formation,
            // c'est le travail quotidien du facilitateur.
            'course_material.view', 'course_material.manage',
            'progression.view', 'progression.complete', 'progression.manage',
            'assignments.view', 'assignments.submit', 'assignments.manage',
            'quiz.view', 'quiz.attempt', 'quiz.manage',
            'journal.view', 'journal.write', 'journal.manage',
        ],

        /*
         * Le membre pose ses questions et vote comme les autres : un Sondage est
         * un outil de conversation, pas une prerogative. Il garde la main sur
         * les siens — `polls.create` suffit a les modifier et a les clore — mais
         * ne touche pas a ceux des autres, faute de `polls.manage`.
         */
        'member' => [
            'loops.view',
            'loop_members.view',
            'manifesto.view',
            'roadmap.view',
            'chatloop.view', 'chatloop.post',
            'polls.view', 'polls.create', 'polls.vote',
            'events.view', 'events.create', 'events.respond',
            'dossiers.view',
            // Consulter, pas monter. Suivre une formation, c'est lire son
            // Support ; le construire est le travail de qui l'anime.
            'course_material.view',
            // Sa progression, pas celle des autres.
            'progression.view', 'progression.complete',
            // Ses Travaux, pas ceux des autres.
            'assignments.view', 'assignments.submit',
            'quiz.view', 'quiz.attempt',
            // Ses entrees, pas celles des autres.
            'journal.view', 'journal.write',
        ],
    ],

    /*
     * ONLY a type's differences from the baseline. A type absent here — or a
     * role absent from a listed type — inherits entirely.
     *
     * `grant` adds, `revoke` removes. Both are ignored for locked permissions.
     *
     * DELIBERATELY EMPTY. The engine is implemented, tested and administrable,
     * but no business variation ships as a system default: the four types all
     * inherit the role baseline for now.
     *
     * Two variations were written here on the way and removed after review,
     * because each was a product decision nobody had taken:
     *
     *   - training granting manifesto.publish to the facilitator assumed every
     *     facilitator of a training Loop is the teacher responsible for its
     *     framing, which does not follow from the type;
     *   - peer_support revoking loop_members.view from members assumed hiding
     *     the roster is what protects a support group, when a private Loop
     *     already protects its access and its content.
     *
     * Real differences will be configured through the administration matrix,
     * where they are an explicit choice with an author — not a default nobody
     * asked for. Adding one here later stays a one-file change.
     */
    'type_overrides' => [
        // e.g. 'training' => ['facilitator' => ['grant' => ['manifesto.publish']]],
    ],
];
