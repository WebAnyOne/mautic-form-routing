# Recette WebAnyOne — version 0.1.4 — 17 octobre 2026

La version 0.1.4 modifie uniquement le titre traduit de l’action et les métadonnées de version. Les catalogues français et anglais, le rattachement de l’action à la clé traduite et les archives sont vérifiés pour cette version. La matrice et les contrôles navigateur ci-dessous ont été exécutés sur la version 0.1.3 ; ils ne sont pas présentés comme une nouvelle exécution complète en 0.1.4.

Tests exécutés localement, sans intervention sur une instance métier, sans connexion à un serveur SMTP et sans message aux agences. Les bases sont jetables, les fixtures utilisent `example.invalid`. Les tests emploient les services, entités, formulaires, moteurs de vues et chemins de soumission natifs.

## Matrice exécutée

| Distribution | PHP | Tests unitaires | Assertions d’intégration | Résultat |
|---|---|---:|---:|---|
| Mautic 4.4.13 | 7.4.33 | 32 | 107 | Réussi |
| Mautic 5.2.11 | 8.3.14 | 32 | 109 | Réussi |
| Mautic 6.0.9 | 8.3.14 | 32 | 109 | Réussi |

Les totaux d’intégration diffèrent car les contrôles d’en-tête Bcc au niveau `SentMessage` sont spécifiques à Symfony Mailer. Les fichiers de résultats et les captures sont fournis dans le dossier `validation` du livrable. Un contrôle de syntaxe PHP 7.4 est également exécuté sur l’ensemble des sources PHP.

## Mise en page 0.1.3

Les thèmes PHP (Mautic 4) et Twig (Mautic 5/6) reprennent la grille native de composition : objet et message à gauche, Reply-To et sélecteur de jetons à droite. Les paramètres de routage suivent la composition. Le widget et les identifiants HTML WebAnyOne restent isolés. La liste conserve le défilement natif et les contrôles emploient les styles Mautic.

La capture `validation/0.1.3/composition-native-layout-6.0.9.png` montre cette disposition dans la véritable fenêtre Mautic 6.0.9, avec CKEditor initialisé. Un clic sur « Courriel » insère effectivement `{formfield=email}` dans le message. Le contrôle a été annulé sans enregistrer d’action ; les fixtures ont été supprimées et le serveur local arrêté. Le rendu PHP 4.4.13 est couvert côté serveur, sans contrôle JavaScript dans cette version.

## Non-régression de l’action native

36 assertions supplémentaires par version (18 avec le bundle, 11 sans le bundle, 7 comparaisons entre les deux rapports) vérifient la définition, le message, les jetons et le HTML de l’action native. Les noyaux ont des caches séparés et le témoin n’enregistre réellement aucun service du bundle. Les rendus natifs sont identiques dans les trois versions, et l’ouverture du formulaire WebAnyOne ne modifie pas les champs en session.

Le test d’intégration exécute également les deux vrais listeners d’envoi successivement dans le même processus, avec capture : aucun mélange de destinataires, Cc/Cci ou corps entre les actions. Le contrôle navigateur Mautic 6.0.9 utilise le véritable éditeur initialisé et vérifie l’insertion de jetons puis la réouverture native après WebAnyOne.

Voir [le rapport détaillé sur le symptôme signalé et les limites du diagnostic](native-action-compatibility.md).

## Vérifications de l’interface française

Le nouveau bundle `WebAnyOneFormRoutingBundle` a été découvert et installé par `mautic:plugins:reload` après retrait du dossier de l’ancien bundle dans les trois laboratoires. La même action `form.routing_results` et le même format de règles sont conservés.

Les catalogues français `fr` et `fr_FR` ont été vérifiés via le traducteur natif pour le champ, le secours et le titre WebAnyOne. Le rendu PHP/Twig vérifie les libellés français de sélection et de composition, l’absence de « Routing contact field » et « Fallback recipients » en français et la présence d’un seul titre « Destinataire de secours », celui du groupe utile. Les captures 0.1.1 ont été inspectées dans le navigateur. Les champs génériques du formulaire parent (nom, description, boutons) suivent la traduction installée du cœur Mautic.

## Couverture

- Huit codes d’agence plus `01`, distinct de `1` ; sélection exacte, ligne non configurée, valeur vide, inconnue, type inattendu, association absente/ambiguë/société/multiple.
- Adresses multiples, séparateurs, adresse invalide, injection de retour à la ligne, jeton destinataire refusé, À obligatoire, déduplication À → Cc → Cci.
- Formulaire Symfony natif, enregistrement/réouverture, nettoyage du formulaire parent, CSRF invalide refusé et CSRF valide accepté. Métadonnées et règles conservées.
- Nouvelle option vide, libellé modifié sans changement de règle, ancienne option conservée pour référence et exclue du routage.
- Objet, message, Reply-To provenant de la soumission ; accents, retours à la ligne, échappement et absence de réinterprétation des jetons soumis.
- Capture via le MailHelper réel ; deux événements identiques de la même soumission ne produisent qu’un message ; deux soumissions distinctes restent indépendantes.
- Cycle `saveSubmission` complet sur formulaire autonome **et** campagne : trois soumissions du même contact, dont deux identiques et une autre agence. Ajout d’un désabonnement e-mail après la première demande ; les suivantes sont notifiées sans copie au prospect.
- Persistance native des actions, relecture de leurs propriétés et copie indépendante via le clonage natif d’action. Les tests ne passent pas par le bouton HTTP de duplication d’un formulaire complet.
- Panne injectée dans le transport natif : soumission enregistrée, diagnostic d’échec, absence de notification au secours. Suppression temporaire du subscriber : la soumission continue sans erreur.
- HTML public généré par Mautic vérifié sans les destinataires privés.
- File SwiftMailer réelle sur disque puis flush vers capture ; deux messages Symfony Mailer sérialisés dans des enveloppes Messenger puis traités par son handler natif. Contacts et configuration modifiés entre mise en file et traitement : destinataires et corps initiaux conservés.
- Cc/Cci présents dans les champs natifs, sans adresses cachées dans le corps. Pour Symfony Mailer, absence de Bcc dans le message transmis, présence des trois destinataires dans l’enveloppe.
- Rendu des panneaux natifs PHP/Twig pour les trois versions, inspecté visuellement avec leurs CSS. Le groupe sélectionné est ouvert, le secours reste visible, les lignes ont leurs libellés traduits. Les aperçus initiaux de configuration étaient statiques. La capture de compatibilité native 0.1.2 sur Mautic 6.0.9 provient de la véritable session administrateur avec CKEditor initialisé, après alternance des actions.

## Limites de la qualification

Les brokers distants, les workers exploités comme services, les fournisseurs SMTP/API externes, leur redélivrance, la réception en boîte et les politiques anti-spam ne sont pas testés. Les tests Messenger utilisent un bus de capture sérialisant les enveloppes et le handler réel, pas un broker de production. La confidentialité Bcc de SwiftMailer repose sur son transport natif ; le spool de test capture son objet message avant dialogue SMTP.

Le test de désactivation retire le subscriber dans le cycle natif. Le mécanisme du cœur qui ignore les actions non disponibles a aussi été inspecté ; une désinstallation/réinstallation complète après création de fixtures n’est pas revendiquée comme test exécuté. Le plugin n’a aucun hook d’installation ou de rejeu historique.

L’interface reprend l’éditeur et l’insertion de jetons natifs. Le clic du sélecteur avec CKEditor initialisé dans une session administrateur a été vérifié sur Mautic 6.0.9, dans les deux actions ; le retour à l’action native conserve son contenu. Ce contrôle JavaScript n’a pas été exécuté sur Mautic 4 et 5. Les permissions proviennent du contrôleur natif ; aucun nouveau contrôleur de configuration n’existe.

Mautic 6 supprime certaines balises avant l’action : le test attend les résultats nettoyés de cette branche. Il ne prétend pas restaurer le texte HTML déjà supprimé par le cœur.

## Reproduction

Les tests sont livrés séparément du ZIP installable. Installer d’abord le plugin dans un laboratoire isolé avec ses dépendances Mautic, puis utiliser le PHP compatible avec cette branche :

```sh
php Tests/unit.php /chemin/mautic/vendor
php -d memory_limit=512M Tests/integration.php /chemin/mautic
```

`unit.php` ne nécessite ni base ni transport. `integration.php` refuse les bases dont le nom ne commence pas par `apimonitor_lab_` et refuse une exécution HTTP. Il crée puis supprime ses fixtures ; il modifie temporairement les services mailer en mémoire pour empêcher tout SMTP réel. Prévoir un laboratoire jetable, jamais une copie reliée à des campagnes, webhooks ou intégrations métier actives. Le préfixe de base est un garde supplémentaire, pas un moyen de sécuriser à lui seul une instance réelle.
