#!/usr/bin/env python3
"""Build languages/mt-smart-link-automation.pot from the plugin source (no gettext tools needed)."""
import re, glob, os, datetime
root = os.path.join(os.path.dirname(__file__), "..", "mt-smart-link-automation")
pat = re.compile(r"""\b(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*,\s*'mt-smart-link-automation'""", re.S)
plural = re.compile(r"""\b_n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'""", re.S)
items = {}
for f in sorted(glob.glob(root + "/**/*.php", recursive=True)):
    src = open(f, encoding="utf-8").read()
    rel = os.path.relpath(f, root)
    for m in pat.finditer(src):
        text = (m.group(2) if m.group(2) is not None else m.group(3)).replace("\\'", "'")
        line = src.count("\n", 0, m.start()) + 1
        items.setdefault(("s", text, None), []).append("%s:%d" % (rel, line))
    for m in plural.finditer(src):
        line = src.count("\n", 0, m.start()) + 1
        items.setdefault(("p", m.group(1), m.group(2)), []).append("%s:%d" % (rel, line))
def q(s): return '"' + s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n") + '"'
out = ['# MT Smart Link Automation translation template.', '# Copyright (C) Mohsen Tavoosi. Licensed under GPL-2.0-or-later.', 'msgid ""', 'msgstr ""',
       '"Project-Id-Version: MT Smart Link Automation 2.2.2\\n"', '"POT-Creation-Date: %s\\n"' % datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M+0000"),
       '"MIME-Version: 1.0\\n"', '"Content-Type: text/plain; charset=UTF-8\\n"', '"Content-Transfer-Encoding: 8bit\\n"', '"X-Domain: mt-smart-link-automation\\n"', '']
for (kind, a, b), refs in items.items():
    out.append("#: " + " ".join(refs))
    if kind == "s":
        out += ["msgid " + q(a), 'msgstr ""', ""]
    else:
        out += ["msgid " + q(a), "msgid_plural " + q(b), 'msgstr[0] ""', 'msgstr[1] ""', ""]
os.makedirs(root + "/languages", exist_ok=True)
open(root + "/languages/mt-smart-link-automation.pot", "w", encoding="utf-8").write("\n".join(out))
print("%d strings" % len(items))
