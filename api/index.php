<?php
// Archivo principal de la API (Punto de entrada para el servidor web)

// Definir la ruta base de la aplicación
define('APP_ROOT', __DIR__);

// Cargar el autoloader de composer
$autoloader = require APP_ROOT . '/vendor/autoload.php';
if (!$autoloader) {
    http_response_code(500);
    echo json_encode(['error' => 'Dependencias de Composer no encontradas. Ejecute "composer install".']);
    exit(1);
}

// Cargar el archivo bootstrap que configura e inicia la aplicación Slim
try {
    require APP_ROOT . '/src/bootstrap.php';
} catch (\Throwable $e) {
    // Captura errores fatales durante el bootstrap
    http_response_code(500);
    // Muestra un error genérico en producción, detalles en logs
    $errorMessage = 'Error crítico durante la inicialización de la aplicación.';
    if (getenv('APP_ENV') !== 'production' && defined('E_ERROR')) { // Mostrar más detalles si no es producción
        $errorMessage .= ' Detalles: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
    }
    error_log($e); // Asegúrate de que los errores se registren
    echo json_encode(['error' => $errorMessage]);
    exit(1);
}


// La configuración y ejecución de la aplicación (`$app->run();`) se maneja dentro de bootstrap.php