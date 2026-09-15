# Migration fixtures

Copies of one-time **data-backfill / guard** migrations that were removed from
`database/migrations/` by the v2.0.2 schema squash
(`database/schema/{sqlite,mariadb}-schema.sql`).

They live here only so the tests that exercise their `up()` logic keep their
coverage — the tests rebuild the pre-migration state themselves and call `up()`
directly, so the files never need to be in the migration path.

**They are NOT migrations any more.** Laravel never loads this directory; adding
a file here runs nothing. New schema changes go in `database/migrations/` as
normal migrations on top of the baseline.

Referenced by:

| fixture | test |
| --- | --- |
| `2026_06_03_000002_migrate_customer_details_to_notes.php` | `tests/Feature/Etl/BackupNoteSyncTest.php` |
| `2026_06_03_000003_drop_details_from_customers_table.php` | `tests/Feature/Etl/BackupNoteSyncTest.php` |
| `2026_09_02_000002_add_series_to_numbered_documents.php` | `tests/Feature/DocumentSeriesFreezeTest.php` |
| `2026_09_02_000005_backfill_inverted_provider_cancellation_marks.php` | `tests/Feature/MyData/BackfillInvertedCancellationMarksTest.php` |
| `2026_09_03_000001_add_afm_key_unique_to_customers.php` | `tests/Feature/Customers/CustomerAfmKeyTest.php` |
| `2026_09_18_000001_normalise_einvoice_provider_key.php` | `tests/Feature/EInvoice/ProviderKeyNormalisationTest.php` |
| `2026_09_21_000006_normalise_tda_invoice_type_flag.php` | `tests/Feature/EInvoice/CombinedTdaSeedFormTest.php` |
| `2026_09_01_000001_add_recipient_country_to_delivery_notes.php` | `tests/Feature/Delivery/RecipientCountryBackfillTest.php` |
| `2026_09_08_000001_add_country_code_to_customers_and_suppliers.php` | `tests/Feature/CountryCodeNormalizationTest.php` |
