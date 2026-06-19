#!/usr/bin/env bash
# SessionStart hook: ensure the last30days skill is installed in this
# ephemeral container. Clones the public skill repo into the personal
# skills dir so /last30days is available in every session.
set -euo pipefail

DEST="${HOME}/.claude/skills/last30days"
REPO="https://github.com/mvanhorn/last30days-skill"

# Already installed this session? Nothing to do.
if [ -f "${DEST}/SKILL.md" ]; then
  exit 0
fi

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

if git clone --depth 1 "${REPO}" "${TMP}/repo" >/dev/null 2>&1 \
   && [ -d "${TMP}/repo/skills/last30days" ]; then
  mkdir -p "${HOME}/.claude/skills"
  rm -rf "${DEST}"
  cp -R "${TMP}/repo/skills/last30days" "${DEST}"
  # Drop heavy, non-runtime demo media to keep the container lean.
  rm -rf "${DEST}/assets"
  echo "[last30days] skill installed into ${DEST}"
else
  echo "[last30days] WARN: could not install skill (offline?)" >&2
fi

exit 0
