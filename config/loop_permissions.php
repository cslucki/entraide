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
