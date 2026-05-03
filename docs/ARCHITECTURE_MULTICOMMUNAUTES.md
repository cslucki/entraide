# Architecture Multisite et Multi-Communautés pour Entraide (v2.0)

## 1. Vision Stratégique : Transformer l'Entraide en un Écosystème Global et Local

L'évolution d'Entraide vers un modèle multi-communautés marque un tournant historique pour la plateforme. Il ne s'agit plus simplement d'une application isolée de troc de services entre particuliers, mais de la naissance d'un véritable **système d'exploitation de la solidarité humaine**. Inspiré par le succès, la robustesse et la flexibilité de modèles comme WordPress Multisite, ce projet permet de mutualiser les coûts d'infrastructure tout en offrant une autonomie quasi-totale aux différentes entités : villes, associations, grandes entreprises, collectifs d'habitants, réseaux d'anciens élèves ou même fédérations sportives.

La vision profonde d'Entraide est celle d'un web décentralisé mais interconnecté. Dans un monde de plus en plus globalisé et parfois impersonnel, le besoin de proximité, de lien social et de confiance locale n'a jamais été aussi fort. Chaque groupe humain doit pouvoir créer son propre "jardin d'entraide", un espace sécurisé avec ses propres membres, sa propre charte et ses propres valeurs, tout en restant lié à une forêt plus vaste de compétences. C'est la force du concept **"Glocal"** : l'agilité et la chaleur d'une petite communauté alliée à la puissance technologique et à la sécurité d'une plateforme nationale de référence.

### Les Quatre Piliers Fondamentaux du Modèle Multisite

1.  **Le Hub Global (La Racine) :**
    Situé sur `entraide.fr`, il sert de vitrine, de centre de ressources et de point de rencontre national. Il permet de découvrir des services accessibles à tous et de naviguer entre les différentes communautés publiques. Il centralise la gestion technique, la sécurité, les sauvegardes et les mises à jour logicielles critiques. C'est l'entité "mère" qui garantit que le système reste robuste, performant et à jour pour tous les utilisateurs, sans que chaque petite association ait à se soucier de la maintenance informatique complexe, de l'hébergement ou de la conformité RGPD.

2.  **Les Espaces de Communautés (Les Branches) :**
    Chaque communauté dispose de sa propre identité visuelle, de ses propres règles de vie et de ses propres membres. Une ville comme Marseille peut avoir son propre espace (`marseille.entraide.fr`), tout comme une grande entreprise peut créer un réseau d'entraide privé pour ses salariés avec des règles de confidentialité strictes. On n'y entre pas anonymement : chaque espace a sa propre politique d'admission (approbation manuelle, invitation secrète ou domaine email vérifié), garantissant la sécurité, la sérénité et la qualité des échanges entre les membres.

3.  **La Visibilité Hybride (L'Opt-in Global) :**
    Contrairement aux réseaux sociaux traditionnels souvent soit totalement publics (type Twitter), soit totalement fermés (type Slack), Entraide permet l'hybridation. Un utilisateur peut décider qu'un service de "Réparation de vélo" posté dans la communauté "Marseille" soit aussi visible sur le Hub national. Cela permet de maximiser l'aide : si aucun Marseillais n'est disponible immédiatement, peut-être qu'un expert d'une ville voisine, de passage, pourra rendre ce service et ainsi dynamiser l'économie du partage au-delà des simples frontières locales.

4.  **L'Isolation Sécurisée (Le Mur de Verre) :**
    L'administrateur global peut configurer chaque communauté selon deux modes : **"Ouvert"** (visibilité mutuelle, SEO indexable par Google pour attirer de nouveaux membres) ou **"Fermé"** (isolation totale type Intranet, accès restreint). Le mode fermé est le socle de l'offre B2B pour les organisations (entreprises, syndicats, administrations) souhaitant un entre-soi sécurisé où les données et les identités ne sortent jamais du périmètre défini, tout en bénéficiant de la puissance et de l'ergonomie du moteur Entraide.

---

## 2. Analyse de Marché et Positionnement Unique

Le marché de l'entraide et du service entre particuliers est mature en France, dominé par des acteurs historiques très installés. Pourtant, la plupart ont dérivé vers des modèles purement mercantiles ou souffrent de limites techniques majeures qui empêchent une adoption large par les structures organisées cherchant une solution "clé en main".

### Analyse Détaillée de la Concurrence

#### AlloVoisins (Le géant commercial)
*   **Modèle Économique :** Commission sur les transactions en Euros + Abonnements premium payants.
*   **Forces :** Notoriété massive auprès du grand public, assurance intégrée aux prestations, énorme volume d'annonces quotidiennes.
*   **Faiblesses :** Modèle centralisé unique. Il est impossible pour une association ou une entreprise de créer son "propre" AlloVoisins privé (marque blanche). De plus, la dérive vers le "jobbing" lucratif (petits boulots payés en euros) tue progressivement l'esprit d'entraide pure et de réciprocité. L'utilisateur est traité comme un client, pas comme un membre d'un collectif.

#### Smiile / Ensembl' (Le réseau social de quartier)
*   **Modèle Économique :** Partenariats avec les collectivités territoriales et régies publicitaires locales.
*   **Forces :** Très bon ancrage local, partenariats publics solides avec les mairies, aspect social développé.
*   **Faiblesses :** Cloisonnement géographique trop rigide. Il est difficile de s'entraider entre collègues d'une même entreprise s'ils n'habitent pas le même quartier. L'interface peut être perçue comme un "Facebook local" où l'échange de service est secondaire par rapport aux flux d'actualités. Le modèle multisite est limité au territoire physique.

#### Yakasaider (Le pionnier solidaire de la monnaie-temps)
*   **Modèle Économique :** Gratuité totale, fonctionnement associatif pur, bénévolat.
*   **Forces :** Engagement militant fort, fidélité absolue aux valeurs de la monnaie-temps (1h = 1h).
*   **Faiblesses :** Technologie vieillissante et peu ergonomique. L'absence d'application mobile moderne et une interface austère rebutent les nouvelles générations. Absence totale de gestion multi-communautés ou de domaines personnalisés. Chaque utilisateur appartient à la "masse" du site, sans sentiment d'appartenance à un groupe restreint et sécurisé.

#### EBS (Échanges de Biens et Services - Logiciel libre SEL)
*   **Modèle Économique :** Logiciel libre à installer et héberger soi-même (Open Source).
*   **Forces :** Personnalisation totale pour les technophiles, indépendance absolue des données et de la gouvernance.
*   **Faiblesses :** Fragmentation extrême. Chaque installation est un "silo" isolé. Il est impossible de faire circuler les points entre deux villes différentes ou d'avoir une identité unique transversale. La maintenance technique (serveurs, mises à jour, sécurité) est un fardeau trop lourd pour la majorité des structures bénévoles.

#### Indigo (L'application de don moderne et sociale)
*   **Modèle Économique :** Dons d'objets et services gratuits, impact social.
*   **Forces :** Simplicité extrême, impact social immédiat, excellente ergonomie mobile "tinder-like".
*   **Faiblesses :** Très efficace pour le don ponctuel d'objets, mais moins adapté pour l'échange structuré de compétences complexes (ex: formation, dépannage informatique, cours de langues). Manque d'outils de gestion et de reporting pour les structures type entreprises ou grandes fédérations.

---

## 3. Innovations et Différenciateurs de la Version 2.0

### Nos Six Innovations de Rupture

1.  **La "Passerelle" Inter-Communautés (Bridges) :**
    C'est notre innovation technologique phare. Un administrateur peut ouvrir des "ponts" de confiance avec d'autres communautés partenaires. Imaginez une "Alliance de l'Entraide" entre toutes les entreprises d'une même zone d'activité : les salariés peuvent s'entraider entre bureaux voisins tout en restant dans leurs environnements sécurisés respectifs. On mutualise les talents d'un territoire sans diluer l'identité de chaque structure.

2.  **Portabilité et Convertibilité des Points (Clearing System) :**
    Nous créons une véritable économie circulaire nationale. Un système de "compensation" permet d'utiliser ses points dans une autre communauté selon un taux de change ou une équivalence simple. Cela évite le syndrome du "compte mort" : vos efforts pour aider vos voisins à Lyon vous servent directement quand vous emménagez à Marseille. Les points deviennent une "unité de valeur sociale" mobile.

3.  **Marque Blanche Dynamique et Instantanée :**
    Le changement d'identité visuelle est instantané et sans code. Via son tableau de bord, l'administrateur local injecte son logo, ses couleurs et ses propres polices. L'utilisateur a l'impression d'être sur un site sur-mesure (ex: `entraide.mon-entreprise.com`) tout en conservant son profil unique, sa messagerie et son historique de confiance. C'est le concept du "SaaS" appliqué à la solidarité humaine.

4.  **Gouvernance Participative et Démocratie Locale :**
    Nous redonnons le pouvoir aux membres. Des outils de vote, de consultation et de sondage permettent aux membres d'une communauté de décider souverainement des évolutions de leur espace (ex: voter pour l'ajout d'une nouvelle catégorie ou décider ensemble de la "valeur" des points pour des services d'intérêt collectif).

5.  **Réputation Transverse Universelle (Global Trust Score) :**
    La confiance est la monnaie de demain. Notre couche de données commune permet de conserver sa réputation (avis certifiés, badges de compétence, score de fiabilité) partout sur le réseau. Un utilisateur arrivant dans une nouvelle ville n'est plus un inconnu : son historique de "citoyen fiable" le précède, facilitant son intégration sociale immédiate.

6.  **IA de Matching Solidaire :**
    Utilisation de modèles de langage pour suggérer automatiquement les services les plus pertinents à un utilisateur en fonction de ses compétences déclarées et de ses recherches récentes, facilitant ainsi la rencontre entre l'offre et la demande sans effort de recherche fastidieux.

---

## 4. Nouveau Modèle de Données (Spécifications Détaillées)

Pour supporter cette vision ambitieuse, nous passons à une architecture **"Single Database, Multi-Tenancy"**. Concrètement : tout le monde partage le même logiciel ultra-performant et la même base de données pour des raisons d'économie d'échelle, mais des filtres automatiques, invisibles et inviolables garantissent que chaque communauté reste strictement "chez elle".

### A. Description Détaillée des Nouvelles Tables

#### 1. Table `communities` (Le Cœur du Système)
Cette table stocke l'ADN et la configuration de chaque espace indépendant créé sur la plateforme.
*   **UUID :** Identifiant unique complexe garantissant que les adresses ne sont pas devinables par des robots.
*   **Nom / Slug :** Le nom public (ex: "Entraide Lyon") et l'identifiant pour l'URL technique (`lyon`).
*   **Custom Domain :** Permet à une organisation de pointer son propre nom de domaine (ex: `partage.mon-entreprise.fr`).
*   **Configuration Visuelle :** Chemins vers le logo, la favicon et les codes couleurs Hexadécimaux pour le thème dynamique.
*   **Terminologie de la Monnaie :** Champs pour personnaliser le nom des points (ex: "Graine", "Heure", "Sourire", "Crédit").
*   **Paramètres d'Accès :** Type (Public, Privé, Restriction par domaine email) et statut de modération des inscriptions.
*   **Paramètres SEO :** Booléen `is_searchable` pour autoriser ou non l'indexation par Google et les autres moteurs.
*   **Plan de Souscription :** Gère les limites (ex: nombre de membres max, espace de stockage pour les médias).
*   **Bonus de Bienvenue :** Montant de points injectés automatiquement sur le compte de chaque nouveau membre de l'espace.
*   **Features (JSON) :** Un interrupteur pour chaque module (Parrainage, Services Collectifs, Messagerie, Géolocalisation).
*   **Description Riche :** Champ HTML pour la présentation de la charte de la communauté et les consignes locales.
*   **Social Links :** Liens vers les réseaux sociaux de la communauté (Facebook, Instagram, LinkedIn).
*   **Language :** Langue par défaut de la communauté (support de l'internationalisation).

#### 2. Table `community_user` (Le Registre des Droits et Rôles)
Gère l'appartenance des utilisateurs aux différentes communautés et leurs droits associés.
*   **Rôle :** Enumération (Membre, Modérateur de contenu, Administrateur d'espace).
*   **Solde Local :** Utilisé si la communauté opte pour une étanchéité totale des points (monnaie locale fermée).
*   **Status :** Cycle de vie du membre (En attente de validation, Actif, Suspendu, Banni localement).
*   **Internal Notes :** Permet aux modérateurs de suivre le comportement ou les compétences spécifiques d'un membre.
*   **Local Badges :** Liste des distinctions obtenues spécifiquement au sein de cette communauté.
*   **Joined At :** Date et heure précises de la première adhésion.

#### 3. Table `referrals` (Le Moteur de Croissance Virale)
Architecture robuste pour le suivi des recommandations et la distribution automatique des récompenses.
*   **Parrain / Filleul :** Liens vers les deux comptes utilisateurs concernés par la recommandation.
*   **Contexte Communautaire :** Dans quel espace le parrainage a été initié (permet des bonus locaux spécifiques).
*   **Code Utilisé :** Copie du code alphanumérique unique pour l'analyse des campagnes de recrutement.
*   **État de la Récompense :** En attente, Validée (après la première action réelle du filleul), Annulée (en cas de fraude).
*   **Reward Amount :** Montant de points réellement distribué lors de la validation finale.

#### 4. Table `service_participants` (Gestion des Services Collectifs)
Nécessaire pour gérer le passage d'une relation 1-à-1 à une relation 1-à-N (activités de groupe).
*   **Service ID :** Référence vers l'annonce de type collectif (ex: un cours de sport).
*   **User ID :** Référence vers le participant qui a réservé sa place.
*   **Slots Reserved :** Nombre de "places" prises par l'inscription (utile si on vient accompagné).
*   **Status :** État de l'inscription (Inscrit, Liste d'attente, Présence confirmée, Désistement, Remboursé).
*   **Escrowed Points :** Montant bloqué temporairement lors de la réservation pour sécuriser l'auteur.
*   **Attended At :** Timestamp de confirmation de présence effective par l'auteur du service.

#### 5. Table `payment_gateways` (L'Autonomie Financière Locale)
Permet à chaque administrateur d'encaisser des fonds (dons, cotisations, achat de points) de manière autonome.
*   **Prestataire :** Support natif de Stripe, HelloAsso, PayPal ou Payfit.
*   **Données de Config :** Clés API et identifiants de marchands, stockés de manière chiffrée en base.
*   **Mode :** Test (Sandbox) pour les réglages ou Production pour les flux réels.
*   **Currency :** Devise utilisée pour les transactions réelles (Euro, Dollar, etc.).

---

### B. Évolutions Critiques des Tables Existantes

1.  **Table `users` (L'Utilisateur Global) :**
    *   `global_referral_code` : Identifiant unique permanent généré à la création (ex: `JUL-A9B8`).
    *   `is_super_admin` : Accès privilégié à l'interface de gestion de l'infrastructure globale.
    *   `current_context_id` : Pour mémoriser la dernière communauté visitée et assurer une redirection fluide.
    *   `trust_score` : Algorithme de réputation pondéré basé sur l'ensemble de l'historique sur le réseau.
    *   `preferences` : JSON pour stocker les réglages d'affichage préférés par communauté.

2.  **Table `services` (Annonces) :**
    *   `community_id` : Rattachement obligatoire à une communauté "mère" pour segmenter le catalogue.
    *   `visibility_level` : Choix de l'auteur entre `Privé`, `Communauté seulement`, ou `Global Hub`.
    *   `service_type` : Distinction technique entre un service unitaire et une activité de groupe.
    *   `max_participants` : Limite physique de places pour les activités collectives.
    *   `virtual_meeting_link` : URL chiffrée (Zoom/Teams) révélée uniquement après validation de l'inscription.
    *   `is_featured` : Permet aux admins locaux de mettre en avant certaines annonces sur la page d'accueil.
    *   `requires_approval` : Si l'auteur veut valider chaque participant manuellement avant l'inscription.

3.  **Table `transactions` (Le Livre de Comptes) :**
    *   `community_id` : Identification systématique de l'espace pour les statistiques et rapports de PIB local.
    *   `transaction_type` : Catégorisation (Échange P2P, Récompense, Cotisation, Bonus System).
    *   `parent_transaction_id` : Pour lier les N paiements individuels d'un service collectif à un événement parent.

4.  **Table `categories` et `skills` :**
    *   `community_id` : Permet la création de catégories "locales" sans polluer le Hub global d'Entraide.

---

## 5. Parcours Utilisateurs et Scénarios de Vie Détaillés

### Scénario 1 : Le Service Collectif (ex: Atelier Yoga ou Cours de Cuisine)
1.  **Publication :** Marie publie un service "Atelier Yoga Débutant". Elle choisit "Collectif", définit 8 places et 20 points par personne. Elle ajoute un lieu physique et un document PDF de conseils.
2.  **Inscription :** 8 membres cliquent sur "Réserver ma place". Leurs 20 points sont immédiatement mis sous séquestre technique. Ils reçoivent les détails de l'adresse et le document automatiquement.
3.  **L'Événement :** Le cours a lieu le samedi matin. Marie est ravie du groupe et de la dynamique d'entraide créée.
4.  **Transaction Finale :** Marie valide la présence des 8 élèves sur son smartphone en fin de séance. Le système transfère les 160 points vers son compte en une seule opération. Les avis sont sollicités.

### Scénario 2 : Le Parrainage en Entreprise (Croissance Virale)
1.  **L'Action :** Thomas veut dynamiser l'entraide au sein de son entreprise. Il partage son lien de parrainage sur le canal Slack dédié au bien-être au travail.
2.  **L'Inscription :** Sa collègue Sophie s'inscrit via le lien. Le système la reconnaît immédiatement comme filleul de Thomas et l'ajoute directement à la communauté d'entreprise.
3.  **Le Bonus :** Thomas reçoit un message de félicitations et un bonus de 50 points dès que Sophie a réalisé sa première action d'aide réelle. Sophie reçoit 20 points de bienvenue. La DRH dispose d'un tableau de bord montrant la progression spectaculaire de l'engagement solidaire interne.

### Scénario 3 : La Navigation Multi-Espaces (Fluidité d'Usage)
1.  **Le Contexte Pro :** Julie est au bureau chez Accenture. Elle utilise l'espace "Accenture Entraide" (logo bleu, services professionnels, confidentialité totale). Elle réserve une aide pour une formation Excel complexe.
2.  **Le Contexte Perso :** Le soir, Julie rentre chez elle à Marseille. Elle bascule sur l'espace "Marseille Entraide" via un simple sélecteur dans sa barre de navigation habituelle. L'interface devient orange (couleurs de la ville), elle voit les demandes de ses voisins pour du baby-sitting ou du prêt d'outils. Ses messages pros et persos sont rangés dans deux dossiers distincts pour respecter son équilibre de vie.

---

## 6. Niveaux de Gouvernance et Droits d'Accès

Le système est structuré comme une pyramide de confiance à trois niveaux, garantissant sécurité et autonomie.

### Niveau 1 : Le Super-Administrateur (La Régie Centrale de la Plateforme)
C'est le "gardien du phare" technique de tout l'écosystème Entraide.
*   **Missions :** Création des communautés pour les nouveaux clients, maintenance infra, mises à jour de sécurité, facturation des abonnements B2B, arbitrage en cas de litige majeur entre deux espaces.
*   **Outils :** Tableau de bord global avec vue sur toutes les instances, statistiques de performance, logs de sécurité.

### Niveau 2 : L'Administrateur de Communauté (Le Maire Digital de l'Espace)
C'est le "chef d'orchestre" local. Il est le seul maître de son territoire numérique et de sa charte.
*   **Missions :** Personnalisation complète (Design), validation des nouveaux membres (si accès restreint), modération des annonces locales, animation (newsletters groupées), nomination de modérateurs locaux.
*   **Outils :** Tableau de bord local complet, gestion des clés de paiement (HelloAsso/Stripe), statistiques d'activité détaillées de son espace.

### Niveau 3 : L'Utilisateur Final (Le Citoyen Actif du Réseau)
C'est le cœur battant de la plateforme. Il est acteur de son propre échange et garant de la qualité du lien social.
*   **Missions :** Publication de services, participation aux activités, parrainage, évaluation des membres.
*   **Outils :** Profil unique transverse, messagerie unifiée (avec dossiers contextuels), historique de points segmenté.

---

## 7. Défis Techniques et Points de Vigilance (Risk Management)

Le passage au modèle multisite multiplie la complexité technique et nécessite une rigueur absolue sur six points critiques.

1.  **L'Isolation Stricte des Données (Security by Design) :**
    C'est le risque numéro 1. Un membre de l'entreprise A ne doit jamais pouvoir accéder par erreur aux données privées de l'entreprise B. Nous utiliserons les **Global Scopes de Laravel**. Chaque requête vers la base de données possède une serrure automatique qui ne s'ouvre que si l'utilisateur possède la clé de la communauté actuelle. C'est une sécurité intégrée au plus bas niveau du moteur de l'application (Laravel Eloquent).

2.  **La Gestion Dynamique des Domaines et du SSL (Le Cadenas Vert) :**
    Gérer des centaines d'adresses différentes (ex: `lyon.fr`, `entraide-pro.com`) est un défi majeur d'infrastructure web. Nous utilisons un serveur intelligent capable de générer et de renouveler des certificats de sécurité dynamiquement (Let's Encrypt) dès qu'un nouveau domaine est configuré, assurant un site toujours "en vert" (HTTPS) sans intervention manuelle de l'admin local.

3.  **La Rapidité Extrême de la Recherche :**
    Avec des millions d'annonces potentielles, une recherche classique dans la base de données deviendrait trop lente. Nous utilisons un moteur de recherche externe "ultra-rapide" (type Meilisearch) qui permet de trouver un service en moins d'un dixième de seconde, même à travers 500 communautés, en gérant parfaitement les filtres d'isolation locaux et les fautes de frappe.

4.  **La Lutte contre la Fraude et l'Auto-Parrainage :**
    Le système de récompense peut attirer des abus (création de comptes fictifs pour accumuler des points). Nous intégrons des algorithmes de détection de "fermes à comptes" qui analysent les comportements suspects (adresses IP identiques, empreinte numérique du navigateur, vitesse d'inscription) et mettent les points de bonus en "quarantaine" jusqu'à ce qu'une action réelle valide le compte.

5.  **La Cohérence des Sessions et de la Messagerie :**
    Si un utilisateur travaille simultanément dans deux onglets sur deux communautés différentes, le système doit garantir que chaque action (poster un service, envoyer un message) est rattachée au bon contexte communautaire. La gestion du contexte en temps réel est cruciale pour éviter les erreurs de communication embarrassantes entre espaces privés et publics.

6.  **Scalabilité des Médias et Performance :**
    La plateforme doit supporter des pics de trafic (ex: après un email groupé dans une ville). L'utilisation de serveurs de cache (Redis) segmentés par instance et d'un stockage objet (S3) pour les images est impérative pour maintenir des temps de réponse inférieurs à 200ms.

---

## 8. Gouvernance Décentralisée et Modération Partagée

Dans une plateforme multi-communautés, la modération ne peut pas être uniquement centralisée.

1.  **Le Conseil de Modération Local :** L'administrateur de communauté peut désigner des modérateurs parmi les membres les plus actifs et fiables (badges "Modérateur"). Ils ont accès à une interface simplifiée pour valider les annonces et traiter les signalements locaux.
2.  **L'Escalade de Signalement :** Si un signalement n'est pas traité localement sous 48h, il remonte automatiquement au Super-Administrateur global pour garantir la sécurité et l'éthique du réseau.
3.  **Vote sur les Règles de Vie :** Chaque communauté peut organiser des votes internes pour décider des tarifs indicatifs des points ou des horaires d'ouverture des services physiques partagés.

---

## 9. Monétisation et Modèle Économique de l'Infrastructure

Bien que les échanges entre membres soient gratuits, l'infrastructure a un coût.
*   **Offre Gratuite :** Pour les petites associations de quartier (limité à 100 membres).
*   **Offre "Association" :** Abonnement annuel modeste permettant la personnalisation marque blanche et l'export de données.
*   **Offre "Entreprise / Corporate" :** Abonnement basé sur le nombre de salariés, incluant l'isolation totale, le support premium et l'intégration SSO.

---

## 10. Conformité RGPD et Protection des Données

Dans un modèle multi-communautés, le respect du RGPD est complexe mais essentiel :
*   **Souveraineté des données :** Chaque administrateur de communauté est co-responsable du traitement pour les données de ses membres au sein de son espace.
*   **Portabilité :** Un utilisateur doit pouvoir exporter ses données de toutes ses communautés en une seule fois via le Hub Global.
*   **Droit à l'oubli :** Un utilisateur peut quitter une communauté tout en gardant son compte global Entraide, ou supprimer son compte global (ce qui entraîne la suppression partout de manière atomique).
*   **Transparence :** Les mentions légales et la politique de confidentialité sont dynamiques et s'adaptent selon la communauté visitée.

---

## 11. Guide Opérationnel pour un Administrateur Local

Pour lancer sa communauté avec succès, l'administrateur local dispose d'un parcours guidé simple :
1.  **Personnalisation Visuelle :** Télécharger le logo de l'organisation et choisir les codes couleurs de l'interface.
2.  **Charte & Valeurs :** Rédiger la présentation de la communauté et ses règles de bienveillance (modèle personnalisable fourni).
3.  **Initialisation du Catalogue :** Créer les premières catégories spécifiques si nécessaire (ex: "Entraide Informatique").
4.  **Lancement & Diffusion :** Partager le lien de parrainage ou configurer l'accès automatique par domaine email.
5.  **Animation du Réseau :** Valider les premières inscriptions et encourager les membres via des notifications push locales.
6.  **Analyse & Reporting :** Suivre le volume d'échanges (KPIs), la satisfaction des membres et le solde de points en circulation pour ajuster les bonus de bienvenue.

---

## 12. Glossaire pour l'Utilisateur Final (Le Petit Lexique)

*   **Hub Global :** Le site central `entraide.fr` qui regroupe la communauté nationale et les espaces publics.
*   **Instance / Communauté :** Un espace dédié à un groupe spécifique (votre entreprise, votre ville, votre association).
*   **Points (ou Graines/Heures) :** La monnaie d'échange permettant de troquer des services sans argent réel.
*   **Service Collectif :** Une annonce qui accepte plusieurs participants simultanément (ex: une formation).
*   **Séquestre de Points :** Action de bloquer les points d'un participant lors de sa réservation pour sécuriser l'auteur.
*   **Marque Blanche :** Capacité à personnaliser entièrement l'interface pour qu'elle ressemble à votre propre organisation.
*   **Bridge (Passerelle) :** Lien de confiance technique entre deux communautés partenaires pour partager leurs catalogues.

---

## 13. Stratégie de Déploiement et Roadmap Progressive

### Phase 1 : Les Fondations Multisite (Mois 1 - Priorité Haute)
Migration des tables `communities` et `community_user`, isolation du code source actuel via les Scopes Eloquent globaux, et mise en place du système de détection des sous-domaines. Le site actuel devient nativement "Le Hub Entraide".

### Phase 2 : Identité Visuelle, Croissance et Parrainage (Mois 2)
Développement du Dashboard admin local (marque blanche), mise en service du moteur de parrainage viral, et interface de basculement fluide entre espaces sans déconnexion. Lancement des premiers partenaires pilotes (villes).

### Phase 3 : Collectif, Séquestre et Autonomie Financière (Mois 3)
Implémentation des services collectifs, logique complexe de séquestre de points, et intégration des passerelles Stripe / HelloAsso pour les administrateurs locaux. Finalisation de l'API de parrainage croisé et des statistiques consolidées.

---

## 14. Conclusion : L'Architecture du Futur Social

Cette architecture v2.0 propulse Entraide dans une nouvelle dimension. En passant d'un site monolithique à une plateforme multi-tenante sécurisée et modulaire, nous offrons aux organisations un outil de lien social sans précédent. La force du modèle réside dans sa capacité à offrir une expérience ultra-locale et personnalisée tout en bénéficiant de la puissance et de l'innovation constante d'une plateforme globale. Sécurité, isolation, performance et engagement sont les maîtres-mots de cette transformation majeure qui fera d'Entraide la référence de l'économie circulaire des compétences.

---
*Ce document d'architecture constitue la référence officielle pour le développement de la version 2.0 d'Entraide. Il garantit la construction d'un système solide, sécurisé et prêt pour une croissance massive au service du lien social et de la solidarité.*
