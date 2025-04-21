<?php
namespace App\Adapters\LibreDTE\Core;

use App\Adapters\ConfigAdapter;
use sasco\LibreDTE\Sii\Folios;
use Exception;

class FoliosManager
{
    private $configAdapter;
    
    /**
     * Constructor
     * 
     * @param ConfigAdapter $configAdapter
     */
    public function __construct(ConfigAdapter $configAdapter)
    {
        $this->configAdapter = $configAdapter;
    }
    
    /**
     * Obtiene el archivo CAF desde base64 o path
     * 
     * @param array $cafData Datos del CAF
     * @param string|null $cafFile Ruta opcional al archivo CAF
     * @return string Ruta al archivo CAF
     * @throws Exception Si no se puede obtener el archivo CAF
     */
    public function obtenerCaf($cafData, $cafFile = null): string
    {
        if (isset($cafData['data'])) {
            return $this->configAdapter->saveCAF($cafData['data']);
        } else if (isset($cafFile)) {
            if (!file_exists($cafFile)) {
                throw new Exception("El archivo CAF especificado no existe: " . $cafFile);
            }
            return $cafFile;
        } else {
            throw new Exception("Debe proporcionar el CAF en base64 (caf.data) o la ruta al archivo (caf_file)");
        }
    }
    
    /**
     * Carga los folios desde archivo CAF
     * 
     * @param string $cafFile Ruta al archivo CAF
     * @return Folios Objeto de folios
     * @throws Exception Si no se pueden cargar los folios
     */
    public function cargarFolios(string $cafFile): Folios
    {
        if (!file_exists($cafFile)) { 
            throw new Exception("Archivo CAF no encontrado: {$cafFile}"); 
        }
        
        $content = file_get_contents($cafFile);
        if (empty($content)) { 
            throw new Exception("Archivo CAF vacío: {$cafFile}"); 
        }
        
        try { 
            $folios = new Folios($content); 
        } catch (\Exception $e) { 
            throw new Exception("Error al parsear CAF: {$cafFile}. Error: " . $e->getMessage()); 
        }
        
        if ($folios->getTipo() === false || $folios->getDesde() === false || $folios->getHasta() === false) {
            throw new Exception("Archivo CAF inválido: {$cafFile}");
        }
        
        return $folios;
    }
    
    /**
     * Valida que el folio solicitado esté dentro del rango del CAF
     * 
     * @param int $folioSolicitado Folio solicitado
     * @param Folios $folios Objeto de folios
     * @throws Exception Si el folio está fuera de rango
     */
    public function validarFolio(int $folioSolicitado, Folios $folios): void
    {
        if ($folioSolicitado < $folios->getDesde() || $folioSolicitado > $folios->getHasta()) {
            throw new Exception("El folio solicitado {$folioSolicitado} está fuera del rango del CAF ({$folios->getDesde()}-{$folios->getHasta()}).");
        }
    }
}