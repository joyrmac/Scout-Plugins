#!/usr/bin/env python3
"""Scout Site Standard check for a theme (Scout-Internal ops/playbook/site-standard.md, §3 and rule 7).

    python3 tools/theme-standard-check.py <theme-dir> [--warn] [--allow FILE]

Fails (exit 1) when the theme is missing a file §3 requires, or contains something rule 7
says a theme never holds: head tags, schema, tracking or consent code, a typed phone,
address, email or hours, post types, redirects, setup or import engines, its own admin
menu, copied plugin code, secrets. --warn reports without failing (a site still being
converted; its release workflow flips to strict when the conversion lands).

Documented exceptions (rule 0: "a site that breaks one records why in its docs/decisions.md")
go in <theme-dir>/.scout-standard-allow, one per line:  <check-id> <path-glob>  <why, naming decisions.md>
"""
import fnmatch, json, os, re, subprocess, sys

args = [a for a in sys.argv[1:]]
WARN = '--warn' in args
allow_file = None
if '--allow' in args:
    allow_file = args[args.index('--allow') + 1]
    args.remove('--allow'); args.remove(allow_file)
positional = [a for a in args if not a.startswith('--')]
if not positional or not os.path.isdir(positional[0]):
    print(__doc__); sys.exit(2)
THEME = positional[0].rstrip('/')
TOOLS = os.path.dirname(os.path.abspath(__file__))
allow_file = allow_file or os.path.join(THEME, '.scout-standard-allow')

allows = []
if os.path.isfile(allow_file):
    for line in open(allow_file, encoding='utf-8'):
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        parts = line.split(None, 2)
        if len(parts) < 3 or 'decisions.md' not in parts[2]:
            print(f"::error file={allow_file}::Each allow line needs: <check-id> <path-glob> <reason that names docs/decisions.md>. Got: {line}")
            sys.exit(1)
        allows.append((parts[0], parts[1], parts[2]))

fails, warns, notes = [], [], []
def rel(p): return os.path.relpath(p, THEME)
def allowed(check, path):
    r = rel(path) if path else ''
    return any(c == check and (fnmatch.fnmatch(r, g) or g == '*') for c, g, _ in allows)
def fail(check, path, msg, line=None):
    if allowed(check, path):
        notes.append((check, rel(path) if path else '', msg)); return
    where = f"{rel(path)}" + (f":{line}" if line else '') if path else THEME
    (warns if WARN else fails).append((check, where, msg))
def warn(check, path, msg, line=None):
    where = f"{rel(path)}" + (f":{line}" if line else '') if path else THEME
    warns.append((check, where, msg))

# ---------- §3: the files ----------
style = os.path.join(THEME, 'style.css')
headers = {}
if os.path.isfile(style):
    head = open(style, encoding='utf-8', errors='replace').read(4000)
    for key in ('Theme Name', 'Version', 'Update URI', 'Scout Release', 'Text Domain', 'Template'):
        m = re.search(r'^\s*\*?\s*' + re.escape(key) + r':\s*(.+?)\s*$', head, re.M)
        if m: headers[key] = m.group(1)
    for key in ('Theme Name', 'Version', 'Update URI', 'Scout Release', 'Text Domain'):
        if key not in headers:
            fail('style-header', style, f"style.css header is missing '{key}:' (§3; Update URI and Scout Release make the theme update itself, §10).")
    if 'Update URI' in headers and 'joyrmac/' in headers['Update URI']:
        fail('update-uri-org', style, "Update URI still points at joyrmac/. The repos live under Scout-Media-Raleigh now.")
    slug = os.path.basename(THEME)
    if 'Scout Release' in headers and headers['Scout Release'] != slug:
        fail('release-slug', style, f"Scout Release is '{headers['Scout Release']}' but the folder is '{slug}'. They must match.")
    if 'Text Domain' in headers and headers['Text Domain'] != slug:
        warn('text-domain', style, f"Text Domain is '{headers['Text Domain']}', folder is '{slug}'. §3 says they match.")
else:
    fail('style-missing', None, "No style.css: this is not a theme folder.")

is_block = os.path.isdir(os.path.join(THEME, 'templates')) or os.path.isfile(os.path.join(THEME, 'theme.json'))
required = ['DESIGN.md', 'scout-setup.json', 'scout-sync.json', 'functions.php', 'README.md', 'CHANGELOG.md', 'inc/scout-requires-build.php']
required += ['theme.json', 'templates'] if is_block else ['index.php']
for f in required:
    if not os.path.exists(os.path.join(THEME, f)):
        fail('missing-file', None, f"Missing {f} (§3: every Scout theme has exactly these files).")
if not any(os.path.isfile(os.path.join(THEME, 'screenshot.' + ext)) for ext in ('png', 'jpg', 'jpeg')):
    fail('missing-file', None, "Missing screenshot.png (§3).")
if os.path.isfile(os.path.join(THEME, 'scout-sync.json')) and not os.path.isdir(os.path.join(THEME, 'sync')):
    fail('missing-file', None, "scout-sync.json is there but no sync/ folder (§3: the page copy Site Sync writes).")
for f in ('scout-setup.json', 'scout-sync.json', 'theme.json'):
    p = os.path.join(THEME, f)
    if os.path.isfile(p):
        try:
            data = json.load(open(p, encoding='utf-8'))
        except Exception as e:
            fail('bad-json', p, f"{f} is not valid JSON: {e}"); continue
        if f == 'scout-setup.json':
            text = json.dumps(data).lower()
            if re.search(r'#[0-9a-f]{6}\b', text) or '"font' in text or 'fontfamily' in text:
                fail('setup-has-design', p, "scout-setup.json holds colors or fonts. Facts only (§5); design lives in DESIGN.md.")
            if 'features' not in data:
                warn('setup-features', p, "scout-setup.json has no \"features\": {\"use\": [...], \"skip\": [...]} list (rule 8, §5).")

# Theme kit copies must match the canonical ones (rule 25).
kit = {
    'inc/scout-requires-build.php': os.path.join(TOOLS, 'theme-kit', 'scout-requires-build.php'),
    'inc/class-scout-admin-ui.php': os.path.join(TOOLS, 'admin-kit', 'class-scout-admin-ui.php'),
    'assets/css/scout-admin.css': os.path.join(TOOLS, 'admin-kit', 'scout-admin.css'),
    'inc/scout-style-guide.php': os.path.join(TOOLS, 'theme-kit', 'style-guide', 'scout-style-guide.php'),
    'page-style-guide.php': os.path.join(TOOLS, 'theme-kit', 'style-guide', 'page-style-guide.php'),
    'templates/page-style-guide.html': os.path.join(TOOLS, 'theme-kit', 'style-guide', 'style-guide.html'),
}
for relp, canon in kit.items():
    p = os.path.join(THEME, relp)
    if os.path.isfile(p) and os.path.isfile(canon):
        if open(p, 'rb').read() != open(canon, 'rb').read():
            fail('stale-kit', p, f"{relp} differs from Scout-Plugins {os.path.relpath(canon, TOOLS)}. Copy the current kit file over (rule 25).")

# Every Scout theme ships /style-guide/ (Client Design Playbook, decision 4, 2026-11-05).
if not os.path.isfile(os.path.join(THEME, 'inc/scout-style-guide.php')):
    warn('style-guide', None, "No inc/scout-style-guide.php: every Scout theme ships /style-guide/. Copy it from tools/theme-kit/style-guide/ (Client Design Playbook, decision 4).")
else:
    page = 'templates/page-style-guide.html' if is_block else 'page-style-guide.php'
    if not os.path.isfile(os.path.join(THEME, page)):
        warn('style-guide', None, f"inc/scout-style-guide.php is there but {page} is not. Copy it from tools/theme-kit/style-guide/.")

# ---------- DESIGN.md and the generated files (§4) ----------
if os.path.isfile(os.path.join(THEME, 'DESIGN.md')):
    r = subprocess.run(['php', os.path.join(TOOLS, 'design-build.php'), THEME, '--check'], capture_output=True, text=True)
    for line in (r.stdout + r.stderr).splitlines():
        if line.startswith('::error'):
            fail('design', None, line.split('::', 2)[-1])
        elif line.startswith('::warning'):
            warn('design', None, line.split('::', 2)[-1])

# ---------- rule 7: what a theme never holds ----------
SKIP = {'inc/scout-requires-build.php', 'inc/class-scout-admin-ui.php', 'inc/scout-style-guide.php'}
def files(exts):
    for root, dirs, names in os.walk(THEME):
        dirs[:] = [d for d in dirs if d not in ('node_modules', 'vendor', '.git', 'sync', '_static-source')]
        for n in names:
            p = os.path.join(root, n)
            if rel(p) in SKIP or not n.lower().endswith(exts):
                continue
            yield p

CODE_RULES = [
    # check id, regex, message
    ('head-tags', r'<meta\s+(name|property)=["\'](description|og:|twitter:|robots)', "Head tags belong to Scout Core (rule 7: no title, description, canonical, Open Graph or robots tags in a theme)."),
    ('head-tags', r'rel=["\']canonical["\']', "Canonical tag in the theme; Scout Core prints it (rule 7)."),
    ('head-tags', r"add_filter\(\s*['\"](pre_get_document_title|wp_title|document_title_parts)", "The theme sets the page title; Scout Core owns titles (rule 7)."),
    ('schema', r'application/ld\+json', "JSON-LD in the theme. Scout Core emits the schema; only breadcrumbs may stay until scout-core emits them (rule 7)."),
    ('tracking', r'googletagmanager\.com|GTM-[A-Z0-9]{5,}|gtag\(|G-[A-Z0-9]{8,}|connect\.facebook\.net|fbq\(|clarity\.ms|hotjar', "Tracking code in the theme. Scout Analytics loads Tag Manager with consent (rule 7)."),
    ('consent', r'cookie[-_ ]?(notice|banner|consent)', "Cookie or consent code in the theme; Scout Analytics owns the notice (rule 7)."),
    ('admin-menu', r'add_menu_page\(|add_theme_page\(|add_options_page\(|add_management_page\(', "The theme adds its own admin screen. Only Scout → Website → Theme is allowed, registered under the 'scout' menu (rule 13, §7)."),
    ('customizer', r'\$wp_customize->add_setting\(', "Customizer settings in the theme (rule 13: no Customizer field that repeats a plugin setting)."),
    ('post-types', r'register_post_type\(|register_taxonomy\(', "Post types or taxonomies in the theme. They go in the client's companion plugin (rule 7)."),
    ('redirects', r'wp_redirect\(|wp_safe_redirect\(|header\(\s*["\']Location:', "A redirect in the theme. Scout Redirects owns redirects (rule 7)."),
    ('engine', r'wp_insert_post\(|wp_update_post\(|media_sideload_image\(|wp_insert_attachment\(|WP_Importer', "A setup, import or content engine in the theme. Site Sync does this from the bundle (rule 7, §6)."),
    ('plugin-copy', r'class\s+Scout_(?!Theme_Requires_Build\b|Admin_UI\b)[A-Za-z_]+', "A Scout_ class in the theme: copied plugin code (rule 7). Only the theme kit is allowed."),
    ('plugin-copy', r'class-scout-plugin-updater', "The plugin updater copied into the theme (rule 7). Themes update through Update URI and Scout Build."),
    ('secrets', r'github_pat_[A-Za-z0-9_]{20,}|ghp_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY|sk_live_[A-Za-z0-9]{16,}', "A secret in the theme (rule 7). Remove it and rotate it."),
]
# Typed facts (rule 7 and 16): a phone, email, street address or hours in a template.
FACT_RULES = [
    ('typed-phone', r'(?<![\w/])\(?\b[2-9]\d{2}\)?[ .-]\d{3}[ .-]\d{4}\b', "A phone number typed into the theme. Scout Core's business info and Scout Contact print it (rule 7, 16)."),
    ('typed-email', r'\b[A-Za-z0-9._%+-]+@(?!example\.|scoutraleigh\.com)[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b', "An email address typed into the theme; it comes from business info (rule 7)."),
    ('typed-address', r'\b\d{2,5}\s+(?:[A-Z][a-z]+\s+){1,3}(?:Street|St\.|Road|Rd\.|Avenue|Ave\.|Place|Pl\.|Drive|Dr\.|Boulevard|Blvd\.|Lane|Ln\.|Court|Ct\.|Way|Highway|Hwy\.|Parkway|Pkwy\.)\b', "A street address typed into the theme; it comes from business info (rule 7)."),
    ('typed-hours', r'\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\b[^\n<]{0,40}\b\d{1,2}(?::\d{2})?\s*(?:am|pm|AM|PM)\b', "Opening hours typed into the theme; Scout Hours owns them (rule 7)."),
]
php_html = tuple(['.php', '.html', '.htm'])
for p in files(php_html):
    try:
        text = open(p, encoding='utf-8', errors='replace').read()
    except OSError:
        continue
    for check, rx, msg in CODE_RULES:
        for m in re.finditer(rx, text):
            line = text.count('\n', 0, m.start()) + 1
            if check == 'schema' and 'BreadcrumbList' in text[m.start():m.start() + 2000]:
                warn(check, p, "Breadcrumbs schema in the theme: allowed until scout-core emits BreadcrumbList (rule 7).", line)
                break
            fail(check, p, msg, line)
            break  # One report per rule per file keeps the list readable.
    for check, rx, msg in FACT_RULES:
        for m in re.finditer(rx, text):
            # Skip version strings, dates and obvious code.
            ctx = text[max(0, m.start() - 30):m.end() + 30]
            if re.search(r'version|@since|\d{4}-\d{2}-\d{2}|\.css|\.js', ctx, re.I):
                continue
            line = text.count('\n', 0, m.start()) + 1
            fail(check, p, msg + f" Found: {m.group(0)!r}", line)
            break
# Secrets can hide in any text file.
for p in files(('.php', '.js', '.json', '.md', '.txt', '.env', '.yml', '.yaml', '.css')):
    text = open(p, encoding='utf-8', errors='replace').read()
    for m in re.finditer(CODE_RULES[-1][1], text):
        fail('secrets', p, CODE_RULES[-1][2], text.count('\n', 0, m.start()) + 1)
        break

# ---------- report ----------
for check, where, msg in notes:
    print(f"allowed  [{check}] {where}: {msg}")
for check, where, msg in warns:
    print(f"::warning file={where}::[{check}] {msg}")
for check, where, msg in fails:
    print(f"::error file={where}::[{check}] {msg}")
name = headers.get('Theme Name', os.path.basename(THEME))
mode = 'report only (--warn)' if WARN else 'strict'
print(f"\nSite Standard check, {name} ({os.path.basename(THEME)}), {mode}: {len(fails)} failing, {len(warns)} warnings, {len(notes)} allowed exceptions.")
if fails:
    print("Fix each line above, or record the exception in docs/decisions.md and list it in .scout-standard-allow.")
sys.exit(1 if fails else 0)
