"""Prepare a corrective version on stable; tag the release commit only after its own full pipeline."""

import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile


def git(*args):
    return subprocess.check_output(['git', *args], text=True).strip()


def advisories(ref, directory):
    for name in ('composer.json', 'composer.lock'):
        (directory / name).write_text(git('show', f'{ref}:{name}'))
    audit = subprocess.run(['composer', '--no-plugins', '--no-scripts', 'audit', '--locked', '--no-dev',
                            '--abandoned=ignore', '--format=json'], cwd=directory, capture_output=True, text=True)
    if audit.returncode not in (0, 1):
        raise RuntimeError('Composer audit unavailable')
    entries = json.loads(audit.stdout)['advisories']
    if entries == []:
        entries = {}
    ids = {(package, item['advisoryId']) for package, items in entries.items()
           for item in (items.values() if isinstance(items, dict) else items)}
    if bool(ids) != bool(audit.returncode) or 'could not' in audit.stderr.lower():
        raise RuntimeError('Incomplete Composer audit')
    return ids


def main():
    sha = os.environ['CI_COMMIT_SHA']
    remote = os.environ['CI_PROJECT_URL'] + '.git'
    git('fetch', '--tags', remote, 'refs/heads/stable')
    if git('rev-parse', 'FETCH_HEAD') != sha:
        return
    tags = git('tag', '--sort=-v:refname').splitlines()
    latest = next(tag for tag in tags if re.fullmatch(r'v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)', tag))
    git('merge-base', '--is-ancestor', latest, sha)
    if git('rev-parse', latest + '^{}') == sha:
        return
    prefix, patch = latest[1:].rsplit('.', 1)
    version = f'{prefix}.{int(patch) + 1}'
    title = f'fix: release security update {version}'
    # GitLab may merge, squash or fast-forward the release MR. Check its first-parent changes, not its title.
    release = Path('VERSION').read_text().strip() == version
    updated = sha + '^' if release else sha
    files = set(git('diff', '--name-only', latest, updated).splitlines())
    if 'composer.lock' not in files or files - {'composer.lock', 'THIRD-PARTY-LICENSES.md'}:
        return
    with tempfile.TemporaryDirectory() as folder:
        before = advisories(latest, Path(folder))
        if not before:
            return
        if advisories(updated, Path(folder)):
            raise RuntimeError('Production advisories remain; no release created')
    git('config', 'user.name', 'Patrimeter Releases')
    git('config', 'user.email', 'patrimeter-release@users.noreply.gitlab.com')
    if release:
        if set(git('diff', '--name-only', updated, sha).splitlines()) != {'VERSION', 'CHANGELOG.md', 'CHANGELOG.en.md'}:
            raise RuntimeError('Unexpected release changes')
        git('tag', '-a', 'v' + version, '-m', 'Patrimeter ' + version, sha)
        git('push', remote, 'refs/tags/v' + version)
        return
    if Path('VERSION').read_text().strip() != latest[1:]:
        raise RuntimeError('Stable does not match the last release')
    packages = ', '.join(sorted({package for package, _ in before}))
    date = datetime.datetime.now(datetime.timezone.utc).date().isoformat()
    for lang, heading, note in [('', 'Sécurité', 'Correctifs de dépendances de production'),
                                ('.en', 'Security', 'Production dependency fixes')]:
        path = Path(f'CHANGELOG{lang}.md')
        text = path.read_text()
        index = text.index('\n## [')
        entry = f'\n## [{version}] - {date}\n\n### {heading}\n\n- {note} : {packages}.\n'
        path.write_text(text[:index] + entry + text[index:] +
                        f'\n[{version}]: {os.environ["CI_PROJECT_URL"]}/-/compare/{latest}...v{version}\n')
    Path('VERSION').write_text(version + '\n')
    git('add', '--', 'VERSION', 'CHANGELOG.md', 'CHANGELOG.en.md')
    git('commit', '-m', title)
    # Push options create the MR with the existing write_repository token; no API token or stable push right.
    git('push', '-o', 'merge_request.create', '-o', 'merge_request.target=stable',
        '-o', 'merge_request.auto_merge', '-o', 'merge_request.remove_source_branch',
        '-o', f'merge_request.title={title}', remote, f'HEAD:refs/heads/release/security-v{version}')


if __name__ == '__main__':
    main()
