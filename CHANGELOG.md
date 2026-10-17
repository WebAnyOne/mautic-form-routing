# Modifications

## 0.1.4

- Libellé de l’action après soumission : « Envoyer les résultats selon une liste », sans préfixe WebAnyOne. Le titre anglais suit la même règle.
- Nom du bundle et type technique d’action conservés pour réutiliser les configurations existantes.

## 0.1.3

- Mise en page de composition alignée sur l’action native : objet et message en colonne de gauche (8/12), option Reply-To et liste des champs à insérer à droite (4/12).
- Réglages de routage placés sous la composition dans la même fenêtre.
- Grille, libellés et liste de jetons natifs Mautic, avec empilement des colonnes sur écran étroit.
- Conservation du widget et des identifiants HTML propres à WebAnyOne.


## 0.1.2

- Thème Twig limité au widget `webanyone_form_routing` : l’action WebAnyOne n’utilise plus le bloc générique `_formaction_properties_row` de Mautic.
- Identifiants HTML propres au plugin pour le message et le sélecteur de jetons ; les identifiants natifs `emailMessage` et `formFieldTokens` sont réservés à l’action native.
- Comparaison du formulaire natif dans deux noyaux réellement avec et sans le bundle, puis après ouvertures successives des deux actions.
- Vérification de l’absence de mélange de destinataires, Cc/Cci et corps avec le MailHelper natif partagé.
- Contrôle navigateur Mautic 6.0.9 avec CKEditor initialisé : insertion d’un jeton dans les deux actions et réouverture de l’action native après WebAnyOne.


## 0.1.1

- Nom affiché : « WebAnyOne — Routage des résultats de formulaire ».
- Bundle, classe principale, espaces de noms, services et archives préfixés WebAnyOne.
- Action : « WebAnyOne — Envoyer les résultats selon une liste ».
- Traduction française pour les langues Mautic `fr` et `fr_FR`, y compris l’objet, le message, le Reply-To et le sélecteur de champs.
- Suppression du rappel « Destinataire de secours » sous chaque ligne vide. La configuration de secours et l’explication générale sont conservées.
- Conservation du type technique `form.routing_results` et du format des propriétés des actions pour réutiliser les configurations 0.1.0.

Depuis 0.1.0, déplacer l’ancien dossier `MauticFormRoutingBundle` hors de `plugins/` avant d’installer le nouveau dossier `WebAnyOneFormRoutingBundle`. Ne pas activer les deux bundles simultanément. Recharger les plugins et vider le cache selon le README.
