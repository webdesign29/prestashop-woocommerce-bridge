# Captures de documentation

`configuration.jpg` montre une démonstration isolée du composant `AdminDesign` de la version 0.2.2. Le formulaire utilise des données synthétiques et ne reproduit pas tous les contrôles de l’administration hôte.

- Source : `scripts/docs-preview.php`, exécuté en CLI sans démarrer les boutiques.
- Domaines : uniquement `woo.example.test` et `ps.example.test`.
- Secret : valeur vide, aucun jeton d’administration ni cookie dans les fichiers.
- Aucun nom, contact, produit ou montant provenant d’une boutique réelle.
- Le mode audit et les indicateurs sont fictifs ; ils ne constituent pas une preuve de synchronisation.
- Capture du navigateur sur une page locale, vérifiée visuellement avant publication. Aucune capture brute d’une boutique cliente n’est incluse.

Pour reproduire : générer le HTML avec le script, le servir depuis un répertoire temporaire limité à ces fichiers, ouvrir la page localement et capturer le composant. Vérifier l’image et ses métadonnées avant publication. Ne jamais remplacer cette fixture par un export de configuration réelle.
