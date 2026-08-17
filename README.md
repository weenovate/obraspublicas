# Plataforma de Obras Públicas — Municipalidad de Ramallo

Mapa público de obras públicas municipales, backoffice de carga y pantalla de
exhibición continua (LIVE). Especificación funcional: `EF-OPR-001 v1.0`.

**Estado: F0 y G2 completadas.** F1 está bloqueada hasta cerrar G3 (dataset del
IGN). El detalle está en [`docs/PLAN-DESARROLLO.md`](docs/PLAN-DESARROLLO.md).

---

## Requisitos

| Herramienta | Versión | Nota |
|---|---|---|
| PHP | 8.4 | Con `gd`, `exif`, `fileinfo`, `pdo_mysql`, `intl`, `zip`. **Sin `imagick`**: producción no lo tiene, así que Intervention Image corre sobre GD. |
| Composer | 2.8+ | |
| Node | 22+ | |
| Docker | cualquiera reciente | Sólo para la base de datos |
| MariaDB | **10.11.18** | No negociable: es la de producción (compuerta G1) |

La versión de MariaDB no es un detalle de comodidad. Parte de
[`docs/MATRIZ-ESPACIAL.md`](docs/MATRIZ-ESPACIAL.md) depende de qué funciones trae
ese build: `ST_IsValid` y `ST_LineInterpolatePoint` no existen, `ST_PointOnSurface`
sí, y `ST_Length` devuelve grados. Un 10.11.x distinto invalidaría celdas del
informe.

---

## Puesta en marcha

```bash
# 1. Base de datos: una instancia de desarrollo y otra para los tests
docker compose up -d

# 2. Dependencias
composer install
npm install

# 3. Entorno
cp .env.example .env
php artisan key:generate
# En .env, ajustar DB_PORT=3307 (el puerto que expone docker-compose)

# 4. Esquema
php artisan migrate

# 5. Assets
npm run build      # o `npm run dev` para desarrollo con recarga

# 6. Servidor
php artisan serve
```

La aplicación queda en `http://localhost:8000`. Todavía no hay usuarios: el CRUD y
el sembrado llegan en F1.

### Página de referencia del sistema de diseño

`http://localhost:8000/referencia-rds` — todos los componentes en una pantalla,
con los tres estados de tema. **No existe en producción.**

---

## Verificación

```bash
# Calidad estática
./vendor/bin/pint --test          # formato
./vendor/bin/phpstan analyse      # Larastan nivel 6

# Disciplina del sistema de diseño (los tres fallan el build)
npm run rds:lint                  # ningún color ni tipografía literal fuera de tokens
npm run rds:contraste             # 76 pares en AA, en los dos temas
npm run build && npm run rds:fuentes

# Tests: 47 en PHP, 66 E2E sobre seis viewports
./vendor/bin/pest
npx playwright test

# Sondas espaciales de G2 → regenera docs/MATRIZ-ESPACIAL.md
php artisan migrate --path=poc/migrations
php poc/sonda.php

# Auditoría de dependencias
composer audit
npm audit --audit-level=high
```

La suite corre contra **MariaDB**, no contra SQLite en memoria: DDL geométrico,
uso real del índice SPATIAL, disparadores de inmutabilidad y comportamiento de
`ST_*` son específicos del motor, y un test verde sobre otro motor no dice nada
sobre producción.

---

## Estructura

```
app/
  Http/Controllers/Auth/      Login mínimo de F0
  Http/Middleware/            Cabeceras de seguridad, Inertia
  Models/                     User, AuditEvent (inmutable)
  Support/Audit/              AuditRecorder: dos caminos explícitos
  Support/Geo/                Adaptador de ejes y longitud geodésica
poc/
  sonda.php                   Las diez sondas de G2
  lib/GeodesicOracle.php      Oráculo analítico de distancias
  migrations/                 Migración de prueba de P3 (fuera del esquema de la app)
resources/
  rds/                        Paquete del RDS, INTACTO (no se edita)
  css/                        Nuestras extensiones: oscuro, accesibilidad, Leaflet, app
  js/Components/rds/          Los siete primitivos en Vue
  js/Pages/                   Páginas de Inertia
scripts/                      Verificadores del RDS
docs/                         Plan, ADRs, matriz espacial, runbook
tests/
  Unit/ Feature/ Arch/        Pest
  e2e/                        Playwright
```

---

## Documentación

| Documento | Qué contiene |
|---|---|
| [`PLAN-DESARROLLO.md`](docs/PLAN-DESARROLLO.md) | Fases, compuertas, Definition of Done, estimaciones |
| [`ARQUITECTURA.md`](docs/ARQUITECTURA.md) | 20 ADRs, con la alternativa descartada y por qué era incorrecta |
| [`MATRIZ-ESPACIAL.md`](docs/MATRIZ-ESPACIAL.md) | Las diez sondas de G2 con resultados **medidos** |
| [`DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md) | Procedencia del RDS, contraste medido, extensiones, compuerta G5 |
| [`MODELO-DATOS.md`](docs/MODELO-DATOS.md) | Las 13 entidades y sus invariantes |
| [`INFRAESTRUCTURA.md`](docs/INFRAESTRUCTURA.md) | Servicios, responsables, costos y tareas recurrentes |
| [`DEPLOY-CPANEL.md`](docs/DEPLOY-CPANEL.md) | Runbook de despliegue, backups y restauración |
| [`BACKLOG.md`](docs/BACKLOG.md) | Trazabilidad RF/CA → tarea → fase → estado |

---

## Tres reglas que conviene conocer antes de escribir código

**1. Las coordenadas son `[longitud, latitud]`, siempre.** `phpgeo` usa el orden
inverso, y por eso toda conversión pasa por `GeoJsonPhpGeoAdapter`. Un test de
arquitectura falla el build si se instancia `Location\Coordinate` en otro archivo.
Un error de ejes no lanza excepción: dibuja la obra en otro continente.

**2. La base no calcula metros.** Topología planar sí; longitudes, distancias y
áreas en metros se calculan en PHP. `ST_Length` sobre lon/lat devuelve **grados**, y
un test de arquitectura la prohíbe en el dominio.

**3. La auditoría de una operación exitosa va en la misma transacción que el
cambio.** `registrar()` para todo lo que tiene éxito, `registrarIntentoFallido()`
sólo para intentos fallidos o denegados. El nombre dice qué admite, y un test de
arquitectura verifica que no se use fuera de su lista blanca.

Ninguna de las tres es preferencia de estilo: las tres corrigen errores concretos
que estaban en versiones anteriores del plan, con el razonamiento en
[`ARQUITECTURA.md`](docs/ARQUITECTURA.md).
