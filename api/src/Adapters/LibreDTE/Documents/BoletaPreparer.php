<?php
namespace App\Adapters\LibreDTE\Documents;

class BoletaPreparer extends AbstractDocumentPreparer
{
    /**
     * Prepara el documento para una boleta electrónica
     * 
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor
     * @param array $detalle Detalle de la boleta
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
        $emisorFormateado = $this->formatearEmisor($emisor);
        $detalleNormalizado = $this->normalizarDetalle($detalle);
        $todosExentos = $this->verificarItemsExentos($detalleNormalizado);
        $tipoDTE = $todosExentos ? 41 : 39;

        $documento = [
            'Encabezado' => [
                'IdDoc' => [ 
                    'TipoDTE' => $tipoDTE, 
                    'Folio' => $folio, 
                    'FchEmis' => date('Y-m-d'), 
                    'IndServicio' => $emisor['IndServicio'] ?? 3 
                ],
                'Emisor' => $emisorFormateado,
                'Receptor' => [ 
                    'RUTRecep' => $receptor['RUTRecep'] ?? '66666666-6', 
                    'RznSocRecep' => $receptor['RznSocRecep'] ?? 'SIN DETALLE', 
                    'DirRecep' => $receptor['DirRecep'] ?? null, 
                    'CmnaRecep' => $receptor['CmnaRecep'] ?? null, 
                    'CiudadRecep' => $receptor['CiudadRecep'] ?? null, 
                ],
            ],
            'Detalle' => $detalleNormalizado 
        ];
        
        // Agregar referencias si existen
        if (!empty($referencias)) {
            $documento['Referencia'] = $this->formatearReferencias($referencias);
        }
        
        return $documento;
    }
    
    /**
     * Formatea los datos del emisor específicamente para boletas
     * 
     * @param array $emisor Datos del emisor
     * @return array Datos formateados
     */
    protected function formatearEmisor(array $emisor): array
    {
        // Para boletas se usa RznSocEmisor
        $emisorFormateado = [
            'RUTEmisor' => $emisor['RUTEmisor'], 
            'RznSocEmisor' => $emisor['RznSoc'], // Nombre correcto para boletas
            'GiroEmisor' => $emisor['GiroEmis'], 
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
}