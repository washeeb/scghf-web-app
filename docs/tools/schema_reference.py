"""Generate docs/DATABASE.md from the live scghf_dev schema: a table per module
with columns, plus a Mermaid ER diagram of the money and content spine."""
import subprocess, collections, re, os, sys

import os, re as _re
_env = open(os.path.join(os.path.dirname(__file__), "..", "..", ".env"), encoding="utf-8").read() if os.path.exists(os.path.join(os.path.dirname(__file__), "..", "..", ".env")) else ""
MYSQL = os.environ.get("MYSQL_BIN", "mysql")
DB = os.environ.get("DB_DATABASE") or (_re.search(r"^DB_DATABASE=(\S+)", _env, _re.M) or [None, "scghf_dev"])[1]
DB_USER = os.environ.get("DB_USERNAME") or (_re.search(r"^DB_USERNAME=(\S+)", _env, _re.M) or [None, "root"])[1]

def q(sql):
    out = subprocess.run([MYSQL, "-u", DB_USER, "-N", "-B", "-e", sql], capture_output=True, text=True, encoding="utf-8", errors="replace")
    return [line.split("\t") for line in out.stdout.splitlines() if line.strip()]

cols = q(f"SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{DB}' ORDER BY TABLE_NAME, ORDINAL_POSITION")
fks = q(f"SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{DB}' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME")
rows = dict((r[0], int(r[1])) for r in q(f"SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='{DB}'"))

tables = collections.OrderedDict()
for t, c, ty, nul, key, dflt, extra in cols:
    tables.setdefault(t, []).append((c, ty, nul, key, dflt, extra))
fk_by_table = collections.defaultdict(list)
for t, c, rt, rc in fks:
    fk_by_table[t].append((c, rt, rc))

MODULES = [
    ("Core & auth", ["users", "roles", "permissions", "model_has_roles", "model_has_permissions", "role_has_permissions", "login_histories", "api_tokens", "password_reset_tokens", "sessions", "settings_history"]),
    ("Settings & CMS", ["settings", "theme_settings", "pages", "page_sections", "page_revisions", "block_types", "menus", "menu_items", "media", "media_folders", "consents", "seo_meta", "redirects", "announcements", "divisions", "offices"]),
    ("Content", ["posts", "blog_categories", "tags", "taggables", "comments", "galleries", "gallery_items", "documents", "faqs", "faq_categories", "testimonials", "partners", "partner_project", "team_members", "team_departments", "stories", "document_project"]),
    ("Programmes & impact", ["focus_areas", "focus_area_project", "projects", "project_updates", "project_milestones", "project_locations", "impact_metrics", "impact_metric_values", "beneficiaries", "beneficiary_documents", "beneficiary_impact_records", "legal_holds", "retention_log", "privacy_test_subjects", "expenditures"]),
    ("Fundraising & payments", ["causes", "cause_updates", "giving_levels", "donations", "donation_items", "donation_receipts", "donors", "donation_plans", "subscriptions", "subscription_charges", "pledges", "sponsorships", "sponsorship_updates", "fundraisers", "receipt_sequences", "payment_transactions", "payment_webhook_events", "refunds", "payouts", "tax_approvals", "reconciliation_runs"]),
    ("Shop", ["products", "product_categories", "product_variants", "product_images", "product_reviews", "inventory_movements", "carts", "cart_items", "orders", "order_items", "order_status_histories", "invoices", "invoice_sequences", "product_related", "digital_download_tokens", "shipping_zones", "shipping_rates", "coupons", "coupon_redemptions", "downloads", "regulatory_reviews"]),
    ("Engagement", ["events", "event_registrations", "event_tickets", "volunteer_opportunities", "volunteer_applications", "safeguarding_checks", "volunteers", "issued_tickets", "volunteer_hours", "volunteer_shifts", "contact_messages", "contact_departments", "enquiries", "prayer_requests"]),
    ("Communications", ["email_templates", "sms_templates", "email_logs", "sms_logs", "scheduled_messages", "newsletters", "subscribers", "newsletter_subscriptions", "newsletter_campaigns", "campaign_recipients", "sms_broadcasts", "suppressions", "inbound_webhook_events", "notification_logs"]),
    ("System", ["audit_logs", "audit_archives", "activity_log", "backups_log", "error_reports", "feature_flags", "visitor_stats", "jobs", "job_batches", "failed_jobs", "cache", "cache_locks", "migrations"]),
]

assigned = set()
out = []
out.append("# Database reference\n")
out.append("Generated from the live schema on 2026-09-18 (`scghf_dev`, 135 tables, migrations through `2026_09_18_000001`). Regenerate with the script in `docs/DATABASE.md` §5 after any migration. The conventions — integer pesewas, ULIDs, append-only money tables, soft deletes, the migration-safety policy — are in `PHASE-3-DATA-ARCHITECTURE.md` and are not repeated here.\n")
out.append("## 1. The spine\n")
out.append("""```mermaid
erDiagram
    users ||--o{ donors : "may own"
    donors ||--o{ donations : gives
    causes ||--o{ donations : receives
    projects ||--o{ causes : "funded by"
    donations ||--o| payment_transactions : "paid by (polymorphic payable)"
    orders ||--o| payment_transactions : "paid by (polymorphic payable)"
    payment_transactions ||--o{ payment_webhook_events : "settled by"
    payment_transactions ||--o{ refunds : refunded
    donations ||--o| donation_receipts : receipted
    subscriptions ||--o{ donations : "charges become"
    donors ||--o{ subscriptions : "gives monthly"
    orders ||--o{ order_items : contains
    product_variants ||--o{ order_items : sold
    products ||--o{ product_variants : has
    pages ||--o{ page_sections : "built from"
    block_types ||--o{ page_sections : renders
    menus ||--o{ menu_items : holds
    events ||--o{ event_registrations : takes
    volunteer_applications ||--o| volunteers : becomes
    media }o--o{ consents : "publishable with"
    audit_logs ||--o{ audit_archives : "moved into"
```
""")
out.append("## 2. Tables by module\n")
out.append("Types are MySQL's. **PK** primary key, **U** unique, **I** indexed, **FK →** foreign key. `*_minor` columns are integer pesewas; `ulid` columns are the public identifier and `id` never leaves the server.\n")
for module, names in MODULES:
    present = [n for n in names if n in tables]
    if not present:
        continue
    out.append(f"### {module}\n")
    for t in present:
        assigned.add(t)
        out.append(f"#### `{t}`\n")
        out.append("| Column | Type | Null | Key | Default |\n|---|---|---|---|---|")
        for c, ty, nul, key, dflt, extra in tables[t]:
            k = {"PRI": "PK", "UNI": "U", "MUL": "I"}.get(key, "")
            fk = next((f"FK → `{rt}.{rc}`" for cc, rt, rc in fk_by_table.get(t, []) if cc == c), "")
            keycell = " ".join(x for x in [k, fk] if x)
            d = "" if dflt in (None, "NULL", "") else f"`{dflt}`"
            if "auto_increment" in extra:
                d = "auto"
            out.append(f"| `{c}` | {ty} | {'yes' if nul == 'YES' else ''} | {keycell} | {d} |")
        out.append("")
rest = [t for t in tables if t not in assigned]
if rest:
    out.append("### Other\n")
    for t in rest:
        out.append(f"#### `{t}`\n")
        out.append("| Column | Type | Null | Key | Default |\n|---|---|---|---|---|")
        for c, ty, nul, key, dflt, extra in tables[t]:
            k = {"PRI": "PK", "UNI": "U", "MUL": "I"}.get(key, "")
            fk = next((f"FK → `{rt}.{rc}`" for cc, rt, rc in fk_by_table.get(t, []) if cc == c), "")
            keycell = " ".join(x for x in [k, fk] if x)
            d = "" if dflt in (None, "NULL", "") else f"`{dflt}`"
            if "auto_increment" in extra:
                d = "auto"
            out.append(f"| `{c}` | {ty} | {'yes' if nul == 'YES' else ''} | {keycell} | {d} |")
        out.append("")

out.append("""## 3. Where money lives

Four tables, and the rules that hold across them (`PHASE-3-DATA-ARCHITECTURE.md` §3, `PAYMENTS.md`):

- `donations` — append-only: status moves forward, amounts never change after creation; a wrong gift is refunded, not edited.
- `payment_transactions` — the gateway boundary, one row per attempt for donations and orders alike (polymorphic `payable_type`/`payable_id`); `gateway_reference` is unique.
- `payment_webhook_events` — every delivery, stored raw before parsing; `event_id` unique is the replay guard; rows are never deleted (bodies older than a year move to compressed files, `payload_archive`).
- `refunds` — requested by one person, approved by another; the ledger moves only when the gateway confirms.

## 4. Tables that are never swept

`audit_logs` (hash-chained; closed years move to `audit_archives`), `payment_webhook_events`, `donations`, `donation_receipts`, `suppressions`. The retention runner (`PHASE-12-DATA-PROTECTION.md`) covers personal data elsewhere on a schedule with holds.

## 5. Regenerating this file

```bash
python docs/tools/schema_reference.py > docs/DATABASE.md
```

The script reads `information_schema` on the database named in `.env` (`DB_DATABASE`, `DB_USERNAME`; the password is taken from `~/.my.cnf` or a `mysql` on the PATH that needs none — set `MYSQL_BIN` when the client is not on the PATH). Run migrations first. The module grouping is a list in the script — a new table not in any list lands under *Other* until somebody files it.
""")
open("E:/Businesses/2. Greater Hope Foundations/SCGHF-Web-App/docs/DATABASE.md", "w", encoding="utf-8", newline="\n").write("\n".join(out))
print(len(tables), "tables;", len(rest), "unfiled:", rest, file=sys.stderr)
