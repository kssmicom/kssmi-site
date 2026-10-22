---
lang: fr
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookies et événements de contact"
title: "Cookies, analyses et interactions de contact"
---

Kssmi sépare une fonction minimale d'événement de contact de la fonction d'analyse du parcours visiteur basée sur le consentement. Elles répondent à des objectifs différents et ne doivent pas être décrites comme un système unique doté d'une base juridique unique.

### 1. Événements de contact

Lorsqu'un visiteur sélectionne délibérément un lien WhatsApp ou un e-mail, le site web peut enregistrer un événement minimal indiquant que le point d'entrée du contact a été ouvert. Sans le consentement pour les analyses, cet événement est conçu pour ne contenir que :

- le canal sélectionné ;
- un type d'événement `open_intent` ;
- l'heure du serveur ;
- le chemin de la page pertinente sur le site ;
- l'emplacement du lien ;
- le SKU du produit le cas échéant ;
- la langue du site ; et
- un statut d'intention (`intent`).

Sans le consentement pour les analyses, cet enregistrement ne doit pas créer ou lire d'identifiant visiteur/session VJT et ne doit pas contenir un parcours de navigation reconstitué, l'URL complète du référent, les paramètres de campagne, l'adresse IP, l'agent utilisateur ou la géolocalisation. Un traitement de sécurité distinct et de courte durée peut être effectué pour limiter le débit (rate limiting).

Un enregistrement `open_intent` signifie uniquement que le lien de contact du site web a été déclenché. Cela ne prouve pas qu'un appareil a ouvert avec succès WhatsApp ou un client de messagerie, que le visiteur a envoyé un message ou que Kssmi en a reçu un.

Pour un formulaire de demande, un événement `submission_success` signifie que le processus d'envoi configuré par le site web a signalé un succès. Cela ne prouve pas qu'un destinataire a lu ou répondu à l'e-mail.

### 2. Suivi du parcours visiteur (VJT)

Avec le consentement pour les analyses, le VJT peut utiliser un identifiant visiteur propriétaire et un identifiant de session de courte durée pour associer les visites de pages et les événements de contact à un parcours consenti. Selon la configuration active, les données du parcours peuvent inclure :

- URL et titres des pages ;
- heures de visite et d'interaction ;
- paramètres du référent et de la campagne ;
- informations sur le navigateur, l'appareil, l'écran, la langue et le fuseau horaire ;
- pays ou ville dérivés de l'IP ;
- mesures de défilement et d'engagement ; et
- attribution des demandes ou des événements de contact.

Le parcours d'analyse doit rester désactivé jusqu'à ce que le visiteur donne son consentement aux analyses. Chaque consentement ouvre un nouveau cycle de consentement, et les identifiants VJT ne sont pas repris d'un cycle à l'autre. Lorsque le consentement est retiré, le site arrête dans le navigateur toute nouvelle collecte d'analyse et toute émission d'identifiants, supprime les valeurs VJT du stockage du navigateur et demande au serveur de faire expirer le cookie d'identité HttpOnly. Cette suppression est retentée un nombre limité de fois et peut échouer, par exemple si le navigateur est hors ligne ou si la requête est bloquée ; un cookie d'identité restant n'est pas utilisé pour poursuivre le parcours antérieur et est remplacé si le consentement est de nouveau donné. Les données déjà envoyées avant le retrait peuvent encore être reçues. Les identifiants créés avant l'introduction des cycles de consentement sont remplacés une fois. Les navigateurs dépourvus des fonctions nécessaires pour coordonner le consentement entre onglets n'utilisent pas VJT.

### 3. Publicité et analyses tierces

Google Analytics, Google Ads, Google Tag Manager ou des technologies de mesure comparables doivent fonctionner conformément aux catégories de consentement sélectionnées par le visiteur et à la configuration réelle du site. L'avis final ne doit décrire que les produits et fonctionnalités qui sont véritablement activés.

### 4. Cookies et stockage dans le navigateur

Les durées et critères suivants s’appliquent aux systèmes du site décrits dans le présent avis :

| Nom | Fournisseur | Finalité | Catégorie | Durée | Type de stockage |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Se souvenir des choix du visiteur en matière d'analyse et de publicité | Nécessaire | Jusqu’à la modification du choix ou l’effacement du stockage du navigateur | Local storage |
| `vjt_visitor_id` | Kssmi | Associer les visites consenties à un parcours visiteur | Analyses | Cookie: up to about 365 days; local copy: Jusqu’au retrait du consentement aux analyses ou à l’effacement du stockage du navigateur | Cookie et stockage local |
| `vjt_session_id` | Kssmi | Associer des événements de page consentis au sein d'une session | Analyses | About 30 minutes | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identité serveur signée qui relie les visites consenties au sein d'un cycle de consentement ; illisible par les scripts de la page | Analyses | Jusqu'à environ 365 jours ; expirée après un retrait lorsque le navigateur le permet | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Session signée de courte durée qui protège les statistiques d'événements de contact contre les événements falsifiés ; contient une valeur aléatoire, pas un identifiant VJT, et est définie sans consentement aux analyses | Sécurité des événements de contact | Environ 30 minutes, prolongée pendant la navigation | Cookie (HttpOnly) |
| Autres identifiants Google/tiers | Google / relevant third party | Analyses ou publicité | Analyses/publicité | Varie selon le fournisseur et la configuration | Cookie ou technologie similaire |

L'inventaire des cookies, la bannière de consentement et l'implémentation en direct doivent concorder. Renommer un traceur ou déplacer un identifiant d'un cookie vers le stockage local ne suffit pas à exempter la technologie de l'obligation de consentement.

### 5. Modification des choix de consentement

Les visiteurs doivent pouvoir rouvrir les Paramètres des cookies et modifier ou retirer leur consentement aux analyses et à la publicité aussi facilement qu'il a été donné. Le retrait n'affecte pas le traitement qui était légal avant le retrait. Une fois un choix effectué, un bouton Paramètres des cookies reste disponible sur chaque page.

### 6. Comptage anonyme des pages vues
Indépendamment de l'analyse fondée sur le consentement décrite ci-dessus, le site compte les pages vues sous une forme agrégée : pour chaque jour calendaire (heure de Pékin) et chaque chemin de page, il ne stocke que le nombre total de vues. Ce tableau de comptage n'utilise aucun cookie, aucun stockage du navigateur ni identifiant de visiteur ou de session, et n'inclut ni adresses IP, ni agents utilisateurs, ni référents, ni horodatages de visites individuelles. La limitation de débit, le filtrage des robots et les journaux serveur sont des opérations de sécurité distinctes décrites dans leurs propres sections ; ils ne font pas partie de ce tableau agrégé. Le serveur peut lire séparément un marqueur signé d'exclusion des administrateurs afin de ne pas compter le trafic d'administration ; ce marqueur n'est pas stocké dans le tableau agrégé anonyme.
