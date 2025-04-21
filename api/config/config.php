<?php
// Configuración de la aplicación

return [
    'app' => [
        'name' => 'LibreDTE API',
        'debug' => true,
    ],
    'sii' => [
        'default_ambiente' => 'certificacion', // 'certificacion' o 'produccion'
        'certificacion' => [
            'servidor' => 'maullin'
        ],
        'produccion' => [
            'servidor' => 'palena'
        ]
    ],
    'storage' => [
        // Directorio temporal base. ConfigAdapter añadirá un ID único si no se especifica uno aquí.
        // O puedes definir una ruta fija si prefieres y manejar la limpieza externamente.
        'temp_dir' => sys_get_temp_dir() . '/libredte_api_tmp', 
        'permanent_dir' => __DIR__ . '/../storage' // Para archivos que no deben borrarse
    ]
];