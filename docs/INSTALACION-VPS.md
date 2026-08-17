# Instalación en un VPS nuevo — AlmaLinux 9 + cPanel/WHM

Instructivo de **primera instalación**, desde un servidor recién entregado hasta la
aplicación andando con su primer Administrador.

Para los despliegues siguientes —los del día a día, con swap atómico y rollback en
segundos— el documento es [`DEPLOY-CPANEL.md`](DEPLOY-CPANEL.md). Este de acá
cubre el paso cero que aquél da por hecho.

---

## Qué se puede desplegar hoy, y qué no

El proyecto está a un tercio del MVP. Lo que sube y funciona:

| Funciona | Todavía no existe |
|---|---|
| Ingreso, sesiones revocables, cambio de contraseña | Web pública del mapa (F4) |
| Usuarios y roles | Pantallas LIVE / kiosco (F5) |
| Los cinco catálogos y los campos técnicos | Fotografías (F2) |
| Configuración del sistema | Búsqueda de direcciones y trazado por calles (F3) |
| Alta, edición y papelera de obras, con editor cartográfico | Pantalla de auditoría (F6) |

Instalar ahora igual conviene: valida el servidor, la base y el correo mientras
todavía no hay presión, y deja el camino de despliegue ejercitado. Lo que **no**
hay que hacer todavía es publicar la URL: eso depende de la compuerta G5.

**Dos cosas que el runbook menciona y todavía no están construidas**, porque son de
F7: el script `scripts/verificar-despliegue.sh` y la ruta `/health`. Hasta
entonces, la verificación del paso 10 se hace a mano y está detallada acá.

---

## Paso 0 · Verificaciones bloqueantes, antes de tocar nada

Estas cuatro no son trámite. Si alguna falla, conviene resolverla **antes** de
seguir, porque después cuesta mucho más.

### 0.1 · La versión de MariaDB · **la más importante**

El proyecto está fijado a **MariaDB 10.11.18** (compuerta G1). No es capricho:
`docs/MATRIZ-ESPACIAL.md` documenta qué funciones espaciales trae ese build y
cuáles no —`ST_IsValid` no existe, `ST_PointOnSurface` devuelve NULL sobre
líneas—, y varias decisiones de arquitectura salen de ahí.

En **WHM → SQL Services → MySQL/MariaDB Upgrade** se ve la versión instalada. La
serie 10.11 es LTS y cPanel la ofrece; lo que puede variar es el número de parche.

Conectate y anotá el resultado exacto:

```sql
SELECT VERSION();
```

| Resultado | Qué hacer |
|---|---|
| `10.11.18` | Perfecto, seguí |
| Otro `10.11.x` | Aceptable. **Anotá la versión** y avisá: hay que re-correr las sondas de G2 contra ella para confirmar que la matriz sigue valiendo |
| `10.6.x`, `11.x` u otra serie | **Pará.** La matriz espacial no aplica. Hay que actualizar a 10.11 desde WHM, o revalidar el proyecto entero contra esa versión |

> La actualización de MariaDB en WHM es **de ida**: no se puede bajar de versión.
> Hacela con un backup completo tomado y verificado.

### 0.2 · PHP 8.4

`composer.json` pide 8.3 como mínimo; **el proyecto se desarrolla y se prueba en
8.4**, y esa es la que conviene instalar.

En **WHM → EasyApache 4 → Customize → PHP Versions**, marcá `ea-php84`. Después,
en **WHM → MultiPHP Manager**, asignale 8.4 al dominio.

### 0.3 · Extensiones de PHP

En EasyApache 4, sección **PHP Extensions**, tienen que estar:

```
gd  exif  fileinfo  pdo_mysql  intl  zip  mbstring  openssl
```

`imagick` **no** se usa —el procesamiento de fotos de F2 va sobre GD, igual que en
la CI—. No hace falta instalarlo.

### 0.4 · Composer disponible

```bash
composer --version
```

Si no está, se instala en el home del usuario, no a nivel sistema:

```bash
cd ~ && curl -sS https://getcomposer.org/installer | php
mkdir -p ~/bin && mv composer.phar ~/bin/composer && chmod +x ~/bin/composer
echo 'export PATH="$HOME/bin:$PATH"' >> ~/.bashrc && source ~/.bashrc
```

**Node no hace falta en el servidor.** Vite compila antes y el release sube ya
construido.

---

## Paso 1 · Cuenta y dominio

En **WHM → Account Functions → Create a New Account**, creá la cuenta con el
dominio o subdominio que corresponda.

**Recomendación fuerte: usá un subdominio.** Con subdominio, cPanel deja fijar el
document root donde uno quiere, que es exactamente lo que este despliegue necesita.
Con el dominio principal el document root es `public_html` y hay que resolverlo con
un symlink que algunas configuraciones de cPanel rechazan.

Después, **SSL**: en cPanel → **SSL/TLS Status**, ejecutá AutoSSL sobre el dominio.
Sin certificado válido la aplicación no debe habilitarse — las cookies van con
`SESSION_SECURE_COOKIE=true`.

---

## Paso 2 · Base de datos

En cPanel → **MySQL® Databases**:

1. Creá la base, por ejemplo `usuario_obras`.
2. Creá el usuario, por ejemplo `usuario_obras`, con una contraseña larga generada
   al azar. **Anotala en el gestor de contraseñas de la Municipalidad**, no en un
   archivo suelto.
3. Asignale el usuario a la base con **ALL PRIVILEGES**.

Anotá los tres valores: van al `.env` del paso 5.

### El privilegio `TRIGGER`

`audit_events` usa disparadores para impedir `UPDATE` y `DELETE` sobre la bitácora
(RF-AUD-002). Con `ALL PRIVILEGES` desde cPanel el privilegio suele estar incluido.
Se confirma después de migrar:

```sql
SHOW TRIGGERS LIKE 'audit_events';
```

Tienen que aparecer los disparadores. Si no están, la inmutabilidad queda apoyada
en dos capas en vez de tres: **anotalo como riesgo residual** y avisá.

---

## Paso 3 · Estructura de directorios

Por SSH, con el usuario de la cuenta:

```bash
mkdir -p ~/app/releases ~/app/shared/storage
```

`storage` completo se crea al subir el primer release; por ahora alcanza con el
directorio.

---

## Paso 4 · Construir el release

**Esto se hace en tu máquina o en CI, no en el VPS.** El servidor no necesita Node
ni las dependencias de desarrollo.

Desde una copia limpia del repositorio, en la rama `main`:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
```

Después armá el paquete, **sin** lo que no va al servidor:

```bash
TS=$(date +%Y%m%dT%H%M)
tar czf "release-$TS.tar.gz" \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='.env' \
  --exclude='storage/logs/*' \
  .
```

`vendor/` y `public/build/` **sí van adentro**: el servidor no los reconstruye.

---

## Paso 5 · Subir e instalar el primer release

Subí el `.tar.gz` por SFTP a `~/app/releases/` y descomprimilo:

```bash
cd ~/app/releases && mkdir -p 20260818T0930 && tar xzf release-*.tar.gz -C 20260818T0930
```

Movés `storage` al estado compartido —sólo la primera vez— y enlazás:

```bash
mv ~/app/releases/20260818T0930/storage/* ~/app/shared/storage/ 2>/dev/null
rm -rf ~/app/releases/20260818T0930/storage
ln -nfs ~/app/shared/storage ~/app/releases/20260818T0930/storage
```

### El `.env` de producción

Creá `~/app/shared/.env` a partir de `.env.example`, cambiando lo que sigue. Los
valores marcados **no son opcionales**: con los del ejemplo, la aplicación queda
insegura.

```dotenv
APP_ENV=production
APP_DEBUG=false                  # ← obligatorio. En true expone rutas y consultas
APP_URL=https://obras.tu-dominio.gob.ar

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=usuario_obras
DB_USERNAME=usuario_obras
DB_PASSWORD=<la del paso 2>

SESSION_SECURE_COOKIE=true       # ← obligatorio con HTTPS
SESSION_ENCRYPT=true
LOG_LEVEL=warning                # ← en producción, no debug

MAIL_MAILER=smtp                 # y los datos de la cuenta de correo de cPanel
```

Enlazalo y generá la clave:

```bash
ln -nfs ~/app/shared/.env ~/app/releases/20260818T0930/.env
cd ~/app/releases/20260818T0930 && php artisan key:generate
```

> `APP_KEY` cifra las sesiones. Se genera **una sola vez** y se conserva: si se
> pierde o se regenera, todas las sesiones se invalidan y cualquier dato cifrado
> deja de poder leerse.

---

## Paso 6 · Migrar, sembrar y crear el primer Administrador

```bash
cd ~/app/releases/20260818T0930

php artisan migrate --force
php artisan db:seed --class=CatalogoBaseSeeder --force
```

El seeder carga los cinco estados base. **Sin ellos no se puede dar de alta ninguna
obra**, y el formulario avisa que falta configurar el catálogo.

El primer Administrador se crea fuera de la interfaz (RF-AUT-002), y **la
contraseña nunca va como argumento** —quedaría en el historial del shell y en la
lista de procesos—:

```bash
php artisan obras:crear-admin --email=admin@ramallo.gob.ar --name="Nombre Apellido"
```

Te la pide por prompt oculto. Elegí una de 12 caracteres o más.

---

## Paso 7 · Document root y swap

```bash
ln -nfs ~/app/releases/20260818T0930 ~/app/current
```

En cPanel → **Domains**, apuntá el document root del subdominio a:

```
/home/<usuario>/app/current/public
```

Comprobalo con una petición real, no de memoria: la aplicación tiene que responder
en la raíz **y** en una ruta profunda como `/login`. Si la raíz anda y `/login` da
404, el `.htaccess` no se está aplicando y falta `AllowOverride` en la
configuración de Apache.

---

## Paso 8 · Cachés

```bash
cd ~/app/current
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Después **reciclá PHP-FPM** desde WHM → *Restart Services* → *PHP-FPM service*.
Con opcache activo, un cambio de symlink puede seguir sirviendo el release
anterior. Es el paso que más veces se olvida y el que produce los síntomas más
desconcertantes.

---

## Paso 9 · Cron

En cPanel → **Cron Jobs**, dos entradas cada minuto:

```cron
* * * * * cd ~/app/current && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
* * * * * cd ~/app/current && php artisan schedule:run >> /dev/null 2>&1
```

Hoy la cola casi no tiene trabajo; se vuelve necesaria en F2, con el procesamiento
de fotos. Conviene dejarla andando desde ahora para que no sea una sorpresa.

---

## Paso 10 · Verificación a mano

`scripts/verificar-despliegue.sh` llega en F7. Hasta entonces, comprobá estos ocho
puntos y anotá el resultado. Reemplazá `DOMINIO` por el tuyo.

| # | Comprobación | Esperado |
|---|---|---|
| 1 | `curl -I https://DOMINIO/` | `200`, y redirección desde `http://` |
| 2 | `curl -I https://DOMINIO/login` | `200` — si da 404, el `.htaccess` no aplica |
| 3 | `curl -I https://DOMINIO/.env` | `403` o `404`, **nunca 200** |
| 4 | `curl -I https://DOMINIO/composer.json` | `403` o `404` |
| 5 | `curl -I https://DOMINIO/storage/logs/laravel.log` | `403` o `404` |
| 6 | `curl -I https://DOMINIO/vendor/` | `403` o `404`, y sin listado |
| 7 | `curl -sI https://DOMINIO/ \| grep -i strict-transport` | La cabecera HSTS presente |
| 8 | Ingresar con el Administrador del paso 6 | Entra y ve el backoffice |

Si el punto 3, 4, 5 o 6 devuelve `200`, **no habilites el sitio**: está exponiendo
código o configuración.

Y una prueba funcional de punta a punta que vale más que las ocho: creá una
categoría, una subcategoría de tipo punto, y cargá una obra dibujándola en el mapa.
Si eso funciona, la base, la geometría y el editor están bien.

> El mapa va a mostrar el contorno del partido sin calles de fondo. Es lo
> esperado: falta contratar el proveedor de teselas, y hasta entonces el editor usa
> el recorte oficial del IGN como referencia.

---

## Rollback

Es la razón de toda la estructura de releases. Si algo sale mal:

```bash
ln -nfs ~/app/releases/<release-anterior> ~/app/current
cd ~/app/current && php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Y reciclá PHP-FPM. Son segundos y no toca datos.

Funciona **porque la política de migraciones es expansiva**: se agregan columnas y
tablas, no se rompen las que el código anterior usa. Por eso el release viejo sigue
andando contra la base nueva.

---

## Lo que queda pendiente de la Municipalidad

Nada de esto lo resuelve el código:

| Pendiente | Bloquea |
|---|---|
| Autorización escrita del RDS (**G5**) | Habilitar la URL pública |
| Cuenta del proveedor de teselas | Las calles de fondo en el mapa (F3, F4) |
| Cuenta de OpenRouteService | El trazado asistido por calles (F3) |
| Destinatarios de las alertas | Que el monitoreo sirva de algo |
| Custodios de la clave de backup | La restauración (ver ADR-017) |
| Especificación del televisor (**G4**) | La aceptación de LIVE (F5) |

---

## Si algo falla

| Síntoma | Causa más probable |
|---|---|
| Pantalla en blanco, sin error | Vistas compiladas viejas. `php artisan view:clear` y recachear |
| La raíz anda, las demás rutas dan 404 | `AllowOverride` insuficiente: el `.htaccess` no se aplica |
| `500` sin detalle | Mirá `~/app/shared/storage/logs/laravel.log`. **No** pongas `APP_DEBUG=true` en producción para investigar |
| Sigue sirviendo el release anterior | Falta reciclar PHP-FPM (paso 8) |
| «No se puede cargar una obra: falta configurar…» | Falta el seeder del paso 6 |
| Error de conexión a la base | `DB_HOST=127.0.0.1`, no `localhost`: en algunos cPanel `localhost` va por socket |
