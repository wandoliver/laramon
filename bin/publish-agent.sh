#!/usr/bin/env bash
#
# Publish the agent package to its read-only mirror repo.
#
# Usage: bin/publish-agent.sh v0.3.0
#
set -euo pipefail

MIRROR="${AGENT_MIRROR:-git@github.com:oliwand/laramon-agent.git}"
VERSION="${1:?Usage: bin/publish-agent.sh vX.Y.Z}"

cd "$(dirname "$0")/.."

if [[ -n "$(git status --porcelain packages/agent)" ]]; then
    echo "packages/agent has uncommitted changes — commit first." >&2
    exit 1
fi

git branch -D agent-split 2>/dev/null || true
git subtree split --prefix=packages/agent -b agent-split

git push "$MIRROR" agent-split:main
git tag -f "$VERSION" agent-split
git push "$MIRROR" "$VERSION"

git branch -D agent-split

echo
echo "Published ${VERSION}. Consumers update with:"
echo "  composer update laramon/agent"
