# WD29 PrestaShop → WooCommerce Bridge

Connecteur direct avec [le module partenaire](https://github.com/webdesign29/woocommerce-prestashop-bridge).

Nom technique du module : **wd29woobridge**. Page : **Modules → WD29 WooCommerce Bridge → Configurer**. Point de réception fourni par `getModuleLink`, utilisable avec et sans réécriture d’URL.

## Fonctionnement

- Deux boutiques appairées directement en HTTPS, sans service intermédiaire.
- Webhooks HMAC-SHA256, horodatage, identifiant de livraison et déduplication persistante.
- File d’attente avec reprises, ordre de traitement par fiche et journal des erreurs.
- Identités `woo:product:ID`, `ps:product:ID` et correspondances des déclinaisons. Les UGS vides ne servent jamais à fusionner des fiches.
- Rapprochement initial par réunion des catalogues : les produits propres à chaque boutique sont conservés.
- Produits simples et variables, noms, descriptions, catégories, attributs, prix et images.
- Stock initial conservé ; mouvements suivants propagés par écarts, avec verrous et protection contre les doubles livraisons. Une disponibilité sans quantité reste une quantité inconnue.
- Commandes copiées comme enregistrements natifs, sans nouvelle opération de paiement. Une commande miroir ne doit pas provoquer un deuxième mouvement de stock. Les modifications financières restent sur la boutique d’origine ; les statuts peuvent revenir vers celle-ci.
- Conflits de catalogue : pause pour examen, priorité WooCommerce ou priorité PrestaShop, à régler pareil des deux côtés.
- Contrôle périodique de pages de 10 produits et 10 commandes pour récupérer les hooks manqués. La fréquence dépend du cron et du volume du catalogue.

## État et limites

**Version candidate à recette, pas une certification KerAwen.** Les tests automatisés utilisent WordPress/WooCommerce et PrestaShop natifs, sans le module commercial KerAwen. Une recette sur les boutiques appairées reste nécessaire avant exploitation.

Les cas suivants sont bloqués ou demandent une adaptation explicite : multiboutique, packs et téléchargements, variations génériques « toutes les tailles », stock mutualisé au niveau du parent WooCommerce, écotaxe, fiscalité sans correspondance, devises différentes et commandes dont les frais/remises ne correspondent pas aux lignes exportées. Les remboursements monétaires ne sont jamais exécutés par le connecteur. Les suppressions ne sont pas propagées automatiquement. La gestion des médias est additive côté PrestaShop ; les suppressions/remplacements complexes de galeries demandent une recette spécifique. Les métadonnées d’extensions tierces ne sont pas migrées automatiquement.

Des prix dont la TVA est désactivée côté WooCommerce restent marqués « prix affiché, fiscalité non renseignée ». Le module PrestaShop demande alors la base HT/TTC, le taux et, si nécessaire, le groupe de règles fiscales. Aucun taux n’est déduit du nom d’un produit. L’import d’un produit PrestaShop taxé vers un WooCommerce sans taxes configurées est bloqué.

## Mise en service

1. Installer les deux archives de la même version, puis ouvrir leurs pages de configuration.
2. Définir une même clé aléatoire d’au moins 32 caractères sur les deux sites. La conserver uniquement dans les configurations privées des boutiques. Ne jamais la publier dans GitHub, un ticket ou un journal.
3. Copier l’URL de webhook de chaque boutique dans la configuration de l’autre. Passer les deux en **audit** et utiliser « Test connection ».
4. Confirmer les correspondances fiscales et la priorité en cas de conflit. Les deux boutiques doivent utiliser la même priorité.
5. Capturer les deux catalogues par lots, puis les commandes si leur historique doit être repris. Les commandes en attente d’un produit sont reprises après celui-ci.
6. Examiner les erreurs et les quantités non suivies, puis activer **live** sur les deux sites seulement après recette. Le mode audit reçoit les événements mais ne modifie pas les fiches, stocks et commandes à partir des événements entrants.
7. Configurer un vrai cron WordPress, idéalement chaque minute. WP-Cron dépend autrement des visites. WooCommerce déclenche aussi le traitement du module PrestaShop, y compris lorsque sa vitrine est en maintenance ; seul le point de réception signé bénéficie de cette exception.
8. Vérifier une création, une modification, une déclinaison, une vente, une annulation et la répétition du même webhook. Vérifier aussi la caisse réelle si KerAwen est utilisé.

La désactivation conserve les correspondances et l’historique de déduplication. Ne pas réutiliser ces tables pour une autre paire de boutiques. Les événements contiennent des données de commande dans la base privée du site ; leurs contenus ne sont pas inclus dans le journal synthétique.

## Développement et tests

`php tests/protocol.php` vérifie les signatures, l’expiration, l’altération des messages, les identités et les quantités inconnues.

Les tests natifs refusent de démarrer hors des bases jetables `wd29woo` et `wd29ps`, sur les domaines de test `woo.example.test` et `ps.example.test`. Ils vident les tables du connecteur **uniquement dans ces installations jetables**. Ils ne doivent jamais être lancés sur une boutique client.

Le protocole et le moteur communs se trouvent dans `includes/Protocol.php` et `includes/Engine.php` dans les deux dépôts. Ces fichiers doivent rester identiques pour une même version du protocole. `python3 scripts/package.py` construit une archive dans `dist/` à partir d’une liste de fichiers autorisés ; les tests, les réglages et les secrets ne sont pas embarqués.

## Licence

GPL-2.0-or-later. Voir `LICENSE`.

### Test natif de ce dépôt

```sh
WD29_PS_ROOT=/chemin/prestashop php tests/prestashop-integration.php
```

### Stock des déclinaisons

PrestaShop partage une politique de commande hors stock pour toutes les déclinaisons. Les produits WooCommerce mélangeant des quantités suivies et des disponibilités sans quantité sont bloqués avant création pour éviter de rendre commandable une déclinaison à zéro. Harmoniser leur suivi de quantité avant import. Le prix de base des parents variables sans prix propre est calculé à partir des prix de leurs déclinaisons.
