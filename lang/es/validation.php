<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| Mensajes de validación
|---------------------------------------------------------------------------
|
| Sin este archivo la aplicación muestra las CLAVES de los mensajes —el usuario
| lee «validation.required» en lugar de «El nombre es obligatorio»—, porque el
| idioma es `es` (ver APP_LOCALE) y Laravel sólo trae el juego en inglés. No es
| un detalle cosmético: un formulario que responde con una clave técnica es un
| formulario que no se puede completar.
|
| Los nombres de los campos NO se traducen acá salvo excepción: cada controlador
| pasa su propio `:attribute` legible en la tercera posición de `validate()`,
| que es donde se ve el contexto y se puede elegir bien la palabra.
|
*/

return [
    'accepted' => 'Tenés que aceptar el campo :attribute.',
    'accepted_if' => 'Tenés que aceptar el campo :attribute cuando :other es :value.',
    'active_url' => 'El campo :attribute no es una dirección web válida.',
    'after' => 'El campo :attribute tiene que ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute tiene que ser una fecha igual o posterior a :date.',
    'alpha' => 'El campo :attribute sólo puede contener letras.',
    'alpha_dash' => 'El campo :attribute sólo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute sólo puede contener letras y números.',
    'any_of' => 'El campo :attribute no es válido.',
    'array' => 'El campo :attribute tiene que ser una lista.',
    'array_keys' => 'El campo :attribute sólo admite estas claves: :values.',
    'ascii' => 'El campo :attribute sólo puede contener caracteres alfanuméricos y símbolos de un byte.',
    'base64' => 'El campo :attribute tiene que ser una cadena Base64 válida.',
    'before' => 'El campo :attribute tiene que ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute tiene que ser una fecha igual o anterior a :date.',
    'between' => [
        'array' => 'El campo :attribute tiene que tener entre :min y :max elementos.',
        'file' => 'El campo :attribute tiene que pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute tiene que estar entre :min y :max.',
        'string' => 'El campo :attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute tiene que ser sí o no.',
    'can' => 'El campo :attribute contiene un valor no permitido.',
    'confirmed' => 'El campo :attribute no coincide con la confirmación.',
    'contains' => 'Al campo :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'date_equals' => 'El campo :attribute tiene que ser una fecha igual a :date.',
    'date_format' => 'El campo :attribute no coincide con el formato :format.',
    'decimal' => 'El campo :attribute tiene que tener :decimal decimales.',
    'declined' => 'Tenés que rechazar el campo :attribute.',
    'declined_if' => 'Tenés que rechazar el campo :attribute cuando :other es :value.',
    'different' => 'El campo :attribute y :other tienen que ser distintos.',
    'digits' => 'El campo :attribute tiene que tener :digits dígitos.',
    'digits_between' => 'El campo :attribute tiene que tener entre :min y :max dígitos.',
    'dimensions' => 'Las dimensiones de la imagen de :attribute no son válidas.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'doesnt_contain' => 'El campo :attribute no puede contener ninguno de estos valores: :values.',
    'doesnt_end_with' => 'El campo :attribute no puede terminar con ninguno de estos valores: :values.',
    'doesnt_start_with' => 'El campo :attribute no puede empezar con ninguno de estos valores: :values.',
    'email' => 'El campo :attribute no es una dirección de correo válida.',
    'encoding' => 'El campo :attribute tiene que estar codificado en :encoding.',
    'ends_with' => 'El campo :attribute tiene que terminar con alguno de estos valores: :values.',
    'enum' => 'La opción elegida en :attribute no es válida.',
    'exists' => 'La opción elegida en :attribute no existe.',
    'extensions' => 'El campo :attribute tiene que tener alguna de estas extensiones: :values.',
    'file' => 'El campo :attribute tiene que ser un archivo.',
    'filled' => 'El campo :attribute no puede quedar vacío.',
    'gt' => [
        'array' => 'El campo :attribute tiene que tener más de :value elementos.',
        'file' => 'El campo :attribute tiene que pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser mayor que :value.',
        'string' => 'El campo :attribute tiene que tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo :attribute tiene que tener :value elementos o más.',
        'file' => 'El campo :attribute tiene que pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute tiene que ser mayor o igual que :value.',
        'string' => 'El campo :attribute tiene que tener :value caracteres o más.',
    ],
    'hex_color' => 'El campo :attribute tiene que ser un color hexadecimal válido.',
    'image' => 'El campo :attribute tiene que ser una imagen.',
    'in' => 'La opción elegida en :attribute no es válida.',
    'in_array' => 'El campo :attribute tiene que existir en :other.',
    'in_array_keys' => 'El campo :attribute tiene que contener al menos una de estas claves: :values.',
    'integer' => 'El campo :attribute tiene que ser un número entero.',
    'ip' => 'El campo :attribute tiene que ser una dirección IP válida.',
    'ipv4' => 'El campo :attribute tiene que ser una dirección IPv4 válida.',
    'ipv6' => 'El campo :attribute tiene que ser una dirección IPv6 válida.',
    'json' => 'El campo :attribute tiene que ser una cadena JSON válida.',
    'list' => 'El campo :attribute tiene que ser una lista.',
    'lowercase' => 'El campo :attribute tiene que estar en minúsculas.',
    'lt' => [
        'array' => 'El campo :attribute tiene que tener menos de :value elementos.',
        'file' => 'El campo :attribute tiene que pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser menor que :value.',
        'string' => 'El campo :attribute tiene que tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo :attribute no puede tener más de :value elementos.',
        'file' => 'El campo :attribute no puede pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser menor o igual que :value.',
        'string' => 'El campo :attribute no puede tener más de :value caracteres.',
    ],
    'mac_address' => 'El campo :attribute tiene que ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El campo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo :attribute no puede tener más de :max dígitos.',
    'mimes' => 'El campo :attribute tiene que ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute tiene que ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute tiene que tener al menos :min elementos.',
        'file' => 'El campo :attribute tiene que pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser al menos :min.',
        'string' => 'El campo :attribute tiene que tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo :attribute tiene que tener al menos :min dígitos.',
    'missing' => 'El campo :attribute no puede estar presente.',
    'missing_if' => 'El campo :attribute no puede estar presente cuando :other es :value.',
    'missing_unless' => 'El campo :attribute no puede estar presente salvo que :other sea :value.',
    'missing_with' => 'El campo :attribute no puede estar presente si :values está presente.',
    'missing_with_all' => 'El campo :attribute no puede estar presente si :values están presentes.',
    'multiple_of' => 'El campo :attribute tiene que ser múltiplo de :value.',
    'not_in' => 'La opción elegida en :attribute no es válida.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => 'El campo :attribute tiene que ser un número.',
    'password' => [
        'letters' => 'El campo :attribute tiene que contener al menos una letra.',
        'mixed' => 'El campo :attribute tiene que contener al menos una mayúscula y una minúscula.',
        'numbers' => 'El campo :attribute tiene que contener al menos un número.',
        'symbols' => 'El campo :attribute tiene que contener al menos un símbolo.',
        'uncompromised' => 'El campo :attribute apareció en una filtración de datos conocida. Elegí otra.',
    ],
    'present' => 'El campo :attribute tiene que estar presente.',
    'present_if' => 'El campo :attribute tiene que estar presente cuando :other es :value.',
    'present_unless' => 'El campo :attribute tiene que estar presente salvo que :other sea :value.',
    'present_with' => 'El campo :attribute tiene que estar presente si :values está presente.',
    'present_with_all' => 'El campo :attribute tiene que estar presente si :values están presentes.',
    'prohibited' => 'El campo :attribute no está permitido.',
    'prohibited_if' => 'El campo :attribute no está permitido cuando :other es :value.',
    'prohibited_if_accepted' => 'El campo :attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => 'El campo :attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => 'El campo :attribute no está permitido salvo que :other esté entre :values.',
    'prohibits' => 'El campo :attribute impide que :other esté presente.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => 'El campo :attribute tiene que incluir entradas para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => 'El campo :attribute es obligatorio salvo que :other esté entre :values.',
    'required_with' => 'El campo :attribute es obligatorio si :values está presente.',
    'required_with_all' => 'El campo :attribute es obligatorio si :values están presentes.',
    'required_without' => 'El campo :attribute es obligatorio si :values no está presente.',
    'required_without_all' => 'El campo :attribute es obligatorio si no está presente ninguno de :values.',
    'same' => 'El campo :attribute y :other tienen que coincidir.',
    'size' => [
        'array' => 'El campo :attribute tiene que tener :size elementos.',
        'file' => 'El campo :attribute tiene que pesar :size kilobytes.',
        'numeric' => 'El campo :attribute tiene que ser :size.',
        'string' => 'El campo :attribute tiene que tener :size caracteres.',
    ],
    'starts_with' => 'El campo :attribute tiene que empezar con alguno de estos valores: :values.',
    'string' => 'El campo :attribute tiene que ser texto.',
    'timezone' => 'El campo :attribute tiene que ser una zona horaria válida.',
    'unique' => 'El campo :attribute ya está en uso.',
    'uploaded' => 'No se pudo subir el campo :attribute.',
    'uppercase' => 'El campo :attribute tiene que estar en mayúsculas.',
    'url' => 'El campo :attribute tiene que ser una dirección web válida.',
    'ulid' => 'El campo :attribute tiene que ser un ULID válido.',
    'uuid' => 'El campo :attribute tiene que ser un UUID válido.',

    /*
    | Mensajes propios por campo y regla. Se usa poco a propósito: cuando un
    | mensaje necesita explicar una regla de negocio, el lugar es el servicio que
    | la hace cumplir, no una tabla de textos lejos del código que la decide.
    */
    'custom' => [
        'password' => [
            'min' => 'La contraseña tiene que tener al menos :min caracteres.',
        ],
    ],

    /*
    | Nombres legibles de campos, como respaldo. Cada controlador pasa los suyos
    | en la propia llamada a `validate()`, que es donde se ve el contexto; esto
    | cubre las validaciones que no los declaran.
    */
    'attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de la contraseña',
        'current_password' => 'contraseña actual',
        'role' => 'rol',
    ],
];
