#!/usr/bin/env bash

# Versioned, side-effect-free release guard helpers. This file is sourced by the
# release command and by local-tests/check-release-safety.sh.

baratables_is_canonical_remote_url() {
	case "${1:-}" in
		git@github.com:trinadin/baratables.git | \
		git@github.com:trinadin/baratables | \
		https://github.com/trinadin/baratables.git | \
		https://github.com/trinadin/baratables | \
		ssh://git@github.com/trinadin/baratables.git | \
		ssh://git@github.com/trinadin/baratables)
			return 0
			;;
		*)
			return 1
			;;
	esac
}

baratables_is_canonical_svn_url() {
	case "${1:-}" in
		https://plugins.svn.wordpress.org/baratables | \
		https://plugins.svn.wordpress.org/baratables/)
			return 0
			;;
		*)
			return 1
			;;
	esac
}

baratables_assert_canonical_git_remotes() {
	local repository="$1"
	local required_remote="$2"
	local canonical_repo="$3"
	local context="${4:-}"
	local remote_count=0
	local remote_name remote_url

	while IFS= read -r remote_name; do
		[[ -n "$remote_name" ]] || continue
		remote_count=$((remote_count + 1))
		while IFS= read -r remote_url; do
			if ! baratables_is_canonical_remote_url "$remote_url"; then
				echo "Foreign Git fetch URL configured${context}: $remote_name -> $remote_url" >&2
				echo "Only $canonical_repo is permitted." >&2
				return 1
			fi
		done < <(git -C "$repository" remote get-url --all "$remote_name")
		while IFS= read -r remote_url; do
			if ! baratables_is_canonical_remote_url "$remote_url"; then
				echo "Foreign Git push URL configured${context}: $remote_name -> $remote_url" >&2
				echo "Only $canonical_repo is permitted." >&2
				return 1
			fi
		done < <(git -C "$repository" remote get-url --push --all "$remote_name")
	done < <(git -C "$repository" remote)

	if [[ "$remote_count" -eq 0 ]] || ! git -C "$repository" remote get-url "$required_remote" >/dev/null 2>&1; then
		echo "The canonical $required_remote remote is required." >&2
		return 1
	fi
}

baratables_remote_tag_sha() {
	local repository="$1"
	local remote="$2"
	local tag="$3"
	local sha

	sha="$(git -C "$repository" ls-remote "$remote" "refs/tags/$tag^{}" | awk 'NR == 1 { print $1 }')"
	if [[ -z "$sha" ]]; then
		sha="$(git -C "$repository" ls-remote "$remote" "refs/tags/$tag" | awk 'NR == 1 { print $1 }')"
	fi
	printf '%s' "$sha"
}

baratables_version_is_valid() {
	[[ "${1:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

baratables_version_is_greater() {
	local candidate="${1:-}"
	local published="${2:-}"
	local candidate_major candidate_minor candidate_patch
	local published_major published_minor published_patch

	baratables_version_is_valid "$candidate" || return 1
	baratables_version_is_valid "$published" || return 1

	IFS=. read -r candidate_major candidate_minor candidate_patch <<<"$candidate"
	IFS=. read -r published_major published_minor published_patch <<<"$published"

	if (( 10#$candidate_major != 10#$published_major )); then
		(( 10#$candidate_major > 10#$published_major ))
		return
	fi
	if (( 10#$candidate_minor != 10#$published_minor )); then
		(( 10#$candidate_minor > 10#$published_minor ))
		return
	fi
	(( 10#$candidate_patch > 10#$published_patch ))
}

baratables_ref_contains_tag() {
	local repository="$1"
	local ref="$2"
	local tag="$3"

	git -C "$repository" merge-base --is-ancestor "$tag" "$ref"
}

baratables_assert_clean_git_tree() {
	local repository="$1"
	local status

	status="$(git -C "$repository" status --short --untracked-files=all)"
	if [[ -n "$status" ]]; then
		echo "Git working tree must be clean before preparing or publishing a release:" >&2
		printf '%s\n' "$status" >&2
		return 1
	fi
}

baratables_readme_section_entry_body() {
	local section="$1"
	local version="$2"
	local readme="$3"

	awk -v section="$section" -v ver="= $version =" '
		$0 == section { in_section = 1; next }
		in_section && /^== .* ==$/ { exit }
		in_section && $0 == ver { grab = 1; next }
		grab && /^= .* =$/ { exit }
		grab { print }
	' "$readme"
}

baratables_readme_section_entry_count() {
	local section="$1"
	local version="$2"
	local readme="$3"

	awk -v section="$section" -v ver="= $version =" '
		$0 == section { in_section = 1; next }
		in_section && /^== .* ==$/ { exit }
		in_section && $0 == ver { count++ }
		END { print count + 0 }
	' "$readme"
}

baratables_readme_section_versions() {
	local section="$1"
	local readme="$2"

	awk -v section="$section" '
		$0 == section { in_section = 1; next }
		in_section && /^== .* ==$/ { exit }
		in_section && /^= [0-9]+\.[0-9]+\.[0-9]+ =$/ { print $2 }
	' "$readme"
}

baratables_changelog_entry_body() {
	local version="$1"
	local readme="$2"

	baratables_readme_section_entry_body '== Changelog ==' "$version" "$readme"
}

baratables_upgrade_notice_entry_body() {
	local version="$1"
	local readme="$2"

	baratables_readme_section_entry_body '== Upgrade Notice ==' "$version" "$readme"
}

baratables_security_disclosures() {
	local version="$1"
	local readme="$2"
	local body

	body="$(baratables_changelog_entry_body "$version" "$readme")"
	awk '
		/^(New|Improvements|Fixes|Security):[[:space:]]*$/ {
			if ($0 == "Security:") {
				in_security = 1
				print
				next
			}
			if (in_security) { exit }
		}
		in_security { print; next }
		/^\*[[:space:]]*Security:/ { print }
	' <<<"$body"
}

baratables_build_committed_package() {
	local repository="$1"
	local ref="$2"
	local destination="$3"

	if [[ -n "$(find "$destination" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
		echo "Package destination is not empty: $destination" >&2
		return 1
	fi

	git -C "$repository" archive --format=tar "$ref" | tar -xf - -C "$destination"
}
