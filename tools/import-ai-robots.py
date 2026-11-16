#!/usr/bin/env python3
"""Rebuild hubbub-ai-hub/data/community-bots.json from the ai.robots.txt community list (MIT licence).

Usage: python3 tools/import-ai-robots.py [path/to/robots.json]
Without a path it downloads https://raw.githubusercontent.com/ai-robots-txt/ai.robots.txt/main/robots.json.
Bots already in data/crawlers.json are left out, since the directory covers them in more depth.
"""
import json, re, sys, os, datetime, urllib.request

SRC = 'https://raw.githubusercontent.com/ai-robots-txt/ai.robots.txt/main/robots.json'
HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, '..', 'hubbub-ai-hub', 'data')

def plain(s, limit=None):
    s = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', s or '')  # markdown links -> text
    s = re.sub(r'[`*_]', '', s).strip()
    s = re.sub(r'\s+', ' ', s)
    if s.lower().startswith(('unclear', 'not applicable', 'no information')):
        s = s.split('.')[0]
    if limit and len(s) > limit:
        s = s[:limit].rsplit(' ', 1)[0].rstrip(',;:') + '…'
    return s

GROUPS = [
    ('search', ('search',)),
    ('assistant', ('assistant', 'user', 'agent', 'coding')),
    ('training', ('scraper', 'training', 'train', 'dataset', 'data provider', 'scrapes')),
]

def group(fn):
    f = (fn or '').lower()
    for g, keys in GROUPS:
        if any(k in f for k in keys):
            return g
    return 'other'

def main():
    if len(sys.argv) > 1:
        raw = json.load(open(sys.argv[1]))
    else:
        raw = json.load(urllib.request.urlopen(SRC, timeout=30))
    ours = {c['token'].lower() for c in json.load(open(os.path.join(DATA, 'crawlers.json')))['crawlers']}
    bots = []
    for name, v in sorted(raw.items(), key=lambda kv: kv[0].lower()):
        if name.lower() in ours or name.startswith('__'):
            continue
        bots.append({
            'name': name,
            'operator': plain(v.get('operator'), 60),
            'type': group(v.get('function')),
            'function': plain(v.get('function'), 60),
            'respects_robots': plain(v.get('respect'), 80),
            'about': plain(v.get('description'), 220),
        })
    out = {
        'updated': datetime.date.today().isoformat(),
        'source': {'name': 'ai.robots.txt', 'url': 'https://github.com/ai-robots-txt/ai.robots.txt', 'licence': 'MIT', 'notice': 'Copyright (c) 2024 ai.robots.txt. Used under the MIT licence.'},
        'bots': bots,
    }
    with open(os.path.join(DATA, 'community-bots.json'), 'w') as f:
        f.write(json.dumps(out, indent=1, ensure_ascii=False) + '\n')
    print(len(bots), 'community bots written')

main()
