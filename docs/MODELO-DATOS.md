# Modelo de datos

13 tablas (spec sección 9). En F0 existen **dos**, las definitivas de esta
iteración: `users` y `audit_events`, más `sessions`, `cache` y `jobs` que necesitan
los drivers. Las 11 restantes son de F1 y están bloqueadas por G3.

Política de esquema: **expansiva**. Se agrega, no se rompe. Renombrados y borrados
de columnas en dos releases, para que un rollback siga funcionando contra el
esquema nuevo.

---

## Existentes (F0)

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
| `theme_preference` | enum(`light`, `dark`, `system`) | `system` es un estado real, no ausencia de elección (RF-CFG-004) |
| `last_login_at` | timestamp nullable | |
| `password_changed_at` | timestamp nullable | |

`role`, `is_active` y `must_change_password` **no son asignables en masa**: se
cambian por métodos del dominio y con auditoría, no porque llegaron en un
formulario (RNF-SEC-003).

No hay tabla de tokens de recuperación por email: está fuera de alcance (spec 16).

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

## Pendientes (F1)

`work_categories` · `work_subcategories` · `work_statuses` · `work_field_definitions`
· `work_field_options` · `works` · `work_field_values` · `work_photos` ·
`system_sequences` · `app_settings` · `auth_sessions`

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

### Catálogos: qué se puede cambiar y qué no

La regla general (RF-CAT-005) es que **lo referenciado no se elimina, se
desactiva**. Cada catálogo tiene además una trampa propia:

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

---

## Convenciones transversales

- Fechas operativas en `America/Argentina/Buenos_Aires`; timestamps técnicos en UTC.
- Sin *hard deletes* fuera del servicio de papelera y su transacción auditada.
- `work_field_values` con columnas tipadas y validación de que **exactamente una**
  coincide con `data_type` (spec 9.3).
- `$fillable` explícito en todo modelo; `$guarded = []` está prohibido y un test de
  arquitectura lo verifica.
