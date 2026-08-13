#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="secure-s3-storage"

ROOT_DIR="$(
    cd "$(dirname "${BASH_SOURCE[0]}")"
    pwd
)"

BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

PLUGIN_FILE="${ROOT_DIR}/secure-s3-storage.php"
COMPOSER_FILE="${ROOT_DIR}/composer.json"
COMPOSER_LOCK="${ROOT_DIR}/composer.lock"

echo "Building ${PLUGIN_SLUG} release package..."

#
# Check required commands.
#
for command in php composer zip; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "Error: required command not found: ${command}" >&2
        exit 1
    fi
done

#
# Check required source files.
#
required_files=(
    "${PLUGIN_FILE}"
    "${ROOT_DIR}/uninstall.php"
    "${ROOT_DIR}/readme.txt"
    "${ROOT_DIR}/LICENSE"
    "${COMPOSER_FILE}"
    "${COMPOSER_LOCK}"
)

for file in "${required_files[@]}"; do
    if [[ ! -f "${file}" ]]; then
        echo "Error: required file not found: ${file}" >&2
        exit 1
    fi
done

if [[ ! -d "${ROOT_DIR}/src" ]]; then
    echo "Error: src directory not found." >&2
    exit 1
fi

#
# Read the plugin version from the WordPress plugin header.
#
VERSION="$(
    sed -n \
        's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' \
        "${PLUGIN_FILE}" \
        | head -n 1 \
        | tr -d '\r'
)"

if [[ -z "${VERSION}" ]]; then
    echo "Error: unable to determine plugin version." >&2
    exit 1
fi

ZIP_FILE="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "Version: ${VERSION}"

#
# Validate Composer metadata before building.
#
echo "Validating Composer configuration..."

composer validate \
    --no-check-publish \
    "${COMPOSER_FILE}"

#
# Syntax-check first-party PHP files.
#
echo "Checking PHP syntax..."

while IFS= read -r -d '' file; do
    php -l "${file}" >/dev/null
done < <(
    find \
        "${ROOT_DIR}/src" \
        -type f \
        -name '*.php' \
        -print0
)

php -l "${PLUGIN_FILE}" >/dev/null
php -l "${ROOT_DIR}/uninstall.php" >/dev/null

echo "PHP syntax check passed."

#
# Start with a clean staging directory.
#
echo "Preparing build directory..."

rm -rf "${STAGE_DIR}"
rm -f "${ZIP_FILE}"

mkdir -p "${STAGE_DIR}"

#
# Copy only files required by the distributed plugin.
#
cp "${PLUGIN_FILE}" \
    "${STAGE_DIR}/secure-s3-storage.php"

cp "${ROOT_DIR}/uninstall.php" \
    "${STAGE_DIR}/uninstall.php"

cp "${ROOT_DIR}/readme.txt" \
    "${STAGE_DIR}/readme.txt"

cp "${ROOT_DIR}/LICENSE" \
    "${STAGE_DIR}/LICENSE"

cp -R "${ROOT_DIR}/src" \
    "${STAGE_DIR}/src"

#
# Composer files are copied temporarily so production dependencies
# can be installed reproducibly from composer.lock.
#
cp "${COMPOSER_FILE}" \
    "${STAGE_DIR}/composer.json"

cp "${COMPOSER_LOCK}" \
    "${STAGE_DIR}/composer.lock"

#
# Install production dependencies directly into the release tree.
#
echo "Installing production Composer dependencies..."

composer install \
    --working-dir="${STAGE_DIR}" \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

#
# composer.lock is used only to build the release reproducibly.
# Keep composer.json in the distributed package so dependency
# metadata remains available for inspection.
#
rm -f \
    "${STAGE_DIR}/composer.lock"

#
# Verify the minimum runtime structure.
#
runtime_files=(
    "${STAGE_DIR}/secure-s3-storage.php"
    "${STAGE_DIR}/uninstall.php"
    "${STAGE_DIR}/readme.txt"
    "${STAGE_DIR}/LICENSE"
    "${STAGE_DIR}/vendor/autoload.php"
)

for file in "${runtime_files[@]}"; do
    if [[ ! -f "${file}" ]]; then
        echo "Error: release file missing: ${file}" >&2
        exit 1
    fi
done

if [[ ! -d "${STAGE_DIR}/src" ]]; then
    echo "Error: release src directory missing." >&2
    exit 1
fi

#
# Create a ZIP containing one top-level plugin directory.
#
echo "Creating ZIP archive..."

(
    cd "${BUILD_DIR}"

    zip \
        -rq \
        "$(basename "${ZIP_FILE}")" \
        "${PLUGIN_SLUG}"
)

echo
echo "Release package created successfully:"
echo "${ZIP_FILE}"
echo
echo "Package contents:"
unzip -l "${ZIP_FILE}" | head -n 40