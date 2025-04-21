<?php

namespace App\Controllers;

use App\Services\NotaCreditoService;
use InvalidArgumentException;

class NotaCreditoController extends DocumentoController
{
    /**
     * Constructor
     *
     * @param NotaCreditoService $notaCreditoService
     */
    public function __construct(NotaCreditoService $notaCreditoService)
    {
        $this->documentoService = $notaCreditoService;
        $this->tipoDocumentoNombre = 'Nota de Crédito';
    }

    /**
     * Procesa una nota de crédito electrónica llamando al método correcto del servicio.
     *
     * @param array $data Datos de la nota de crédito
     * @param bool $esPreview Si es true, solo genera sin enviar
     * @return array Resultado de la operación desde el servicio/adapter
     */
    protected function procesarDocumento(array $data, bool $esPreview): array
    {
        // ***** CORREGIDO: Llamar a procesarNotaCredito *****
        return $this->documentoService->procesarNotaCredito(
            $data['certificado'],
            $data['caf'],
            $data['emisor'],
            $data['receptor'],
            $data['detalle'],
            $data['referencias'], // Obligatorio y validado en validateRequest
            $data['folio'] ?? null,
            array_merge($data['opciones'] ?? [], ['previsualizar' => $esPreview])
        );
    }

    /**
     * Validaciones específicas para notas de crédito
     *
     * @param array $data Datos de la solicitud
     * @param bool $isPreview Indica si la validación es para preview
     * @throws InvalidArgumentException Si faltan datos requeridos o son inválidos
     */
    protected function validateRequest(array $data, bool $isPreview = false): void
    {
        // Llamar primero a la validación base
        parent::validateRequest($data, $isPreview);

        // Validaciones específicas para Notas de Crédito

        // Validar Receptor (campos obligatorios para notas de crédito)
        if (empty($data['receptor']) || !is_array($data['receptor'])) {
            throw new InvalidArgumentException("El campo 'receptor' es requerido y debe ser un objeto para notas de crédito.", 400);
        }

        $requiredReceptorFields = ['RUTRecep', 'RznSocRecep'];
        // GiroRecep, DirRecep, CmnaRecep son opcionales en NC según esquema, pero pueden ser necesarios
        // si la NC anula una factura que los tenía. El Adapter/LibreDTE debería manejar esto.
        foreach ($requiredReceptorFields as $field) {
             if (!isset($data['receptor'][$field]) || $data['receptor'][$field] === '') {
                throw new InvalidArgumentException("El campo 'receptor.{$field}' es requerido para notas de crédito.", 400);
            }
        }

        if (!empty($data['receptor']['RUTRecep']) && !$this->validarRut($data['receptor']['RUTRecep'])) {
            throw new InvalidArgumentException("El formato de 'receptor.RUTRecep' no es válido.", 400);
        }

        // Validar Referencias (obligatorio para notas de crédito)
        if (empty($data['referencias']) || !is_array($data['referencias'])) {
            throw new InvalidArgumentException("El campo 'referencias' es requerido y debe ser un arreglo no vacío para notas de crédito.", 400);
        }
         if (count($data['referencias']) === 0) {
             throw new InvalidArgumentException("El campo 'referencias' no puede ser un arreglo vacío para notas de crédito.", 400);
        }


        foreach ($data['referencias'] as $index => $ref) {
            if (!is_array($ref)) {
                throw new InvalidArgumentException("Cada elemento en 'referencias' debe ser un objeto. Error en índice {$index}.", 400);
            }

            // Validar campos obligatorios en cada referencia para Notas de Crédito
            $requiredRefFields = ['TpoDocRef', 'FolioRef', 'FchRef', 'CodRef', 'RazonRef'];
            foreach ($requiredRefFields as $field) {
                 // CodRef puede ser 0 (aunque raro para NC, el esquema lo permite técnicamente), pero no null o vacío.
                 if ($field === 'CodRef') {
                     if (!isset($ref[$field]) || ($ref[$field] === '' && $ref[$field] !== 0)) { // Asegurar que esté presente y no vacío (0 es ok)
                         throw new InvalidArgumentException("El campo 'referencias[{$index}].{$field}' es requerido para notas de crédito.", 400);
                     }
                 } elseif (!isset($ref[$field]) || $ref[$field] === '') {
                     throw new InvalidArgumentException("El campo 'referencias[{$index}].{$field}' es requerido para notas de crédito.", 400);
                 }
            }

            // Validar que CodRef sea un valor numérico esperado (1, 2, 3)
            if (isset($ref['CodRef']) && !in_array((int)$ref['CodRef'], [1, 2, 3], true)) {
                 // Convertimos a int para la comparación estricta
                throw new InvalidArgumentException("El campo 'referencias[{$index}].CodRef' debe ser 1 (Anula), 2 (Corrige Texto) o 3 (Corrige Monto).", 400);
            }
            // Validar formato fecha YYYY-MM-DD
            if (isset($ref['FchRef']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref['FchRef'])) {
                 throw new InvalidArgumentException("El formato de 'referencias[{$index}].FchRef' debe ser YYYY-MM-DD.", 400);
            }
        }
    }
}