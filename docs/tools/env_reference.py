"""Generate docs/ENVIRONMENT.md from .env.example.

Every key with its default and the comment block above it, grouped by the
section headers in .env.example, followed by the cross-check against what
the code actually reads (config/, app/, bootstrap/, routes/, database/).

    python docs/tools/env_reference.py > docs/ENVIRONMENT.md
"""
import os, re, sys, glob

sys.stdout.reconfigure(encoding="utf-8")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
lines = open(os.path.join(ROOT, ".env.example"), encoding="utf-8").read().splitlines()

sections = []          # (title, [ (key, default, inline_comment, block_comment) ])
current = None
pending_comment = []
for raw in lines:
    line = raw.rstrip()
    m = re.match(r"^# ─+ (.+?) ─+$", line)
    if m:
        current = (m.group(1).strip(), [])
        sections.append(current)
        pending_comment = []
        continue
    if line.startswith("#"):
        text = line.lstrip("#").strip()
        if text:
            pending_comment.append(text)
        continue
    if not line.strip():
        pending_comment = []
        continue
    m = re.match(r"^([A-Z0-9_]+)=(.*)$", line)
    if m and current is not None:
        key, rest = m.group(1), m.group(2)
        inline = ""
        default = rest
        if "#" in rest and not rest.strip().startswith('"'):
            default, inline = rest.split("#", 1)
            default, inline = default.strip(), inline.strip()
        current[1].append((key, default.strip(), inline, " ".join(pending_comment)))
        pending_comment = []

documented = {k for _, keys in sections for (k, *_rest) in keys}

read = set()
for pattern in ["config/**/*.php", "app/**/*.php", "bootstrap/**/*.php", "routes/**/*.php", "database/**/*.php"]:
    for path in glob.glob(os.path.join(ROOT, pattern), recursive=True):
        src = open(path, encoding="utf-8", errors="replace").read()
        read.update(re.findall(r"env\('([A-Z0-9_]+)'", src))

VENDOR_PREFIXES = ("REDIS_", "MEMCACHED_", "DYNAMODB_", "SQS_", "BEANSTALKD_", "SENTRY_", "LOG_", "POSTMARK_", "SLACK_", "PAPERTRAIL_", "AWS_", "FFMPEG", "FFPROBE", "DB_", "MAIL_", "AUTH_", "SESSION_", "APP_MAINTENANCE", "APP_PREVIOUS", "MEDIA_", "QUEUE_CONVERSIONS", "FORCE_MEDIA", "ENABLE_MEDIA", "MYSQL_ATTR")
read_not_documented = sorted(k for k in read - documented if not k.startswith(VENDOR_PREFIXES))
documented_not_read = sorted(k for k in documented - read if not k.startswith("HONEYPOT_"))

out = []
out.append("# Environment reference — every `.env` key\n")
out.append("Generated from `.env.example` by `docs/tools/env_reference.py`; regenerate after adding a key. The rule (`CLAUDE.md`): every key in `.env.example` is read by something, and every key the code reads is in `.env.example`. §2 is the check. Real values live only in `shared/.env` on the server; after changing one there, `php artisan config:cache`.\n")
out.append(f"{len(documented)} keys in {len(sections)} sections.\n")
out.append("## 1. Keys by section\n")
for title, keys in sections:
    if not keys:
        continue
    out.append(f"### {title}\n")
    out.append("| Key | Default in `.env.example` | What it does |")
    out.append("|---|---|---|")
    for key, default, inline, block in keys:
        desc = " ".join(x for x in [block, inline] if x).replace("|", "\\|")
        d = f"`{default}`" if default else "*(empty)*"
        out.append(f"| `{key}` | {d} | {desc} |")
    out.append("")
out.append("## 2. The cross-check\n")
out.append("Keys the code reads via `env()` that `.env.example` does not document (framework and vendor defaults with their own config files are excluded):\n")
out.append("- " + ", ".join(f"`{k}`" for k in read_not_documented) if read_not_documented else "- none ✔")
out.append("\nKeys `.env.example` documents that no `env()` call in this repository reads (`HONEYPOT_*` are read by spatie/laravel-honeypot's own config and are excluded):\n")
out.append("- " + ", ".join(f"`{k}`" for k in documented_not_read) if documented_not_read else "- none ✔")
out.append("""
## 3. The ones that matter most

| Key | Why it is dangerous to get wrong |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` and `false`, or the deploy script refuses. Debug pages show `.env` values |
| `APP_KEY` | rotating it invalidates every encrypted setting, cookie and signed URL; `APP_PREVIOUS_KEYS` exists for that |
| `PAYMENT_DRIVER`, `PAYSTACK_*` | `fake` takes no money; `sk_test_` on production is refused by the deploy script; the webhook secret must be the same account's |
| `PAYSTACK_WEBHOOK_SECRET` | empty = every webhook rejected = no donation is ever completed |
| `MAIL_*`, `RESEND_*` | receipts; `PHASE-10-EMAIL-DELIVERABILITY.md` |
| `SMS_DRIVER` + its keys | the provider refuses to boot in production with a driver chosen and no key |
| `ADMIN_PATH` | the panel's address; `/admin` is refused |
| `TRUSTED_PROXIES` | behind Cloudflare, without it every visitor is Cloudflare — rate limits and the IP allowlist stop working |
| `MEDIA_INODE_BUDGET` | the health row is only as honest as this number |
| `BACKUP_*` | off-server destination and the encryption password; a backup you cannot decrypt is not one |
""")
print("\n".join(out))
