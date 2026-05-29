#!/bin/bash
# =============================================================
# iCalSync — Setup de dependencias para Docker
# =============================================================
# Ejecutar DENTRO del contenedor de FacturaScripts:
#
#   docker exec -it <contenedor> bash /var/www/html/Plugins/iCalSync/setup.sh
#
# O si ya estás dentro del contenedor:
#
#   cd /var/www/html/Plugins/iCalSync && bash setup.sh
# =============================================================

set -e

# Detectar el directorio raíz de FacturaScripts
if [ -f "/var/www/html/Core/App/App.php" ]; then
    FS_ROOT="/var/www/html"
elif [ -f "/app/Core/App/App.php" ]; then
    FS_ROOT="/app"
elif [ -f "/var/www/facturascripts/Core/App/App.php" ]; then
    FS_ROOT="/var/www/facturascripts"
else
    echo "❌ No se encontró FacturaScripts. Buscando..."
    FS_ROOT=$(find / -name "App.php" -path "*/Core/App/*" 2>/dev/null | head -1 | sed 's|/Core/App/App.php||')
    if [ -z "$FS_ROOT" ]; then
        echo "❌ No se pudo detectar la instalación de FacturaScripts."
        echo "   Ejecutá este script desde el directorio raíz de FS:"
        echo "   cd /ruta/a/facturascripts && bash Plugins/iCalSync/setup.sh"
        exit 1
    fi
fi

echo "✅ FacturaScripts detectado en: $FS_ROOT"
echo ""

# =============================================================
# Opción A: Composer disponible en el contenedor
# =============================================================
if command -v composer &> /dev/null; then
    echo "📦 Composer detectado. Instalando dependencias..."

    cd "$FS_ROOT"

    # Agregar las dependencias al composer.json principal
    if ! grep -q "sabre/dav" composer.json 2>/dev/null; then
        echo "   Agregando sabre/dav y sabre/vobject..."
        composer require sabre/dav sabre/vobject --no-interaction
    else
        echo "   Dependencias ya declaradas. Instalando..."
        composer install --no-interaction
    fi

    echo "✅ Dependencias instaladas."
    echo ""

# =============================================================
# Opción B: Sin composer — instalación manual
# =============================================================
else
    echo "⚠️  Composer no disponible. Instalación manual..."

    VENDOR_DIR="$FS_ROOT/vendor"
    SABRE_DIR="$VENDOR_DIR/sabre"

    mkdir -p "$SABRE_DIR/dav/lib" "$SABRE_DIR/vobject/lib" \
             "$SABRE_DIR/event/lib" "$SABRE_DIR/http/lib" \
             "$SABRE_DIR/uri/lib" "$SABRE_DIR/xml/lib"

    echo "   Descargando sabre/dav..."
    # Usar git si está disponible, sino curl al zip de GitHub
    if command -v git &> /dev/null; then
        TMPDIR=$(mktemp -d)
        git clone --depth 1 https://github.com/sabre-io/dav.git "$TMPD/dav" 2>/dev/null || true
    fi

    # Alternativa: descargar releases de GitHub
    echo "   Intentando descarga via curl/wget de GitHub releases..."

    download_release() {
        local repo=$1
        local dest=$2
        local url="https://api.github.com/repos/sabre-io/${repo}/releases/latest"

        if command -v curl &> /dev/null; then
            DOWNLOAD_URL=$(curl -s "$url" | grep "tarball_url" | cut -d'"' -f4)
            if [ -n "$DOWNLOAD_URL" ]; then
                curl -L "$DOWNLOAD_URL" -o /tmp/sabre.tar.gz 2>/dev/null
                mkdir -p "$dest"
                tar -xzf /tmp/sabre.tar.gz -C "$dest" --strip-components=1 2>/dev/null
                rm -f /tmp/sabre.tar.gz
                return 0
            fi
        fi
        return 1
    }

    # Intentar descargar sabre/dav
    if ! download_release "dav" "$SABRE_DIR/dav"; then
        echo ""
        echo "❌ No se pudieron descargar las dependencias automáticamente."
        echo ""
        echo "   Opciones manuales:"
        echo ""
        echo "   1. Instalar composer en el contenedor:"
        echo "      apt update && apt install -y curl unzip"
        echo "      curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"
        echo "      cd $FS_ROOT && composer require sabre/dav sabre/vobject"
        echo ""
        echo "   2. Reconstruir la imagen Docker con las dependencias:"
        echo "      Agregar al Dockerfile:"
        echo "      RUN cd /var/www/html && composer require sabre/dav sabre/vobject"
        echo ""
        echo "   3. Instalar desde el host y copiar vendor/:"
        echo "      cd /ruta/local/facturascripts"
        echo "      composer require sabre/dav sabre/vobject"
        echo "      docker cp vendor/ <contenedor>:$FS_ROOT/vendor/"
        exit 1
    fi

    # Descargar sabre/vobject, event, http, uri, xml
    for lib in vobject event http uri xml; do
        download_release "$lib" "$SABRE_DIR/$lib" 2>/dev/null || true
    done

    echo "✅ Dependencias instaladas manualmente."
fi

# =============================================================
# Verificar instalación
# =============================================================
echo ""
echo "🔍 Verificando..."

php -r "
\$files = [
    '$FS_ROOT/vendor/sabre/dav/lib/Client.php',
    '$FS_ROOT/vendor/sabre/vobject/lib/VCalendar.php',
];
\$ok = true;
foreach (\$files as \$f) {
    if (file_exists(\$f)) {
        echo '  ✅ ' . basename(dirname(dirname(\$f))) . ' — OK' . PHP_EOL;
    } else {
        echo '  ❌ ' . basename(dirname(dirname(\$f))) . ' — NO ENCONTRADO' . PHP_EOL;
        \$ok = false;
    }
}
if (!\$ok) {
    echo PHP_EOL . '⚠️  Faltan dependencias. El plugin funcionará pero CalDAV no estará disponible.' . PHP_EOL;
    echo '   Seguí las instrucciones de arriba para instalarlas.' . PHP_EOL;
} else {
    echo PHP_EOL . '✅ Todas las dependencias instaladas correctamente.' . PHP_EOL;
}
" 2>/dev/null || echo "   (no se pudo verificar con PHP — revisá manualmente)"

echo ""
echo "📋 Próximos pasos:"
echo "   1. Activá iCalSync desde Admin → Plugins en FacturaScripts"
echo "   2. Configurá la cuenta iCloud en Admin → iCalSync Configuración"
echo "   3. Consultá MANUAL.md para la guía completa"
echo ""
