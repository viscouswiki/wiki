#!/usr/bin/env bash
#
# Clone the MediaWiki skins used by Viscous Wiki into ./skins (gitignored —
# they are third-party and pinned to their upstream repos).
#
#   Citizen : default skin, themed Viscous-green (MediaWiki:Common.css)
#   Fluent  : alternative skin (switch in Preferences → Appearance)
#
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p skins

if [ ! -d skins/Citizen ]; then
  echo "[skins] cloning Citizen..."
  git clone --depth 1 https://github.com/StarCitizenTools/mediawiki-skins-Citizen.git skins/Citizen
fi
if [ ! -d skins/Fluent ]; then
  echo "[skins] cloning Fluent..."
  git clone --depth 1 https://github.com/immewnity/mediawiki-fluent.git skins/Fluent
fi

echo "[skins] done. After (re)starting, run:"
echo "  docker compose exec mediawiki php maintenance/run.php update --quick"
