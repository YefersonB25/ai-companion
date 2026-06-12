#!/usr/bin/env bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DEPLOY_SERVER="root@ai.omnirepair.online"
BACKEND_PATH="/var/www/ai-companion"
DOWNLOAD_URL_BASE="http://ai.omnirepair.online/downloads"

# Helper functions
print_header() {
    echo ""
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC} $1"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

print_step() {
    echo -e "${YELLOW}→${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

confirm() {
    local prompt="$1"
    local default="${2:-n}"
    local response

    if [[ "$default" == "y" ]]; then
        read -p "$(echo -e ${YELLOW})$prompt (Y/n)$(echo -e ${NC}) " -n 1 -r response
    else
        read -p "$(echo -e ${YELLOW})$prompt (y/N)$(echo -e ${NC}) " -n 1 -r response
    fi
    echo

    if [[ "$response" == "y" ]] || [[ "$response" == "Y" ]]; then
        return 0
    elif [[ "$response" == "n" ]] || [[ "$response" == "N" ]]; then
        return 1
    elif [[ -z "$response" ]]; then
        if [[ "$default" == "y" ]]; then
            return 0
        else
            return 1
        fi
    fi
    return 1
}

# Main function
main() {
    print_header "Backend - Publish Version"

    # Get version from argument or prompt
    local version="${1:-}"
    if [ -z "$version" ]; then
        read -p "$(echo -e ${YELLOW})Ingresa la versión (ej: 1.0.62):$(echo -e ${NC}) " version
    fi

    if ! [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "Versión inválida: $version (debe ser X.Y.Z)"
        exit 1
    fi

    print_success "Versión: $version"

    # Get platform
    local platform="${2:-android}"
    if [ "$platform" != "android" ] && [ "$platform" != "ios" ]; then
        read -p "$(echo -e ${YELLOW})Plataforma [android/ios] [$platform]:$(echo -e ${NC}) " platform_input
        platform=${platform_input:-$platform}
    fi

    print_success "Plataforma: $platform"

    # Get version code
    local version_code=$(echo "$version" | cut -d. -f3)
    read -p "$(echo -e ${YELLOW})Version Code [$version_code]:$(echo -e ${NC}) " vc_input
    version_code=${vc_input:-$version_code}

    print_success "Version Code: $version_code"

    # Get changelog
    print_step "Ingresa cambios (una línea por cambio, línea vacía para terminar):"
    local changelog=""
    while IFS= read -r line; do
        if [ -z "$line" ]; then
            break
        fi
        changelog="$changelog$line"$'\n'
    done

    if [ -z "$changelog" ]; then
        changelog="Release v$version"
    fi

    # Get download URL
    local download_url="$DOWNLOAD_URL_BASE/ai-companion-v${version}.apk"
    if [ "$platform" == "ios" ]; then
        download_url="$DOWNLOAD_URL_BASE/ai-companion-v${version}.ipa"
    fi

    read -p "$(echo -e ${YELLOW})Download URL [$download_url]:$(echo -e ${NC}) " url_input
    download_url=${url_input:-$download_url}

    # Required update?
    local is_required="false"
    if confirm "¿Es una actualización obligatoria?"; then
        is_required="true"
    fi

    echo ""
    print_header "Resumen"
    echo "Versión: $version"
    echo "Plataforma: $platform"
    echo "Version Code: $version_code"
    echo "Cambios:"
    echo "$changelog" | sed 's/^/  • /'
    echo "URL: $download_url"
    echo "Obligatoria: $is_required"
    echo ""

    if ! confirm "¿Publicar versión?"; then
        print_error "Publicación cancelada"
        exit 1
    fi

    echo ""
    print_step "Publicando en backend..."

    local cmd="cd $BACKEND_PATH && php artisan app:release $version --platform=$platform --url='$download_url'"

    if [ "$is_required" == "true" ]; then
        cmd="$cmd --required"
    fi

    # Execute command with changelog input
    local changelog_lines=$(echo -e "$changelog" | grep -v '^$' || echo "Release v$version")

    ssh "$DEPLOY_SERVER" << EOF
$cmd << 'EOCMD'
$version_code
$changelog_lines

EOCMD
EOF

    if [ $? -eq 0 ]; then
        print_success "Versión publicada"

        echo ""
        print_header "Verificación"

        # Verify
        local response=$(curl -s "https://ai.omnirepair.online/api/app/version?platform=$platform")
        local returned_version=$(echo "$response" | jq -r '.version // empty')

        if [ "$returned_version" == "$version" ]; then
            print_success "✓ Endpoint retorna v$version"
            echo ""
            echo "Respuesta completa:"
            echo "$response" | jq .
        else
            print_error "Endpoint retorna v$returned_version (esperaba v$version)"
        fi

        echo ""
        print_header "✓ Publicación Completada"
    else
        print_error "Falló la publicación"
        exit 1
    fi
}

# Error handler
trap 'print_error "Script interrumpido"; exit 1' INT TERM

# Run
main "$@"
