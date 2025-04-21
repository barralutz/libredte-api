<?php

namespace App\Adapters;

use Exception;

/**
 * Adaptador para la configuración
 * 
 * Esta clase encapsula el acceso a la configuración
 * para desacoplar la API del sistema de configuración original
 */
class ConfigAdapter
{
    private $config;
    private $tempDir;
    
    /**
     * Constructor
     *
     * @param string $configFile Ruta al archivo de configuración (opcional)
     */
    public function __construct(string $configFile = null)
    {
        if ($configFile && file_exists($configFile)) {
            $this->config = require $configFile;
            // Use temp_dir from config if available, otherwise create a unique one
            $this->tempDir = $this->config['storage']['temp_dir'] ?? (sys_get_temp_dir() . '/libredte_api_' . uniqid());
        } else {
            // Configuración por defecto si no hay archivo
            $this->tempDir = sys_get_temp_dir() . '/libredte_api_' . uniqid();
            $this->config = [
                'app' => [
                    'name' => 'LibreDTE API',
                    'debug' => true,
                ],
                'sii' => [
                    'default_ambiente' => 'certificacion',
                    'certificacion' => [
                        'servidor' => 'maullin'
                    ],
                    'produccion' => [
                        'servidor' => 'palena'
                    ]
                ],
                'storage' => [
                    'temp_dir' => $this->tempDir,
                    // Add permanent_dir to default config as well
                    'permanent_dir' => __DIR__ . '/../storage' 
                ]
            ];
        }

        // Asegurarse de que el directorio temporal exista
        if (!is_dir($this->tempDir)) {
            if (!mkdir($this->tempDir, 0777, true)) {
                throw new Exception("No se pudo crear el directorio temporal: {$this->tempDir}");
            }
        }
        
        // Crear la estructura de directorios necesaria dentro del directorio temporal
        $this->createDirectories();
    }
    
    /**
     * Crea los directorios necesarios para la API dentro del directorio temporal
     */
    private function createDirectories(): void
    {
        $dirs = [
            '/data',
            '/data/certs',
            '/data/caf',
            '/data/caf/boletas',
            '/data/caf/facturas',
            '/data/caf/nota_credito',
            '/data/caf/nota_debito',
            '/data/docs',
            '/data/docs/boletas',
            '/data/docs/boletas/xml',
            '/data/docs/boletas/pdf',
            '/data/docs/facturas',
            '/data/docs/facturas/xml',
            '/data/docs/facturas/pdf',
            '/data/docs/nota_credito',
            '/data/docs/nota_credito/xml',
            '/data/docs/nota_credito/pdf',
            '/data/docs/nota_debito',
            '/data/docs/nota_debito/xml',
            '/data/docs/nota_debito/pdf',
        ];
        
        foreach ($dirs as $dir) {
            $path = $this->tempDir . $dir;
            if (!is_dir($path)) {
                if (!mkdir($path, 0777, true)) {
                    // Consider logging or throwing a more specific error if needed
                    error_log("Advertencia: No se pudo crear el subdirectorio temporal: {$path}");
                }
            }
        }
    }
    
    /**
     * Guarda un archivo en el directorio temporal
     *
     * @param string $content Contenido del archivo
     * @param string $subDir Subdirectorio relativo dentro del directorio temporal (ej: 'data/certs')
     * @param string $filename Nombre del archivo
     * @return string Ruta completa al archivo guardado
     * @throws Exception Si no se puede crear el subdirectorio o guardar el archivo
     */
    public function saveFile(string $content, string $subDir, string $filename): string
    {
        // Asegurar que el subdirectorio no empiece con / para evitar rutas absolutas accidentales
        $subDir = ltrim($subDir, '/'); 
        $dir = $this->tempDir . '/' . $subDir;
        
        // Crear el subdirectorio si no existe
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                 throw new Exception("No se pudo crear el subdirectorio para guardar el archivo: {$dir}");
            }
        }
        
        $filePath = $dir . '/' . $filename;
        if (file_put_contents($filePath, $content) === false) {
            throw new Exception("No se pudo guardar el archivo en: {$filePath}");
        }
        
        return $filePath;
    }
    
    /**
     * Guarda un certificado desde base64 usando saveFile
     *
     * @param string $base64Content Contenido del certificado en base64
     * @param string $filename Nombre del archivo (opcional)
     * @return string Ruta al archivo guardado
     */
    public function saveCertificate(string $base64Content, string $filename = 'cert.p12'): string
    {
        // Generar un nombre único para evitar colisiones si se llama múltiples veces
        $uniqueFilename = 'cert_' . uniqid() . '_' . $filename;
        return $this->saveFile(base64_decode($base64Content), 'data/certs', $uniqueFilename);
    }
    
    /**
     * Guarda un archivo CAF desde base64 usando saveFile
     *
     * @param string $base64Content Contenido del CAF en base64
     * @param string $filename Nombre del archivo (opcional)
     * @return string Ruta al archivo guardado
     */
    public function saveCAF(string $base64Content, string $filename = 'FBoleta.xml'): string
    {
         // Generar un nombre único para evitar colisiones si se llama múltiples veces
        $uniqueFilename = 'caf_' . uniqid() . '_' . $filename;
        return $this->saveFile(base64_decode($base64Content), 'data/caf', $uniqueFilename);
    }
    
    /**
     * Obtiene la ruta al directorio temporal principal
     *
     * @return string Ruta al directorio temporal
     */
    public function getTempDir(): string
    {
        return $this->tempDir;
    }

     /**
     * Obtiene la ruta completa a un subdirectorio dentro del directorio temporal
     *
     * @param string $subDir Subdirectorio relativo (ej: 'data/docs/boletas/xml')
     * @return string Ruta completa al subdirectorio
     */
    public function getTempPath(string $subDir): string
    {
        // Asegurar que el subdirectorio no empiece con /
        $subDir = ltrim($subDir, '/'); 
        return $this->tempDir . '/' . $subDir;
    }
    
    /**
     * Obtiene un valor de configuración
     *
     * @param string $key Clave de configuración en formato "seccion.clave"
     * @param mixed $default Valor por defecto si no existe la clave
     * @return mixed Valor de configuración
     */
    public function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $config = $this->config;
        
        foreach ($parts as $part) {
            if (!isset($config[$part])) {
                return $default;
            }
            $config = $config[$part];
        }
        
        return $config;
    }
    
    /**
     * Establece un valor de configuración
     *
     * @param string $key Clave de configuración
     * @param mixed $value Valor a establecer
     */
    public function set(string $key, $value): void
    {
        $parts = explode('.', $key);
        $configRef = &$this->config;
        
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $configRef[$part] = $value;
            } else {
                if (!isset($configRef[$part])) {
                    $configRef[$part] = [];
                }
                $configRef = &$configRef[$part];
            }
        }
    }
    
    /**
     * Destructor para limpiar recursos
     * Opcionalmente, se puede habilitar la limpieza del directorio temporal aquí
     * ¡PRECAUCIÓN! Habilitar esto podría eliminar archivos antes de que se completen otras operaciones.
     * Es más seguro limpiar directorios temporales antiguos mediante un cron job o similar.
     */
    public function __destruct()
    {
        // Ejemplo de limpieza (descomentar con precaución):
        // if (is_dir($this->tempDir)) {
        //     $this->recursiveRemoveDirectory($this->tempDir);
        // }
    }

    /**
     * Elimina recursivamente un directorio y su contenido.
     * Usar con extrema precaución.
     * 
     * @param string $dir Directorio a eliminar.
     */
    // private function recursiveRemoveDirectory(string $dir): void
    // {
    //     if (!is_dir($dir)) {
    //         return;
    //     }
    //     $items = new \RecursiveIteratorIterator(
    //         new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
    //         \RecursiveIteratorIterator::CHILD_FIRST
    //     );
    //     foreach ($items as $item) {
    //         if ($item->isDir() && !$item->isLink()) {
    //             rmdir($item->getRealPath());
    //         } else {
    //             unlink($item->getRealPath());
    //         }
    //     }
    //     rmdir($dir);
    // }
}