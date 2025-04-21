<?php
// Bootstrap de la aplicación - Inicialización y configuración

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use App\Adapters\ConfigAdapter;
use App\Adapters\LibreDteAdapter;
use App\Services\BoletaService;
use App\Services\FacturaService;
use App\Services\EnvioMultipleService;
use App\Controllers\BoletaController;
use App\Controllers\FacturaController;
use App\Controllers\EnvioMultipleController;
use App\Services\NotaCreditoService;
use App\Controllers\NotaCreditoController;

// Activar todos los errores para desarrollo
ini_set('display_errors', '1'); // Usar '1' en lugar de true
ini_set('display_startup_errors', '1'); // Mostrar errores de inicio
error_reporting(E_ALL);

// Zona horaria
date_default_timezone_set('America/Santiago');

// Crear el contenedor de dependencias
$container = new Container();

// Registrar servicios en el contenedor
$container->set(ConfigAdapter::class, function() {
    try {
         return new ConfigAdapter(__DIR__ . '/../config/config.php');
    } catch (\Exception $e) {
         error_log("Error fatal al inicializar ConfigAdapter: " . $e->getMessage());
         die("Error de configuración inicial. Revise los logs."); 
    }
});

$container->set(LibreDteAdapter::class, function($c) {
    return new LibreDteAdapter($c->get(ConfigAdapter::class)); 
});

// Registrar servicios para Boletas
$container->set(BoletaService::class, function($c) {
    return new BoletaService(
        $c->get(LibreDteAdapter::class) 
    );
});

// Registrar controlador para Boletas
$container->set(BoletaController::class, function($c) {
    return new BoletaController(
        $c->get(BoletaService::class)
    );
});

// Registrar servicios para Facturas
$container->set(FacturaService::class, function($c) {
    return new FacturaService(
        $c->get(LibreDteAdapter::class) 
    );
});

// Registrar controlador para Facturas
$container->set(FacturaController::class, function($c) {
    return new FacturaController(
        $c->get(FacturaService::class)
    );
});

// Registrar el servicio de envío múltiple
$container->set(EnvioMultipleService::class, function($c) {
    return new EnvioMultipleService(
        $c->get(LibreDteAdapter::class),
        $c->get(ConfigAdapter::class)
    );
});

// Registrar el controlador de envío múltiple
$container->set(EnvioMultipleController::class, function($c) {
    return new EnvioMultipleController(
        $c->get(EnvioMultipleService::class)
    );
});

// Registrar servicios para Notas de Crédito
$container->set(App\Services\NotaCreditoService::class, function($c) {
    return new App\Services\NotaCreditoService(
        $c->get(App\Adapters\LibreDteAdapter::class) 
    );
});

// Registrar controlador para Notas de Crédito
$container->set(App\Controllers\NotaCreditoController::class, function($c) {
    return new App\Controllers\NotaCreditoController(
        $c->get(App\Services\NotaCreditoService::class)
    );
});



// Crear la aplicación Slim con el contenedor
AppFactory::setContainer($container);
$app = AppFactory::create();

// Configuración de middleware
$app->addRoutingMiddleware(); // Necesario para el enrutamiento
$app->addBodyParsingMiddleware(); // Para parsear JSON, form data, etc.

// Middleware de Error: El orden importa. Debe ser uno de los últimos en añadirse (o el último).
// El primer argumento (displayErrorDetails) debe ser false en producción.
// El tercer argumento (logErrors) debe ser true para registrar errores.
$errorMiddleware = $app->addErrorMiddleware(true, true, true); 

// Habilitar CORS para desarrollo (o configuración más específica para producción)
// Este middleware debe ir ANTES del enrutamiento si maneja peticiones OPTIONS
$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response; // Permite preflight requests
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    // Orígenes permitidos (ser más restrictivo en producción)
    $allowedOrigins = ['*']; // Cambiar a ['http://tu-frontend.com'] en producción
    $origin = $request->getHeaderLine('Origin');

    if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
         $response = $response->withHeader('Access-Control-Allow-Origin', $origin ?: '*');
    }
    
    return $response
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS') // Añadir PATCH si es necesario
        ->withHeader('Access-Control-Allow-Credentials', 'true'); // Si usas cookies/sesiones
});


// Definir rutas
$app->group('/api/v1', function (RouteCollectorProxy $group) {
    
    // Rutas para facturas
    $group->post('/facturas', FacturaController::class . ':emitir')->setName('factura.emitir');
    $group->post('/facturas/preview', FacturaController::class . ':preview')->setName('factura.preview');
    // Ruta para JSON de impresión térmica de facturas
    $group->post('/facturas/json_impresion', FacturaController::class . ':jsonParaImpresion')->setName('factura.jsonParaImpresion');

    // Rutas para notas de crédito
    $group->post('/notas_credito', App\Controllers\NotaCreditoController::class . ':emitir')->setName('nota_credito.emitir');
    $group->post('/notas_credito/preview', App\Controllers\NotaCreditoController::class . ':preview')->setName('nota_credito.preview');
    // Ruta para JSON de impresión térmica de notas de crédito
    $group->post('/notas_credito/json_impresion', App\Controllers\NotaCreditoController::class . ':jsonParaImpresion')->setName('nota_credito.jsonParaImpresion');


    // Rutas para boletas
    $group->post('/boletas', BoletaController::class . ':emitir')->setName('boleta.emitir');
    $group->post('/boletas/preview', BoletaController::class . ':preview')->setName('boleta.preview');
    //Ruta para JSON de impresión térmica de boletas
    $group->post('/boletas/json_impresion', BoletaController::class . ':jsonParaImpresion')->setName('boleta.jsonParaImpresion');

    //Ruta boletas multiples
    $group->post('/boletas/multiple', EnvioMultipleController::class . ':emitirMultiple')->setName('boleta.emitirMultiple');
    
     // Ruta de prueba básica
     $group->get('/status', function ($request, $response, $args) {
        $response->getBody()->write(json_encode(['status' => 'ok', 'timestamp' => date('c')]));
        return $response->withHeader('Content-Type', 'application/json');
    })->setName('api.status');
});
// Ruta raíz para verificar que la API está funcionando
$app->get('/', function ($request, $response, $args) {
    $response->getBody()->write(json_encode(['message' => 'API LibreDTE v1 activa']));
    return $response->withHeader('Content-Type', 'application/json');
});


// Ejecutar la aplicación
$app->run();