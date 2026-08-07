#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT_DIR="$(cd "$SOURCE_DIR/.." && pwd)"
SVN_DIR="$ROOT_DIR/baratables-svn"
GIT_REMOTE="origin"
GIT_BRANCH="main"
CANONICAL_REPO="trinadin/baratables"
SVN_CANONICAL_URL="https://plugins.svn.wordpress.org/baratables"

# shellcheck source=release-guards.sh
source "$SOURCE_DIR/tools/release-guards.sh"

usage() {
	cat <<'USAGE'
Usage:
  ./bin/release-svn.sh <version> [--skip-tests] [--include-assets]
  ./bin/release-svn.sh <version> --commit --push-github \
    --confirm-release=<version> --confirm-head=<full-sha> [--include-assets]

Optional edits to published Changelog or Upgrade Notice history must be named explicitly:
  --allow-changelog-edit=<published-version>

Without --commit, the script prepares a reproducible SVN trunk and tag from the
clean, committed Git HEAD, then stops for review. A real release requires GitHub
publication, the full suite, and confirmation of both the version and exact SHA.
USAGE
}

VERSION=""
COMMIT=0
PUSH_GITHUB=0
SKIP_TESTS=0
INCLUDE_ASSETS=0
CONFIRM_RELEASE=""
CONFIRM_HEAD=""
ALLOWED_CHANGELOG_EDITS=()

for arg in "$@"; do
	case "$arg" in
		--commit)
			COMMIT=1
			;;
		--push-github|--github)
			PUSH_GITHUB=1
			;;
		--skip-tests)
			SKIP_TESTS=1
			;;
		--include-assets)
			INCLUDE_ASSETS=1
			;;
		--confirm-release=*)
			CONFIRM_RELEASE="${arg#*=}"
			;;
		--confirm-head=*)
			CONFIRM_HEAD="${arg#*=}"
			;;
		--allow-changelog-edit=*)
			ALLOWED_CHANGELOG_EDITS+=("${arg#*=}")
			;;
		-h|--help)
			usage
			exit 0
			;;
		-*)
			echo "Unknown option: $arg" >&2
			usage >&2
			exit 1
			;;
		*)
			if [[ -n "$VERSION" ]]; then
				echo "Only one version argument is allowed." >&2
				exit 1
			fi
			VERSION="$arg"
			;;
	esac
done

if [[ -z "$VERSION" ]]; then
	usage >&2
	exit 1
fi

if ! baratables_version_is_valid "$VERSION"; then
	echo "Version must use numeric x.y.z form: $VERSION" >&2
	exit 1
fi

if [[ "${#ALLOWED_CHANGELOG_EDITS[@]}" -gt 0 ]]; then
	for allowed_version in "${ALLOWED_CHANGELOG_EDITS[@]}"; do
		if ! baratables_version_is_valid "$allowed_version"; then
			echo "Invalid --allow-changelog-edit version: $allowed_version" >&2
			exit 1
		fi
	done
fi

if [[ "$PUSH_GITHUB" -eq 1 && "$COMMIT" -eq 0 ]]; then
	echo "--push-github requires --commit." >&2
	exit 1
fi

if [[ "$COMMIT" -eq 1 && "$PUSH_GITHUB" -eq 0 ]]; then
	echo "A real release must publish the reviewed Git commit and tag before WordPress.org." >&2
	echo "Use --commit --push-github after Nathan approves the exact version and SHA." >&2
	exit 1
fi

if [[ "$COMMIT" -eq 1 && "$SKIP_TESTS" -eq 1 ]]; then
	echo "--skip-tests is preparation-only. A real release always runs the full suite and Plugin Check." >&2
	exit 1
fi

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		exit 1
	fi
}

for command_name in git svn rsync rg tar diff awk; do
	require_command "$command_name"
done

if [[ ! -d "$SOURCE_DIR" || ! -f "$SOURCE_DIR/baratables.php" ]]; then
	echo "Plugin source directory is missing: $SOURCE_DIR" >&2
	exit 1
fi

if [[ ! -d "$SVN_DIR/.svn" ]]; then
	echo "SVN working copy is missing: $SVN_DIR" >&2
	exit 1
fi

assert_svn_identity() {
	local relative_path expected_url actual_url actual_depth

	for relative_path in '' trunk tags assets; do
		expected_url="$SVN_CANONICAL_URL"
		if [[ -n "$relative_path" ]]; then
			expected_url="$expected_url/$relative_path"
		fi
		actual_url="$(svn info --show-item url "$SVN_DIR${relative_path:+/$relative_path}" 2>/dev/null || true)"
		if [[ -z "$relative_path" ]] && ! baratables_is_canonical_svn_url "$actual_url"; then
			echo "Unexpected WordPress.org SVN URL for ${relative_path:-root}: $actual_url" >&2
			echo "Expected: $expected_url" >&2
			exit 1
		fi
		if [[ "$actual_url" != "$expected_url" ]]; then
			echo "Unexpected WordPress.org SVN URL for ${relative_path:-root}: $actual_url" >&2
			echo "Expected: $expected_url" >&2
			exit 1
		fi
		actual_depth="$(svn info --show-item depth "$SVN_DIR${relative_path:+/$relative_path}" 2>/dev/null || true)"
		if [[ "$actual_depth" != "infinity" ]]; then
			echo "SVN ${relative_path:-root} is sparse (depth $actual_depth); release requires a complete working copy." >&2
			exit 1
		fi
	done
}

assert_svn_identity

if [[ "$(git -C "$SOURCE_DIR" rev-parse --is-inside-work-tree 2>/dev/null || true)" != "true" ]]; then
	echo "Release source must be the Git working copy at $SOURCE_DIR." >&2
	echo "A copied directory or source tree without Git provenance cannot be released." >&2
	exit 1
fi

baratables_assert_clean_git_tree "$SOURCE_DIR"

baratables_assert_canonical_git_remotes "$SOURCE_DIR" "$GIT_REMOTE" "$CANONICAL_REPO" " in $SOURCE_DIR"

current_branch="$(git -C "$SOURCE_DIR" branch --show-current)"
if [[ "$current_branch" != "$GIT_BRANCH" ]]; then
	echo "Release preparation must run from $GIT_BRANCH, not $current_branch." >&2
	exit 1
fi

echo "== Refreshing canonical Git and WordPress.org state =="
git -C "$SOURCE_DIR" fetch "$GIT_REMOTE" "$GIT_BRANCH" --tags
svn update "$SVN_DIR"

RELEASE_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/baratables-release.XXXXXX")"
trap 'rm -rf "$RELEASE_TMP_DIR"' EXIT
SVN_BASE_README="$RELEASE_TMP_DIR/published-readme.txt"
# svn cat reads the pristine working-copy BASE, not a locally staged trunk from
# an earlier preparation of this same version.
svn cat "$SVN_DIR/trunk/readme.txt" >"$SVN_BASE_README"

if ! git -C "$SOURCE_DIR" show-ref --verify --quiet "refs/remotes/$GIT_REMOTE/$GIT_BRANCH"; then
	echo "Missing fetched branch: $GIT_REMOTE/$GIT_BRANCH" >&2
	exit 1
fi

if ! git -C "$SOURCE_DIR" merge-base --is-ancestor "$GIT_REMOTE/$GIT_BRANCH" HEAD; then
	echo "Local $GIT_BRANCH does not contain the current $GIT_REMOTE/$GIT_BRANCH." >&2
	echo "Pull/rebase before preparing a release." >&2
	exit 1
fi

assert_allowed_svn_status() {
	local phase="$1"
	local status_line status_path status_columns
	local status_file="$RELEASE_TMP_DIR/svn-status-$phase.txt"

	if ! svn status "$SVN_DIR" >"$status_file"; then
		echo "Could not inspect the SVN working copy during $phase." >&2
		return 1
	fi
	while IFS= read -r status_line; do
		[[ -n "$status_line" ]] || continue
		status_columns="${status_line:0:7}"
		if [[ "$status_columns" == *C* || "$status_columns" == *S* || "${status_line:0:1}" == "~" ]]; then
			echo "Conflicted, switched, or obstructed SVN state found during $phase:" >&2
			echo "$status_line" >&2
			return 1
		fi
		status_path="${status_line:8}"
		case "$status_path" in
			"$SVN_DIR/trunk" | "$SVN_DIR/trunk/"* | "$SVN_DIR/tags/$VERSION" | "$SVN_DIR/tags/$VERSION/"*)
				;;
			"$SVN_DIR/assets" | "$SVN_DIR/assets/"*)
				if [[ "$INCLUDE_ASSETS" -ne 1 ]]; then
					echo "SVN assets have changes during $phase, but --include-assets was not approved:" >&2
					echo "$status_line" >&2
					return 1
				fi
				;;
			*)
				echo "Unrelated SVN change found during $phase:" >&2
				echo "$status_line" >&2
				return 1
				;;
		esac
	done <"$status_file"
}

assert_allowed_svn_status "preflight"

PUBLISHED_VERSION="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$SVN_BASE_README")"
if ! baratables_version_is_valid "$PUBLISHED_VERSION"; then
	echo "Cannot determine the currently published stable version from SVN trunk: $PUBLISHED_VERSION" >&2
	exit 1
fi

SVN_PUBLISHED_TAG_URL="$SVN_CANONICAL_URL/tags/$PUBLISHED_VERSION"
SVN_PUBLISHED_TAG_README="$RELEASE_TMP_DIR/published-tag-readme.txt"
if ! svn cat "$SVN_PUBLISHED_TAG_URL/readme.txt" >"$SVN_PUBLISHED_TAG_README"; then
	echo "Published stable tag is missing its readme: $SVN_PUBLISHED_TAG_URL/readme.txt" >&2
	exit 1
fi

if ! published_trunk_drift="$(svn diff --summarize "$SVN_CANONICAL_URL/trunk" "$SVN_PUBLISHED_TAG_URL")"; then
	echo "Could not compare published SVN trunk with stable tag $PUBLISHED_VERSION." >&2
	exit 1
fi
if [[ -n "$published_trunk_drift" ]]; then
	echo "Published SVN trunk and stable tag $PUBLISHED_VERSION have drifted apart:" >&2
	printf '%s\n' "$published_trunk_drift" >&2
	echo "Resolve and review this provenance mismatch before preparing another release." >&2
	exit 1
fi

if ! baratables_version_is_greater "$VERSION" "$PUBLISHED_VERSION"; then
	echo "Release $VERSION must be newer than the published stable version $PUBLISHED_VERSION." >&2
	exit 1
fi

PUBLISHED_GIT_TAG="v$PUBLISHED_VERSION"
if ! git -C "$SOURCE_DIR" rev-parse "$PUBLISHED_GIT_TAG^{commit}" >/dev/null 2>&1; then
	echo "Git is missing the tag for the published WordPress.org release: $PUBLISHED_GIT_TAG" >&2
	exit 1
fi

if ! baratables_ref_contains_tag "$SOURCE_DIR" HEAD "$PUBLISHED_GIT_TAG"; then
	echo "HEAD does not descend from the published WordPress.org release $PUBLISHED_GIT_TAG." >&2
	echo "This is the exact stale-codebase failure that corrupted the withdrawn 1.2.0 attempt." >&2
	exit 1
fi

remote_published_sha="$(baratables_remote_tag_sha "$SOURCE_DIR" "$GIT_REMOTE" "$PUBLISHED_GIT_TAG")"
local_published_sha="$(git -C "$SOURCE_DIR" rev-parse "$PUBLISHED_GIT_TAG^{commit}")"
if [[ -z "$remote_published_sha" || "$remote_published_sha" != "$local_published_sha" ]]; then
	echo "Published tag $PUBLISHED_GIT_TAG is missing from canonical GitHub or points elsewhere." >&2
	exit 1
fi

PLUGIN_VERSION="$(awk -F': ' '/^[[:space:]]*\* Version:/{print $2; exit}' "$SOURCE_DIR/baratables.php")"
STABLE_TAG="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$SOURCE_DIR/readme.txt")"

if [[ "$PLUGIN_VERSION" != "$VERSION" ]]; then
	echo "baratables.php version is $PLUGIN_VERSION, expected $VERSION." >&2
	exit 1
fi

if [[ "$STABLE_TAG" != "$VERSION" ]]; then
	echo "readme.txt Stable tag is $STABLE_TAG, expected $VERSION." >&2
	exit 1
fi

assert_release_readme_metadata() {
	local readme="$1"
	local label="$2"
	local section count first_version body

	for section in '== Changelog ==' '== Upgrade Notice =='; do
		count="$(baratables_readme_section_entry_count "$section" "$VERSION" "$readme")"
		if [[ "$count" != "1" ]]; then
			echo "$label must contain exactly one $section entry for $VERSION; found $count." >&2
			exit 1
		fi
		first_version="$(baratables_readme_section_versions "$section" "$readme" | head -n 1)"
		if [[ "$first_version" != "$VERSION" ]]; then
			echo "$label must list $VERSION first in $section; found ${first_version:-none}." >&2
			exit 1
		fi
		body="$(baratables_readme_section_entry_body "$section" "$VERSION" "$readme")"
		if [[ -z "$body" ]]; then
			echo "$label has an empty $section entry for $VERSION." >&2
			exit 1
		fi
	done
}

assert_release_readme_metadata "$SOURCE_DIR/readme.txt" "Source readme.txt"

HEAD_SHA="$(git -C "$SOURCE_DIR" rev-parse HEAD)"

if [[ "$COMMIT" -eq 1 ]]; then
	if [[ "$CONFIRM_RELEASE" != "$VERSION" ]]; then
		echo "Publishing requires --confirm-release=$VERSION after Nathan approves that version." >&2
		exit 1
	fi
	if [[ "$CONFIRM_HEAD" != "$HEAD_SHA" ]]; then
		echo "Publishing requires --confirm-head=$HEAD_SHA after Nathan approves that exact commit." >&2
		exit 1
	fi
fi

changelog_edit_allowed() {
	local version="$1"
	local allowed
	[[ "${#ALLOWED_CHANGELOG_EDITS[@]}" -gt 0 ]] || return 1
	for allowed in "${ALLOWED_CHANGELOG_EDITS[@]}"; do
		if [[ "$allowed" == "$version" ]]; then
			return 0
		fi
	done
	return 1
}

USED_CHANGELOG_EDITS=()
mark_changelog_edit_used() {
	local version="$1"
	local used
	if [[ "${#USED_CHANGELOG_EDITS[@]}" -gt 0 ]]; then
		for used in "${USED_CHANGELOG_EDITS[@]}"; do
			[[ "$used" == "$version" ]] && return
		done
	fi
	USED_CHANGELOG_EDITS+=("$version")
}

changelog_edit_was_used() {
	local version="$1"
	local used
	[[ "${#USED_CHANGELOG_EDITS[@]}" -gt 0 ]] || return 1
	for used in "${USED_CHANGELOG_EDITS[@]}"; do
		[[ "$used" == "$version" ]] && return 0
	done
	return 1
}

# Explicit floor protects releases that reached users even if an SVN tag is ever
# removed. 1.2.0 is intentionally absent because it never reached users.
REQUIRED_CHANGELOG_VERSIONS=(1.0.0 1.0.1 1.1.0 1.1.1 1.2.1 1.2.2 1.2.3)
published_versions=("${REQUIRED_CHANGELOG_VERSIONS[@]}" "$PUBLISHED_VERSION")
while IFS= read -r published_version; do
	[[ -n "$published_version" ]] && published_versions+=("$published_version")
done < <(baratables_readme_section_versions '== Changelog ==' "$SVN_BASE_README")
while IFS= read -r published_version; do
	[[ -n "$published_version" ]] && published_versions+=("$published_version")
done < <(baratables_readme_section_versions '== Changelog ==' "$SVN_PUBLISHED_TAG_README")

SVN_TAG_LIST="$RELEASE_TMP_DIR/svn-tags.txt"
if ! svn list "$SVN_CANONICAL_URL/tags" >"$SVN_TAG_LIST"; then
	echo "Could not list the canonical WordPress.org tags." >&2
	exit 1
fi
while IFS= read -r published_tag_name; do
	published_tag_name="${published_tag_name%/}"
	if baratables_version_is_valid "$published_tag_name"; then
		published_versions+=("$published_tag_name")
	fi
done <"$SVN_TAG_LIST"

while IFS= read -r published_version; do
	[[ -n "$published_version" ]] || continue
	entry_count="$(baratables_readme_section_entry_count '== Changelog ==' "$published_version" "$SOURCE_DIR/readme.txt")"
	if [[ "$entry_count" != "1" ]]; then
		echo "readme.txt must retain exactly one changelog entry for published version $published_version; found $entry_count." >&2
		exit 1
	fi

	baseline_readme="$SVN_BASE_README"
	old_body="$(baratables_changelog_entry_body "$published_version" "$baseline_readme")"
	if [[ -z "$old_body" ]]; then
		published_tag_readme="$RELEASE_TMP_DIR/tag-$published_version-readme.txt"
		if svn cat "$SVN_CANONICAL_URL/tags/$published_version/readme.txt" >"$published_tag_readme" 2>/dev/null; then
			baseline_readme="$published_tag_readme"
			old_body="$(baratables_changelog_entry_body "$published_version" "$baseline_readme")"
		fi
	fi
	[[ -n "$old_body" ]] || continue

	new_body="$(baratables_changelog_entry_body "$published_version" "$SOURCE_DIR/readme.txt")"
	old_security="$(baratables_security_disclosures "$published_version" "$baseline_readme")"
	new_security="$(baratables_security_disclosures "$published_version" "$SOURCE_DIR/readme.txt")"
	if [[ "$old_security" != "$new_security" ]]; then
		echo "Published version $published_version changes protected Security disclosure text." >&2
		echo "The release is blocked. This guard has no command-line bypass." >&2
		exit 1
	fi

	if [[ "$old_body" != "$new_body" ]]; then
		if ! changelog_edit_allowed "$published_version"; then
			echo "Published changelog entry $published_version was edited." >&2
			echo "Review it with Nathan, then repeat preparation with:" >&2
			echo "  --allow-changelog-edit=$published_version" >&2
			exit 1
		fi
		mark_changelog_edit_used "$published_version"
		echo "APPROVED INPUT: published changelog entry $published_version is intentionally edited."
	fi

	old_upgrade_notice="$(baratables_upgrade_notice_entry_body "$published_version" "$baseline_readme")"
	if [[ -n "$old_upgrade_notice" ]]; then
		upgrade_count="$(baratables_readme_section_entry_count '== Upgrade Notice ==' "$published_version" "$SOURCE_DIR/readme.txt")"
		if [[ "$upgrade_count" != "1" ]]; then
			echo "readme.txt must retain exactly one Upgrade Notice for published version $published_version; found $upgrade_count." >&2
			exit 1
		fi
		new_upgrade_notice="$(baratables_upgrade_notice_entry_body "$published_version" "$SOURCE_DIR/readme.txt")"
		if [[ "$old_upgrade_notice" != "$new_upgrade_notice" ]]; then
			if ! changelog_edit_allowed "$published_version"; then
				echo "Published Upgrade Notice $published_version was edited." >&2
				echo "Review it with Nathan, then repeat preparation with:" >&2
				echo "  --allow-changelog-edit=$published_version" >&2
				exit 1
			fi
			mark_changelog_edit_used "$published_version"
			echo "APPROVED INPUT: published Upgrade Notice $published_version is intentionally edited."
		fi
	fi
done < <(printf '%s\n' "${published_versions[@]}" | sort -u)

if [[ "${#ALLOWED_CHANGELOG_EDITS[@]}" -gt 0 ]]; then
	for allowed_version in "${ALLOWED_CHANGELOG_EDITS[@]}"; do
		if ! changelog_edit_was_used "$allowed_version"; then
			echo "Unused --allow-changelog-edit=$allowed_version; remove stale approvals and review the actual diff." >&2
			exit 1
		fi
	done
fi

if [[ "$SKIP_TESTS" -eq 0 ]]; then
	"$ROOT_DIR/local-tests/run-tests.sh"
else
	echo "== Full suite skipped for local preparation only =="
fi

baratables_assert_clean_git_tree "$SOURCE_DIR"
if [[ "$(git -C "$SOURCE_DIR" rev-parse HEAD)" != "$HEAD_SHA" ]]; then
	echo "Git HEAD changed while the release checks were running." >&2
	exit 1
fi

PACKAGE_DIR="$RELEASE_TMP_DIR/package"
mkdir -p "$PACKAGE_DIR"
baratables_build_committed_package "$SOURCE_DIR" "$HEAD_SHA" "$PACKAGE_DIR"

if [[ ! -f "$PACKAGE_DIR/baratables.php" || ! -f "$PACKAGE_DIR/readme.txt" ]]; then
	echo "Committed Git archive is not a BaraTables release package." >&2
	exit 1
fi

PACKAGE_PLUGIN_VERSION="$(awk -F': ' '/^[[:space:]]*\* Version:/{print $2; exit}' "$PACKAGE_DIR/baratables.php")"
PACKAGE_STABLE_TAG="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$PACKAGE_DIR/readme.txt")"
if [[ "$PACKAGE_PLUGIN_VERSION" != "$VERSION" || "$PACKAGE_STABLE_TAG" != "$VERSION" ]]; then
	echo "Committed package metadata does not match release $VERSION." >&2
	echo "Plugin Version: $PACKAGE_PLUGIN_VERSION; Stable tag: $PACKAGE_STABLE_TAG" >&2
	exit 1
fi
assert_release_readme_metadata "$PACKAGE_DIR/readme.txt" "Committed package readme.txt"

reject_pattern='(^|/)(\.[^/]+|__MACOSX|CLAUDE|docs|node_modules|tests|wordpress-org|composer\.(json|lock)|package(-lock)?\.json|pnpm-lock\.yaml|yarn\.lock|phpunit\.xml(\.dist)?|local-tests)(/|$)|(^|/)(.*\.log|.*\.md|.*\.zip|.*\.tar\.gz)$'
reject_release_artifacts() {
	local release_dir="$1"
	local matches symlinks
	matches="$(find "$release_dir" -mindepth 1 -print | rg -n "$reject_pattern" || true)"
	if [[ -n "$matches" ]]; then
		echo "Rejected release artifacts found in $release_dir:" >&2
		printf '%s\n' "$matches" >&2
		return 1
	fi
	symlinks="$(find "$release_dir" -type l -print)"
	if [[ -n "$symlinks" ]]; then
		echo "Symbolic links are not permitted in the WordPress.org package:" >&2
		printf '%s\n' "$symlinks" >&2
		return 1
	fi
}

reject_release_artifacts "$PACKAGE_DIR"

rsync -a --delete "$PACKAGE_DIR/" "$SVN_DIR/trunk/"

svn status "$SVN_DIR/trunk" | awk '/^!/ {print substr($0, 9)}' | sort -r | while IFS= read -r missing_path; do
	if [[ -n "$missing_path" ]]; then
		svn rm --force "$missing_path" >/dev/null || true
	fi
done
svn add --force "$SVN_DIR/trunk" --depth infinity --quiet

TAG_DIR="$SVN_DIR/tags/$VERSION"
if [[ -e "$TAG_DIR" ]]; then
	tag_state="$(svn status "$TAG_DIR" | awk 'NR==1 {print substr($0, 1, 1)}')"
	if [[ "$tag_state" != "A" ]]; then
		echo "Tag already exists and is not a new local SVN copy: $TAG_DIR" >&2
		exit 1
	fi
	svn revert --recursive "$TAG_DIR" >/dev/null
	rm -rf "$TAG_DIR"
fi
svn copy "$SVN_DIR/trunk" "$TAG_DIR" >/dev/null

if ! package_drift="$(diff -r --brief "$PACKAGE_DIR" "$SVN_DIR/trunk")"; then
	echo "SVN trunk does not match the committed Git package:" >&2
	printf '%s\n' "${package_drift:-diff command failed without details}" >&2
	exit 1
fi

if ! tag_drift="$(diff -r --brief "$SVN_DIR/trunk" "$TAG_DIR")"; then
	echo "SVN tag does not match trunk:" >&2
	printf '%s\n' "${tag_drift:-diff command failed without details}" >&2
	exit 1
fi

reject_release_artifacts "$SVN_DIR/trunk"
reject_release_artifacts "$TAG_DIR"
assert_allowed_svn_status "staging"

svn status "$SVN_DIR"

publish_github_release() {
	local git_tag="v$VERSION"
	local local_tag_sha remote_tag_sha remote_branch_sha

	git -C "$SOURCE_DIR" fetch "$GIT_REMOTE" "$GIT_BRANCH" --tags
	baratables_assert_clean_git_tree "$SOURCE_DIR"
	if [[ "$(git -C "$SOURCE_DIR" rev-parse HEAD)" != "$HEAD_SHA" ]]; then
		echo "Git HEAD changed after approval." >&2
		exit 1
	fi
	if [[ "$(git -C "$SOURCE_DIR" branch --show-current)" != "$GIT_BRANCH" ]]; then
		echo "Git branch changed after approval." >&2
		exit 1
	fi
	if ! git -C "$SOURCE_DIR" merge-base --is-ancestor "$GIT_REMOTE/$GIT_BRANCH" HEAD; then
		echo "Canonical $GIT_BRANCH advanced or diverged after preparation." >&2
		exit 1
	fi

	if git -C "$SOURCE_DIR" rev-parse "$git_tag^{commit}" >/dev/null 2>&1; then
		local_tag_sha="$(git -C "$SOURCE_DIR" rev-parse "$git_tag^{commit}")"
		if [[ "$local_tag_sha" != "$HEAD_SHA" ]]; then
			echo "Local tag $git_tag exists at another commit." >&2
			exit 1
		fi
	else
		git -C "$SOURCE_DIR" tag -a "$git_tag" -m "BaraTables $VERSION" "$HEAD_SHA"
	fi

	remote_tag_sha="$(baratables_remote_tag_sha "$SOURCE_DIR" "$GIT_REMOTE" "$git_tag")"
	if [[ -n "$remote_tag_sha" && "$remote_tag_sha" != "$HEAD_SHA" ]]; then
		echo "Remote tag $git_tag exists at another commit." >&2
		exit 1
	fi

	# Git first: if either push fails, WordPress.org remains untouched.
	git -C "$SOURCE_DIR" push "$GIT_REMOTE" "HEAD:refs/heads/$GIT_BRANCH"
	if [[ -z "$remote_tag_sha" ]]; then
		git -C "$SOURCE_DIR" push "$GIT_REMOTE" "refs/tags/$git_tag"
	fi

	remote_branch_sha="$(git -C "$SOURCE_DIR" ls-remote "$GIT_REMOTE" "refs/heads/$GIT_BRANCH" | awk '{print $1}')"
	if [[ "$remote_branch_sha" != "$HEAD_SHA" ]]; then
		echo "GitHub branch verification failed for $GIT_BRANCH; expected $HEAD_SHA, found ${remote_branch_sha:-nothing}." >&2
		exit 1
	fi

	remote_tag_sha="$(baratables_remote_tag_sha "$SOURCE_DIR" "$GIT_REMOTE" "$git_tag")"
	if [[ "$remote_tag_sha" != "$HEAD_SHA" ]]; then
		echo "GitHub tag verification failed for $git_tag." >&2
		exit 1
	fi
}

verify_published_svn() {
	local published_trunk="$RELEASE_TMP_DIR/remote-trunk"
	local published_tag="$RELEASE_TMP_DIR/remote-tag"
	local remote_drift remote_plugin_version remote_stable_tag

	svn export --quiet "$SVN_CANONICAL_URL/trunk" "$published_trunk"
	svn export --quiet "$SVN_CANONICAL_URL/tags/$VERSION" "$published_tag"

	if ! remote_drift="$(diff -r --brief "$PACKAGE_DIR" "$published_trunk")"; then
		echo "Published WordPress.org trunk does not match the approved Git package:" >&2
		printf '%s\n' "${remote_drift:-diff command failed without details}" >&2
		exit 1
	fi
	if ! remote_drift="$(diff -r --brief "$PACKAGE_DIR" "$published_tag")"; then
		echo "Published WordPress.org tag $VERSION does not match the approved Git package:" >&2
		printf '%s\n' "${remote_drift:-diff command failed without details}" >&2
		exit 1
	fi

	remote_plugin_version="$(awk -F': ' '/^[[:space:]]*\* Version:/{print $2; exit}' "$published_tag/baratables.php")"
	remote_stable_tag="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$published_trunk/readme.txt")"
	if [[ "$remote_plugin_version" != "$VERSION" || "$remote_stable_tag" != "$VERSION" ]]; then
		echo "Published WordPress.org metadata does not identify release $VERSION." >&2
		echo "Plugin Version: $remote_plugin_version; Stable tag: $remote_stable_tag" >&2
		exit 1
	fi
	assert_release_readme_metadata "$published_tag/readme.txt" "Published tag readme.txt"
}

if [[ "$COMMIT" -eq 1 ]]; then
	assert_svn_identity
	publish_github_release
	assert_svn_identity
	assert_allowed_svn_status "publication"
	commit_paths=("$SVN_DIR/trunk" "$TAG_DIR")
	if [[ "$INCLUDE_ASSETS" -eq 1 ]]; then
		commit_paths+=("$SVN_DIR/assets")
	fi
	svn commit "${commit_paths[@]}" -m "Release $VERSION from Git $HEAD_SHA"
	svn update "$SVN_DIR"
	if [[ -n "$(svn status "$SVN_DIR")" ]]; then
		echo "SVN working copy is not clean after publication." >&2
		svn status "$SVN_DIR" >&2
		exit 1
	fi
	verify_published_svn
	cat <<EOF

Released BaraTables $VERSION and verified canonical GitHub and WordPress.org SVN.
Git source: $HEAD_SHA
GitHub tag: v$VERSION
WordPress.org tag: tags/$VERSION

When WordPress.org finishes rebuilding the public ZIP, verify the exact user download with:
  ./bin/verify-release.sh $VERSION --confirm-head=$HEAD_SHA
EOF
else
	cat <<EOF

Prepared BaraTables $VERSION from committed Git source.

Published baseline: $PUBLISHED_VERSION
Reviewed Git SHA:  $HEAD_SHA

Review:
  svn status "$SVN_DIR"
  svn diff "$SVN_DIR/trunk"
  diff -r --brief "$SVN_DIR/trunk" "$TAG_DIR"

STOP HERE. Nathan must explicitly approve version $VERSION and Git SHA $HEAD_SHA.
After that approval, publish with:
  ./bin/release-svn.sh $VERSION --commit --push-github --confirm-release=$VERSION --confirm-head=$HEAD_SHA
EOF
	if [[ "$INCLUDE_ASSETS" -eq 1 ]]; then
		echo "  Repeat --include-assets because promotional SVN assets are part of this approval."
	fi
	if [[ "${#ALLOWED_CHANGELOG_EDITS[@]}" -gt 0 ]]; then
		echo "  Repeat each --allow-changelog-edit=<version> flag because published history changed."
	fi
fi
