#!/usr/bin/env python3
"""Compile this plugin's singular UTF-8 PO catalog without external dependencies.

Reject unsupported PO constructs instead of silently omitting translations.
"""
import ast, gettext, pathlib, re, struct

root = pathlib.Path(__file__).resolve().parents[1]
source = root / 'languages/ozeki-database-backup-for-s3-ja.po'
messages = {}
key = value = None
field = None
def finish():
    global key, value
    if key is not None:
        if value is None or key in messages:
            raise ValueError('Incomplete or duplicate PO entry')
        messages[key] = value
    key = value = None
for line in source.read_text(encoding='utf-8').splitlines() + ['']:
    if not line.strip():
        finish(); field = None
    elif line.startswith('#'):
        if 'fuzzy' in line:
            raise ValueError('Review fuzzy translation before compiling')
    elif line.startswith('msgid '):
        key = ast.literal_eval(line[6:]); field = 'key'
    elif line.startswith('msgstr '):
        value = ast.literal_eval(line[7:]); field = 'value'
    elif line.startswith('"') and field:
        if field == 'key': key += ast.literal_eval(line)
        else: value += ast.literal_eval(line)
    else:
        raise ValueError('Unsupported PO syntax: ' + line)
ordered = sorted(messages)
for path in ['src/Admin/SettingsPage.php', 'src/Admin/MediaBackupPanel.php', 'src/Aws/ConnectionTester.php']:
    strings = re.findall(r"(?:__|_e|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'])*)'", (root/path).read_text(encoding='utf-8'))
    for text in strings:
        if not messages.get(text): raise ValueError('Missing translation: ' + text)
for key, value in messages.items():
    if sorted(re.findall(r'%\d+\$[sd]', key)) != sorted(re.findall(r'%\d+\$[sd]', value)):
        raise ValueError('Format placeholders differ: ' + key)
ids = [s.encode('utf-8') for s in ordered]
values = [messages[s].encode('utf-8') for s in ordered]
offset = 28 + 16 * len(ids)
tables = []
for group in (ids, values):
    for item in group:
        tables.append(struct.pack('<II', len(item), offset))
        offset += len(item) + 1
binary = struct.pack('<7I', 0x950412de, 0, len(ids), 28, 28 + 8*len(ids), 0, 0)
binary += b''.join(tables) + b''.join(s+b'\0' for s in ids+values)
target = source.with_suffix('.mo')
target.write_bytes(binary)
with target.open('rb') as stream:
    translations = gettext.GNUTranslations(stream)
for key, value in messages.items():
    if key: assert translations.gettext(key) == value
print(f'Compiled and verified {len(messages)-1} translations: {target.name}')
