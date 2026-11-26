#!/usr/bin/env python3
"""OpenSSF Scorecard of the project (CI job "scorecard", docs/ci.md).

Downloads the Scorecard release pinned by SCORECARD_VERSION and checked against SCORECARD_SHA256, analyses this
GitLab project with a read-only token (SCORECARD_TOKEN: read_api and read_repository), and writes in scorecard/:
the JSON result, a text summary, and badge.svg, the badge that the README shows (latest artifact of main).
Without a token, the badge says "not measured" and the job succeeds: nothing is pretended.

Usage: python3 ci/scorecard.py, or python3 ci/scorecard.py --badge-from RESULT.json (badge and summary only, to try
them without network nor token).
"""

import hashlib
import json
import os
import subprocess
import sys
import tarfile
import tempfile
import urllib.request
from pathlib import Path
from xml.sax.saxutils import escape

OUTPUT = Path("scorecard")
RELEASE = "https://github.com/ossf/scorecard/releases/download/v{version}/scorecard_{version}_linux_amd64.tar.gz"


def badge(score):
    """SVG badge in the style of the other README badges: label on the left, score (or "not measured") on the right."""
    if score is None:
        value, colour = "non mesuré", "#9f9f9f"
    else:
        value = f"{score:.1f} / 10"
        colour = "#4c1" if score >= 7 else "#dfb317" if score >= 5 else "#fe7d37" if score >= 3 else "#e05d44"
    label = "OpenSSF Scorecard"
    # Width estimated from the length of the texts (Verdana 11 px, about 7 px per character).
    left, right = 10 + 7 * len(label), 10 + 7 * len(value)
    total = left + right
    title = escape(f"{label} : {value}")
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{total}" height="20" role="img" aria-label="{title}">'
        f"<title>{title}</title>"
        f'<rect width="{left}" height="20" fill="#555"/>'
        f'<rect x="{left}" width="{right}" height="20" fill="{colour}"/>'
        f'<g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11">'
        f'<text x="{left / 2}" y="14">{escape(label)}</text>'
        f'<text x="{left + right / 2}" y="14">{escape(value)}</text>'
        f"</g></svg>\n"
    )


def summary(result):
    """Text summary: overall score, then each check with its score and reason (-1: not applicable)."""
    lines = [f"OpenSSF Scorecard {result.get('scorecard', {}).get('version', '')} : {result.get('score')} / 10", ""]
    for check in sorted(result.get("checks", []), key=lambda c: c.get("name", "")):
        lines.append(f"{check.get('score', -1):>3}  {check.get('name')} : {check.get('reason')}")
    return "\n".join(lines) + "\n"


def write(result):
    OUTPUT.mkdir(exist_ok=True)
    score = result.get("score") if result else None
    (OUTPUT / "badge.svg").write_text(badge(score if isinstance(score, (int, float)) and score >= 0 else None), encoding="utf-8")
    if result:
        (OUTPUT / "scorecard.json").write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        (OUTPUT / "scorecard.txt").write_text(summary(result), encoding="utf-8")
        print(summary(result))


def download(version, digest, directory):
    archive = Path(directory) / "scorecard.tar.gz"
    with urllib.request.urlopen(RELEASE.format(version=version), timeout=120) as response:
        archive.write_bytes(response.read())
    actual = hashlib.sha256(archive.read_bytes()).hexdigest()
    if actual != digest:
        sys.exit(f"Empreinte de Scorecard inattendue : {actual} (attendue : {digest})")
    with tarfile.open(archive) as tar:
        tar.extract("scorecard", directory, filter="data")
    return Path(directory) / "scorecard"


def main():
    if len(sys.argv) == 3 and sys.argv[1] == "--badge-from":
        write(json.loads(Path(sys.argv[2]).read_text(encoding="utf-8")))
        return
    token = os.environ.get("SCORECARD_TOKEN", "")
    if token == "":
        print("SCORECARD_TOKEN absent : Scorecard non mesuré (docs/ci.md).")
        write(None)
        return
    repository = f"{os.environ['CI_SERVER_HOST']}/{os.environ['CI_PROJECT_PATH']}"
    with tempfile.TemporaryDirectory() as directory:
        binary = download(os.environ["SCORECARD_VERSION"], os.environ["SCORECARD_SHA256"], directory)
        # Run from an empty folder: some checks (Vulnerabilities, through osv-scanner) walk the current folder.
        workspace = Path(directory) / "workspace"
        workspace.mkdir()
        run = subprocess.run(
            [str(binary), f"--repo={repository}", "--format=json", "--show-details"],
            cwd=workspace,
            env={"PATH": os.environ.get("PATH", ""), "HOME": directory, "GITLAB_AUTH_TOKEN": token},
            capture_output=True,
            text=True,
            timeout=900,
            check=False,
        )
    # Scorecard exits with 1 when a check could not run, but still gives the result of the others: kept, the check in
    # error counting for "-1" (not applicable). Without any result, the badge says "not measured".
    result = None
    try:
        result = json.loads(run.stdout) if run.stdout.strip() else None
    except json.JSONDecodeError:
        result = None
    write(result)
    if run.returncode != 0:
        # The token never appears: Scorecard does not print it, and only the end of its error output is shown.
        print(run.stderr[-2000:], file=sys.stderr)
        if result is None:
            sys.exit(f"Scorecard a échoué (code {run.returncode}).")
        print("Scorecard : une vérification n'a pas pu tourner ; les autres sont gardées.", file=sys.stderr)


if __name__ == "__main__":
    main()
