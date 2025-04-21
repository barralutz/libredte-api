<?php

namespace App\Controllers;

use App\Services\FacturaService;
use InvalidArgumentException;

class FacturaController extends DocumentoController
{
    /**
     * Constructor
     *
     * @param FacturaService $facturaService
     */
    public function __construct(FacturaService $facturaService)
    {
        $this->documentoService = $facturaService;
        $this->tipoDocumentoNombre = 'Factura';
    }

    /**
     * Procesa una factura electrónica llamando al método correcto del servicio.
     *
     * @param array $data Datos de la factura
     * @param bool $esPreview Si es true, solo genera sin enviar
     * @return array Resultado de la operación desde el servicio/adapter
     */
    protected function procesarDocumento(array $data, bool $esPreview): array
    {
        // ***** CORREGIDO: Llamar a procesarFactura *****
        return $this->documentoService->procesarFactura(
            $data['certificado'],
            $data['caf'],
            $data['emisor'],
            $data['receptor'], // Receptor es obligatorio y validado en validateRequest
            $data['detalle'],
            $data['referencias'] ?? [],
            $data['folio'] ?? null,
            array_merge($data['opciones'] ?? [], ['previsualizar' => $esPreview])
        );
    }

    /**
     * Validaciones específicas para facturas
     *
     * @param array $data Datos de la solicitud
     * @param bool $isPreview Indica si la validación es para preview
     * @throws InvalidArgumentException Si faltan datos requeridos o son inválidos
     */
    protected function validateRequest(array $data, bool $isPreview = false): void
    {
        // Llamar primero a la validación base
        parent::validateRequest($data, $isPreview);

        // Validaciones específicas para Facturas

        // Validar Receptor (campos obligatorios para facturas)
        if (empty($data['receptor']) || !is_array($data['receptor'])) {
            throw new InvalidArgumentException("El campo 'receptor' es requerido y debe ser un objeto para facturas.", 400);
        }

        $requiredReceptorFields = ['RUTRecep', 'RznSocRecep', 'GiroRecep'];
        // La dirección no es estrictamente obligatoria según el esquema, pero sí muy recomendable.
        // Podríamos añadir 'DirRecep', 'CmnaRecep' si queremos forzarlo. Por ahora, lo dejamos así.
        foreach ($requiredReceptorFields as $field) {
             // Usar isset porque el campo podría existir pero ser null o vacío, lo cual es inválido aquí
            if (!isset($data['receptor'][$field]) || $data['receptor'][$field] === '') {
                throw new InvalidArgumentException("El campo 'receptor.{$field}' es requerido y no puede estar vacío para facturas.", 400);
            }
        }

        if (!empty($data['receptor']['RUTRecep']) && !$this->validarRut($data['receptor']['RUTRecep'])) {
            throw new InvalidArgumentException("El formato de 'receptor.RUTRecep' no es válido.", 400);
        }

        // Validar Referencias (si existen)
        if (!empty($data['referencias'])) {
            if (!is_array($data['referencias'])) {
                throw new InvalidArgumentException("El campo 'referencias' debe ser un arreglo.", 400);
            }

            foreach ($data['referencias'] as $index => $ref) {
                if (!is_array($ref)) {
                    throw new InvalidArgumentException("Cada elemento en 'referencias' debe ser un objeto. Error en índice {$index}.", 400);
                }

                // Validar campos obligatorios en cada referencia para Factura
                $requiredRefFields = ['TpoDocRef', 'FolioRef', 'FchRef']; // CodRef y RazonRef NO son obligatorios en factura
                foreach ($requiredRefFields as $field) {
                     if (!isset($ref[$field]) || $ref[$field] === '') {
                        throw new InvalidArgumentException("El campo 'referencias[{$index}].{$field}' es requerido cuando se incluyen referencias en facturas.", 400);
                    }
                }
                 // Validar formato fecha YYYY-MM-DD
                if (isset($ref['FchRef']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref['FchRef'])) {
                     throw new InvalidArgumentException("El formato de 'referencias[{$index}].FchRef' debe ser YYYY-MM-DD.", 400);
                }
            }
        }
    }
}