#!/usr/bin/env python3
"""Check that commits carry a Developer Certificate of Origin sign-off.

SPDX-License-Identifier: AGPL-3.0-only
Every commit reachable from HEAD but not from BASE, except merge commits, needs
a "Signed-off-by: Name <email>" line whose email matches the commit's author.
CI runs this on pull requests from forks; see CONTRIBUTING.md.

  python3 scripts/check-dco.py origin/development HEAD

Exits 0 when every commit is signed off, 1 when any is not, 2 when git fails.
"""

import argparse
from dataclasses import dataclass
import os
import re
import subprocess
import sys

FIELD = "\x00"
COMMIT_FORMAT = "%an%x00%ae%x00%s%x00%B"  # git writes %x00 as FIELD
SIGN_OFF = re.compile(r"^signed-off-by:[ \t]*(.*?)[ \t]*<([^<>\s]+)>[ \t]*$", re.IGNORECASE | re.MULTILINE)
FIX = (
    "Add a sign-off to each commit with `git commit -s` (or `git commit --amend -s --no-edit` for the last one).\n"
    "To sign off every commit on the branch: `git rebase --signoff <base>`, where <base> is the commit\n"
    "the branch started from, such as upstream/development; then force-push the branch.\n"
    "The sign-off email must match the commit's author email. See CONTRIBUTING.md#sign-off-your-commits-dco."
)


@dataclass(frozen=True)
class Commit:
    sha: str
    author_name: str
    author_email: str
    subject: str
    message: str


def parse_commit(sha, text):
    """A commit from `git show -s --format=COMMIT_FORMAT` output.

    The message comes last, so whatever it contains cannot shift the other fields.
    """
    name, email, subject, message = text.split(FIELD, 3)
    return Commit(sha, name, email, subject, message)


def sign_off_emails(message):
    return [match.group(2).strip().lower() for match in SIGN_OFF.finditer(message)]


def is_signed_off(commit):
    return commit.author_email.strip().lower() in sign_off_emails(commit.message)


def unsigned_commits(commits):
    return [commit for commit in commits if not is_signed_off(commit)]


def git(arguments, cwd=None):
    result = subprocess.run(["git", *arguments], capture_output=True, text=True, cwd=cwd)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or f"git {' '.join(arguments)} failed")
    return result.stdout


def read_commits(base, head, cwd=None):
    """Non-merge commits in base..head, oldest first."""
    shas = git(["rev-list", "--no-merges", "--reverse", f"{base}..{head}"], cwd).split()
    return [parse_commit(sha, git(["show", "-s", f"--format={COMMIT_FORMAT}", sha], cwd)) for sha in shas]


def escape_annotation(text):
    """Keep commit text from ending or adding a GitHub workflow command."""
    return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def describe(commit):
    return f'{commit.sha[:12]} "{commit.subject}" by {commit.author_name} <{commit.author_email}>'


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("base", help="commit the branch started from, such as the pull request's base")
    parser.add_argument("head", help="last commit to check, such as the pull request's head")
    arguments = parser.parse_args(argv)
    try:
        commits = read_commits(arguments.base, arguments.head)
    except RuntimeError as error:
        print(f"check-dco: {error}", file=sys.stderr)
        return 2
    if not commits:
        print("No commits to check.")
        return 0
    missing = unsigned_commits(commits)
    if not missing:
        print(f"Every commit is signed off ({len(commits)} checked).")
        return 0
    annotate = os.environ.get("GITHUB_ACTIONS") == "true"
    for commit in missing:
        line = f"Missing sign-off: {describe(commit)}"
        print(f"::error title=DCO sign-off::{escape_annotation(line)}" if annotate else line)
    print(f"\n{len(missing)} of {len(commits)} commits have no Signed-off-by line for their author.\n{FIX}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
