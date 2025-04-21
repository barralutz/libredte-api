<?php

namespace App\Services\Traits;

use App\Adapters\LibreDTE\Output\DteToJsonFormatter;
use sasco\LibreDTE\Sii\Dte; // Importar Dte class
use Exception;

/**
 * Trait que proporciona la funcionalidad para generar JSON para impresión térmica
 * para cualquier tipo de documento (boletas, facturas, notas de crédito)
 * USADO SOLAMENTE POR EL ENDPOINT /json_impresion
 */
trait JsonImpresionTrait
{
    /**
     * Genera el JSON para impresión térmica de un documento llamando al Adapter en modo preview.
     *
     * @param array $data Datos para generar el documento
     * @return array Estructura de datos JSON para impresión térmica
     * @throws Exception Si ocurre un error durante el proceso.
     */
    public function obtenerJsonParaImpresion(array $data): array
    {
        // Este método es llamado por el endpoint /xxx/json_impresion
        // Necesita generar el DTE en modo preview para obtener el objeto DTE y formatearlo.

        try {
            // Crear una instancia del formateador
            $jsonFormatter = new DteToJsonFormatter();

            // Preparar datos para el adaptador LibreDTE, asegurando modo preview
            $opciones = $data['opciones'] ?? [];
            $opciones['previsualizar'] = true; // Forzar previsualizar

            $docData = [
                'certificado' => $data['certificado'],
                'caf' => $data['caf'],
                'emisor' => $data['emisor'],
                'receptor' => $data['receptor'] ?? [], // Ajustar según tipo de documento si es necesario
                'detalle' => $data['detalle'],
                'referencias' => $data['referencias'] ?? [],
                'folio' => $data['folio'] ?? null,
                'certificacion' => $data['certificacion'] ?? true,
                'opciones' => $opciones // Opciones incluyendo previsualizar = true
            ];

            // Determinar qué método del *servicio* llamar (p.ej., procesarBoleta)
            // Necesitamos una forma de saber qué método llamar en el servicio actual.
            // O podemos llamar directamente al adapter si el servicio solo lo envuelve.
            // Llamemos al adapter directamente para simplificar, ya que el servicio ahora es un wrapper simple.

             // Determinar qué método del *adapter* usar basado en el tipo de servicio
            $adapterMethod = $this->determinarMetodoAdapter();

            // Generar el DTE usando el método adecuado del adapter EN MODO PREVIEW
            // El adapter devolverá ['DTE' => DteObject, 'preview_data' => [...]]
            $resultado = $this->libreDteAdapter->$adapterMethod($docData, true); // true forza preview

            // Verificar que el resultado contiene el objeto DTE
            if (empty($resultado['DTE']) || !($resultado['DTE'] instanceof Dte)) {
                // Loguear el resultado para depuración
                error_log("JsonImpresionTrait: Adapter preview result did not contain a valid DTE object. Result: " . print_r($resultado, true));
                throw new Exception("No se pudo obtener el objeto DTE para la conversión a JSON desde el resultado de previsualización.");
            }

            // Obtener el objeto DTE
            $dteObject = $resultado['DTE'];

            // Convertir el DTE a formato JSON para impresión
            $jsonData = $jsonFormatter->formatToJson(
                $dteObject,
                $data['emisor'],
                $data['opciones'] ?? [] // Pasar opciones originales (papel, etc)
            );

            return $jsonData;

        } catch (Exception $e) {
            // Loguear el error completo antes de relanzar
             error_log("Error en JsonImpresionTrait::obtenerJsonParaImpresion: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            // Re-lanzar la excepción para que el controlador la maneje
            throw new Exception("Error al generar JSON para impresión: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Determina qué método del libreDteAdapter usar basado en el tipo de servicio que usa el Trait.
     *
     * @return string Nombre del método del Adapter a utilizar
     * @throws Exception Si el tipo de servicio no es reconocido
     */
    private function determinarMetodoAdapter(): string
    {
        // Necesita acceso a $this->libreDteAdapter que debe estar definido en la clase que usa el trait
        if (!isset($this->libreDteAdapter)) {
             throw new Exception("La propiedad libreDteAdapter no está disponible en la clase que usa JsonImpresionTrait.");
        }

        $className = get_class($this); // Obtiene el nombre de la clase que USA el trait

        if (str_contains($className, 'BoletaService')) {
            return 'generarEnviarBoleta';
        } elseif (str_contains($className, 'FacturaService')) {
            return 'generarEnviarFactura';
        } elseif (str_contains($className, 'NotaCreditoService')) {
            return 'generarEnviarNotaCredito';
        }
        // Añadir más tipos si es necesario (NotaDebitoService, etc.)
        // elseif (str_contains($className, 'NotaDebitoService')) {
        //     return 'generarEnviarNotaDebito';
        // }
        else {
            throw new Exception("Tipo de servicio '{$className}' no compatible con generación de JSON para impresión vía Trait.");
        }
    }
}