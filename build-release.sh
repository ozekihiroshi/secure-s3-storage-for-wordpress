#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="secure-s3-storage"

ROOT_DIR="$(
    cd "$(dirname "${BASH_SOURCE[0]}")"
    pwd
)"

BUILD_DIR="${ROOT_DIR}/build"

PLUGIN_FILE="${ROOT_DIR}/secure-s3-storage.php"
COMPOSER_FILE="${ROOT_DIR}/composer.json"
COMPOSER_LOCK="${ROOT_DIR}/composer.lock"
SCOPER_CONFIG="${ROOT_DIR}/scoper.inc.php"

SCOPER_VERSION="0.18.19"
SCOPER_SHA256="170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933"
SCOPER_DIR="${BUILD_DIR}/tools"
SCOPER_PHAR="${SCOPER_DIR}/php-scoper-${SCOPER_VERSION}.phar"
SCOPER_URL="https://github.com/humbug/php-scoper/releases/download/${SCOPER_VERSION}/php-scoper.phar"

echo "Building ${PLUGIN_SLUG} release package..."

#
# Check required commands.
#
for command in php composer curl sha256sum zip unzip mktemp; do
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
    "${SCOPER_CONFIG}"
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

WORK_DIR="$(mktemp -d)"
STAGE_DIR="${WORK_DIR}/stage/${PLUGIN_SLUG}"
SCOPED_DIR="${WORK_DIR}/scoped/${PLUGIN_SLUG}"
WORK_ZIP="${WORK_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

cleanup() {
    rm -rf "${WORK_DIR}"
}

trap cleanup EXIT

echo "Version: ${VERSION}"

#
# Validate Composer metadata and PHP-Scoper configuration.
#
echo "Validating build configuration..."

composer validate \
    --no-check-publish \
    "${COMPOSER_FILE}"

php -l "${SCOPER_CONFIG}" >/dev/null

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
# Start with a clean Linux staging directory. Only the completed ZIP and
# the verified build-tool cache are stored on the Windows workspace.
#
echo "Preparing build directory..."

rm -rf "${BUILD_DIR}/${PLUGIN_SLUG}"
rm -f "${ZIP_FILE}"

mkdir -p \
    "${STAGE_DIR}" \
    "$(dirname "${SCOPED_DIR}")" \
    "${SCOPER_DIR}"

scoper_is_valid() {
    printf '%s  %s\n' \
        "${SCOPER_SHA256}" \
        "${SCOPER_PHAR}" \
        | sha256sum --status -c -
}

#
# Download the pinned PHP-Scoper build tool and verify it before use.
# The PHAR is cached outside the release tree and is never distributed.
#
if ! scoper_is_valid; then
    echo "Downloading PHP-Scoper ${SCOPER_VERSION}..."

    rm -f "${SCOPER_PHAR}"

    curl \
        -fL \
        --retry 3 \
        -o "${WORK_DIR}/php-scoper.phar" \
        "${SCOPER_URL}"

    printf '%s  %s\n' \
        "${SCOPER_SHA256}" \
        "${WORK_DIR}/php-scoper.phar" \
        | sha256sum -c -

    cp \
        "${WORK_DIR}/php-scoper.phar" \
        "${SCOPER_PHAR}"
fi

printf '%s  %s\n' \
    "${SCOPER_SHA256}" \
    "${SCOPER_PHAR}" \
    | sha256sum -c -

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
# Install production dependencies directly into the Linux staging tree.
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
# Prefix all bundled third-party namespaces so another plugin's Composer
# autoloader cannot substitute incompatible AWS SDK dependencies.
#
echo "Scoping production Composer dependencies..."

php \
    -d memory_limit=1G \
    "${SCOPER_PHAR}" \
    add-prefix \
    --config="${SCOPER_CONFIG}" \
    --working-dir="${STAGE_DIR}" \
    --output-dir="${SCOPED_DIR}" \
    --force \
    .

# Keep WordPress entry points byte-for-byte identical to the reviewed source.
# They do not reference bundled third-party namespaces and must remain in the
# global namespace for WordPress and Plugin Check.
cp "${STAGE_DIR}/secure-s3-storage.php" \
    "${SCOPED_DIR}/secure-s3-storage.php"

cp "${STAGE_DIR}/uninstall.php" \
    "${SCOPED_DIR}/uninstall.php"

composer dump-autoload \
    --working-dir="${SCOPED_DIR}" \
    --no-dev \
    --optimize

STAGE_DIR="${SCOPED_DIR}"

# composer.lock is used only to build the release reproducibly.
rm -f "${STAGE_DIR}/composer.lock"

#
# Verify that only the plugin-prefixed AWS SDK can be autoloaded.
#
php -r '
    require $argv[1];

    if (! class_exists("SecureS3StorageForWordpress\\Plugin")) {
        fwrite(STDERR, "Plugin autoload verification failed.\n");
        exit(1);
    }

    if (! class_exists("SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client")) {
        fwrite(STDERR, "Scoped AWS SDK autoload verification failed.\n");
        exit(1);
    }

    if (class_exists("Aws\\S3\\S3Client")) {
        fwrite(STDERR, "Unscoped AWS SDK remains autoloadable.\n");
        exit(1);
    }
' "${STAGE_DIR}/vendor/autoload.php"

php \
    "${ROOT_DIR}/tests/manual/test-scoped-release.php" \
    "${STAGE_DIR}/vendor/autoload.php"

if grep -R -F -q \
    'SecureS3StorageForWordpressVendor\WP_CLI' \
    "${STAGE_DIR}/src"; then
    echo "WordPress-provided WP_CLI class was incorrectly scoped." >&2
    exit 1
fi

if grep -F -q \
    'namespace SecureS3StorageForWordpressVendor' \
    "${STAGE_DIR}/secure-s3-storage.php"; then
    echo "The WordPress plugin entry point was incorrectly scoped." >&2
    exit 1
fi

if ! grep -F -q \
    'phpcs:ignore Generic.PHP.ForbiddenFunctions.Found' \
    "${STAGE_DIR}/src/Backup/Database/NativeMySqlDumper.php"; then
    echo "The proc_open() Plugin Check exemption was lost during scoping." >&2
    exit 1
fi

echo "Composer dependency scoping passed."

#
# Syntax-check the transformed first-party files.
#
while IFS= read -r -d '' file; do
    php -l "${file}" >/dev/null
done < <(
    find \
        "${STAGE_DIR}/src" \
        -type f \
        -name '*.php' \
        -print0
)

php -l "${STAGE_DIR}/secure-s3-storage.php" >/dev/null
php -l "${STAGE_DIR}/uninstall.php" >/dev/null

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
# Create a ZIP containing one top-level plugin directory, then copy only
# the completed archive back to the workspace.
#
echo "Creating ZIP archive..."

(
    cd "$(dirname "${STAGE_DIR}")"

    zip \
        -rq \
        "${WORK_ZIP}" \
        "${PLUGIN_SLUG}"
)

cp "${WORK_ZIP}" "${ZIP_FILE}"

echo
echo "Release package created successfully:"
echo "${ZIP_FILE}"
echo
echo "Package contents:"
unzip -l "${ZIP_FILE}" | sed -n '1,40p'
