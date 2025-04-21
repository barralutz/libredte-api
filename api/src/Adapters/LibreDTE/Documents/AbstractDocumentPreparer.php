<?php
namespace App\Adapters\LibreDTE\Documents;

use Exception;

abstract class AbstractDocumentPreparer
{
    /**
     * Método abstracto para preparar un documento
     * 
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor
     * @param array $detalle Detalle del documento
     * @param int $folio Número de folio
     * @param array $referencias Referencias opcionales
     * @return array Documento preparado
     */
    abstract public function prepararDocumento(
        array $emisor, 
        array $receptor, 
        array $detalle, 
        int $folio,
        array $referencias = []
    ): array;
    
    /**
     * Método abstracto para formatear los datos del emisor
     * 
     * @param array $emisor Datos del emisor
     * @return array Datos formateados
     */
    abstract protected function formatearEmisor(array $emisor): array;
        
    /**
     * Normaliza el detalle del documento
     * 
     * @param array $detalle Detalle del documento
     * @return array Detalle normalizado
     * @throws Exception Si faltan datos requeridos
     */
    protected function normalizarDetalle(array $detalle): array
    {
        $detalleNormalizado = [];
        
        foreach ($detalle as $index => $item) {
            if (empty($item['NmbItem'])) { 
                throw new Exception("Falta 'NmbItem' en detalle línea " . ($index + 1)); 
            }
            
            if (!isset($item['QtyItem'])) {
                if (isset($item['PrcItem'])) { 
                    $item['QtyItem'] = $item['QtyItem'] ?? 1; 
                } else { 
                    if(!isset($item['MontoItem'])) { 
                        throw new Exception("Falta 'QtyItem'/'PrcItem' o 'MontoItem' en detalle línea " . ($index + 1)); 
                    } 
                }
            }
            
            if (!isset($item['PrcItem']) && !isset($item['MontoItem'])) { 
                throw new Exception("Falta 'PrcItem' o 'MontoItem' en detalle línea " . ($index + 1)); 
            }
            
            if (!isset($item['NroLinDet'])) { 
                $item['NroLinDet'] = $index + 1; 
            }
            
            $detalleNormalizado[] = $item; 
        }
        
        return $detalleNormalizado;
    }
    
    /**
     * Verifica si todos los items son exentos
     * 
     * @param array $detalle Detalle normalizado
     * @return bool True si todos los items son exentos
     */
    protected function verificarItemsExentos(array $detalle): bool
    {
        foreach ($detalle as $item) {
            if (!isset($item['IndExe']) || $item['IndExe'] != 1) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Formatea las referencias
     * 
     * @param array $referencias Referencias a otros documentos
     * @return array Referencias formateadas
     */
    protected function formatearReferencias(array $referencias): array
    {
        $referenciasNormalizadas = [];
        
        foreach ($referencias as $index => $ref) {
            if (!isset($ref['NroLinRef'])) {
                $ref['NroLinRef'] = $index + 1;
            }
            $referenciasNormalizadas[] = $ref;
        }
        
        return $referenciasNormalizadas;
    }
}