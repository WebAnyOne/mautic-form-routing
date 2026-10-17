# Architecture et inspection native

Sources consultées : distributions locales Mautic 4.4.13, 5.2.11, 6.0.9, dont les métadonnées `app/release_metadata.json` ont été lues. Références amont : [4.4.13](https://github.com/mautic/mautic/tree/4.4.13), [5.2.11](https://github.com/mautic/mautic/tree/5.2.11), [6.0.9](https://github.com/mautic/mautic/tree/6.0.9).

## Points d’extension étudiés

- `FormBundle/EventListener/FormSubscriber.php` : action `form.email`, événement `ON_EXECUTE_SUBMIT_ACTION`, composition et `MailHelper::send`. L’action native peut utiliser l’e-mail courant du contact, copier au prospect/propriétaire et décoder les valeurs ; ces comportements ne conviennent pas tous au routage demandé. Le plugin ne copie ni ne sous-classe ce subscriber.
- `FormBundle/Form/Type/SubmitActionEmailType.php`, `FormFieldTrait.php`, `ActionType.php` : éditeur `editor editor-basic`, syntaxe `{formfield=alias}`, insertion `Mautic.insertTextInEditor`, propriétés d’action, nettoyage et validation Symfony.
- `FormBundle/Model/SubmissionModel.php` : `saveEntity($submission)` précède `executeFormActions`. Celui-ci passe sur toutes les actions disponibles sans déduplication par contact. Les trois branches utilisent un même événement pour les différentes actions de la soumission. Les champs de cet événement ne contiennent que `id/type/alias` : les métadonnées d’association doivent être lues depuis les entités de champ du formulaire.
- `FormBundle/Entity/Field.php` : Mautic 4.4.13 utilise `leadField` ; 5.2.11/6.0.9 exposent `mappedObject/mappedField`. L’adaptation conserve le support de `leadField`, tout en rejetant une association explicite à une société.
- `FormBundle/Helper/FormFieldHelper.php` et `CoreBundle/Helper/InputHelper.php` : nettoyage par défaut `clean` dans 4.4.13/5.2.11, `string` dans 6.0.9. Ce dernier supprime les balises sans encodage HTML. `SubmissionValue` applique l’adaptation explicite, sans deviner à partir du contenu.
- `EmailBundle/Helper/MailHelper.php` : interface commune `getMailer`, `setLead`, `setTo/Cc/Bcc`, `setSubject`, `setBody`, `parsePlainText`, `setReplyTo`, `send`, `reset`. `send(false)` évite un second passage de résolution de jetons via les listeners d’e-mail. Les jetons du formulaire sont déjà substitués et échappés, sans relire la fiche contact. Pas de suivi d’ouverture demandé ni de pièce jointe ajoutée.
- Mautic 4 utilise SwiftMailer ; 5/6 utilisent Symfony Mailer et peuvent remettre un message complet à Messenger. Les tests interceptent ces chemins avec capture, puis exécutent le flush du spool ou le handler Messenger natif.

## Répartition des responsabilités

| Classe | Responsabilité |
|---|---|
| `Catalogue` | Charger les listes contact, obtenir leurs options et résoudre une association unique avec le formulaire |
| `Recipients` | Validation Symfony stricte, normalisation et déduplication des adresses |
| `Router` | Choisir exclusivement une règle active ou le secours, sans transport ni lecture du contact |
| `SubmissionValue` | Adapter l’encodage natif des valeurs nettoyées selon la branche |
| `Composer` | Substitution non récursive, échappement HTML, retours à la ligne, Reply-To de la soumission |
| `Sender` | Remise au MailHelper natif en mode interne et remise à zéro de son état |
| `RoutingType` | Formulaire natif, réconciliation des options, validation et stockage des règles |
| `FormSubscriber` | Enregistrement de l’action, garde local de doublons, orchestration et logs |

Les valeurs techniques et les alias de champs deviennent des clés `v_` suivies de leur représentation hexadécimale. Cela empêche la conversion implicite des clés numériques PHP et préserve `01`/`1`. Les banques de règles sont séparées par champ contact ; leur métadonnée de libellé permet de conserver les options disparues.

Le formulaire parent Mautic impose permissions, CSRF et nettoyage. Le plugin demande le mode `raw` pour son modèle, objet et groupes d’adresses, puis applique sa propre validation : éviter que le nettoyage HTML général transforme une adresse fixe contenant un caractère autorisé. Les destinataires ne sont ni des jetons, ni des valeurs de POST public. Les modèles sont administrables ; les données soumises sont échappées et les jetons ne sont pas admis dans les attributs HTML ou les éléments script/style.

## Doublons et conservation de la demande

Le garde de requête est indexé par identifiant de soumission et identifiant d’action, et un marqueur d’événement couvre le cas sans identifiants. Une nouvelle soumission bénéficie d’un nouvel identifiant et n’est jamais dédupliquée par contact, agence ou texte.

Aucune table d’exécution persistante : le cycle natif inspecté ne réexécute pas l’action dans le worker, qui reçoit déjà son message figé. Une table supplémentaire ne rendrait pas atomiques une transaction SQL et l’acceptation distante SMTP ; elle ne garantirait donc pas une livraison exactement une fois. Les duplications dues aux réessais du transport restent une limite explicite.

Le subscriber intercepte les erreurs d’envoi et n’émet pas `FormBundle\Exception\ValidationException`, car le cœur supprimerait alors la soumission. Le succès de la saisie utilisateur est séparé du diagnostic de notification interne. Un échec synchrone ne provoque ni secours ni deuxième tentative du plugin.


## Isolation de l’éditeur natif

Depuis 0.1.2, le thème Twig ne définit que `webanyone_form_routing_widget` et le rendu PHP porte le même préfixe. Les conteneurs du message et des jetons ont des identifiants WebAnyOne propres. Le plugin ne remplace aucun bloc `_formaction_properties_row` et n’écrit pas dans la liste des champs préparée en session par Mautic. Voir la comparaison avec/sans bundle et l’alternance des fenêtres dans [le rapport de compatibilité native](native-action-compatibility.md).
