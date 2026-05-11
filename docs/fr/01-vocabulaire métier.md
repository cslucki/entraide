# 07-GLOSSARY.md
**Date de mise à jour : 11/05/2026 - 20h36**

Document de stabilisation du vocabulaire métier et technique de BouclePro / Cyberworkers.

Objectif :

* stabiliser les concepts,
* éviter la dérive des prompts IA,
* unifier la documentation,
* préparer la migration Community → Organization,
* harmoniser Laravel / IA / UX / Produit.

Références :

* Domain Architecture V2 
* Engineering Workflow Rules 
* UI Rules 
* Community Transaction Matrix 

---

# 1. Règle Fondamentale

La langue principale du produit est :

```text
Français
```

Mais les concepts système critiques restent :

```text
en anglais
```

afin de :

* stabiliser les prompts IA,
* éviter les ambiguïtés,
* préserver la cohérence technique,
* préparer les futures APIs,
* faciliter les intégrations IA,
* conserver un langage commun entre :

  * produit,
  * code,
  * documentation,
  * IA,
  * architecture.

---

# 2. Concepts Officiels Système

Ces termes sont considérés comme :

* officiels,
* stables,
* prioritaires.

Ils ne doivent PAS dériver.

| Concept officiel | Type                | Description                    |
| ---------------- | ------------------- | ------------------------------ |
| Platform         | Core System         | Système global BouclePro       |
| Organization     | Tenant Boundary     | Organisation principale        |
| Loop             | Collaborative Group | Groupe collaboratif interne    |
| Member           | User Role           | Utilisateur d’une Organization |
| Module           | Architecture        | Fonctionnalité activable       |
| Tenant           | Infrastructure      | Frontière d’isolation          |
| Interaction      | Domain Layer        | Activité collaborative         |
| Workflow         | Process             | Flux métier                    |
| Scope            | Security Concept    | Limitation d’accès             |
| Provider         | AI Layer            | Fournisseur IA                 |
| Prompt           | AI Layer            | Instruction IA                 |
| Agent            | AI System           | Système autonome IA            |

---

# 3. Vocabulary Mapping

## 3.1 Organization

### Official Term

```text
Organization
```

### Français autorisé

```text
organisation
```

### Legacy technique accepté temporairement

```text
Community
community
community_id
```

### Interdits

```text
groupe
réseau
espace
tenant
```

### Définition

L’Organization représente :

* la frontière métier,
* la frontière de sécurité,
* la frontière de facturation,
* la frontière d’administration.

L’Organization est le vrai tenant du système.

---

## 3.2 Loop

### Official Term

```text
Loop
```

### Français autorisé

```text
boucle
```

### Interdits

```text
community
tenant
organisation
workspace
```

### Définition

Une Loop est :

* un espace collaboratif,
* relationnel,
* contextuel,
* interne à une Organization.

Une Loop n’est PAS :

* un tenant,
* une frontière de sécurité,
* une isolation base de données.

---

## 3.3 Member

### Official Term

```text
Member
```

### Français autorisé

```text
membre
```

### Interdits

```text
client
abonné
contact
```

### Définition

Un Member appartient à une Organization
et peut participer à plusieurs Loops.

---

## 3.4 Platform

### Official Term

```text
Platform
```

### Français autorisé

```text
plateforme
```

### Définition

La Platform représente :

* l’infrastructure globale,
* le système BouclePro,
* les services mutualisés,
* l’architecture IA,
* la facturation,
* les modules.

---

## 3.5 Module

### Official Term

```text
Module
```

### Français autorisé

```text
module
```

### Définition

Un Module est une fonctionnalité activable
par Organization.

---

## 3.6 Tenant

### Official Term

```text
Tenant
```

### Français autorisé

```text
tenant
```

### Définition

Le Tenant représente :

* l’isolation logique,
* l’isolation sécurité,
* l’isolation métier.

Dans BouclePro :

```text
Organization = Tenant
```

et NON :

```text
Loop = Tenant
```

---

## 3.7 Interaction

### Official Term

```text
Interaction
```

### Français autorisé

```text
interaction
```

### Définition

Les Interactions représentent :

* transactions,
* messages,
* commentaires,
* reviews,
* échanges IA,
* workflows collaboratifs.

---

# 4. Legacy Mapping

## Migration Conceptuelle

| Legacy               | Nouvelle cible          |
| -------------------- | ----------------------- |
| Community            | Organization            |
| community_id         | organization_id         |
| Community Admin      | Organization Admin      |
| CommunityRequest     | OrganizationRequest     |
| community middleware | organization middleware |

---

# 5. Naming Rules

## UI / Produit

Toujours préférer :

```text
Organization
Loop
Member
```

même dans une interface française.

Exemple :

```text
Créer une Organization
Rejoindre une Loop
Inviter un Member
```

---

## Documentation Technique

Autorisé temporairement :

```text
Community
community_id
```

UNIQUEMENT :

* pour décrire l’existant Laravel,
* la compatibilité legacy,
* les migrations futures.

---

## Base de données

Temporairement accepté :

```text
community_id
```

jusqu’à migration officielle.

---

## Prompts IA

Les prompts doivent utiliser :

* les termes officiels,
* les mêmes concepts,
* les mêmes conventions.

Éviter :

* synonymes multiples,
* variations,
* vocabulaire ambigu.

---

# 6. Synonymes Interdits

## Pour Organization

Interdits :

* espace
* réseau
* groupe
* tenant
* workspace

---

## Pour Loop

Interdits :

* communauté
* organisation
* équipe
* tenant

---

## Pour Member

Interdits :

* client
* prospect
* utilisateur final

---

# 7. AI Alignment Rules

Les systèmes IA doivent considérer :

```text
Organization
Loop
Member
```

comme :

* vocabulaire canonique,
* stable,
* prioritaire.

---

# 8. Documentation Rules

Tous les nouveaux documents doivent :

* utiliser le glossaire,
* éviter les variations,
* référencer les concepts officiels,
* conserver les termes système stables.

---

# 9. Product Philosophy Vocabulary

Vocabulaire encouragé :

```text
calm
human
conversational
modular
lightweight
trustworthy
intentional
scalable
AI-ready
```

Vocabulaire déconseillé :

```text
disruptive
revolutionary
AI-powered everywhere
futuristic
ultra-automation
growth hacking
```

Conformément aux règles produit et UX.   

---

# 10. Strategic Rule

Avant toute migration technique majeure :

priorité à :

1. stabilisation conceptuelle,
2. stabilisation vocabulaire,
3. alignement IA,
4. alignement documentation,
5. architecture cible,
6. migration technique.

Jamais l’inverse.
