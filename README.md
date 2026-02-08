# Factur-X Module for Thelia 3

Generates **PDF/A-3 invoices** compliant with the [Factur-X (EN16931)](https://fnfe-mpe.org/factur-x/) standard for French electronic invoicing requirements.

## How it works

The module intercepts Thelia's PDF invoice generation. When an invoice is produced, it:

1. Builds a **CrossIndustryInvoice XML** (CII) from the order data
2. Embeds the XML into the PDF and converts it to **PDF/A-3** using [atgp/factur-x](https://github.com/atgp/factur-x)
3. Archives the resulting PDF to disk
4. Returns the compliant PDF to the user

The process is transparent — existing invoice generation continues to work as before, with Factur-X compliance added on top.

## Requirements

- Thelia 3
- PHP 8.2+

## Installation

**Via Composer:**

```bash
composer require thelia/facturx-module
php Thelia module:activate FacturX
php Thelia cache:clear
```

**Manual installation:**

```bash
git clone <repository-url> local/modules/FacturX
composer require atgp/factur-x
php Thelia module:activate FacturX
php Thelia cache:clear
```

## Configuration

Navigate to **Back-office > Modules > Factur-X** and fill in:

| Field | Description |
|-------|-------------|
| **SIRET** | Your company's 14-digit SIRET number |
| **VAT identification number** | EU VAT number, e.g. `FR12345678901` |
| **Enable Factur-X** | Toggle automatic generation on/off |

Seller name and address are read from the store configuration (**Settings > Store**).

## EN16931 Compliance

The generated XML covers the EN16931 **comfort** profile:

- **Seller**: name, address, SIRET (BT-30), VAT number (BT-31)
- **Buyer**: name and address from the invoice address
- **Invoice**: number, date, currency, type code (380)
- **Lines**: product name, quantity, unit price, VAT rate
- **Totals**: line total, tax basis, tax amount, grand total, due payable amount

## Archive

Generated Factur-X PDFs are automatically archived on disk, organized by year. The storage path is configurable in the module settings.

## File Structure

```
FacturX/
├── composer.json
├── FacturX.php
├── Config/
│   ├── module.xml
│   └── config.xml
├── EventListener/
│   └── InvoicePdfListener.php
├── Service/
│   └── FacturXService.php
├── Form/
│   └── ConfigurationForm.php
├── Controller/
│   └── Admin/
│       └── ConfigurationController.php
├── I18n/
│   ├── fr_FR.php
│   └── en_US.php
└── templates/
    └── backOffice/
        └── default/
            └── facturx-configuration.html
```

## License

This module is part of the [Thelia](https://thelia.net) e-commerce ecosystem.
