**Seeding Customs Profiles**

- Source: `zonos/zonos_classification_100.json` (keys: `"Description|CC"`)
- Output options:
  - CSV for admin importer: `scripts/zonos_json_to_customs_csv.php`
  - SQL seed for direct DB load: `scripts/zonos_json_to_sql.php`

Usage

- CSV:
  - `php scripts/zonos_json_to_customs_csv.php zonos/zonos_classification_100.json > data/customs_profiles.csv`
  - Columns: `description,country_code,hs_code,fta_flags,us_duty_json,effective_from,effective_to,notes`
  - Note: `fta_flags` left as `[]` (cannot infer eligibility from Zonos file)

- SQL:
  - `php scripts/zonos_json_to_sql.php zonos/zonos_classification_100.json > data/seed_customs_profiles.sql`
  - Load into DB after running migration: `migrations/001_create_customs_profiles.sql`

Notes

- `us_duty_json` includes only channels present in the source and their `rates` map. De minimis thresholds are omitted unless present in input.
- `effective_from` defaults to the day of conversion; adjust if you need a specific version window.
- Table name uses `wp_wrd_customs_profiles`. If your site uses a different table prefix, adjust accordingly in the SQL or WordPress migration code.

