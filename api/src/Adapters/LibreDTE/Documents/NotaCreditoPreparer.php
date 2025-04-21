<?php
namespace App\Adapters\LibreDTE\Documents;

use Exception;

class NotaCreditoPreparer extends AbstractDocumentPreparer
{
    /**
     * Prepara el documento para una nota de crédito electrónica
     * 
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor
     * @param array $detalle Detalle de la nota de crédito
     * @param int $folio Número de folio
     * @param array $referencias Referencias (obligatorio para notas de crédito)
     * @return array Documento preparado
     */
    public function prepararDocumento(
        array $emisor, 
        array $receptor, 
        array $detalle, 
        int $folio,
        array $referencias = []
    ): array
    {
        // Validar que existan referencias (obligatorio en notas de crédito)
        if (empty($referencias)) {
            throw new Exception('Las notas de crédito deben referenciar al menos un documento');
        }

        $emisorFormateado = $this->formatearEmisor($emisor);
        $detalleNormalizado = $this->normalizarDetalle($detalle);
        $referenciasFormateadas = $this->validarFormatearReferenciasNC($referencias);

        // Validar receptor
        $this->validarReceptorNC($receptor);

        $tipoDTE = 61; // Nota de Crédito Electrónica

        $documento = [
            'Encabezado' => [
                'IdDoc' => [ 
                    'TipoDTE' => $tipoDTE, 
                    'Folio' => $folio, 
                    'FchEmis' => date('Y-m-d')
                ],
                'Emisor' => $emisorFormateado,
                'Receptor' => $receptor,
            ],
            'Detalle' => $detalleNormalizado,
            'Referencia' => $referenciasFormateadas
        ];
        
        return $documento;
    }
    
    /**
     * Formatea los datos del emisor específicamente para notas de crédito
     * 
     * @param array $emisor Datos del emisor
     * @return array Datos formateados
     */
    protected function formatearEmisor(array $emisor): array
    {
        $emisorFormateado = [
            'RUTEmisor' => $emisor['RUTEmisor'],
            'RznSoc' => $emisor['RznSoc'],  // Nombre correcto para notas de crédito
            'GiroEmis' => $emisor['GiroEmis'],
            'Acteco' => $emisor['Acteco'] ?? null,
            'DirOrigen' => $emisor['DirOrigen'],
            'CmnaOrigen' => $emisor['CmnaOrigen'],
            'CiudadOrigen' => $emisor['CiudadOrigen'] ?? $emisor['CmnaOrigen']
        ];
        
        if ($emisorFormateado['Acteco'] === null) {
            unset($emisorFormateado['Acteco']);
        }
        
        return $emisorFormateado;
    }
    
    /**
     * Valida los campos mínimos requeridos del receptor para notas de crédito
     * 
     * @param array $receptor Datos del receptor
     * @throws Exception Si faltan campos requeridos
     */
    private function validarReceptorNC(array $receptor): void
    {
        if (empty($receptor['RUTRecep'])) {
            throw new Exception("El campo 'receptor.RUTRecep' es obligatorio para notas de crédito.");
        }
        if (empty($receptor['RznSocRecep'])) {
            throw new Exception("El campo 'receptor.RznSocRecep' es obligatorio para notas de crédito.");
        }
        
        // Para notas de crédito que referencian una factura, también es obligatorio el GiroRecep
        if (isset($receptor['GiroRecep']) && empty($receptor['GiroRecep'])) {
            throw new Exception("Si se incluye el campo 'receptor.GiroRecep', no puede estar vacío.");
        }
    }
    
    /**
     * Valida y formatea referencias para notas de crédito
     * 
     * @param array $referencias Referencias a otros documentos
     * @return array Referencias formateadas y validadas
     * @throws Exception Si faltan campos requeridos en las referencias
     */
    private function validarFormatearReferenciasNC(array $referencias): array
    {
        $referenciasNormalizadas = [];
        
        foreach ($referencias as $index => $ref) {
            if (!isset($ref['NroLinRef'])) {
                $ref['NroLinRef'] = $index + 1;
            }
            
            // Validar campos obligatorios en cada referencia
            if (empty($ref['TpoDocRef'])) {
                throw new Exception("Falta 'TpoDocRef' en referencia línea " . $ref['NroLinRef']);
            }
            if (empty($ref['FolioRef'])) {
                throw new Exception("Falta 'FolioRef' en referencia línea " . $ref['NroLinRef']);
            }
            if (empty($ref['FchRef'])) {
                throw new Exception("Falta 'FchRef' en referencia línea " . $ref['NroLinRef']);
            }
            
            // Validar CodRef (Obligatorio para notas de crédito)
            if (!isset($ref['CodRef'])) {
                throw new Exception("Falta 'CodRef' en referencia línea " . $ref['NroLinRef']);
            }
            
            // Validar que el CodRef sea un valor válido (1=Anulación, 2=Corrige texto, 3=Devolución)
            if (!in_array($ref['CodRef'], [1, 2, 3])) {
                throw new Exception("El valor de 'CodRef' en referencia línea " . $ref['NroLinRef'] . " debe ser 1, 2 o 3");
            }
            
            // RazonRef es obligatorio para notas de crédito
            if (empty($ref['RazonRef'])) {
                throw new Exception("Falta 'RazonRef' en referencia línea " . $ref['NroLinRef']);
            }
            
            $referenciasNormalizadas[] = $ref;
        }
        
        return $referenciasNormalizadas;
    }
}