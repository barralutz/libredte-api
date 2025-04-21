<?php
namespace App\Adapters\LibreDTE\Core;

use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\Log;
use Exception;

class DteGenerator
{
    /**
     * Genera el DTE, timbra y firma
     * 
     * @param array $documento Datos del documento
     * @param FirmaElectronica $firma Objeto de firma electrónica
     * @param Folios $folios Objeto de folios
     * @return Dte Objeto DTE generado
     * @throws Exception Si hay errores en la generación
     */
    public function generar(array $documento, FirmaElectronica $firma, Folios $folios): Dte
    {
        $dte = null; 
        
        try {
            $dte = new Dte($documento);
        } catch (\Exception $e) {
            throw new Exception("Error al inicializar DTE: " . $e->getMessage());
        }
        
        $folio = $documento['Encabezado']['IdDoc']['Folio'];
        $tipoDTE = $documento['Encabezado']['IdDoc']['TipoDTE'];
        
        if (!$dte->timbrar($folios)) {
            $errors = Log::readAll();
            throw new Exception("Error al timbrar DTE (Tipo: $tipoDTE, Folio: {$folio}): " . implode(", ", $errors));
        }
        
        if (!$dte->firmar($firma)) {
            $errors = Log::readAll();
            throw new Exception("Error al firmar DTE (Tipo: $tipoDTE, Folio: {$folio}): " . implode(", ", $errors));
        }
        
        return $dte;
    }
    
    /**
     * Obtiene el nombre del tipo de DTE
     * 
     * @param int $tipo
     * @return string
     */
    public function obtenerNombreTipoDTE(int $tipo): string
    {
        switch ($tipo) {
            case 33:
                return 'factura';
            case 39:
            case 41:
                return 'boleta';
            case 56:
                return 'nota_debito';
            case 61:
                return 'nota_credito';
            default:
                return 'documento';
        }
    }
}