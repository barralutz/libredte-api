<?php
namespace App\Adapters\LibreDTE\Documents;

use Exception;

class FacturaPreparer extends AbstractDocumentPreparer
{
    /**
     * Prepara el documento para una factura electrónica
     * 
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor
     * @param array $detalle Detalle de la factura
     * @param int $folio Número de folio
     * @param array $referencias Referencias (opcional)
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
        // Validar campos mínimos del receptor para factura
        $this->validarReceptorFactura($receptor);
        
        $emisorFormateado = $this->formatearEmisor($emisor);
        $detalleNormalizado = $this->normalizarDetalle($detalle);
        
        $receptorFormateado = [
            'RUTRecep' => $receptor['RUTRecep'],
            'RznSocRecep' => $receptor['RznSocRecep'],
            'GiroRecep' => $receptor['GiroRecep'],
            'DirRecep' => $receptor['DirRecep'] ?? null,
            'CmnaRecep' => $receptor['CmnaRecep'] ?? null,
            'CiudadRecep' => $receptor['CiudadRecep'] ?? null,
        ];

        $tipoDTE = 33; // Factura Electrónica
        
        $documento = [
            'Encabezado' => [
                'IdDoc' => [ 
                    'TipoDTE' => $tipoDTE, 
                    'Folio' => $folio, 
                    'FchEmis' => date('Y-m-d')
                ],
                'Emisor' => $emisorFormateado,
                'Receptor' => $receptorFormateado,
            ],
            'Detalle' => $detalleNormalizado 
        ];
        
        // Agregar referencias si existen
        if (!empty($referencias)) {
            $documento['Referencia'] = $this->validarFormatearReferenciasFactura($referencias);
        }
        
        return $documento;
    }
    
    /**
     * Formatea los datos del emisor específicamente para facturas
     * (Sobrescribe el método heredado para asegurar orden correcto de elementos XML)
     * 
     * @param array $emisor Datos del emisor
     * @return array Datos formateados
     */
    protected function formatearEmisor(array $emisor): array
    {
        // Para facturas se debe usar RznSoc y no RznSocEmisor
        $emisorFormateado = [
            'RUTEmisor' => $emisor['RUTEmisor'],
            'RznSoc' => $emisor['RznSoc'],  // Nombre correcto para facturas
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
     * Valida los campos mínimos requeridos del receptor para facturas
     * 
     * @param array $receptor Datos del receptor
     * @throws Exception Si faltan campos requeridos
     */
    private function validarReceptorFactura(array $receptor): void
    {
        if (empty($receptor['RUTRecep'])) {
            throw new Exception("El campo 'receptor.RUTRecep' es obligatorio para facturas.");
        }
        if (empty($receptor['RznSocRecep'])) {
            throw new Exception("El campo 'receptor.RznSocRecep' es obligatorio para facturas.");
        }
        if (empty($receptor['GiroRecep'])) {
            throw new Exception("El campo 'receptor.GiroRecep' es obligatorio para facturas.");
        }
    }
    
    /**
     * Valida y formatea referencias para facturas
     * 
     * @param array $referencias Referencias a otros documentos
     * @return array Referencias formateadas y validadas
     * @throws Exception Si faltan campos requeridos en las referencias
     */
    private function validarFormatearReferenciasFactura(array $referencias): array
    {
        $referenciasNormalizadas = [];
        
        foreach ($referencias as $index => $ref) {
            if (!isset($ref['NroLinRef'])) {
                $ref['NroLinRef'] = $index + 1;
            }
            
            if (empty($ref['TpoDocRef'])) {
                throw new Exception("Falta 'TpoDocRef' en referencia línea " . $ref['NroLinRef']);
            }
            if (empty($ref['FolioRef'])) {
                throw new Exception("Falta 'FolioRef' en referencia línea " . $ref['NroLinRef']);
            }
            if (empty($ref['FchRef'])) {
                throw new Exception("Falta 'FchRef' en referencia línea " . $ref['NroLinRef']);
            }
            
            $referenciasNormalizadas[] = $ref;
        }
        
        return $referenciasNormalizadas;
    }
}