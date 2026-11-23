<?php

// TASK-1898 — page publique de suppression de compte (/suppression-de-compte),
// exigee par Google Play. Le texte decrit le parcours REEL :
// ProfileController::close() (desactivation immediate + demande d'effacement)
// puis l'effacement execute par un administrateur (UserDeletionExecutor, selon
// UserDataLifecycleRegistry). Aucun effacement automatique n'est promis.

return [
    'title' => 'Supprimer votre compte et vos données',
    'meta_description' => 'Comment supprimer votre compte BouclePro et ce que deviennent vos données.',
    'updated' => 'Version du 24 novembre 2026',
    'intro' => 'Cette page s’applique au site bouclepro.com, aux espaces des organisations qui l’utilisent et à l’application Android BouclePro, éditée par l’Association AMT.',
    'privacy_link' => 'Politique de confidentialité',
    'legal_link' => 'Mentions légales',

    'sections' => [
        [
            'title' => 'Depuis l’application ou le site (recommandé)',
            'steps' => [
                'Connectez-vous à BouclePro.',
                'Ouvrez votre profil, puis « Sécurité du compte ».',
                'Dans « Fermer mon compte », cochez « Demander également l’effacement de mes données personnelles », saisissez votre mot de passe et confirmez.',
            ],
            'after' => [
                'Votre compte est désactivé immédiatement : vous êtes déconnecté, vous ne pouvez plus vous connecter et votre profil n’est plus visible. Votre demande d’effacement est ensuite examinée et exécutée par un administrateur.',
            ],
        ],
        [
            'title' => 'Sans accès à votre compte',
            'paragraphs' => [
                'Écrivez à l’éditeur, aux coordonnées des mentions légales, depuis l’adresse email de votre compte, en indiquant « Suppression de compte BouclePro » et l’organisation à laquelle vous appartenez. Nous pourrons vous demander de confirmer votre identité.',
            ],
            'link' => 'legal',
        ],
        [
            'title' => 'Ce qui est supprimé',
            'items' => [
                'votre compte et votre profil ;',
                'vos appartenances aux Boucles, vos réactions, favoris et badges ;',
                'vos préférences de notification, vos notifications et vos abonnements aux notifications sur vos appareils ;',
                'vos échanges avec les assistants d’intelligence artificielle ;',
                'vos inscriptions aux ateliers.',
            ],
        ],
        [
            'title' => 'Ce qui est conservé sans votre nom',
            'paragraphs' => [
                'Vos messages et commentaires dans les espaces partagés, vos évaluations et vos contributions aux Boucles restent visibles, sans votre nom, pour que les échanges restent compréhensibles pour les autres membres.',
            ],
        ],
        [
            'title' => 'Ce qui est confié à un autre membre',
            'paragraphs' => [
                'Vos articles, publications, demandes d’aide et services proposés peuvent être confiés à un autre membre de votre organisation, qui en devient l’auteur.',
            ],
        ],
        [
            'title' => 'Ce qui peut être conservé',
            'items' => [
                'les journaux techniques de connexion et d’envoi d’emails, les signalements et rapports de modération ;',
                'les fichiers que vous avez déposés dans des Dossiers partagés ;',
                'les données que la loi nous oblige à conserver, pendant la durée prévue par la loi ;',
                'les sauvegardes de la base de données, 7 jours au plus.',
            ],
        ],
        [
            'title' => 'Ce qui peut retarder la suppression',
            'paragraphs' => [
                'Certaines données engagent une autre personne : un échange, un vote ou une écriture de points, par exemple, ou un rôle de responsable d’organisation. Tant que ces situations existent, l’effacement ne peut pas être exécuté ; nous vous contactons alors pour les régulariser.',
            ],
        ],
        [
            'title' => 'Délai',
            'paragraphs' => [
                'Nous traitons votre demande dans un délai d’un mois, sous réserve des exceptions et prolongations prévues par la loi.',
            ],
        ],
        [
            'title' => 'Fermer sans effacer',
            'paragraphs' => [
                'Si vous fermez votre compte sans cocher la demande d’effacement, il est seulement désactivé : vos contributions et votre historique sont conservés, et seul un administrateur peut rouvrir un compte fermé. Vous pouvez demander l’effacement plus tard, de la manière décrite ci-dessus.',
            ],
        ],
    ],
];
