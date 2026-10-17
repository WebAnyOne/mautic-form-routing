# WebAnyOne — Routage des résultats de formulaire — 0.1.4

Action **« Envoyer les résultats selon une liste »**, intégrée aux actions après soumission des formulaires Mautic autonomes et de campagne. Chaque nouvelle soumission déclenche sa propre notification interne. Le routage et le message ne lisent jamais les valeurs actuelles de la fiche contact.

## Compatibilité qualifiée

| Mautic exact | PHP réellement exécuté | Interface | Envoi |
|---|---|---|---|
| 4.4.13 | 7.4.33 | Thème PHP natif | MailHelper / SwiftMailer |
| 5.2.11 | 8.3.14 | Thème Twig natif | MailHelper / Symfony Mailer |
| 6.0.9 | 8.3.14 | Thème Twig natif | MailHelper / Symfony Mailer |

Un paquet partagé, sans dépendance supplémentaire, sans table ni migration. Composer limite volontairement Mautic à ces trois versions exactes. PHP déclaré : `>=7.4 <8.4`, à croiser avec les contraintes de Mautic : 4.4.13 accepte PHP 7.4–8.0 ; les distributions 5.2.11 et 6.0.9 acceptent PHP 8.1–8.3. Les autres combinaisons PHP et les autres correctifs Mautic **ne sont pas qualifiés par ces tests**.

Voir [le contrôle de compatibilité avec l’action native](docs/native-action-compatibility.md), [la recette et ses limites](docs/validation.md) et [l’étude des extensions natives](docs/architecture.md).

## Téléchargement

Télécharger le **ZIP installable** depuis la [release v0.1.4](https://github.com/WebAnyOne/mautic-form-routing/releases/tag/v0.1.4). Les archives automatiques « Source code » de GitHub servent au développement ; le ZIP `WebAnyOneFormRoutingBundle-0.1.4.zip` possède directement l’arborescence attendue par Mautic. Le fichier `.sha256` permet de vérifier son intégrité.

## Installation

1. Sauvegarder les fichiers et la base Mautic. Tester d’abord sur une préproduction.
2. Extraire `WebAnyOneFormRoutingBundle-0.1.4.zip` dans `plugins/`. Le chemin final doit être `plugins/WebAnyOneFormRoutingBundle/WebAnyOneFormRoutingBundle.php`.
3. Depuis la racine de Mautic, avec le PHP de cette installation et son utilisateur habituel :

```sh
php bin/console cache:clear --env=prod
php bin/console mautic:plugins:reload --env=prod
php bin/console cache:clear --env=prod
```

L’action apparaît dans l’éditeur de formulaire, catégorie des actions e-mail. Aucun écran principal ni service externe n’est ajouté. Installation et activation n’interrogent pas les anciennes soumissions et ne déclenchent aucun envoi rétroactif.

Pour migrer depuis la version 0.1.0, déplacer l’ancien dossier `plugins/MauticFormRoutingBundle` hors de `plugins/`, puis installer `plugins/WebAnyOneFormRoutingBundle`. Ne pas conserver les deux bundles actifs simultanément. Exécuter ensuite les commandes ci-dessus. Le type technique d’action `form.routing_results` reste identique : les actions et leurs règles existantes sont réutilisées. Depuis 0.1.1, remplacer simplement le dossier WebAnyOne et vider le cache avec les commandes ci-dessus ; les règles et les modèles restent conservés. Les configurations sont conservées dans les propriétés des actions Mautic. Ne pas installer les tests ou les preuves de recette dans le répertoire public : ils ne figurent pas dans le ZIP installable.

L’interface du plugin est traduite pour les langues Mautic `fr` et `fr_FR`. Choisir le français dans les préférences Mautic ; l’anglais reste disponible pour une session anglophone. Les champs et leurs options conservent leurs libellés métier.

La fenêtre reprend la disposition de l’action native : objet puis message à gauche, option « Répondre au contact » et sélecteur de champs à droite. Les paramètres de routage figurent sous cette zone. Sur écran étroit, la grille native empile les colonnes.

## Configuration

1. Créer ou vérifier un champ personnalisé **contact**, type **liste simple**. Relever ses valeurs techniques exactes, pas seulement ses libellés.
2. Dans le formulaire, associer exactement un champ liste simple à ce champ contact. Enregistrer les modifications de champs avant de rouvrir l’action. Une association absente, multiple ou vers une liste multiple empêche l’enregistrement.
3. Ajouter l’action « Envoyer les résultats selon une liste », puis choisir le champ contact affiché avec son alias.
4. Renseigner obligatoirement le groupe **Destinataire de secours**, avec au moins une adresse À valide.
5. Déplier le groupe du champ retenu. Chaque option affiche son libellé et sa valeur technique. Renseigner À, Cc et Cci selon les besoins ; virgules et points-virgules sont acceptés. Les adresses sont fixes, sans jetons ni noms d’affichage. Les doublons sont supprimés dans l’ordre À, puis Cc, puis Cci.
6. Une ligne entièrement vide utilise le secours. Le rappel « Destinataire de secours » n’est plus répété sous chaque ligne : l’aide générale explique ce comportement. Une ligne renseignée doit avoir une adresse À valide. Le secours ne reçoit pas une copie des notifications correctement routées.
7. Personnaliser l’objet et le corps. L’éditeur et le sélecteur de jetons emploient les mécanismes natifs. Cliquer sur les champs proposés pour insérer les vrais jetons. Ne pas inventer leurs alias.
8. Conserver « Répondre au contact » pour utiliser l’adresse e-mail de cette soumission. S’il n’existe pas exactement un champ associé à l’e-mail du contact, enregistré dans les résultats et contenant une adresse valide, le Reply-To reste celui prévu par Mautic.

Les champs proposés dans le corps sont les champs usuels enregistrés ; fichiers, mots de passe, champs cachés, boutons et champs non enregistrés sont exclus. Pour une donnée métier sensible portée par un champ texte ordinaire, retirer son jeton du modèle : le plugin ne peut pas en deviner la sensibilité. Les accolades littérales des données sont remplacées par des parenthèses dans l’objet et la version texte brut, pour empêcher une seconde interprétation par le mailer ; la version HTML conserve leur affichage. Les jetons de contact ne sont pas acceptés en V1, afin de ne pas mélanger fiche courante et demande soumise. Insérer les jetons dans le texte du modèle, pas dans ses attributs HTML.

Les règles des autres champs restent conservées quand on change la sélection. Les groupes sont distincts et identifiés par champ. Lors d’une réouverture, une option nouvelle est ajoutée vide ; une option supprimée reste visible avec un avertissement et ne peut plus router de demande. Le changement de libellé conserve la destination. Les valeurs `01` et `1` restent distinctes.

Si le champ de routage n’enregistre pas ses résultats, sa valeur est prise dans le POST de **cette soumission** et nettoyée avec le filtre natif de liste simple avant correspondance. Une valeur postée n’est jamais utilisée comme adresse.

## Exemple de configuration

Exemple fictif à adapter aux valeurs techniques du champ contact choisi :

| Valeur | Libellé | À |
|---|---|---|
| 1 | Agence Nord | agence.nord@example.invalid |
| 2 | Agence Sud | agence.sud@example.invalid |
| 3 | Agence Centre | agence.centre@example.invalid |

Cc/Cci vides dans cet exemple. Définir une adresse de secours adaptée à l’installation. Les adresses `example.invalid` sont fictives et doivent être remplacées avant utilisation.

Objet conseillé : `[Site] Nouvelle demande de contact`. Dans le corps, insérer depuis le sélecteur les champs effectivement présents dans le formulaire. Aucun destinataire métier n’est codé dans le plugin.

## Envoi, files et erreurs

L’expéditeur et le transport restent ceux de Mautic. Pas de copie au prospect, ni au propriétaire, ni de pièces jointes. La notification est interne et n’associe pas le prospect au mailer ; son désabonnement marketing n’interrompt pas l’envoi et n’est pas modifié.

Le corps et les destinataires sont résolus avant la remise au mailer. Mautic 4 conserve son mode SwiftMailer : avec la file sur disque, utiliser la commande existante `php bin/console mautic:emails:send`. Mautic 5/6 conservent leur configuration Messenger : pour une file e-mail asynchrone, utiliser le consommateur existant `php bin/console messenger:consume email` conformément à l’exploitation de l’instance. Aucune commande ni tâche planifiée propre au plugin.

La recette vérifie un véritable spool Swift sur disque et le chemin natif Mailer → message Messenger sérialisé → handler → transport de capture. Elle ne certifie pas un broker distant, un fournisseur SMTP ou ses politiques de réessai.

Valeur vide, inconnue, supprimée, de type inattendu, règle vide ou association rompue : secours. Échec de transport : journalisation, **sans second envoi au secours**. La soumission reste enregistrée. Le visiteur reçoit le résultat normal de son formulaire ; il ne reçoit pas le diagnostic interne d’e-mail.

Mautic 6.0.9 supprime les balises HTML saisies avant l’appel de l’action ; le plugin restitue les résultats nettoyés, sans reconstituer les balises retirées. Mautic 4.4.13 et 5.2.11 encodent ces caractères : le plugin décode ce nettoyage une seule fois puis échappe les données pour le message. Accents, retours à la ligne et caractères conservés par le cœur restent lisibles.

## Journalisation et dépannage

Chercher `form_routing` dans les logs Mautic de l’installation. Contexte : identifiants du formulaire, de l’action, de la soumission ; valeur technique reconnue ; règle ou motif de secours ; statut. Aucune adresse du prospect, aucun corps complet ni texte d’exception potentiellement sensible dans les logs du plugin. Une valeur inconnue est consignée comme motif `unknown` sans recopier une saisie arbitraire.

- `rule` : règle sélectionnée.
- `empty`, `unexpected_type`, `unknown`, `unconfigured`, `broken_mapping` : recours au secours, avec motif.
- `accepted_by_mailer_delivery_unconfirmed` : le mailer Mautic a accepté la demande, immédiatement ou dans sa file. **Ce n’est pas une preuve de livraison en boîte de réception.** Consulter le transport/la file pour son traitement ultérieur.
- `transport_failed` / `notification_failed` : échec synchrone ; contrôler transport, configuration, puis les logs natifs. Aucun rejeu automatique du plugin.

Si l’action n’apparaît pas : vérifier l’arborescence, recharger les plugins et vider le cache. Si la liste est vide : créer un champ contact simple et vérifier les permissions de formulaire. Si une association est signalée : contrôler le champ du formulaire, son alias et son association contact, puis rouvrir l’action. Si une nouvelle agence reçoit le secours : renseigner sa ligne et enregistrer.

Le garde de doublons utilise l’action et la soumission dans la requête courante, plus un marqueur d’événement lorsque les identifiants ne sont pas disponibles. Le cycle natif exécute chaque action une seule fois après sauvegarde, et les workers n’exécutent pas à nouveau le routage. Il n’existe pas de registre persistant interprocessus : un transport peut réessayer après une acceptation dont l’accusé réseau s’est perdu. La livraison « exactement une fois » n’est pas garantie. Deux véritables soumissions, même identiques, restent deux demandes.

## Désactivation et désinstallation

Retirer l’action des formulaires avant une désinstallation durable. Pour un arrêt temporaire, désactiver le plugin via les mécanismes habituels de Mautic, ou retirer son dossier puis vider le cache. Le cœur ignore les types d’action indisponibles ; les autres actions continuent. Les propriétés restent dans les actions existantes tant qu’elles ne sont pas supprimées. Aucune table du plugin à purger. Une notification déjà remise à une file native peut encore être traitée après désactivation.

## Développement

Les sources et les tests sont disponibles dans ce dépôt. Les commandes de test et leurs garde-fous sont décrits dans [la recette](docs/validation.md).

Pour reconstruire le ZIP installable depuis un clonage du dépôt, avec Python 3 :

```sh
python3 scripts/build-release.py
```

L’archive et son empreinte SHA-256 sont générées dans `dist/`. Elles incluent le bundle et sa documentation ; les tests, preuves de recette et outils de développement restent dans les sources.

Licence : GPL-3.0-or-later, voir [LICENSE.txt](LICENSE.txt).
