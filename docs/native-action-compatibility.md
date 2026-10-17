# Vérification de l’action native « Envoyer les résultats du formulaire »

## Symptôme étudié

Le message et la liste « Cliquer pour insérer un champ » apparaissent tous les deux vides dans l’action native. La capture fournie montre la présentation native de cette action ; elle ne permet pas, à elle seule, de déterminer si la réponse du serveur est vide ou si un script a masqué son contenu.

Le signalement concerne Mautic **4.4.13**, avec les champs présents dans l’onglet « Champs ». Cette information ne prouve pas que la requête d’ouverture de l’action reçoit les mêmes champs en session. L’instance concernée n’a pas été inspectée ; le défaut n’est pas reproduit dans le laboratoire 4.4.13.

## Résultat des comparaisons

Des noyaux Mautic distincts ont été construits avec et sans `WebAnyOneFormRoutingBundle`, avec des caches séparés. Le témoin vérifie que les services du bundle sont réellement absents. Les mêmes champs synthétiques et le même identifiant de formulaire sont utilisés dans les deux cas.

Pour Mautic 4.4.13, 5.2.11 et 6.0.9 :

- la définition et le type de formulaire de `form.email` restent ceux du cœur ;
- le message natif est prérempli et son sélecteur propose les trois champs utiles ;
- le HTML natif, son message et ses jetons sont identiques avec et sans le bundle ;
- l’ouverture de la configuration WebAnyOne ne modifie pas les champs en session ;
- l’ouverture répétée de l’action native après WebAnyOne conserve le même HTML ;
- un modèle natif existant est conservé, y compris s’il a explicitement été enregistré vide ;
- le filtrage des champs supprimés reste celui de Mautic.

Une comparaison avant modification a également été exécutée avec la version 0.1.1 sur Mautic 6.0.9 : le HTML natif était déjà identique avec et sans le plugin. Les changements de la 0.1.2 renforcent donc l’isolation ; ils ne sont pas présentés comme la correction d’une cause certaine de l’incident sur l’instance signalée.

## Pourquoi les deux zones peuvent être vides

Dans les trois versions étudiées, `FormFieldTrait::getFormFields()` lit les champs préparés par l’éditeur dans la session, via `FieldModel::getSessionFields($formId)`. Cette même liste sert au corps par défaut et au sélecteur de jetons.

Les tests reproduisent les deux zones vides, avec **et sans** le plugin, lorsque la session ne contient pas les champs du formulaire ou lorsque l’identifiant demandé ne correspond pas à celui préparé en session. Cela donne des pistes de diagnostic, sans prouver que c’est la cause sur une instance qui n’a pas été inspectée.

## Isolation renforcée en 0.1.2

Le plugin utilisait, comme plusieurs actions natives, le bloc Twig générique `_formaction_properties_row` et les identifiants HTML `emailMessage` / `formFieldTokens` dans sa propre fenêtre. La version 0.1.2 utilise un widget `webanyone_form_routing` et les identifiants `webanyoneFormRoutingMessage` / `webanyoneFormRoutingTokens`. Il n’existe plus de remplacement de bloc natif dans son thème Twig.

Aucun fichier du cœur n’est modifié. Le plugin n’ajoute aucun JavaScript global et aucune écriture dans les clés de session des champs. Le type technique d’action et les propriétés déjà enregistrées restent compatibles.

Les deux véritables listeners d’action sont également exécutés successivement avec un transport de capture : les destinataires, Cc/Cci et corps restent indépendants lorsque l’action native suit WebAnyOne et lorsque WebAnyOne suit l’action native.

## Contrôle navigateur exécuté

Sur Mautic 6.0.9 local, avec les contrôleurs authentifiés et CKEditor réellement initialisé :

1. Ouverture de l’action native : message prérempli, trois jetons proposés.
2. Clic sur « Courriel » : insertion effective du jeton dans l’éditeur natif.
3. Annulation, puis ouverture de WebAnyOne : contenu prérempli, trois jetons proposés, insertion effective du jeton.
4. Annulation, puis réouverture de l’action native : contenu initial présent, trois jetons proposés, un seul conteneur natif et aucun conteneur WebAnyOne résiduel.

Un formulaire non publié et un champ entièrement synthétiques ont servi à ce contrôle. Aucun formulaire public n’a été soumis et aucun e-mail réel n’a été envoyé. Le contrôle navigateur n’a pas été exécuté sur les branches 4 et 5 ; leurs comparaisons de rendu et tests d’envoi sont exécutés côté serveur.

## Si le défaut persiste sur une instance

Après remplacement du dossier WebAnyOne, vider le cache Mautic et recharger l’éditeur depuis la liste des formulaires. Vérifier que l’onglet « Champs » contient les champs attendus, puis ouvrir l’action native dans ce même éditeur.

Si elle reste vide sur cette instance 4.4.13, relever les éventuelles erreurs JavaScript et l’identifiant `formId` de la requête d’ouverture de l’action. La réponse du serveur permet de distinguer une liste de champs absente en session d’un problème d’affichage de l’éditeur. Un message déjà enregistré vide n’est pas automatiquement remplacé par le plugin.

## Reproduction des tests

Depuis les sources, sur une base jetable `apimonitor_lab_*` :

```sh
php -d memory_limit=512M Tests/native-compatibility.php /chemin/mautic with
php -d memory_limit=512M Tests/native-compatibility.php /chemin/mautic without
php Tests/compare-native-results.php 6.0.9
```

Adapter la dernière version au laboratoire exécuté. Les rapports et HTML sont écrits dans le répertoire temporaire de PHP, sous `webanyone-native-comparison/results/<version>/`. Les tests de comparaison ne modifient pas les tables métier et n’envoient aucun e-mail. Les tests d’intégration généraux utilisent des fixtures temporaires et un mailer de capture.
