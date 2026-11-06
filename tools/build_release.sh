#!/usr/bin/env bash
# Build the classic shared-hosting release artifact (F2).
#
# Usage (from any directory):
#   bash tools/build_release.sh [--version 1.0.0] [--output dist]
#
# Produces:
#   dist/opng-<version>-classic.zip
#   dist/opng-<version>-classic.tar.gz
#   dist/SHA256SUMS
#
# The package intentionally excludes tests, CI, Composer/PHPStan tooling,
# screenshots, Git metadata and the real src/config.php. It always contains
# src/config.example.php.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version=""
output="$root/dist"

usage() {
    cat <<'EOT'
Usage: bash tools/build_release.sh [--version VERSION] [--output DIRECTORY]

  --version VERSION   Release version (default: OPNG_VERSION from src/includes/version.php)
  --output DIRECTORY  Destination directory (default: <repo>/dist)
  -h, --help          Show this help

The script writes opng-<version>-classic.zip, opng-<version>-classic.tar.gz
and SHA256SUMS. It refuses to include src/config.php or development files.
EOT
}

while (($# > 0)); do
    case "$1" in
        --version)
            [[ $# -ge 2 ]] || { echo "ERROR: --version needs a value." >&2; exit 2; }
            version="$2"
            shift 2
            ;;
        --version=*)
            version="${1#*=}"
            shift
            ;;
        --output)
            [[ $# -ge 2 ]] || { echo "ERROR: --output needs a value." >&2; exit 2; }
            output="$2"
            shift 2
            ;;
        --output=*)
            output="${1#*=}"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [[ -z "$version" ]]; then
    version="$(sed -n "s/.*define('OPNG_VERSION', '\([^']*\)').*/\1/p" "$root/src/includes/version.php" | head -n 1)"
fi
if [[ ! "$version" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]]; then
    echo "ERROR: invalid release version: '$version'" >&2
    exit 2
fi

command -v php >/dev/null 2>&1 || { echo "ERROR: php is required to validate the package." >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo "ERROR: zip is required to build the classic archive." >&2; exit 1; }
command -v tar >/dev/null 2>&1 || { echo "ERROR: tar is required to build the classic archive." >&2; exit 1; }

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        echo "ERROR: no SHA-256 tool (sha256sum or shasum) found." >&2
        exit 1
    fi
}

mkdir -p "$output"
output="$(cd "$output" && pwd)"
package="opng-${version}"
stage="$output/$package"
archive_zip="$output/opng-${version}-classic.zip"
archive_tar="$output/opng-${version}-classic.tar.gz"

case "$output" in
    /|"") echo "ERROR: refusing to use an unsafe output directory: '$output'" >&2; exit 2 ;;
esac

rm -rf "$stage"
mkdir -p "$stage"

# Copy only runtime, schema, operational tools and deployment documentation.
cp -R "$root/src" "$stage/src"
cp -R "$root/database" "$stage/database"
cp -R "$root/tools" "$stage/tools"
cp -R "$root/deploy" "$stage/deploy"
cp -R "$root/docs" "$stage/docs"
cp "$root/README.md" "$root/CHANGELOG.md" "$root/LICENSE" "$stage/"

# Never ship the real configuration or development-only tooling.
rm -f "$stage/src/config.php"
rm -f "$stage/tools/seed.php" "$stage/tools/build_release.sh"
rm -rf "$stage/deploy/apple-container"
rm -f "$stage/docs/APPLE-CONTAINER.md"
find "$stage" \( -name '.DS_Store' -o -name '*.log' -o -name '*.sqlite' \) -delete
find "$stage" -type d -empty -delete

if [[ ! -f "$stage/src/config.example.php" ]]; then
    echo "ERROR: src/config.example.php is missing from the package." >&2
    exit 1
fi
if [[ -e "$stage/src/config.php" ]]; then
    echo "ERROR: src/config.php must never be packaged." >&2
    exit 1
fi
if [[ -d "$stage/vendor" || -f "$stage/composer.json" || -f "$stage/phpstan.neon" ]]; then
    echo "ERROR: development tooling leaked into the package." >&2
    exit 1
fi

# Validate every packaged PHP file before archiving.
while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
done < <(find "$stage" -name '*.php' -print0)

# Per-file checksums inside the release directory.
(
    cd "$stage"
    find . -type f ! -name 'SHA256SUMS' -print | LC_ALL=C sort | while IFS= read -r file; do
        printf '%s  %s\n' "$(sha256_file "./$file")" "${file#./}"
    done > SHA256SUMS
)

rm -f "$archive_zip" "$archive_tar"
(
    cd "$output"
    zip -qr "$(basename "$archive_zip")" "$package"
    tar -czf "$(basename "$archive_tar")" "$package"
)

(
    cd "$output"
    {
        printf '%s  %s\n' "$(sha256_file "$(basename "$archive_zip")")" "$(basename "$archive_zip")"
        printf '%s  %s\n' "$(sha256_file "$(basename "$archive_tar")")" "$(basename "$archive_tar")"
    } > SHA256SUMS
)

cat <<EOT
Classic release built:
  $archive_zip
  $archive_tar
  $output/SHA256SUMS

Verify a download with:
  sha256sum -c SHA256SUMS        # Linux
  shasum -a 256 -c SHA256SUMS    # macOS
EOT
