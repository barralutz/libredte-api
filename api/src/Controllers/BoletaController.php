<?php

namespace App\Controllers;

use App\Services\BoletaService;
use InvalidArgumentException;

class BoletaController extends DocumentoController
{
    /**
     * Constructor
     *
     * @param BoletaService $boletaService
     */
    public function __construct(BoletaService $boletaService)
    {
        // $this->documentoService se asigna aquí
        $this->documentoService = $boletaService;
        $this->tipoDocumentoNombre = 'Boleta';
    }

    /**
     * Procesa una boleta electrónica llamando al método correcto del servicio.
     *
     * @param array $data Datos de la boleta
     * @param bool $esPreview Si es true, solo genera sin enviar
     * @return array Resultado de la operación desde el servicio/adapter
     */
    protected function procesarDocumento(array $data, bool $esPreview): array
    {
        // ***** LÍNEA 30 CORREGIDA *****
        // Llamar a procesarBoleta en lugar de generarYEnviarBoleta
        return $this->documentoService->procesarBoleta(
            $data['certificado'],
            $data['caf'],
            $data['emisor'],
            $data['receptor'] ?? [],
            $data['detalle'],
            $data['referencias'] ?? [],
            $data['folio'] ?? null,
            // Pasar las opciones, incluyendo 'previsualizar' correctamente
            array_merge($data['opciones'] ?? [], ['previsualizar' => $esPreview])
        );
    }

    /**
     * Validaciones específicas para boletas
     *
     * @param array $data Datos de la solicitud
     * @param bool $isPreview Indica si la validación es para preview
     * @throws InvalidArgumentException Si faltan datos requeridos o son inválidos
     */
    protected function validateRequest(array $data, bool $isPreview = false): void
    {
        // Llamar primero a la validación base
        parent::validateRequest($data, $isPreview);

        // Validaciones específicas para Boletas
        if (isset($data['receptor']) && !is_array($data['receptor'])) {
            throw new InvalidArgumentException("Si se proporciona 'receptor', debe ser un objeto.", 400);
        }

        // Validar formato RUT Receptor si se proporciona
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
                // No hay validaciones de campos obligatorios específicos para referencias de boletas aquí
                // Se asume que el Adapter/LibreDTE los manejará si son necesarios para algún caso
            }
        }
    }
}