#!/usr/bin/env bash
#
# Descarga el recorte del partido de Ramallo desde el WFS del IGN (compuerta G3).
#
# El archivo que produce es el origen de TODAS las coordenadas verificadas del
# proyecto, así que el script falla ruidosamente ante cualquier sorpresa en lugar
# de dejar pasar un archivo silenciosamente distinto: si el servicio cambia la
# capa, devuelve más de una entidad, entrega un punto en lugar de un polígono o
# invierte los ejes, se entera acá y no tres fases después mirando un mapa raro.
#
# Uso:  bash scripts/obtener-recorte-ign.sh [directorio-destino]
#
# El egreso hacia ign.gob.ar está bloqueado en el entorno de desarrollo por
# política, así que esto se corre desde una máquina con red abierta y el
# resultado se versiona en database/geo/ junto a su manifiesto.

set -euo pipefail

OWS="https://wms.ign.gob.ar/geoserver/ows"
PARTIDO="Ramallo"
DESTINO="${1:-database/geo}"
SALIDA="$DESTINO/ramallo-partido-$(date +%Y%m%d).geojson"

mkdir -p "$DESTINO"

echo "→ Resolviendo la capa de departamentos en el GetCapabilities…"
CAPAS=$(curl -sS "$OWS?service=WFS&version=2.0.0&request=GetCapabilities" \
  | grep -oE '<Name>[^<]*departamento[^<]*</Name>' | sed 's/<[^>]*>//g' | sort -u)

if [ -z "$CAPAS" ]; then
  echo "✗ No apareció ninguna capa con «departamento» en el nombre."
  echo "  El IGN pudo haberla renombrado: revisá el GetCapabilities a mano."
  exit 1
fi

TYPENAME=$(echo "$CAPAS" | head -1)
echo "  capas candidatas: $(echo "$CAPAS" | tr '\n' ' ')"
echo "  uso: $TYPENAME"

echo "→ Descargando el partido de $PARTIDO…"
curl -sS -G "$OWS" \
  --data-urlencode "service=WFS" \
  --data-urlencode "version=2.0.0" \
  --data-urlencode "request=GetFeature" \
  --data-urlencode "typeNames=$TYPENAME" \
  --data-urlencode "outputFormat=application/json" \
  --data-urlencode "srsName=EPSG:4326" \
  --data-urlencode "CQL_FILTER=nam='$PARTIDO'" \
  -o "$SALIDA"

echo "→ Verificación"

jq -e '.features | length == 1' "$SALIDA" >/dev/null \
  || { echo "✗ No vino exactamente una entidad: $(jq '.features|length' "$SALIDA"). Revisá el filtro."; exit 1; }

jq -e '.features[0].geometry.type | test("Polygon")' "$SALIDA" >/dev/null \
  || { echo "✗ La geometría es $(jq -r '.features[0].geometry.type' "$SALIDA"), no un polígono."
       echo "  «$PARTIDO» también es una localidad dentro del partido: puede haber matcheado esa."; exit 1; }

# El control que más veces salva: WFS 2.0.0 con EPSG:4326 a veces devuelve
# lat,lon. Una inversión pone a Ramallo en el océano Índico y ninguna validación
# de esquema se queja.
read -r X Y <<<"$(jq -r '.features[0].geometry.coordinates | flatten | "\(.[0]) \(.[1])"' "$SALIDA")"
echo "  primer par: $X $Y"
awk -v x="$X" -v y="$Y" 'BEGIN{
  if (x > -62 && x < -59 && y > -35 && y < -32) { print "  ✓ ejes correctos: [lon, lat]"; exit 0 }
  if (y > -62 && y < -59 && x > -35 && x < -32) { print "  ✗ EJES INVERTIDOS: viene [lat, lon]"; exit 1 }
  print "  ✗ El par no cae sobre Ramallo. Verificá qué se descargó."; exit 1
}'

jq -r '.features[0].properties' "$SALIDA"

echo
echo "  archivo : $SALIDA"
echo "  tamaño  : $(du -h "$SALIDA" | cut -f1)"
echo "  vértices: $(jq -r '.features[0].geometry.coordinates|flatten|length/2|floor' "$SALIDA")"
if command -v sha256sum >/dev/null 2>&1; then
  echo "  SHA-256 : $(sha256sum "$SALIDA" | cut -d' ' -f1)"
else
  echo "  SHA-256 : $(shasum -a 256 "$SALIDA" | cut -d' ' -f1)"
fi
echo "  bajado  : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo
echo "Actualizá database/geo/MANIFIESTO.md con estos valores."
echo "La fecha de PUBLICACIÓN del dataset no la expone el servicio: ver el manifiesto."
