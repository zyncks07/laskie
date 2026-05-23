#!/usr/bin/env bash
# Regenerates SRI (Subresource Integrity) hashes for self-hosted vendor assets.
# Run this whenever assets/vendor/*.{css,js} are updated.
# Prints a PHP array literal — paste the body into VENDOR_SRI in config/functions.php.

set -euo pipefail

cd "$(dirname "$0")/.."
VENDOR_DIR=assets/vendor

if [[ ! -d $VENDOR_DIR ]]; then
    echo "error: $VENDOR_DIR not found (run from project root)" >&2
    exit 1
fi

echo "// Regenerated $(date -Iseconds) by scripts/generate-sri.sh"
echo "// Replace the body of vendorSriMap() in config/functions.php with the lines below."
cd "$VENDOR_DIR"
for f in $(find . -maxdepth 1 -type f \( -name '*.css' -o -name '*.js' \) | sort); do
    name=${f#./}
    hash=$(openssl dgst -sha384 -binary "$f" | openssl base64 -A)
    printf "    '%-40s => 'sha384-%s',\n" "${name}'" "$hash"
done
