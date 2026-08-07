#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GIT_REMOTE="origin"
CANONICAL_REPO="trinadin/baratables"
SVN_CANONICAL_URL="https://plugins.svn.wordpress.org/baratables"

# shellcheck source=release-guards.sh
source "$SOURCE_DIR/tools/release-guards.sh"

usage() {
	cat <<'USAGE'
Usage:
  ./bin/verify-release.sh <version> --confirm-head=<full-sha>

Downloads the public WordPress.org ZIP and proves that it is byte-identical to
both the canonical SVN tag and the Git archive from the approved release SHA.
It does not publish or modify either remote.
USAGE
}

VERSION=""
CONFIRM_HEAD=""
for arg in "$@"; do
	case "$arg" in
		--confirm-head=*) CONFIRM_HEAD="${arg#*=}" ;;
		-h|--help) usage; exit 0 ;;
		-*) echo "Unknown option: $arg" >&2; usage >&2; exit 1 ;;
		*)
			if [[ -n "$VERSION" ]]; then
				echo "Only one version argument is allowed." >&2
				exit 1
			fi
			VERSION="$arg"
			;;
	esac
done

if ! baratables_version_is_valid "$VERSION"; then
	echo "Version must use numeric x.y.z form: $VERSION" >&2
	exit 1
fi
if [[ ! "$CONFIRM_HEAD" =~ ^[0-9a-f]{40}$ ]]; then
	echo "Verification requires --confirm-head=<full-40-character-git-sha>." >&2
	exit 1
fi

for command_name in git svn curl unzip diff awk tar shasum; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Missing required command: $command_name" >&2
		exit 1
	fi
done

if [[ "$(git -C "$SOURCE_DIR" rev-parse --is-inside-work-tree 2>/dev/null || true)" != "true" ]]; then
	echo "Verification source must be the BaraTables Git working copy." >&2
	exit 1
fi

baratables_assert_canonical_git_remotes "$SOURCE_DIR" "$GIT_REMOTE" "$CANONICAL_REPO"

GIT_TAG="v$VERSION"
if ! LOCAL_TAG_SHA="$(git -C "$SOURCE_DIR" rev-parse "$GIT_TAG^{commit}" 2>/dev/null)"; then
	echo "Missing local Git tag: $GIT_TAG" >&2
	exit 1
fi
if [[ "$LOCAL_TAG_SHA" != "$CONFIRM_HEAD" ]]; then
	echo "$GIT_TAG resolves to $LOCAL_TAG_SHA, not approved SHA $CONFIRM_HEAD." >&2
	exit 1
fi

REMOTE_TAG_SHA="$(baratables_remote_tag_sha "$SOURCE_DIR" "$GIT_REMOTE" "$GIT_TAG")"
if [[ "$REMOTE_TAG_SHA" != "$CONFIRM_HEAD" ]]; then
	echo "Canonical GitHub tag $GIT_TAG does not resolve to approved SHA $CONFIRM_HEAD." >&2
	exit 1
fi

VERIFY_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/baratables-public-verify.XXXXXX")"
trap 'rm -rf "$VERIFY_TMP_DIR"' EXIT
GIT_PACKAGE="$VERIFY_TMP_DIR/git-package"
SVN_PACKAGE="$VERIFY_TMP_DIR/svn-package"
ZIP_ROOT="$VERIFY_TMP_DIR/public-download"
ZIP_FILE="$VERIFY_TMP_DIR/baratables.$VERSION.zip"
mkdir -p "$GIT_PACKAGE" "$ZIP_ROOT"

baratables_build_committed_package "$SOURCE_DIR" "$CONFIRM_HEAD" "$GIT_PACKAGE"
svn export --quiet "$SVN_CANONICAL_URL/tags/$VERSION" "$SVN_PACKAGE"

PUBLIC_ZIP_URL="https://downloads.wordpress.org/plugin/baratables.$VERSION.zip"
if ! curl --fail --location --silent --show-error --retry 3 "$PUBLIC_ZIP_URL" -o "$ZIP_FILE"; then
	echo "The public WordPress.org ZIP is not available yet: $PUBLIC_ZIP_URL" >&2
	echo "Confirm any WordPress.org release email, wait for the rebuild, and run this check again." >&2
	exit 1
fi

unexpected_zip_paths="$(unzip -Z1 "$ZIP_FILE" | awk '$0 !~ /^baratables\// { print }')"
if [[ -n "$unexpected_zip_paths" ]]; then
	echo "Public ZIP contains paths outside its baratables/ root:" >&2
	printf '%s\n' "$unexpected_zip_paths" >&2
	exit 1
fi
unzip -q "$ZIP_FILE" -d "$ZIP_ROOT"
PUBLIC_PACKAGE="$ZIP_ROOT/baratables"

compare_packages() {
	local expected="$1"
	local actual="$2"
	local label="$3"
	local drift
	if ! drift="$(diff -r --brief "$expected" "$actual")"; then
		echo "$label is not byte-identical:" >&2
		printf '%s\n' "$drift" >&2
		exit 1
	fi
}

compare_packages "$GIT_PACKAGE" "$SVN_PACKAGE" "Canonical SVN tag $VERSION versus Git $CONFIRM_HEAD"
compare_packages "$SVN_PACKAGE" "$PUBLIC_PACKAGE" "Public WordPress.org ZIP versus canonical SVN tag $VERSION"

TRUNK_README="$VERIFY_TMP_DIR/trunk-readme.txt"
svn cat "$SVN_CANONICAL_URL/trunk/readme.txt" >"$TRUNK_README"
TRUNK_STABLE_TAG="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$TRUNK_README")"
for package_dir in "$GIT_PACKAGE" "$SVN_PACKAGE" "$PUBLIC_PACKAGE"; do
	plugin_version="$(awk -F': ' '/^[[:space:]]*\* Version:/{print $2; exit}' "$package_dir/baratables.php")"
	package_stable_tag="$(awk -F': ' '/^Stable tag:/{print $2; exit}' "$package_dir/readme.txt")"
	if [[ "$plugin_version" != "$VERSION" || "$package_stable_tag" != "$VERSION" ]]; then
		echo "Release metadata mismatch in $package_dir." >&2
		echo "Plugin Version: $plugin_version; Stable tag: $package_stable_tag" >&2
		exit 1
	fi
	for section in '== Changelog ==' '== Upgrade Notice =='; do
		entry_count="$(baratables_readme_section_entry_count "$section" "$VERSION" "$package_dir/readme.txt")"
		if [[ "$entry_count" != "1" ]]; then
			echo "$package_dir/readme.txt has $entry_count $section entries for $VERSION; expected one." >&2
			exit 1
		fi
	done
done
if [[ "$TRUNK_STABLE_TAG" != "$VERSION" ]]; then
	echo "Canonical SVN trunk points at $TRUNK_STABLE_TAG, not verified release $VERSION." >&2
	exit 1
fi

ZIP_SHA256="$(shasum -a 256 "$ZIP_FILE" | awk '{print $1}')"
cat <<EOF
Verified BaraTables $VERSION from approved Git SHA $CONFIRM_HEAD.
Canonical SVN tag and the public WordPress.org ZIP are byte-identical to the Git archive.
Public ZIP: $PUBLIC_ZIP_URL
ZIP SHA-256: $ZIP_SHA256
EOF
