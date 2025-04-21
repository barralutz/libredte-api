<?php
// Inicia el servidor de desarrollo de PHP para la API desacoplada

// Define la ruta al directorio raíz de la API (donde está index.php y vendor/)
$apiDir = __DIR__ . '/api';

// Verifica que el directorio existe
if (!is_dir($apiDir)) {
    echo "Error: El directorio de la API '{$apiDir}' no existe." . PHP_EOL;
    exit(1);
}

// Verifica que index.php existe
if (!file_exists($apiDir . '/index.php')) {
     echo "Error: El archivo 'index.php' no se encontró en '{$apiDir}'." . PHP_EOL;
    exit(1);
}


// Define el puerto para el servidor (puedes usar una variable de entorno)
$port = getenv('API_PORT') ?: 8005;
$host = getenv('API_HOST') ?: 'localhost';

// Muestra mensaje informativo
echo "Iniciando servidor de desarrollo PHP para la API LibreDTE..." . PHP_EOL;
echo "Directorio raíz: {$apiDir}" . PHP_EOL;
echo "Escuchando en: http://{$host}:{$port}" . PHP_EOL;
echo "Presiona Ctrl+C para detener el servidor." . PHP_EOL . PHP_EOL;

// Comando para iniciar el servidor
// -S <host>:<port> : Define la dirección y puerto
// -t <directorio> : Define el directorio raíz (document root)
// <router_script> : Especifica un script router (index.php en este caso)
$command = sprintf(
    'php -S %s:%d -t %s %s',
    escapeshellarg($host),
    (int)$port,
    escapeshellarg($apiDir), // El directorio raíz es /api
    escapeshellarg($apiDir . '/index.php') // El script router está dentro de /api
);

// Ejecuta el comando
passthru($command);