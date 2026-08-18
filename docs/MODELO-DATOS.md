# Modelo de datos

13 tablas (spec sección 9). Con F2 están **las trece**: `work_photos` fue la
última en entrar. Además están `sessions`, `cache` y `jobs`, que necesitan los
drivers.

`works` y `work_field_values` entraron en F1-A **como esquema, no como CRUD**: las
reglas de catálogo dependen de saber si algo *está en uso*, y sin esas tablas la
regla no se puede hacer cumplir ni testear. El alta de obras con geometría es de
F1-B y sigue bloqueada por G3.

Política de esquema: **expansiva**. Se agrega, no se rompe. Renombrados y borrados
de columnas en dos releases, para que un rollback siga funcionando contra el
esquema nuevo.

---

## Acceso y usuarios

### `users`

| Columna | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `name` | string | |
| `email` | string único | |
| `password` | string(255) | Hash **Argon2id**, no bcrypt (RNF-SEC-002) |
| `role` | enum(`ADMIN`, `OBRAS_PUBLICAS`) | Dos roles, sin jerarquía intermedia |
| `is_active` | bool | Desactivado no puede ingresar; sus sesiones se revocan en cascada. **No se borra**: sus eventos deben seguir apuntando a alguien |
| `must_change_password` | bool | Contraseña temporal puesta por el Admin |
| `theme_preference` | enum(`light`, `dark`) **nullable** | Vacío = no eligió, y entonces manda `app_settings.default_theme` (RF-CFG-004/005). Ver ADR-021 |
| `last_login_at` | timestamp nullable | |
| `password_changed_at` | timestamp nullable | |

`role`, `is_active` y `must_change_password` **no son asignables en masa**: se
cambian por métodos del dominio y con auditoría, no porque llegaron en un
formulario (RNF-SEC-003).

No hay tabla de tokens de recuperación por email: está fuera de alcance (spec 16).

### `auth_sessions`

Sesiones revocables, incluidas las persistentes de las pantallas LIVE
(RF-AUT-006/007, RF-USR-003).

| Columna | Nota |
|---|---|
| `session_id` | El identificador de la sesión de Laravel. Es lo que permite cortar **esta** sesión y no todas |
| `device_label`, `ip_address`, `user_agent` | Para que el usuario reconozca desde dónde está conectado |
| `is_persistent` | Las de LIVE no vencen por inactividad: una pantalla de exhibición que se cierra sola a las 8 h es una pantalla que alguien tiene que ir a reiniciar cada mañana |
| `last_seen_at` | Base del vencimiento por inactividad (RF-AUT-006) |
| `revoked_at`, `revoked_reason`, `revoked_by_user_id` | El motivo es parte del registro, no un detalle: `LOGOUT`, `USER_DEACTIVATED`, `PASSWORD_CHANGED`, `ADMIN_REVOKED`, `INACTIVITY` |

Una fila marcada como revocada no desconecta a nadie por sí sola: el middleware
`sesion.activa` la revisa en cada petición. Sin eso, «revocar» sería escribir una
fecha y esperar.

### `audit_events`

| Columna | Nota |
|---|---|
| `occurred_at` | UTC. Es un timestamp técnico, no una fecha operativa |
| `user_id` | nullable, `nullOnDelete` |
| `actor_email`, `actor_role` | Desnormalizados: una bitácora que pierde de quién fue la acción no sirve |
| `action` | Verbo del dominio, no nombre de método |
| `entity_type`, `entity_id` | Polimórfico y laxo: los eventos de seguridad no tienen entidad |
| `ip_address`, `user_agent`, `request_id` | `request_id` cruza el evento con el log estructurado |
| `before_json`, `after_json`, `metadata_json` | Ya redactados; la lista de redacción se verifica por test |
| `is_failed_attempt` | Marca los eventos del camino no transaccional |

**Inmutable en tres capas:** privilegios de tabla (runbook), disparadores
`SIGNAL SQLSTATE '45000'` en `BEFORE UPDATE`/`BEFORE DELETE`, y guardas de modelo.
Ver ADR-004.

---

## Catálogos y configuración

### `work_categories` · `work_subcategories` · `work_statuses`

| Tabla | Columnas que no son obvias |
|---|---|
| `work_categories` | `slug` único —participa de URLs compartibles—, `icon` del registro sólo-agregar, `color` validado por contraste contra **los dos** temas antes de guardar (RF-CAT-003) |
| `work_subcategories` | `geometry_mode` (`POINT`, `LINE_ROUTED_ROAD`, `LINE_MANUAL_NETWORK`, `POLYGON`) y `routing_profile`, que sólo tiene sentido en los modos de línea |
| `work_statuses` | `key` estable e `is_final`; `is_system` marca los cinco base, que no se eliminan. `CANCELLED` **no** es finalizador y **no** restringe transiciones posteriores |

### `work_field_definitions` · `work_field_options`

`scope_type` + `scope_id` es polimórfico a mano y no con la convención de Laravel:
los dos únicos alcances posibles —categoría o subcategoría— están cerrados por el
spec, y una relación polimórfica genérica invitaría a agregar un tercero sin
pensarlo.

El `code` es único **dentro de su alcance**, no globalmente: dos categorías
distintas pueden tener cada una su campo `superficie`. `public_visible` y
`live_visible` arrancan en `false`: un campo se publica cuando alguien lo decide,
no por omisión.

**La unión de alcances y su desempate (F2).** Una obra presenta los campos de su
categoría más los de su subcategoría, sin códigos duplicados. Ante el mismo
código en los dos alcances **gana la subcategoría**: quien definió ahí un campo
que la categoría ya tenía lo hizo para afinarlo, no para duplicarlo, y el
criterio contrario haría inútil definir un campo específico. Lo resuelve
`App\Support\Fields\WorkFieldSet`.

**Los valores fuera de alcance se conservan (ADR-027).** Si una obra cambia de
subcategoría, los valores de campos que dejan de aplicar no se borran: quedan
guardados, dejan de mostrarse y reaparecen si la obra vuelve. Distinto es vaciar
un campo que **sigue** a la vista, que sí borra su valor —ahí vaciar es corregir—.

### `system_sequences` · `app_settings`

`system_sequences` es una fila por secuencia con su `current_value`; hoy la única
es `work_code`. `app_settings` es clave/valor **tipado**: `data_type` acompaña al
valor y el catálogo de claves está cerrado en `App\Support\Settings\AppSettings`.
No admite claves libres (RF-CFG-001) y no contiene ni un solo secreto: esos se
inyectan por entorno (RF-CFG-003), y hay un test que falla si aparece una clave
que parezca uno.

---

## Obras

El esquema existe desde F1-A; el CRUD con geometría es de F1-B. Lo de abajo son los
invariantes que ese CRUD tiene que hacer cumplir.

Las coordenadas de referencia —centro, bbox y viewbox— salen del recorte oficial
del IGN, congelado por hash en `database/geo/` (ADR-024, compuerta G3), y se leen
de `config('obras.mapa')`. Los fixtures de la suite usan el centroide del partido,
comprobado dentro del polígono: ya no hay coordenadas inventadas en el proyecto.

### Invariantes de `works` que ya están decididos

- **`geometry` `GEOMETRY NOT NULL` y `representative_point` `POINT NOT NULL`**,
  **sin** atributo SRID de columna: la sintaxis de MySQL 8 no parsea y el
  `REF_SYSTEM_ID` de MariaDB se acepta pero **no rechaza** un SRID distinto
  (medido en P2). El 4326 se impone en cada escritura y se verifica con `ST_SRID`.
- **Índice SPATIAL en las dos columnas**, porque las dos se interrogan según el
  modo de consulta (ADR-007). Un índice creado pero ignorado no cumple RNF-PER-001,
  así que la suite incluye aserciones de `EXPLAIN`.
- **`ST_Contains(geometry, representative_point)` antes de persistir.** Si falla, el
  guardado se rechaza. Vale también para líneas.
- **Cómo se elige ese punto**, según el tipo (ADR-009 y ADR-025):
  punto → el punto; polígono → `ST_PointOnSurface`, que lo calcula la base;
  **línea → un vértice suyo**, el más cercano a la mitad del recorrido medido por
  longitud geodésica. Para líneas el motor devuelve NULL en `ST_PointOnSurface` y
  en `ST_Centroid`, y el punto medio aritmético **no** queda contenido de forma
  confiable: 54 de 200 segmentos medidos.
- El tipo de geometría debe coincidir con el modo de la subcategoría, validado en
  la aplicación.

### Fechas (ADR-008)

| Columna | Regla |
|---|---|
| `start_date` | |
| `estimated_end_date` | Siempre obligatoria, ≥ `start_date`. **Nunca se sobrescribe** al finalizar. Puede ser futura. |
| `actual_end_date` | **Obligatoria** cuando el estado tiene `is_final = true`; ≥ `start_date` y ≤ hoy. Con `is_final = false` puede conservarse como valor histórico y **no participa** de la fecha efectiva. |
| `effective_end_date` | `DATE NOT NULL`, **derivada y materializada** por `CASE WHEN status.is_final THEN actual ELSE estimated END`. Se recalcula en cada guardado. |

`effective_end_date` está materializada porque evaluar ese `CASE` sobre un join en
cada filtro impide usar un índice. Como es derivada, necesita guardas: test de
invariante y comando `obras:verificar-integridad`.

### Longitud

`length_m DECIMAL(14,2)` obligatorio y positivo **sólo** para líneas, `NULL` en
punto y polígono, con `length_calc_method` (`VINCENTY` | `HAVERSINE_FALLBACK`).
Nunca se guarda un resultado no convergido como si fuera exacto (ADR-012).

### Código

`code` y `sequence_number` únicos e **inmutables**, con guardas en el modelo además
del índice. `OBR-YYYY-XXXX` con secuencia global atómica
(`SELECT … FOR UPDATE` sobre `system_sequences`) dentro de la transacción; nunca
reutiliza ni decrementa.

El generador existe desde F1-A (`App\Support\Work\WorkCodeGenerator`) y **exige**
estar dentro de una transacción: fuera de una, el bloqueo se libera antes de que el
alta confirme y dos altas simultáneas podrían recibir el mismo número. Que el
bloqueo funciona se prueba con dos conexiones reales, no con un mock. **CA-002
queda en P**: su enunciado habla de dos altas, y el alta de obra llega en F1-B.

---

## Catálogos: qué se puede cambiar y qué no

La regla general (RF-CAT-005) es que **lo referenciado no se elimina, se
desactiva**. Cada catálogo tiene además una trampa propia.

Estas reglas ya no son sólo documentación: viven en
`App\Support\Catalog\CatalogRules`, se invocan desde los controladores y están
fijadas una por una —en las dos direcciones, rechazo y permiso— en
`tests/Feature/Catalogos/ReglasDeInmutabilidadTest.php`.

| Catálogo | Inmutable una vez en uso | Por qué |
|---|---|---|
| Categoría | `slug` | Participa de URLs compartibles (RF-WEB-006) |
| Subcategoría | Categoría padre; **modo geométrico** | Cambiar `POINT` a `POLYGON` invalidaría la geometría de cada obra existente, y no hay conversión razonable. Incluye obras en papelera: restaurar tiene que dar un registro válido |
| Subcategoría | *Excepción:* `LINE_ROUTED_ROAD` ↔ `LINE_MANUAL_NETWORK` **sí** se permite | Ambos persisten `LINESTRING`; la diferencia es sólo si se ofrece trazado asistido. No toca ninguna geometría almacenada |
| Estado | clave interna; **`is_final`** | Cambiar `is_final` con obras asociadas cambia la semántica de fechas de obras existentes y desincronizaría `effective_end_date` |
| Definición de campo | código técnico; tipo de dato y alcance si hay valores | |
| Iconos | El registro es **sólo-agregar** | Quitar un identificador deja marcadores rotos en el mapa público. Una verificación de arranque falla si una categoría apunta a un icono inexistente |

Volver obligatorio un campo con obras previas **no invalida retroactivamente**: se
exige en las ediciones siguientes y se advierte cuáles quedan incompletas.

**«En uso» incluye la papelera.** Restaurar una obra tiene que devolver un registro
válido (RF-DEL-003), así que una subcategoría con obras borradas sigue estando en
uso aunque no aparezca en ningún listado. Todos los `isInUse()` de los modelos usan
`withTrashed()`, y hay tests que fallan si alguno deja de hacerlo: es el error más
fácil de cometer acá.

---

## Convenciones transversales

- Fechas operativas en `America/Argentina/Buenos_Aires`; timestamps técnicos en UTC.
- Sin *hard deletes* fuera del servicio de papelera y su transacción auditada.
- `work_field_values` con columnas tipadas y validación de que **exactamente una**
  coincide con `data_type` (spec 9.3).
- `$fillable` explícito en todo modelo; `$guarded = []` está prohibido y un test de
  arquitectura lo verifica.

---

## `work_photos`

La decimotercera tabla (F2, ADR-019).

| Columna | Tipo | Nota |
|---|---|---|
| `work_id` | FK | `cascadeOnDelete`: al eliminar definitivamente una obra se van sus fotos |
| `status` | enum | `PENDING` → `READY` \| `FAILED`. **Sólo `READY` se publica** |
| `original_filename` | string | Lo que subió la persona, para poder decirle cuál falló |
| `disk` | string | Guardado **por fila**: permite migrar a almacenamiento de objetos sin mover lo viejo |
| `path_original` | string | El archivo tal cual llegó |
| `path_large`, `path_thumb` | string, nullable | Derivados a 1600 y 400 px de lado mayor. **Columnas, no convención de nombres** |
| `mime_type`, `size_bytes` | | Del archivo recibido |
| `width`, `height` | int, nullable | Del original, leídos al procesar |
| `checksum_sha256` | char(64), nullable | Integridad, **no** deduplicación: no es único |
| `caption`, `sort_order` | | Lo único que edita una persona |
| `attempts`, `failure_reason`, `processed_at` | | Diagnóstico sin entrar al servidor |
| `uploaded_by`, `deleted_by` | FK users | Quién subió y quién quitó |
| `deleted_at` | | Baja lógica, igual que en `works` |

### Por qué los derivados son columnas y no un patrón de nombres

Deducir la ruta de la miniatura a partir de la del original ata el código a un
esquema de nombres que después no se puede cambiar sin migrar archivos. Con las
rutas guardadas, agregar un tamaño o cambiar de almacenamiento es una migración
de filas.

### Por qué el estado vive en la foto y no en la obra

La obra se publica de inmediato y cada foto se suma al llegar a `READY`
(ADR-019). Una falla de procesamiento no invalida datos ya guardados, que es
exactamente lo que pide la sección 14 del spec sin bloquear RF-BO-007.

### Dónde viven los archivos

`storage/app/private/fotos/{obra}/`, **fuera del document root**. Se sirven por
controlador con URL firmada (RNF-SEC-005); el despliegue no corre `storage:link`,
que publicaría el directorio entero y haría enumerable todo lo subido.
