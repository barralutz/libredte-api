<?php

namespace App\Services;

use App\Adapters\LibreDteAdapter;
use App\Services\Traits\JsonImpresionTrait;
use Exception;

/**
 * Servicio para la generación y envío de facturas electrónicas
 */
class FacturaService
{
    // Usar el trait para la funcionalidad de generación de JSON (para el endpoint /json_impresion)
    use JsonImpresionTrait;

    private $libreDteAdapter;

    /**
     * Constructor
     *
     * @param LibreDteAdapter $libreDteAdapter Adaptador de LibreDTE
     */
    public function __construct(LibreDteAdapter $libreDteAdapter)
    {
        $this->libreDteAdapter = $libreDteAdapter;
    }

    /**
     * Procesa una factura electrónica (genera y/o envía) usando el adaptador de LibreDTE.
     *
     * @param array $certificado Datos del certificado ['data' => base64, 'pass' => string]
     * @param array $caf Datos del CAF ['data' => base64]
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor (obligatorio para factura)
     * @param array $detalle Detalle de la factura
     * @param array $referencias Referencias a otros documentos (opcional)
     * @param int|null $folio Folio específico (opcional)
     * @param array $opciones Opciones adicionales (incluye 'previsualizar' y 'certificacion')
     * @return array Resultado de la operación desde el Adapter (incluye DTE y sii_result o preview_data)
     * @throws Exception Si ocurre un error durante el proceso.
     */
    public function procesarFactura(
        array $certificado,
        array $caf,
        array $emisor,
        array $receptor,
        array $detalle,
        array $referencias = [],
        ?int $folio = null,
        array $opciones = []
    ): array {
        try {
            // Preparar datos para el adaptador LibreDTE
            $data = [
                'certificado' => $certificado,
                'caf' => $caf,
                'emisor' => $emisor,
                'receptor' => $receptor,
                'detalle' => $detalle,
                'referencias' => $referencias,
                'folio' => $folio,
                'certificacion' => $opciones['certificacion'] ?? true,
                'opciones' => $opciones
            ];

            // Determinar si es previsualización o envío real
            $previsualizar = $opciones['previsualizar'] ?? false;

            // Usar el adaptador para generar y/o enviar la factura
            return $this->libreDteAdapter->generarEnviarFactura($data, $previsualizar);

        } catch (Exception $e) {
             // Re-lanzar la excepción para que el controlador la maneje
            throw new Exception("Error en FacturaService al procesar factura: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

     // El método obtenerJsonParaImpresion viene del Trait
}