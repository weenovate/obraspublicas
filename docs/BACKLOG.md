# Backlog y trazabilidad

Fuente de verdad del avance. **P** = verificado parcialmente; **A** = aceptado y
cerrado.

Un criterio se cierra sólo cuando **todo** su enunciado es verificable de punta a
punta. CA-001 exige aparición en LIVE en 30 s y en Web en 60 s, así que no puede
cerrarse antes de F5 por más que el alta funcione en F1. Marcarlo antes sería
convertir el backlog en una lista de deseos.

---

## Estado por fase

| Fase | Alcance | P | A |
|---|---|---|---|
| **F0** ✅ | Fundaciones, tooling, CI, seguridad base, login mínimo con throttle auditado, RDS con tema oscuro medido y primitivos Vue, arnés Playwright, PoC espacial y matriz | — | — |
| **F1-A** ✅ | Nueve migraciones y seeders, auth completa, roles y políticas, CRUD de usuarios, sesiones revocables, los cinco catálogos con sus reglas de inmutabilidad, campos técnicos, configuración tipada, generador de códigos | 002, 024 | **014, 025** |
| **F1-B** ✅ | CRUD de obras con geometría manual, fechas, concurrencia optimista, papelera lógica, editores cartográficos | 001, 007, 010, 015, 018 | **002, 003, 004, 008, 009** |
| **F2** ◐ | Ciclo completo de fotos, formulario dinámico, cambio incompatible, galería con estados | 013 | **010, 011, 012** |
| **F3** | Nominatim, geocodificación inversa, pin móvil, ruta ORS con previsualización y fallback, límites municipales, E2E de editores | — | **005, 006, 007** |
| **F4** | SPA, capas, filtros, clustering, URL compartible, allowlist, contrato de rendimiento, caché versionado, tema, puente Leaflet completo | 001 | **013, 019, 020, 021** |
| **F5** | Kiosco, sesión persistente, recorrido automático, persistencia local, reconexión | 024 | **001, 015, 022, 022a–g, 023** |
| **F6** | Papelera completa, borrado definitivo por tipeo exacto, UI de auditoría con diff y filtros | — | **016, 017, 018, 024** |
| **F7** | Seed de 10.000 obras, performance medida, WCAG 2.2 AA, rate limiting completo, backups y restauración cronometrada, despliegue, capacitación | — | **026, 027** |

---

## Lo que F0 dejó verificado

F0 no cierra criterios de aceptación de negocio —su entregable es
infraestructura—, pero sí deja verificaciones permanentes en la suite. Estas son
las que otras fases van a apoyarse encima:

| Verificación | Dónde | Sostiene |
|---|---|---|
| Round-trip de ejes contra el motor: `ST_X` = longitud, `ST_Y` = latitud, SRID 4326 | `tests/Feature/Geo` | RF-GEO-005, y todo F1/F3 |
| `Location\Coordinate` sólo se instancia en el adaptador | `tests/Arch` | La convención `[lon, lat]` |
| `ST_Length` no aparece en el dominio | `tests/Arch` | RF-GEO-011 |
| Vincenty a ±1 mm contra oráculo analítico | `tests/Unit/Geo` | RF-GEO-011 |
| Fallback de Vincenty registrado en el método | `tests/Unit/Geo` | RF-GEO-011 |
| Invariante `ST_Contains(geometry, representative_point)` en los tres tipos | `tests/Feature/Geo` | RF-GEO-014 |
| Uso real del índice SPATIAL en los dos modos (`EXPLAIN`) | `tests/Feature/Geo` | RNF-PER-001 |
| Transacción de negocio revertida **no** deja evento | `tests/Feature/Audit` | RF-AUD-001 |
| Intento denegado dentro de una transacción revertida **sí** deja evento | `tests/Feature/Audit` | CA-014 |
| `audit_events` rechaza UPDATE y DELETE, incluso por SQL directo | `tests/Feature/Audit` | RF-AUD-002 |
| Redacción de secretos en cualquier anidamiento | `tests/Feature/Audit` | RF-CFG-003 |
| Argon2id efectivo en el hash guardado | `tests/Feature/Auth` | RNF-SEC-002 |
| 429 tras agotar el límite, incluso con contraseña correcta | `tests/Feature/Auth` | RNF-SEC-004 |
| Respuesta uniforme: no revela si el correo existe | `tests/Feature/Auth` | RNF-SEC-004 |
| Login exitoso auditado por `registrar()`, tras regenerar la sesión | `tests/Feature/Auth` | RF-AUD-001 |
| Cabeceras de seguridad y CSP en toda respuesta | `tests/Feature/Auth`, `tests/e2e` | RNF-SEC-001 |
| Contraste AA en 76 pares, en los dos temas | `npm run rds:contraste` | RNF-ACC-001, CA-025 |
| Los tres estados de tema, incluidos los cruzados | `tests/e2e/tema.spec.js` | RF-THE-001/002, RF-CFG-004 |
| Controles de Leaflet legibles en oscuro | `tests/e2e/tema.spec.js` | RF-THE-002 |
| Tipografías descargadas sin 404 | `tests/e2e/humo.spec.js`, `npm run rds:fuentes` | RNF-LOC-001, RNF-PER-002 |

---

## Lo que F1-A dejó verificado

| Verificación | Dónde | Sostiene |
|---|---|---|
| Un usuario Obras Públicas recibe 403 en las seis rutas administrativas | `tests/Feature/Auth/AuthorizationTest.php` | **CA-014** |
| El intento denegado queda auditado **sin** cuerpo, consulta ni datos protegidos | `tests/Feature/Auth/AuthorizationTest.php` | **CA-014**, RF-AUD-001 |
| El tema elegido se lee de la base en otra sesión de navegador, con dos contextos reales | `tests/e2e/tema-por-usuario.spec.js` | **CA-025** |
| El tema viene estampado en el HTML antes de la primera pintura, y vacío = seguir al dispositivo | `tests/Feature/Tema` | RF-CFG-004/005, RF-THE-001 |
| Dos desactivaciones concurrentes no pueden dejar el sistema sin Admin, con dos conexiones reales | `tests/Feature/Users` | RF-AUT-005 |
| Desactivar revoca las sesiones y audita las dos cosas en la misma transacción | `tests/Feature/Users` | RF-USR-003, RF-AUD-001 |
| Dos transacciones simultáneas no pueden recibir el mismo código de obra | `tests/Feature/Obras` | RF-OBR-001…004, sostiene CA-002 |
| El generador se niega a trabajar fuera de una transacción | `tests/Unit/Work` | RF-OBR-002 |
| Cada regla de inmutabilidad de catálogo, en las dos direcciones, con papelera incluida | `tests/Feature/Catalogos` | RF-CAT-003/004/005, RF-DIN-004, RF-OBR-008 |
| El contraste medido en PHP coincide con el de Node hasta 1e-9, y los fondos siguen siendo los de los tokens | `tests/Feature/Color` | RF-CAT-003, RNF-ACC-001 |
| La configuración rechaza claves libres, valores fuera de rango y no declara ningún secreto | `tests/Feature/Configuracion` | RF-CFG-001/002/003 |
| Los mensajes de validación llegan traducidos, no como claves | `tests/Feature/Idioma` | Usabilidad del backoffice (ADR-023) |

### Y lo que dejó G3

| Verificación | Dónde | Sostiene |
|---|---|---|
| El recorte del IGN conserva su hash exacto | `tests/Feature/Geo/RecorteIgnTest.php` | El dataset congelado por versión |
| Una sola entidad, y es el partido, no la localidad homónima | ídem | La identidad del dato |
| SRID 4326 y ejes en `[longitud, latitud]` medidos por valor | ídem | RF-GEO-005, ADR-003 |
| Validez topológica compuesta, sin `ST_IsValid` | ídem | ADR-010 |
| La superficie es la de un partido, no la de su envolvente | ídem | Que no se haya sustituido el polígono por un rectángulo |
| Centro, bbox y viewbox salen del polígono y no de memoria | ídem | El encuadre del mapa y el sesgo de geocodificación |
| Las obras de prueba caen dentro del partido | ídem | Fixtures con coordenadas reales |

**CA-002 queda en P**: la secuencia atómica está probada con dos transacciones
concurrentes, pero su enunciado habla de dos altas de obra, y el alta llega en
F1-B.

---

## Lo que F1-B dejó verificado

| Verificación | Dónde | Sostiene |
|---|---|---|
| El punto representativo elegido cumple `ST_Contains` **contra MariaDB** en punto, línea de dos vértices, línea de varios, línea en U, polígono convexo y cóncavo | `tests/Feature/Obras/GeometriaDeObraTest.php` | **CA-003**, RF-GEO-014, ADR-025 |
| El punto medio aritmético queda contenido sólo 54 veces de 200, y un vértice las 200 | ídem | ADR-025, y que la decisión no se revierta por intuición |
| El vértice se elige por longitud acumulada, no por índice | ídem | Que el punto caiga en la mitad del recorrido y no en un racimo de vértices |
| Tipo contra modo, ejes, anillos cerrados y clics repetidos, rechazados con mensaje de negocio | ídem | RF-GEO-005, RF-CAT-004 |
| Dos ALTAS de obra reciben códigos distintos, y borrar una no devuelve su número | `tests/Feature/Obras/AltaDeObraTest.php` | **CA-002** completo |
| Un alta rechazada no deja obra, ni evento, ni consume secuencia | ídem | RF-OBR-002, RF-AUD-001 |
| La fecha efectiva sale de `is_final` —no de la clave `COMPLETED`— y se recalcula al cambiar sólo el estado | ídem | **CA-008**, ADR-008, D3 |
| La fecha real se conserva al salir de un estado finalizador y deja de gobernar la efectiva | ídem | ADR-008, y que `COALESCE` habría estado mal |
| La segunda de dos ediciones simultáneas se rechaza y no pisa, sin dejar auditoría | ídem | **CA-009**, ADR-026 |
| La baja lógica deja `deleted_by` además de `deleted_at`, y la obra sigue contando como uso del catálogo | ídem | **CA-004**, RF-DEL-001 |
| Los dos roles pueden cargar obras | `tests/Feature/Obras/PantallaDeObrasTest.php` | Matriz de permisos, spec 2.2 |
| La geometría vuelve al editor idéntica a como se dibujó, sin invertir ejes | ídem | ADR-003, RF-GEO-005 |
| Las excepciones de dominio llegan como error de formulario, no como 500 | ídem | Usabilidad del backoffice |
| Se dibuja punto, línea y polígono con el mouse, se deshace un vértice y se guarda | `tests/e2e/obras.spec.js` | **CA-003**, RF-GEO-001…005 |
| El ciclo completo: dibujar, guardar, reabrir con la geometría dibujada, mandar a papelera | ídem | **CA-004** |
| Con el catálogo vacío el formulario avisa qué falta, en vez de un botón que no hace nada | `tests/Feature/Obras/PantallaDeObrasTest.php`, `tests/e2e/obras.spec.js` | Usabilidad de una instalación nueva |

**CA-002 y CA-003 se cierran acá.** CA-004, CA-008 y CA-009 también: la papelera
lógica, la semántica de fechas y la concurrencia están verificadas de punta a punta.

**Lo que sigue en P y por qué:** CA-001 necesita la aparición en LIVE y en la Web
(F5 y F4). CA-007 necesita el trazado asistido sobre calles (F3). CA-010 necesita
las fotos (F2). CA-015 y CA-018 son de F5 y F6.

**CA-024 queda en P**: revocar una sesión desde administración funciona, corta el
acceso en la petición siguiente y queda auditado. Falta la mitad que necesita
pantallas: la sesión persistente de LIVE y lo que ve el kiosco al ser revocado,
que son de F5, y la vista de auditoría con la que se revisa, que es de F6.

---

## Criterios de aceptación nuevos (D5) — recorrido de LIVE

Complementan CA-022, que sólo cubre el caso feliz. Se verifican en F5.

| ID | Escenario |
|---|---|
| CA-022a | Orden `updated_at DESC, id DESC` estable entre sondeos, con empates deterministas |
| CA-022b | Una obra editada durante el recorrido no reinicia la secuencia ni se saltea |
| CA-022c | Una obra enviada a papelera desaparece y el recorrido continúa sin error |
| CA-022d | Cambiar filtros pausa 60 s y reinicia la secuencia sobre el conjunto nuevo |
| CA-022e | Con una obra no oscila; con cero, estado vacío con hora de última sincronización |
| CA-022f | Intervalos de 5 y 120 s se aplican sin recargar; fuera de rango se rechazan en servidor |
| CA-022g | 8 h continuas cumpliendo los seis umbrales cuantitativos de memoria, sin pasos saltados y sin pérdida de sesión |

### Los seis umbrales de la prueba de memoria (CA-022g)

«Sin fuga de memoria» no es verificable, así que lleva números. Con el intervalo por
omisión de 12 s, ocho horas son unos **2.400 pasos**. Línea base a los 15 minutos,
no al arrancar, para que cachés y primeros ciclos estén estabilizados. Muestreo cada
5 minutos.

| Métrica | Criterio |
|---|---|
| Montículo de JavaScript | ≤ **15 %** sobre la línea base tras recolección forzada, y sin tendencia creciente en las últimas 3 h. Techo absoluto **400 MB** |
| Nodos del DOM | ≤ línea base **+ 200 nodos**. El estado estacionario debe ser plano |
| Capas de Leaflet | ≤ línea base **+ 5 %**, volviendo a su meseta en cada ciclo |
| Nodos desprendidos | Sin tendencia de crecimiento entre la hora 1 y la 8 |
| Respuesta al final | Desplazamiento y zoom responden; un paso se completa dentro del intervalo, sin pasos saltados |
| Sesión | Sin reingreso ni pérdida de filtros o capas (CA-023) |

Superar cualquiera es un **defecto**, no una conversación sobre tolerancias.

En CI corre una variante acelerada de ~30 min con intervalo de 1 s (~1.800 pasos)
con los mismos criterios proporcionalmente, para detectar regresiones por commit.
La corrida completa se ejecuta una vez en F5 y otra antes del pase a producción.

---

## Nuevos criterios derivados de las fechas (ADR-008)

A incorporar por enmienda al spec, verificables en F1:

- Pasar a un estado finalizador **exige** `actual_end_date` y rechaza fecha futura.
- Volver a un estado no finalizador **no borra** el dato y el cambio queda auditado.
- Un estado propio marcado como finalizador se comporta igual que `COMPLETED`.
- El filtro por rango usa el intervalo efectivo en las tres superficies.

---

## Lo que F2 lleva verificado

El ciclo de fotografías está completo; los campos técnicos dinámicos en el
formulario de obra siguen pendientes.

| Verificación | Dónde | Sostiene |
|---|---|---|
| La foto se guarda en `PENDING` y el archivo existe **antes** de encolar | `tests/Feature/Fotos/CicloDeFotoTest.php` | ADR-019, y que el job no corra una carrera propia |
| Los derivados salen a 1600 y 400 px conservando la proporción | ídem | La galería y la ficha |
| Una foto más chica que el derivado **no se agranda** | ídem | Que `scaleDown` no invente información |
| Un archivo corrupto queda `FAILED` con motivo legible, sin filtrar rutas ni trazas | ídem | Diagnóstico sin exponer el servidor |
| Reprocesar deja el mismo resultado, sin duplicar filas ni archivos | ídem | **La idempotencia de ADR-019** |
| El job ignora una foto ya `READY` y no explota si la borraron | ídem | Reintentos de cola seguros |
| **La obra sigue publicada aunque todas sus fotos fallen** | ídem | ADR-019 contra la sección 14 del spec |
| El archivo **no** es alcanzable sin firma, ni con sesión iniciada | `tests/Feature/Fotos/PantallaDeFotosTest.php` | **RNF-SEC-005** |
| Una firma manipulada —mismo token, otro identificador— se rechaza | ídem | Que la URL no sea adivinable |
| Una foto que no llegó a `READY` no se sirve, aunque la URL esté firmada | ídem | ADR-019 |
| El tamaño se restringe en la ruta: no se puede pedir un archivo arbitrario | ídem | RNF-SEC-003 |
| Subir, ver la miniatura procesada y quitarla, en el navegador | `tests/e2e/fotos.spec.js` | **CA-010, CA-011, CA-012** |

**Falta para cerrar F2:** los campos técnicos dinámicos en el formulario de obra,
con el manejo del cambio incompatible. Las definiciones existen desde F1-A; lo
que no existe todavía es el render y la carga de valores.
