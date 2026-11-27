#!/usr/bin/env bash
#
# Leak gate: fail when a private term appears in the repository tree, in a
# built zip, or in the Git history.
#
# The term list is never committed. Supply it, one term per line
# (case-insensitive, fixed strings, lines starting with # are ignored), through:
#   LEAK_GATE_TERMS       environment variable (in CI: a repository secret), or
#   LEAK_GATE_TERMS_FILE  path to a file kept outside the repository.
# With no terms the gate fails closed.
#
# A few generic patterns need no list and are always checked: real Pinecone
# index hosts and commit-message trailers that carry tool session metadata.
#
# Output names the file and line only, never the matching text, so CI logs do
# not repeat what the gate found.
#
# Usage:
#   bash bin/leak-gate.sh                 scan the working tree
#   bash bin/leak-gate.sh path ...        scan files, directories or .zip files
#   bash bin/leak-gate.sh --history       also scan every commit (patches,
#                                         messages, author and committer)
#

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

TERMS="$WORK/terms.txt"
if [[ -n "${LEAK_GATE_TERMS:-}" ]]; then
	printf '%s\n' "$LEAK_GATE_TERMS" > "$TERMS.raw"
elif [[ -n "${LEAK_GATE_TERMS_FILE:-}" && -f "${LEAK_GATE_TERMS_FILE}" ]]; then
	cp "$LEAK_GATE_TERMS_FILE" "$TERMS.raw"
else
	echo "leak-gate: no term list (set LEAK_GATE_TERMS or LEAK_GATE_TERMS_FILE). Failing closed." >&2
	exit 2
fi
tr -d '\r' < "$TERMS.raw" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | grep -v -e '^$' -e '^#' > "$TERMS" || true
if [[ ! -s "$TERMS" ]]; then
	echo "leak-gate: the term list is empty. Failing closed." >&2
	exit 2
fi

PATTERNS="$WORK/patterns.txt"
# The [i] and [y] classes stop this file from matching a fixed-string term list.
cat > "$PATTERNS" <<'EOF'
[a-z0-9-]+\.svc\.[a-z0-9-]+\.pinecone\.io
^[[:space:]]*Claude-Sess[i]on:
^[[:space:]]*Co-Authored-B[y]:
EOF

HITS=0
GREP_EXCLUDES=(--exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=build --exclude='*.zip')

report() {
	# $1 = label, stdin = grep -n output (file:line:text or line:text).
	local label="$1" line
	while IFS= read -r line; do
		echo "LEAK ($label): $(printf '%s' "$line" | cut -d: -f1,2)"
		HITS=$((HITS + 1))
	done
}

scan_dir() {
	local dir="$1" label="$2"
	report "$label" < <(grep -rInF -i -f "$TERMS" "${GREP_EXCLUDES[@]}" "$dir" || true)
	report "$label" < <(grep -rInE -i -f "$PATTERNS" "${GREP_EXCLUDES[@]}" "$dir" || true)
	# File and directory names.
	report "$label name" < <(cd "$dir" && find . \( -path ./.git -o -path ./vendor -o -path ./node_modules \) -prune -o -print | grep -nF -i -f "$TERMS" | awk -F: '{print $2 ":" $1}' || true)
}

scan_zip() {
	local zip="$1" dest
	dest="$WORK/zip-$(basename "$zip" .zip)-$RANDOM"
	mkdir -p "$dest"
	unzip -qq "$zip" -d "$dest"
	report "zip $(basename "$zip") listing" < <(unzip -Z1 "$zip" | grep -nF -i -f "$TERMS" | awk -F: '{print "listing:" $1}' || true)
	scan_dir "$dest" "zip $(basename "$zip")"
}

scan_history() {
	git -C "$ROOT" rev-parse --git-dir > /dev/null 2>&1 || { echo "leak-gate: not a Git repository, skipping history." >&2; return; }
	git -C "$ROOT" log --all -p --format=fuller > "$WORK/history.txt"
	report "history" < <(grep -nF -i -f "$TERMS" "$WORK/history.txt" | awk -F: '{print "git-log-p:" $1}' || true)
	report "history" < <(grep -nE -i -f "$PATTERNS" "$WORK/history.txt" | awk -F: '{print "git-log-p:" $1}' || true)
}

TARGETS=()
WANT_HISTORY=false
for arg in "$@"; do
	if [[ "$arg" == "--history" ]]; then
		WANT_HISTORY=true
	else
		TARGETS+=("$arg")
	fi
done
if [[ ${#TARGETS[@]} -eq 0 ]]; then
	TARGETS=("$ROOT")
fi

for target in "${TARGETS[@]}"; do
	if [[ -d "$target" ]]; then
		scan_dir "$target" "tree"
	elif [[ -f "$target" && "$target" == *.zip ]]; then
		scan_zip "$target"
	elif [[ -f "$target" ]]; then
		report "file" < <(grep -InF -i -f "$TERMS" "$target" | sed "s|^|$target:|" || true)
		report "file" < <(grep -InE -i -f "$PATTERNS" "$target" | sed "s|^|$target:|" || true)
	else
		echo "leak-gate: $target not found." >&2
		exit 2
	fi
done

if $WANT_HISTORY; then
	scan_history
fi

TERM_COUNT=$(wc -l < "$TERMS" | tr -d ' ')
if [[ "$HITS" -gt 0 ]]; then
	echo "leak-gate: $HITS hit(s) against $TERM_COUNT terms. Remove them before merging or releasing." >&2
	exit 1
fi
echo "leak-gate: clean ($TERM_COUNT terms, ${#TARGETS[@]} target(s)$($WANT_HISTORY && echo ', history'))."
