# Infraestructura, responsables y costos recurrentes

**No hay cifras acá, a propósito.** Dependen del proveedor que se elija y del
tráfico real, y un número inventado en un documento se convierte después en un
compromiso. Lo que sí hay es **qué factor determina el costo** y **quién debe
cotizarlo**, que es lo que permite decidir.

---

## Matriz de servicios

| Servicio | Opciones | Responsable | Factor de costo | Notas |
|---|---|---|---|---|
| **Hosting producción** | VPS AlmaLinux + cPanel existente | Municipalidad / proveedor de hosting | Ya contratado | Verificar PHP 8.4 en MultiPHP y privilegios para cron y grants |
| **Staging** | Subdominio en el mismo cPanel con base y usuario propios, o segundo plan | Municipalidad | Incremental bajo si comparte VPS | Compartir VPS es aceptable en v1, pero **la carga de staging afecta a producción**: las pruebas de carga de F7 van en ventana acordada o en un plan separado |
| **Teselas** | Proveedor contratado (MapTiler, Stadia, Mapbox…) o teselas propias | Municipalidad contrata; desarrollo integra | Cargas de mapa por mes | El servidor público de OSM **no es opción** (spec 11.2, T1). **Las pantallas LIVE son el consumidor dominante**: exhibición continua con recentrado genera muchísimas más solicitudes que los visitantes. Dimensionar con 10 pantallas × horas de exhibición, no con 200 visitantes |
| **OpenRouteService** | Plan gratuito con cuotas, plan pago, o instancia propia | Municipalidad contrata | Solicitudes de trazado por día y por minuto | Uso bajo: sólo al editar líneas viales, no en consulta. **Validar que sus términos admitan uso institucional** (spec T7) antes de depender de él. El fallback manual (RF-GEO-008) hace que agotar la cuota degrade el servicio sin romperlo |
| **Geocodificación** | Nominatim público, instancia propia, o proveedor pago | Municipalidad decide | Solicitudes por segundo | El público es gratis pero limita a 1 req/s, exige identificación y prohíbe autocompletado (spec T2), todo ya contemplado |
| **Backup externo** | Almacenamiento de objetos compatible con S3, **cuenta distinta del hosting** | Municipalidad contrata y paga | GB almacenados × 30 días + egreso en restauraciones | Dominado por el crecimiento de fotos: hasta 10 por obra a 10 MB |
| **Dominio y certificado** | AutoSSL de cPanel | Municipalidad | Incluido | |
| **Monitoreo y alertas** | Servicio de uptime + alertas de backup | Municipalidad define destinatarios | Suele alcanzar un plan gratuito | **Sin destinatario definido, las alertas no existen** |
| **Dispositivo de kiosco** | TV o pantalla + equipo | Municipalidad | Hardware, único | Especificación pendiente en G4 |

---

## Reparto de responsabilidades

- **Municipalidad**: titular de todas las cuentas y pagos, custodia la clave privada
  de backups y la credencial de lectura del bucket, y define destinatarios de
  alertas.
- **Proveedor de hosting**: sostiene cPanel, MariaDB y cron.
- **Equipo de desarrollo**: entrega código, despliegue y runbooks.

**Ninguna cuenta de proveedor queda a nombre del equipo de desarrollo.** Eso evita
que la plataforma dependa de una persona.

---

## Tareas operativas recurrentes de la Municipalidad

No son automatizables por el equipo de desarrollo, y sin dueño asignado no
ocurren.

| Tarea | Periodicidad |
|---|---|
| Custodia de la clave privada de backups **y** de la credencial de lectura del bucket, ambas fuera del VPS: dos custodios nombrados, copia en gestor de contraseñas y copia offline sellada | Permanente; rotación anual y ante cambio de personal |
| Configuración del lifecycle de 30 días en el bucket, y del object lock si el proveedor lo ofrece | Al crear el bucket; revisión anual |
| Titularidad y pago de cuentas: teselas, ORS, geocodificación, almacenamiento de objetos | Permanente |
| Rotación de tokens de teselas y claves de ORS | Anual y ante incidente |
| Participación en la prueba de restauración y firma del acta | **Trimestral** |
| Revisión de la matriz de visibilidad pública antes de habilitar la URL y después de cada cambio | Por evento (spec 18) |
| Operación del kiosco: suspensión y protector desactivados, arranque automático, recuperación tras corte, resguardo físico | Permanente |
| Ciclo de vida de usuarios: altas, bajas y revocación de sesiones LIVE ante cambios de personal | Por evento |
| Actualización de la versión del límite del IGN cuando cambien los límites oficiales | Por evento |
| Destinatarios de alertas definidos, y actuación ante alerta de backup fallido | Permanente |
| Designar participantes de UAT por fase y firmar la aceptación | Por fase |

---

## Ambientes

| Ambiente | Base | Notas |
|---|---|---|
| Desarrollo | `mariadb:10.11.18` en Docker, puerto 3307 | Más una instancia en tmpfs en 3308 para la suite |
| Staging | Base y usuario propios en el mismo cPanel | Donde se hace la UAT de cada fase y se ensaya cada restauración |
| Producción | MariaDB del VPS | |

La versión del motor es la misma en los tres. Ver [`ARQUITECTURA.md`](ARQUITECTURA.md)
ADR-001.

---

## Nota sobre el entorno de construcción

La política de egreso del entorno donde se ejecutó F0 bloquea, con 403:

- `production.cloudfront.docker.com` — CDN de blobs de Docker Hub. La imagen se
  obtuvo por un espejo permitido (`mirror.gcr.io`). `docker-compose.yml` referencia
  la imagen canónica, que es lo correcto en cualquier entorno normal.
- `api.github.com` y `codeload.github.com` para descargas *dist* de Composer.
- `ign.gob.ar`, `datos.gob.ar`, `nominatim.openstreetmap.org`, `overpass-api.de`.

Los dos últimos grupos explican por qué **G3 no pudo cerrarse acá** y por qué el
oráculo de distancias es analítico en lugar de basarse en vectores publicados
(ADR-012). Ninguna de estas limitaciones afecta al producto ni al despliegue.
