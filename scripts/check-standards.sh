#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend/src"

if rg -n "\ba1-" "$FRONTEND_DIR" >/dev/null 2>&1; then
  echo "[check-standards] FAIL: найден legacy префикс a1-* в frontend/src"
  rg -n "\ba1-" "$FRONTEND_DIR" | sed -n '1,120p'
  exit 1
fi

echo "[check-standards] PASS: legacy префиксы не найдены"

AUTH_PAGE="$ROOT_DIR/frontend/src/app/(site)/auth/page.tsx"

if ! rg -n "className=.*\b(header|menu|burger)(-|__)|className=.*\bauth-" "$AUTH_PAGE" >/dev/null 2>&1; then
  echo "[check-standards] FAIL: не найден ожидаемый namespace shared/feature в auth/page.tsx"
  exit 1
fi

echo "[check-standards] PASS: namespace shared/feature обнаружен"

if rg -n "^\.(auth|checkout|profile)-" "$ROOT_DIR/frontend/src/app/globals.css" >/dev/null 2>&1; then
  echo "[check-standards] FAIL: найден feature namespace в globals.css (auth-/checkout-/profile-)"
  rg -n "^\.(auth|checkout|profile)-" "$ROOT_DIR/frontend/src/app/globals.css" | sed -n '1,120p'
  exit 1
fi

echo "[check-standards] PASS: feature namespace отсутствует в globals.css"

ARBITRARY_REGEX='(text|leading|tracking|p[trblxy]?|m[trblxy]?|gap|space-[xy])-\[[^]]+\]'
ARBITRARY_HITS="$(rg -n "$ARBITRARY_REGEX" "$FRONTEND_DIR" --glob '*.tsx' --glob '*.ts' --glob '*.jsx' --glob '*.js' --glob '*.css' || true)"

if [[ -n "$ARBITRARY_HITS" ]]; then
  FILTERED_HITS="$(printf '%s\n' "$ARBITRARY_HITS" | rg -v "tw-arbitrary-ok" || true)"
  if [[ -n "$FILTERED_HITS" ]]; then
    echo "[check-standards] FAIL: найдено запрещенное arbitrary-значение Tailwind для типографики/spacing"
    printf '%s\n' "$FILTERED_HITS" | sed -n '1,120p'
    echo "[check-standards] hint: если отклонение согласовано, добавьте комментарий с меткой 'tw-arbitrary-ok: <причина>'"
    exit 1
  fi
fi

echo "[check-standards] PASS: arbitrary-значения типографики/spacing не найдены"

RAW_PALETTE_HITS="$(
  python3 - "$FRONTEND_DIR" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
palette_re = re.compile(r"\b(?:text|bg|border|divide|placeholder|accent|outline)-(?:zinc|gray|slate|neutral)-\d+(?:/\d+)?\b")
allowed_variants = (":", "!")
hits = []

for path in sorted(root.rglob("*.tsx")):
    if path.name.endswith(".test.tsx"):
        continue
    source = path.read_text(encoding="utf-8")
    for match in palette_re.finditer(source):
        token = match.group(0)
        prefix = source[max(0, match.start() - 32):match.start()]
        if "/" in token or prefix.endswith(allowed_variants) or "slate-" in token:
            continue
        line = source.count("\n", 0, match.start()) + 1
        rel = path.relative_to(root.parent.parent)
        hits.append(f"{rel}:{line}: {token}")

print("\n".join(hits))
PY
)"

if [[ -n "$RAW_PALETTE_HITS" ]]; then
  echo "[check-standards] FAIL: raw palette role без semantic-класса"
  printf '%s\n' "$RAW_PALETTE_HITS" | sed -n '1,120p'
  echo "[check-standards] hint: используйте ui-* role или оставьте только явно контекстный/opacity-вариант"
  exit 1
fi

echo "[check-standards] PASS: повторяемые raw palette roles переведены в semantic-классы"

FEATURE_VISUAL_HITS="$(
  python3 - "$FRONTEND_DIR" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
selector_re = re.compile(r"\.(home|auth|checkout|profile)-[^{,]*__button\b")
visual_prop_re = re.compile(
    r"^\s*(color|background(?:-color|-image)?|border(?:-color|-radius|-width|-style)?|"
    r"font(?:-size|-weight|-family|-style)?|line-height|letter-spacing|"
    r"box-shadow|text-shadow|outline(?:-color|-width|-style)?)\s*:",
    re.MULTILINE,
)

for path in sorted(root.rglob("*.css")):
    text = path.read_text(encoding="utf-8")
    for match in re.finditer(r"([^{}]+)\{([^{}]*)\}", text, re.MULTILINE):
        selector = match.group(1).strip()
        body = match.group(2)
        if not selector_re.search(selector):
            continue
        if "feature-visual-ok:" in body:
            continue
        if not visual_prop_re.search(body):
            continue
        line = text.count("\n", 0, match.start(1)) + 1
        rel = path.relative_to(root.parent.parent)
        print(f"{rel}:{line}: {selector}")
PY
)"

if [[ -n "$FEATURE_VISUAL_HITS" ]]; then
  echo "[check-standards] FAIL: feature __button selector задает visual style shared-кнопки"
  printf '%s\n' "$FEATURE_VISUAL_HITS" | sed -n '1,120p'
  echo "[check-standards] hint: вынесите вид кнопки в Button variant или добавьте согласованную метку 'feature-visual-ok: <причина>'"
  exit 1
fi

echo "[check-standards] PASS: feature __button selectors не задают visual variant"

node "$ROOT_DIR/scripts/check-image-pipeline.mjs"

echo "[check-standards] DONE"
