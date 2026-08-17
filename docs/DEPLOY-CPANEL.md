# Runbook de despliegue — cPanel / AlmaLinux

## Estructura de releases

```
~/app/
├── releases/
│   ├── 20260817T1200/          ← release completo, INCLUYE public/build ya compilado
│   └── 20260818T0930/
├── shared/
│   ├── .env                    ← symlink desde cada release
│   └── storage/                ← symlink desde cada release
└── current -> releases/20260818T0930
```

**`public/build` va dentro de cada release, nunca compartido.** Vite emite nombres
con hash más un `manifest.json`, y ese par es una unidad indivisible: un `build`
compartido serviría el manifiesto de un release contra los assets de otro, con
fallos intermitentes, y rompería el rollback justo cuando más se lo necesita. Sólo
`.env` y `storage` se comparten, porque son estado.

**Vite compila en CI** y el release sube ya construido, así que cPanel no necesita
Node.

---

## Verificaciones de una sola vez, antes del primer despliegue

Cada una es un ítem que puede fallar según cómo esté configurado el hosting. **No
son supuestos**: hay que comprobarlas y anotar el resultado.

| # | Verificación | Si falla |
|---|---|---|
| 1 | **PHP 8.4 seleccionado en MultiPHP** para el dominio | Nada funciona: el proyecto requiere 8.4 |
| 2 | Extensiones presentes: `gd`, `exif`, `fileinfo`, `pdo_mysql`, `intl`, `zip` | Sin `gd` no hay procesamiento de fotos. `imagick` **no** se usa |
| 3 | **Document root apunta a `~/app/current/public`** | Ver abajo |
| 4 | `AllowOverride` suficiente para que el `.htaccess` aplique el rewrite | Toda ruta que no sea la raíz devuelve 404 |
| 5 | Privilegio **`TRIGGER`** para el usuario de base | Los disparadores de inmutabilidad de `audit_events` no se crean: queda como riesgo residual con dos capas en lugar de tres |
| 6 | Privilegios de tabla afinados sobre `audit_events`: `INSERT` y `SELECT`, sin `UPDATE` ni `DELETE` | cPanel otorga `ALL` desde su interfaz, así que el ajuste va por SQL con un usuario privilegiado. Si el hosting no lo permite, riesgo residual documentado |
| 7 | Cron disponible cada minuto | Sin cola ni tareas programadas |
| 8 | `MARIADB` versión **10.11.18** (`SELECT VERSION()`) | La matriz espacial no aplica |

### Sobre el document root (verificación 3)

- **Para subdominios**, cPanel permite fijarlo directamente en
  `~/app/current/public`: es la opción preferida.
- **Para el dominio principal** el document root es `public_html`, así que hay dos
  caminos: moverlo si el hosting lo permite, o dejar `public_html` como
  **symlink**. El symlink exige `Options +FollowSymLinks` o
  `SymLinksIfOwnerMatch`, y algunas configuraciones de cPanel lo rechazan. **Es un
  ítem de verificación, no un supuesto.**

`~/app/current/public` contiene `index.php`, `.htaccess`, favicon, `robots.txt` y
`build/`. Al `.htaccess` se le agrega `Options -Indexes`, denegación de dotfiles y
caché larga para `build/`, cuyos nombres tienen hash.

**No se ejecuta `storage:link`.** Las fotos son privadas y se sirven por controlador
con URL firmada; un symlink de `storage` en el document root abriría lo que
RNF-SEC-005 quiere cerrado.

---

## Despliegue

```bash
# 1. Subir el release ya construido a ~/app/releases/<timestamp>/
# 2. Enlazar el estado compartido
ln -nfs ~/app/shared/.env      ~/app/releases/<ts>/.env
ln -nfs ~/app/shared/storage   ~/app/releases/<ts>/storage

# 3. Migraciones (expansivas: se agregan, no rompen el código anterior)
cd ~/app/releases/<ts> && php artisan migrate --force

# 4. Swap atómico
ln -nfs ~/app/releases/<ts> ~/app/current

# 5. Invalidar y recachear
cd ~/app/current
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 6. Reciclar PHP-FPM o limpiar opcache  ← PASO OBLIGATORIO
#    Con opcache y realpath cache activos, un cambio de symlink puede seguir
#    sirviendo las rutas del release anterior.

# 7. Verificar
./scripts/verificar-despliegue.sh
```

### Rollback

Repuntar `current` al release anterior y repetir los pasos 5 y 6: segundos, sin
tocar datos. Es seguro **porque** la política de esquema es expansiva.

---

## Cron

No hay supervisord, así que la cola va por cron cada minuto:

```cron
* * * * * cd ~/app/current && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
* * * * * cd ~/app/current && php artisan schedule:run >> /dev/null 2>&1
```

Driver `database`, sin Redis.

---

## Backups

Ver ADR-017. Lo esencial en forma operativa:

| Credencial | Dónde vive | Permisos |
|---|---|---|
| Escritura habitual | En el VPS, en `.env` | **`PutObject` únicamente.** Sin `GetObject`, sin `DeleteObject`, sin `ListBucket` |
| Lectura para restauración | **Fuera del VPS**, en poder de la Municipalidad | `GetObject`, `ListBucket` |

La **retención de 30 días la aplica el lifecycle del bucket**, no el servidor. Por
eso la credencial de escritura no lleva `DeleteObject`: un atacante con acceso al
VPS no puede leer ni destruir el historial.

Con versionado y object lock disponibles, se activan con período de retención no
mayor al del lifecycle.

**Cifrado del lado del cliente antes de subir**, con `age` y clave pública de
destinatario. El VPS guarda sólo la clave pública, así que **no puede descifrar sus
propios backups**. Alcanza a la base y a `storage/app/fotos`.

**Custodia de la clave privada**: la conserva la Municipalidad —nunca el
repositorio, nunca el VPS, nunca `.env`— en el gestor de contraseñas más una copia
offline sellada en otra ubicación física, con **dos custodios nombrados**.
Procedimiento de recuperación escrito; rotación anual y ante cambio de personal.

**Integridad sin leer**: la suma de comprobación se calcula localmente antes de
subir y se confirma en la respuesta del `PutObject`. Es la única verificación
coherente con una credencial de sólo escritura, y alcanza para detectar una subida
truncada o corrupta.

RPO 24 h · RTO 8 h (RNF-RES-001).

### Restauración trimestral en staging (CA-027)

Con runbook, cronómetro y acta firmada. Valida cuatro cosas:

1. Que el objeto está.
2. Que la **credencial de lectura** es accesible para quien debe tenerla.
3. Que la **clave privada** permite descifrar.
4. Que el tiempo total entra en el RTO de 8 h.

**Una prueba que no ejercita las dos custodias no prueba nada:** si nadie puede leer
o nadie puede descifrar, el backup no existe.

---

## `scripts/verificar-despliegue.sh`

Corre en cada release y **falla el despliegue** si:

- `/.env`, `/composer.json`, `/vendor/`, `/storage/logs/laravel.log` o `/.git/`
  responden algo distinto de 403/404
- hay listado de directorios
- falta la redirección a HTTPS o la cabecera HSTS
- `APP_DEBUG` está activo
- el document root efectivo no resuelve dentro de `current/public`
- una ruta profunda devuelve 404, señal de que el `.htaccess` no se aplica
- `/health` no responde OK

> Este script se implementa en F7, junto con el despliegue real. Los ítems están
> definidos acá para que el runbook no dependa de la memoria de quien lo escribió.

---

## Monitoreo

`/health` chequea base, almacenamiento y cola. Alertas por errores, espacio en
disco, **backup faltante o fallido**, suma de comprobación discordante, y
acumulación de trabajos fallidos.

**Sin destinatarios definidos las alertas no existen**: definirlos es una tarea de
la Municipalidad (ver [`INFRAESTRUCTURA.md`](INFRAESTRUCTURA.md)).

---

## Kiosco (LIVE)

Tareas operativas, no automatizables desde la aplicación:

- Suspensión y protector de pantalla **desactivados**
- Arranque automático del navegador en la URL de LIVE
- Recuperación tras corte de energía
- Resguardo físico del equipo (riesgo de spec 17)

La especificación técnica del dispositivo es la compuerta **G4**.
