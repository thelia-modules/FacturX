# Factur-X module for Thelia 3

Turns the invoice PDF of an order into a **Factur-X** document: a PDF/A-3 file that carries the structured invoice (CII XML) as an attachment, in the profile the merchant chose. The page stays readable by a person and becomes readable by a machine.

## What it does

- **Embeds** the structured invoice in the invoice PDF produced by the active PDF template (the template keeps the look of the invoice).
- **Checks before it issues.** Each profile needs data the shop may not have (seller VAT number, buyer VAT number for an intra-community supply, payment method...). The order sheet of the back-office lists what is missing, and the invoice is refused instead of producing a file the recipient would reject.
- **Stores the invoice once.** The first time the invoice is issued, the file is archived with its SHA-256 fingerprint and served as it was from then on. A later change of profile does not touch an issued invoice, and a file whose fingerprint no longer matches is never served.
- **Generates on demand, never for a visitor.** The invoice is issued from the order sheet of the back-office, or when the invoice mail is sent. A customer who downloads the invoice from the account page receives the issued Factur-X file when there is one, and the plain PDF of the template otherwise: a request of a visitor never starts a generation.
- **Mails it** (optional): when the order is paid, the customer receives a message with the Factur-X invoice attached.
- **Keeps one total.** The structured invoice is built from the same order lines, postage and discount as the readable document, with the rounding mode of the order. If the totals it computes differ from the totals of the order, the invoice is refused.

## Profiles

`Minimum`, `Basic WL`, `Basic`, `EN 16931` (default), `Extended`. The XML of each profile is validated against its official schema before it is embedded, and read back from the produced PDF.

## Data it reads

| Data | Where it comes from |
|---|---|
| Invoice number | `order.invoice_ref`; `FA` + the order reference for an order invoiced before `pdf_invoice_ref_from` or that has no `invoice_ref` (same rule as the PDF template) |
| Seller | store settings (`store_name`, address, `store_siret`, `store_vat_intracom`); the module settings only fill what the store settings leave empty |
| Buyer | invoice address frozen on the order: company or name, address, country, SIRET, VAT number |
| Tax categories | `S` standard; `K` intra-community supply when the order is VAT exempted; `E` when the shop is not liable to VAT (`store_vat_exempt`); `Z` zero rate otherwise |
| Payment | prepaid in full (the invoice exists once the order is paid); the payment method is the one frozen on the order, or the title of the payment module |

Personal data is limited to what the invoice needs: no e-mail address and no phone number of the buyer.

## Requirements

- Thelia 3 core `^3.2` (it reads the VAT exemption frozen on the order address), PHP 8.3+.
- `atgp/factur-x` ^3.5 (installed by Composer with the module).
- **Ghostscript** is recommended: when `gs` and its sRGB profile are found, the PDF is converted to PDF/A-3 before the structured invoice is attached. Without it the invoice is embedded as rendered, and it is only a valid PDF/A-3 file if the document template uses embedded fonts only (the default template does). The back-office says which case applies.

## Access and limits of use

| Route | Right required | Notes |
|---|---|---|
| `GET /admin/module/FacturX/order/{id}/download` | view orders | generates the invoice if it is not issued yet |
| `POST /admin/module/FacturX/order/{id}/generate` | update orders | needs the back-office token |
| `POST /admin/module/FacturX/save` | update the module | settings form |

Generation is the costly operation: an administrator can start 30 of them per 15 minutes, beyond that the answer is `429`. The archive lives in `local/media/documents/facturx/`, outside the web root. The module exposes no API resource.

## Installation

```bash
composer require thelia/facturx-module
php Thelia module:refresh
php Thelia module:activate FacturX
php Thelia cache:clear
```

Settings are under **Back-office > Modules > Factur-X**.

## Limits

- An order placed before the current rounding rules (`last_legacy_rounding_order_id`) is refused.
- Amounts are written in the currency of the order. The VAT amount in the accounting currency is not written.
- The reasons printed on the allowance and charge lines and the exemption reasons are in French.
- The XSD of the profile is validated when the file is produced. The business rules were checked on issued invoices with Mustang 2.26.0 (EN 16931 and French Flux 2 Schematron): no error, and the file is a valid PDF/A-3b. What remains are warnings of the French rules, not written yet: the electronic addresses of the seller and of the buyer (BT-34, BT-49) and the legal mentions on recovery costs, late payment penalties and discount (BR-FR-05). They are left out on purpose: the electronic address of the buyer is personal data the profile does not require, and the mentions belong to the French rules, not to the EN 16931 profile. The delivery block carries the country of the delivery address, nothing more. A seller without a SIRET (`store_siret`) also gets a BR-FR-10 warning.
- The invoice mail is only sent from a request: a status change made from the command line is logged and the mail is not sent.

## Tests

```bash
env -u DATABASE_URL -u DATABASE_HOST APP_ENV=test php vendor/bin/phpunit -c phpunit.xml.dist \
    --bootstrap local/modules/FacturX/Tests/bootstrap.php local/modules/FacturX/Tests
```

Run the tests through that command rather than `phpunit` alone: the `DATABASE_*` variables of the environment would send them to the development database.

`FACTURX_LIBRARY_AUTOLOAD` is only needed when the library is not in the project vendor. A module checkout must not carry its own `vendor/` folder: the module loader scans the module directory.

Some tests depend on pieces that ship on their own release cycle and are reported as skipped when those are older than the module expects: the PDF template (the number printed on the invoice and on the credit note), the CreditNote module (it must be active in the test database) and the Flexy theme (the customer invoice route).

## License

LGPL-3.0+, part of the [Thelia](https://thelia.net) ecosystem.
