#!/usr/bin/env bash
#
# sync-changelog.sh — Convert CHANGELOG.md to WordPress readme.txt format.
#
# Reads CHANGELOG.md, converts Keep-a-Changelog format to wp.org format,
# and replaces the == Changelog == section in readme.txt.
#
# Version headings use the standard format: ## [X.Y.Z] - YYYY-MM-DD
#
# Usage: bash sync-changelog.sh   (run from the plugin root directory)
#

set -euo pipefail

CHANGELOG="CHANGELOG.md"
README="readme.txt"

if [[ ! -f "$CHANGELOG" ]]; then
	echo "Error: $CHANGELOG not found in $(pwd)" >&2
	exit 1
fi

if [[ ! -f "$README" ]]; then
	echo "Error: $README not found in $(pwd)" >&2
	exit 1
fi

# --- Step 1: Convert CHANGELOG.md to wp.org format ---

converted=""
version_count=0
in_header=true
in_unreleased=false

while IFS= read -r line || [[ -n "$line" ]]; do
	# Strip carriage return (CRLF files).
	line="${line%$'\r'}"

	# Skip header lines (title, description, format reference, links).
	if $in_header; then
		if [[ "$line" =~ ^##\  ]]; then
			in_header=false
		else
			continue
		fi
	fi

	# Stop at footer sections (--- separator, ## Version Numbering, ## Links).
	if [[ "$line" == "---" ]] || [[ "$line" =~ ^##\ Version ]] || [[ "$line" =~ ^##\ Links ]]; then
		break
	fi

	# The [Unreleased] section never reaches readme.txt: skip its heading and
	# everything under it until the next version heading.
	if [[ "$line" =~ ^##\ \[Unreleased\] ]]; then
		in_unreleased=true
		continue
	fi
	if $in_unreleased; then
		if [[ "$line" =~ ^##\ \[ ]]; then
			in_unreleased=false
		else
			continue
		fi
	fi

	# Version heading — standard format: ## [X.Y.Z] - YYYY-MM-DD (also handles date-based versions)
	if [[ "$line" =~ ^##\ \[([0-9]+\.[0-9.]+[a-z-]*)\]\ -\ [0-9]{4}-[0-9]{2}-[0-9]{2} ]]; then
		version="${BASH_REMATCH[1]}"
		if [[ "$version_count" -gt 0 ]]; then
			converted+=$'\n'
		fi
		converted+="= ${version} ="$'\n'
		converted+=$'\n'
		version_count=$((version_count + 1))
		continue
	fi

	# Category headings: ### Added, ### Fixed, etc. → drop entirely.
	if [[ "$line" =~ ^###\  ]]; then
		continue
	fi

	# Sub-headings: #### Something → drop entirely.
	if [[ "$line" =~ ^####\  ]]; then
		continue
	fi

	# Bullet items: - Item text  →  * Item text
	if [[ "$line" =~ ^-\  ]]; then
		converted+="* ${line#- }"$'\n'
		continue
	fi

	# Continuation lines (indented bullets): spaces + - Item  →  * Item
	if [[ "$line" =~ ^[[:space:]]+-\  ]]; then
		trimmed="${line#"${line%%[![:space:]]*}"}"
		converted+="* ${trimmed#- }"$'\n'
		continue
	fi

	# Blank lines — skip (we control spacing via version headings).
	if [[ -z "$line" ]]; then
		continue
	fi

	# Non-blank, non-heading, non-bullet text — pass through.
	converted+="$line"$'\n'
done < "$CHANGELOG"

# Ensure single trailing newline.
converted="$(printf '%s' "$converted" | sed -e 's/[[:space:]]*$//')"
converted+=$'\n'

# --- Step 2: Replace == Changelog == section in readme.txt ---

# Find the line number of == Changelog ==
changelog_line=$(grep -n '^== Changelog ==' "$README" | head -1 | cut -d: -f1)

if [[ -z "$changelog_line" ]]; then
	echo "Error: '== Changelog ==' section not found in $README" >&2
	exit 1
fi

# Extract content before == Changelog == (lines 1 to changelog_line-1).
head_end=$((changelog_line - 1))
before_changelog="$(head -n "$head_end" "$README")"

# Find the next == Section == heading after == Changelog == (if any).
after_changelog=""
total_lines=$(wc -l < "$README")
scan_start=$((changelog_line + 1))
if [[ "$scan_start" -le "$total_lines" ]]; then
	next_section_offset=$(tail -n +"$scan_start" "$README" | grep -n '^== .* ==' | head -1 | cut -d: -f1 || true)
	if [[ -n "$next_section_offset" ]]; then
		next_section_line=$((changelog_line + next_section_offset))
		after_changelog="$(tail -n +"$next_section_line" "$README")"
	fi
fi

# Build the new readme.txt.
{
	printf '%s\n' "$before_changelog"
	echo ""
	echo "== Changelog =="
	echo ""
	printf '%s' "$converted"
	if [[ -n "$after_changelog" ]]; then
		echo ""
		printf '%s\n' "$after_changelog"
	else
		echo ""
	fi
} > "$README"

echo "Synced $version_count versions from $CHANGELOG to $README"
