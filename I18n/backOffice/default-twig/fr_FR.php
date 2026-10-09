<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
    'Factur-X invoices' => 'Factures Factur-X',
    'Factur-X invoice' => 'Facture Factur-X',
    'Save' => 'Enregistrer',
    'Ghostscript is not available on this server. The invoice is embedded as it is rendered: it is only a valid PDF/A-3 file if the document template uses embedded fonts only.' => "Ghostscript n'est pas disponible sur ce serveur. La facture est jointe telle qu'elle est rendue : elle n'est un PDF/A-3 valide que si le gabarit du document n'utilise que des polices embarquées.",
    'The profile decides which data the structured invoice carries. A richer profile needs more data on the order: the back-office tells what is missing before an invoice is issued.' => "Le profil décide des données que porte la facture structurée. Un profil plus riche demande plus de données sur la commande : le back-office dit ce qui manque avant d'émettre une facture.",
    'An invoice is stored the first time it is produced after the order is paid, and served as it was from then on: a later change of profile does not touch it. Archive folder: %path%' => "Une facture est conservée la première fois qu'elle est produite après le paiement de la commande, puis servie telle quelle : un changement de profil ultérieur n'y touche pas. Dossier d'archive : %path%",
    'Factur-X is switched off in the module settings.' => 'Factur-X est désactivé dans les réglages du module.',
    'The invoice is issued when the order is paid.' => 'La facture est émise quand la commande est payée.',
    'Invoice %number%, profile %profile%' => 'Facture %number%, profil %profile%',
    'Issued and archived' => 'Émise et archivée',
    'Everything the profile needs is on the order.' => 'Tout ce que demande le profil est sur la commande.',
    'The invoice cannot be issued, because:' => 'La facture ne peut pas être émise, parce que :',
    'Ghostscript is not available: the invoice is only PDF/A-3 if the template embeds its fonts.' => "Ghostscript n'est pas disponible : la facture n'est un PDF/A-3 que si le gabarit embarque ses polices.",
    'Download the Factur-X invoice' => 'Télécharger la facture Factur-X',
    'Issue the invoice now' => 'Émettre la facture maintenant',
    'facturx.missing.not_invoiced' => "La commande n'a pas encore de date de facture : elle n'est pas payée.",
    'facturx.missing.legacy_rounding' => "La commande est antérieure aux règles d'arrondi actuelles et ne peut pas être écrite en facture structurée.",
    'facturx.missing.inconsistent_totals' => 'Les totaux calculés depuis les lignes diffèrent des totaux de la commande.',
    'facturx.missing.currency' => 'La devise de la commande est inconnue.',
    'facturx.missing.seller_name' => 'Le nom de la boutique manque dans les réglages de la boutique.',
    'facturx.missing.seller_country' => 'Le pays de la boutique manque dans les réglages de la boutique.',
    'facturx.missing.seller_address' => "L'adresse, le code postal ou la ville de la boutique manque dans les réglages de la boutique.",
    'facturx.missing.seller_identifier' => "La boutique n'a ni SIRET ni numéro de TVA intracommunautaire.",
    'facturx.missing.seller_vat_number' => 'Le numéro de TVA intracommunautaire de la boutique manque : la facture porte de la TVA.',
    'facturx.missing.buyer_name' => "L'adresse de facturation n'a ni nom ni société.",
    'facturx.missing.buyer_country' => "L'adresse de facturation n'a pas de pays.",
    'facturx.missing.buyer_address' => "L'adresse de facturation n'a pas de rue.",
    'facturx.missing.buyer_vat_number' => "Le numéro de TVA de l'acheteur manque.",
    'facturx.missing.payment_means' => "La commande n'a pas de mode de paiement.",
    'facturx.missing.no_lines' => "La commande n'a aucune ligne.",
];
