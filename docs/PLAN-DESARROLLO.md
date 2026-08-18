# Plan de desarrollo

Basado en el plan aprobado **v2.3.1** con su fe de erratas. Este documento es la
versión operativa: fases, compuertas, Definition of Done y estimaciones. El
razonamiento de cada decisión está en [`ARQUITECTURA.md`](ARQUITECTURA.md).

---

## Estado

| Fase / compuerta | Estado |
|---|---|
| **G1** — Motor de producción confirmado | ✅ Cerrada. MariaDB 10.11.18, `SELECT VERSION()` registrado |
| **G2** — PoC espacial y matriz | ✅ **Cerrada en verde**. P3, P4, P6, P7 y P9 bloqueantes, todas verdes |
| **F0** — Fundaciones | ✅ **Completada** |
| **F1-A** — Datos, acceso y catálogos | ✅ **Completada** |
| **G3** — Dataset del IGN recortado a Ramallo | ✅ **Cerrada**. Recorte del WFS del IGN verificado y congelado por hash |
| **G4** — Especificación del kiosco | ⛔ Pendiente. Bloquea la aceptación de F5 |
| **G5** — Autorización escrita del RDS | ⛔ Pendiente. Bloquea la habilitación de la URL pública |
| **F1-B** — Obras con geometría | ✅ **Completada** |
| **F2** — Fotos y campos dinámicos | ✅ **Completada** |
| **F3** a **F7** | No iniciadas |

---

## Compuertas pendientes

### G3 — Capa de departamentos del IGN ✅

Cerrada. El polígono del partido llegó del WFS oficial del IGN
(`ign:departamento`, filtro `nam='Ramallo'`, `srsName=EPSG:4326`) y está
congelado en `database/geo/`, con su manifiesto y su SHA-256.

**Las coordenadas del proyecto ya son datos verificados.** De ahí salen el centro
—el centroide, comprobado dentro del polígono—, el zoom de respaldo, el viewbox
de sesgo para la geocodificación y el punto de los fixtures. Todo vive en
`config/obras.php` bajo `mapa`, y `tests/Feature/Geo/RecorteIgnTest.php` verifica
que esos valores sigan saliendo del archivo y no de la memoria de nadie.

Lo que se midió contra MariaDB: SRID 4326, `MULTIPOLYGON` de un polígono sin
huecos, 2.390 vértices, simple, anillo cerrado, punto interior contenido y ~1.046
km² de superficie. La validez es **compuesta** porque `ST_IsValid` no existe en
este motor (ADR-010).

**Salvedad registrada:** el servicio del IGN no expone fecha de publicación del
dataset. Queda anotada en el manifiesto en lugar de inventar un valor; la URL, el
filtro, el momento de descarga y el hash alcanzan para reproducir y auditar el
archivo.

El egreso hacia `ign.gob.ar` sigue bloqueado en el entorno de desarrollo, así que
la descarga se hace desde una máquina con red abierta con
`scripts/obtener-recorte-ign.sh` y el resultado se versiona.

### G4 — Especificación del kiosco (bloquea la aceptación de F5)

RNF-UI-001 no cubre la pantalla de exhibición, que es justamente donde vive LIVE.
Tres variables cambian el diseño por completo y hay que preguntarlas:

- **Modelo del televisor y resolución nativa.**
- **Escalado del sistema operativo.** Un TV 4K al 200 % da un viewport CSS de
  1920 px; el mismo TV al 100 % da 3840 px. Son dos diseños distintos.
- **Distancia de visualización.** Texto dimensionado para 60 cm es ilegible a 4 m.
- **Navegador** del dispositivo.

Hasta tenerlo, LIVE se prueba en 1920×1080 y 3840×2160 con relación de píxeles 1 y
2, y la legibilidad a distancia se acepta en una prueba presencial.

### G5 — Autorización del RDS (bloquea la URL pública)

Ver [`DESIGN-SYSTEM.md`](DESIGN-SYSTEM.md).

---

## Fases

### F0 — Fundaciones ✅

Scaffolding Laravel 13.25 + Inertia 2 + Vue 3 sobre MariaDB · RDS integrado con
tema oscuro construido y contraste medido · primitivos Vue · tooling (Pint,
Larastan, Pest, Playwright) · CI con MariaDB 10.11.18 y auditorías bloqueantes ·
seguridad base con login mínimo funcional y auditoría atómica · PoC espacial de G2.

### F1-A — Datos, acceso y catálogos ✅

F1 se partió al planificarla, porque la mayor parte no depende de la geografía y
esperar a G3 con todo el resto detenido no compraba nada.

Nueve migraciones y seeders —doce de las trece tablas del spec— · autenticación
completa con roles y políticas, con toda denegación auditada · CRUD de usuarios con
contraseña temporal, último Admin protegido y sesiones revocables · los cinco
catálogos con sus reglas de inmutabilidad · campos técnicos dinámicos ·
configuración tipada · generador de código `OBR-YYYY-XXXX` con secuencia atómica ·
pantallas del backoffice con los componentes de aplicación del RDS.

`works` y `work_field_values` entraron **como esquema**: sin ellas, «está en uso»
no se puede consultar y las reglas de catálogo no se pueden hacer cumplir.

### F1-B — Obras con geometría (14–17 días-dev) ✅

CRUD de obras con geometría manual · las tres columnas de fecha y
`effective_end_date` materializada · concurrencia optimista · papelera lógica ·
editores cartográficos. Todo lo que necesita coordenadas verificadas.

Lo que quedó, y lo que costó decidir cada cosa:

- **`WorkGeometry`** valida el GeoJSON contra el modo de la subcategoría y elige
  el punto representativo. Para las líneas es **un vértice**, y no el punto medio,
  porque el punto medio aritmético falla: sobre 200 segmentos medidos quedó
  contenido en la línea sólo 54 veces, y un vértice las 200 (ADR-025).
- **`WorkWriter`** hace el alta, la edición y la baja lógica en una sola
  transacción con el código, la geometría y la auditoría adentro, y **le pregunta
  a la base** si `ST_Contains(geometry, representative_point)` se cumple antes de
  confirmar. La verificación es contra el motor, no contra la expectativa.
- **Concurrencia optimista** con la comparación de `lock_version` en el `WHERE`
  del `UPDATE`, nunca leyendo antes y comparando en PHP: entre la lectura y la
  escritura pasan exactamente las dos ediciones que se quiere evitar.
- **Editor cartográfico** sin plugin de terceros, con la conversión de ejes en un
  único módulo (`resources/js/mapa/ejes.js`) y todos los colores leídos de tokens
  del RDS en tiempo de ejecución.
- **Sin proveedor de teselas todavía**, el editor usa de fondo el contorno oficial
  del partido que dejó G3. Se dibuja igual; las calles aparecen cuando el
  municipio active el servicio (dependencia externa de F3).

**Fuera de F1-B, y a la vista:** la geocodificación de direcciones y el trazado
asistido sobre calles son de F3, y los campos técnicos dinámicos en el formulario
de obra son de F2.

### F2 — Fotos y campos dinámicos (10–12) ✅

Ciclo completo de fotografías con cola, reintentos e idempotencia · formulario
dinámico · galería con estados `PENDING`/`FAILED` y reintento.

**Listo:** `work_photos` —la decimotercera y última tabla—, el procesamiento con
derivados a 1600 y 400 px, la cola idempotente con su tope de reintentos, la
entrega por URL firmada fuera del document root, y la galería con los tres
estados y el botón de reintentar.

Dos decisiones que quedaron tomadas acá:

- **El EXIF se descarta al recomprimir**, con `strip: true` explícito. Las fotos
  de obra se sacan con teléfonos que escriben la ubicación GPS del operario en
  los metadatos; publicarlas así filtraría dónde estuvo una persona, no dónde
  está la obra. La orientación se aplica **antes** de descartar.
- **La ruta de entrega vive fuera del grupo de sesión**: lo que autoriza es la
  firma, no la cookie. Así la misma ruta sirve al backoffice hoy y a la web
  pública de F4, donde no hay sesión.

**Los campos técnicos dinámicos** cierran la fase. Las definiciones existían
desde F1-A pero el formulario de obra no las mostraba: un campo definido no
servía para nada. Ahora se resuelve la unión de alcances, cada tipo se dibuja con
su control y los valores se validan contra lo que declaró el Administrador.

La decisión que quedó tomada acá es **ADR-027**: los valores que quedan fuera de
alcance al cambiar de subcategoría **se conservan ocultos** en lugar de borrarse.
Elegir mal en un desplegable no debería destruir carga manual.

### F3 — Cartografía asistida (13–15)

Geocodificación con Nominatim · pin móvil · trazado con ORS, previsualización y
fallback manual · límites municipales · E2E de los editores.

### F4 — Web pública y contrato de rendimiento (17–19)

SPA pública · capas, filtros y clustering · URL compartible · allowlist de
visibilidad · contrato de rendimiento del mapa con los dos modos de consulta ·
caché versionado con snapshot y `ETag` · puente de Leaflet completo.

### F5 — LIVE (14–16)

Kiosco con sesión persistente · recorrido automático con sus siete criterios nuevos
(CA-022a–g) · persistencia local · reconexión · prueba de memoria de ocho horas con
los seis umbrales cuantitativos.

### F6 — Administración (13–15)

Papelera completa y borrado definitivo por tipeo exacto · UI de auditoría con diff
y filtros. La configuración tipada, los usuarios y la revocación de sesiones se
adelantaron a F1-A.

### F7 — Calidad y producción (15–17)

Seed de 10.000 obras · performance medida contra el contrato · WCAG 2.2 AA
completo · rate limiting en todos los endpoints · backups y restauración
cronometrada · despliegue · capacitación.

---

## Estimación

| | Días-dev |
|---|---|
| F0 (completada) | 13–16 |
| F1-A (completada) | 12–14 |
| F1-B | 14–17 |
| F2 | 10–12 |
| F3 | 13–15 |
| F4 | 17–19 |
| F5 | 14–16 |
| F6 | 13–15 |
| F7 | 15–17 |
| **Subtotal** | **121–140** |
| Contingencia 15 % | 18–21 |
| **Total** | **139–161 días-dev** |

**Días-dev no es calendario.** La conversión depende de factores que no controla el
equipo de desarrollo:

| Factor | Supuesto | Efecto si no se cumple |
|---|---|---|
| Dedicación | 5 días-dev por semana por desarrollador | Proporcional |
| Respuesta de UAT | Observaciones en 5 días hábiles | Cada semana extra corre el calendario 1:1 |
| Compuertas G4 y G5 | Insumos antes de necesitarlos | G3 ya está cerrada; G5 bloquea la URL pública |
| Proveedores | Cuentas de teselas y ORS activas antes de F3 | F3 y F4 a media máquina |
| Coordinación | ~15 % de sobrecarga | Proporcional |

Con esos supuestos: **1 desarrollador ≈ 32 a 37 semanas**; **2 desarrolladores ≈ 18
a 21 semanas**, aprovechando que F4 y F5 son separables de F2 y F3.

La brecha entre 139 días-dev y 37 semanas es coordinación, UAT y compuertas.
Conviene que esté explícita desde el principio y no como sorpresa en el mes cinco.

---

## UAT

**Una ronda por fase** para F1 a F7, con demostración en staging, registro de
observaciones y **una** pasada de correcciones. F0 no lleva UAT de negocio: su
entregable es infraestructura, y lleva revisión técnica.

Para cerrar una ronda, cada observación se clasifica:

| Clasificación | Qué implica |
|---|---|
| **Defecto** | Está dentro de lo especificado: se corrige en la pasada incluida |
| **Cambio** | Es alcance nuevo: se cotiza aparte y no consume la contingencia |
| **Aceptado como está** | Se registra y se cierra |

Sin esa clasificación una ronda no cierra nunca, y es el mecanismo por el que un
proyecto de siete fases se convierte en catorce.

**No incluido:** segundas rondas por cambios de alcance, rediseños visuales
posteriores a la aprobación del RDS, y capacitación más allá de la prevista en F7.

---

## Definition of Done

Común a todas las fases:

1. RF de la fase implementados y reflejados en [`BACKLOG.md`](BACKLOG.md) con
   estado P/A justificado.
2. Tests Pest en verde para **todos** los CA marcados A; los P documentan qué falta
   y en qué fase cierran.
3. Pint sin diferencias y Larastan sin hallazgos en el nivel acordado.
4. `composer audit` y `npm audit --audit-level=high` sin severidad alta.
5. Migraciones expansivas, o con rollback documentado y ensayado en staging.
6. Auditoría emitiendo eventos para toda acción nueva, con lista de redacción
   verificada por test y **atomicidad verificada** por la prueba de rollback.
7. Mensajes en lenguaje de negocio, español de Argentina, fechas DD/MM/AAAA, metros.
8. **Ningún color, espaciado, radio, sombra ni familia tipográfica literal.** Toda
   pantalla nueva revisada en **ambos temas**.
9. Desplegado en staging con `scripts/verificar-despliegue.sh` en verde.
10. Una ronda de UAT registrada, con observaciones clasificadas y defectos
    corregidos.
11. Documentación actualizada y CI sin regresiones.

Añadidos por fase: **F0** cerró con la matriz publicada, G2 resuelta, el throttle
probado contra su endpoint real, el RDS pasando AA por script y el arnés Playwright
con casos de humo. **F1** exige el round-trip de coordenadas en verde y el
invariante `ST_Contains(geometry, representative_point)` en los tres tipos de
geometría. **F2**, el ciclo de fotos probado incluyendo fallo y reintento. **F3**,
que ORS caído no impida cargar una obra. **F4**, WCAG AA con axe y recorrido por
teclado en los dos temas, el contrato de rendimiento cumplido, y prueba de que un
campo oculto desaparece de la respuesta **y del caché**. **F5**, CA-022a–g y la
corrida de 8 h cumpliendo los seis umbrales de memoria. **F6**, que ninguna ruta
administrativa sea alcanzable por un usuario Obras Públicas. **F7**, restauración
de backup completada y cronometrada dentro del RTO, ejercitando la custodia de la
clave.

---

## Desviaciones aprobadas respecto de EF-OPR-001

| # | Desviación | Sección afectada |
|---|---|---|
| D1 | MariaDB 10.11.18 en lugar de MySQL 8.4 LTS | 11.1, 11.2 |
| D2 | Dos columnas de fecha de fin en lugar de una | 3.1, 3.3, 9.2, CA-004 |
| D3 | Bandera `is_final` en `work_statuses` | 3.3, RF-OBR-005/006/009 |
| D4 | RDS como capa de estilos, sin Tailwind; tema oscuro construido | 6.4, 7.3, 8.2 |
| D5 | Criterios nuevos CA-022a…g para el recorrido de LIVE | 15.5 |
| D6 | Sondeos más cortos (Web 30 s, LIVE 15 s), **dentro** del presupuesto | 6.1, 7.1, RF-BO-010 |

D6 no relaja nada: acorta el sondeo para poder **cumplir** el presupuesto de
propagación.

---

## Fuera de alcance (spec 16)

Multi-municipio · presupuesto o contratista · workflow de aprobación · importación
y exportación · API pública para terceros · 2FA · recuperación automática por
email · videos o documentos adjuntos · cálculo de superficie de polígonos · mapas
offline · apps móviles nativas.
