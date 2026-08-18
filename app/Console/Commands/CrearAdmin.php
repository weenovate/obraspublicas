<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Users\UserManager;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Alta del primer Administrador, fuera de la interfaz (RF-AUT-002).
 *
 * No hay autorregistro ni un formulario público de instalación: el primer Admin
 * se crea con acceso al servidor, que es el control real. De ahí en adelante los
 * administradores se gestionan desde el backoffice.
 *
 * La contraseña NUNCA se pasa como argumento de línea de comandos: quedaría en el
 * historial del shell y en la lista de procesos, donde la ve cualquiera con
 * acceso a la máquina. Se pide por prompt oculto, o por variable de entorno para
 * los despliegues automatizados.
 */
final class CrearAdmin extends Command
{
    protected $signature = 'obras:crear-admin
                            {--email= : Correo del administrador}
                            {--name= : Nombre completo}
                            {--temporal : Marcar la contraseña como temporal y exigir cambio al ingresar}';

    protected $description = 'Crea el primer Administrador del sistema (RF-AUT-002).';

    public function handle(UserManager $users): int
    {
        $email = mb_strtolower((string) ($this->option('email') ?: $this->ask('Correo electrónico')));
        $name = (string) ($this->option('name') ?: $this->ask('Nombre completo'));

        // Variable de entorno para despliegues desatendidos; prompt oculto para
        // el uso normal. En ningún caso un argumento.
        //
        // Se lee por `config()` y no por `env()` directo: con la configuración
        // cacheada —que es lo normal en producción— `env()` devuelve null y el
        // comando quedaría pidiendo una contraseña por prompt en medio de un
        // despliegue automatizado.
        $password = (string) (config('obras.admin_initial_password')
            ?: $this->secret('Contraseña (mínimo 12 caracteres)'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // La validación `unique` de arriba comprueba y después inserta, y entre
        // las dos cosas hay una ventana. Dos ejecuciones simultáneas del comando
        // —dos procesos de un arnés en paralelo, o dos personas a la vez— pasan
        // las dos la comprobación y la segunda choca contra el índice único.
        //
        // Quien cierra la carrera es el índice, no la validación; acá sólo se
        // traduce el choque al mismo mensaje que ya da la validación. Sin esto,
        // el comando muere con un `SQLSTATE` y una traza en pantalla por algo
        // que tiene una explicación de una línea.
        try {
            $user = $users->create(
                name: $name,
                email: $email,
                role: User::ROLE_ADMIN,
                temporaryPassword: $password,
            );
        } catch (UniqueConstraintViolationException) {
            $this->components->error('El campo correo electrónico ya está en uso.');

            return self::FAILURE;
        }

        // Si no se pidió temporal, la contraseña es definitiva: quien corrió el
        // comando la eligió, no hay nadie más que la conozca.
        if (! $this->option('temporal')) {
            $user->forceFill([
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();
        }

        $this->components->info("Administrador «{$email}» creado.");

        if ($this->option('temporal')) {
            $this->components->warn('La contraseña es temporal: se pedirá cambiarla al ingresar.');
        }

        // El hash tampoco se muestra: no hay motivo para que aparezca en pantalla.
        $this->line('  Verificá el ingreso en /login antes de cerrar esta terminal.');

        return self::SUCCESS;
    }
}
