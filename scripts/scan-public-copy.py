#!/usr/bin/env python3
"""Bounded disclosure scan. Reports rule/path/line only, never matched values.

This complements review of data provenance, binary assets and licenses. It is
not a proof that an application is secure or every possible secret is absent.
"""
import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
RULES = {
    'private-key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
    'cloud-access-key': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
    'github-token': rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})\b',
    'service-token': rb'\b(?:sk_live_|rk_live_|xox[baprs]-)[A-Za-z0-9-]{16,}',
    'credential-url': rb'://[^\s/:"\x27<>]+:[^\s/@"\x27<>]{6,}@',
    'real-email': rb'\b[A-Za-z0-9._%+-]+@(?:gmail\.com|mail\.ru|yandex\.ru|christmedschool\.com)\b',
    'home-path': rb'/(?:Users|home)/[A-Za-z0-9_-]+/(?:Desktop|Documents|\.ssh|\.aws)',
    'host-login': rb'\broot@(?:\d{1,3}\.){3}\d{1,3}',
    'private-storage': rb'\b[0-9a-f]{8}-[0-9a-f-]{27,}\.selstorage\.ru\b',
}
COMPILED = {name: re.compile(pattern) for name, pattern in RULES.items()}


def matches(data):
    return [(name, data.count(b'\n', 0, match.start()) + 1, hashlib.sha256(match.group()).hexdigest())
            for name, regex in COMPILED.items() for match in regex.finditer(data)]


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--history', action='store_true')
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    if args.self_test:
        # Assembled at runtime: canaries are not live keys and are not saved in Git.
        canaries = {
            'private-key': b'-----BEGIN '+b'PRIVATE KEY-----',
            'cloud-access-key': b'AK' + b'IA' + b'X' * 16,
            'github-token': b'gh' + b'p_' + b'X' * 36,
            'service-token': b'sk_' + b'live_' + b'X' * 24,
            'credential-url': b'https://demo:' + b'canary-only@host.invalid',
            'real-email': b'canary@' + b'gmail.com',
            'home-path': b'/Users/' + b'canary/Desktop/project',
            'host-login': b'root@' + b'203.0.113.10',
            'private-storage': b'12345678-' + b'1234-1234-1234-123456789abc.selstorage.ru',
        }
        for rule, value in canaries.items():
            assert any(name == rule for name, _, _ in matches(value)), rule
        assert not matches(b'https://example.invalid/demo demo@example.test')
        print(json.dumps({'self_test': 'passed', 'positive_canaries': len(canaries), 'negative_canaries': 1}))
        return
    paths = git('ls-files', '-z').decode().split('\0')[:-1]
    if not paths:
        raise SystemExit('Index is empty: stage the candidate before scanning')
    findings, inventory, reviewed = [], [], 0
    allowlist = json.loads((ROOT / 'scripts/disclosure-allowlist.json').read_text())

    def allowed(identity, rule, digest):
        # File exceptions bind both the value and its reviewed complete blob.
        blob = git('hash-object', '--', str(ROOT / identity)).decode().strip() if (ROOT / identity).is_file() else identity
        return any((identity == item['git_blob'] or (identity == item['path'] and blob == item['git_blob']))
                   and rule == item['rule'] and digest == item['match_sha256'] for item in allowlist)
    for relative in paths:
        path = ROOT / relative
        if path.is_symlink():
            findings.append({'path': relative, 'rule': 'symlink'})
            continue
        if (path.suffix.lower() in {'.sql', '.sqlite', '.log', '.bak', '.zip', '.pdf', '.mp4', '.webm', '.pyc'}
                or path.name == '.env' or (path.name.startswith('.env.') and path.name != '.env.example')
                or any(part in {'.agent-work', '.serena', 'vendor', 'node_modules'} for part in path.relative_to(ROOT).parts)):
            findings.append({'path': relative, 'rule': 'forbidden-artifact'})
        data = path.read_bytes()
        for rule, line, digest in matches(data):
            if allowed(relative, rule, digest):
                reviewed += 1
            else:
                findings.append({'path': relative, 'rule': rule, 'line': line})
        inventory.append({'path': relative, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    objects = Counter()
    if args.history:
        for line in git('cat-file', '--batch-all-objects', '--batch-check=%(objectname) %(objecttype)').decode().splitlines():
            oid, kind = line.split()
            objects[kind] += 1
            data = git('cat-file', kind, oid)
            for rule, lineno, digest in matches(data):
                if allowed(oid, rule, digest):
                    reviewed += 1
                else:
                    findings.append({'object': oid, 'kind': kind, 'rule': rule, 'line': lineno})
        # This copy is already public. Review the one authorized origin exactly;
        # an additional remote or a changed URL still fails without printing it.
        for remote in git('remote').decode().splitlines():
            urls = git('remote', 'get-url', '--all', remote).decode().splitlines()
            pushurls = git('remote', 'get-url', '--push', '--all', remote).decode().splitlines()
            if remote != 'origin' or urls != ['https://github.com/Web-A1/CMS_laravel-next.git'] or pushurls != urls:
                findings.append({'rule': 'unreviewed-remote'})
    print(json.dumps({'files_scanned': len(paths), 'bytes_scanned': sum(x['bytes'] for x in inventory),
                      'git_objects_scanned': dict(objects), 'reviewed_allowlisted_matches': reviewed, 'findings': findings,
                      'inventory_digest': hashlib.sha256(json.dumps(inventory, sort_keys=True).encode()).hexdigest()}, ensure_ascii=False, indent=2))
    raise SystemExit(bool(findings))


if __name__ == '__main__':
    main()
