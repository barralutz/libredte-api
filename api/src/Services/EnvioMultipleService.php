<?php

namespace App\Services;

use App\Adapters\LibreDteAdapter;
use App\Adapters\ConfigAdapter;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\Log;
use Exception;

/**
 * Servicio para el envío múltiple de boletas electrónicas en un solo sobre
 */
class EnvioMultipleService
{
    private $libreDteAdapter;
    private $configAdapter;
    
    /**
     * Constructor
     * 
     * @param LibreDteAdapter $libreDteAdapter Adaptador de LibreDTE
     * @param ConfigAdapter $configAdapter Adaptador de configuración
     */
    public function __construct(LibreDteAdapter $libreDteAdapter, ConfigAdapter $configAdapter)
    {
        $this->libreDteAdapter = $libreDteAdapter;
        $this->configAdapter = $configAdapter;
    }
    
    /**
     * Genera y envía múltiples boletas en un solo sobre
     * 
     * @param array $data Datos para generar el sobre con múltiples boletas
     * @return array Resultado de la operación
     * @throws Exception Si ocurre un error durante el proceso
     */
    public function generarEnviarMultipleBoletas(array $data): array
    {
        try {
            // Configurar el ambiente
            $certificacion = $data['opciones']['certificacion'] ?? true;
            $this->libreDteAdapter->setupEnvironment($certificacion);
            
            // Guardar el certificado usando ConfigAdapter en lugar de llamar a métodos privados
            $certFile = $this->configAdapter->saveCertificate($data['certificado']['data']);
            
            // Cargar la firma con el certificado
            $firma = new FirmaElectronica([
                'file' => $certFile,
                'pass' => $data['certificado']['pass']
            ]);
            
            if (!$firma->getID()) {
                $errors = Log::readAll();
                throw new Exception("Error al cargar el certificado/firma: " . implode(", ", $errors));
            }
            
            // Crear envío de boletas
            $EnvioBOLETA = new EnvioDte();
            
            // Procesar cada boleta
            $dtes = [];
            foreach ($data['boletas'] as $index => $boletaData) {
                // Guardar el CAF
                $cafFile = $this->configAdapter->saveCAF($boletaData['caf']['data']);
                
                // Cargar los folios
                $folios = new Folios(file_get_contents($cafFile));
                if ($folios->getTipo() === false || $folios->getDesde() === false || $folios->getHasta() === false) {
                    throw new Exception("Archivo CAF inválido para boleta #{$index}");
                }
                
                $folio = $boletaData['folio'];
                if ($folio < $folios->getDesde() || $folio > $folios->getHasta()) {
                    throw new Exception("El folio {$folio} está fuera del rango del CAF ({$folios->getDesde()}-{$folios->getHasta()}).");
                }
                
                // Preparar documento
                $documento = $this->prepararDocumentoBoleta(
                    $data['emisor'],
                    $boletaData['receptor'],
                    $boletaData['detalle'],
                    $folio,
                    $boletaData['fecha_emision'] ?? date('Y-m-d'),
                    $boletaData['IndServicio'] ?? 3,
                    $boletaData['referencias'] ?? []
                );
                
                // Generar DTE
                $dte = new Dte($documento);
                if (!$dte->timbrar($folios)) {
                    $errors = Log::readAll();
                    throw new Exception("Error al timbrar DTE (Folio: {$folio}): " . implode(", ", $errors));
                }
                
                if (!$dte->firmar($firma)) {
                    $errors = Log::readAll();
                    throw new Exception("Error al firmar DTE (Folio: {$folio}): " . implode(", ", $errors));
                }
                
                // Agregar al arreglo de DTEs
                $dtes[] = $dte;
                
                // Agregar al sobre
                $EnvioBOLETA->agregar($dte);
                
                // Limpieza del archivo CAF temporal
                if (file_exists($cafFile)) {
                    @unlink($cafFile);
                }
            }
            
            // Configurar caratula
            $caratula = [
                'RutEmisor' => $data['emisor']['RUTEmisor'],
                'RutEnvia' => $data['caratula']['RutEnvia'] ?? $firma->getID(),
                'RutReceptor' => $data['caratula']['RutReceptor'] ?? '60803000-K',  // SII
                'FchResol' => $data['caratula']['FchResol'] ?? $data['emisor']['FchResol'],
                'NroResol' => $data['caratula']['NroResol'] ?? $data['emisor']['NroResol'],
                'TmstFirmaEnv' => date('Y-m-d\TH:i:s'),
                'SubTotDTE' => [
                    ['TpoDTE' => 39, 'NroDTE' => count($dtes)]
                ]
            ];
            
            $EnvioBOLETA->setCaratula($caratula);
            $EnvioBOLETA->setFirma($firma);
            
            // Generar XML
            $xmlEnvioDTE = $EnvioBOLETA->generar();
            if (!$xmlEnvioDTE) {
                $errors = Log::readAll();
                throw new Exception("Error al generar XML EnvioDTE: " . implode(", ", $errors));
            }
            
            // Convertir a EnvioBOLETA
            $xmlEnvioBOLETA = str_replace(
                '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd" version="1.0">',
                '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd" version="1.0">',
                $xmlEnvioDTE
            );
            $xmlEnvioBOLETA = str_replace('</EnvioDTE>', '</EnvioBOLETA>', $xmlEnvioBOLETA);
            
            // Guardar XML
            $dirXml = $this->configAdapter->getTempPath('data/docs/boletas/xml');
            $xmlFilename = "envio_multiple_boletas_" . date('YmdHis') . '.xml';
            $xmlPath = $dirXml . '/' . $xmlFilename;
            
            if (file_put_contents($xmlPath, $xmlEnvioBOLETA) === false) {
                throw new Exception("No se pudo guardar XML envío: {$xmlPath}");
            }
            
            // Obtener token y enviar al SII
            $token = \sasco\LibreDTE\Sii\Autenticacion::getToken($firma);
            if (!$token) {
                $errors = Log::readAll();
                throw new Exception("Error al obtener token SII: " . implode(", ", $errors));
            }
            
            $rutEnvia = $firma->getID();
            $rutEmisor = $data['emisor']['RUTEmisor'];
            $trackId = \sasco\LibreDTE\Sii::enviar($rutEnvia, $rutEmisor, $xmlEnvioBOLETA, $token);
            
            if (!$trackId) {
                $errors = Log::readAll();
                throw new Exception("Error al enviar al SII: " . implode(", ", $errors));
            }
            
            // Preparar resultado
            $result = [
                'track_id' => $trackId,
                'xml_path' => $xmlPath,
                'xml_content' => base64_encode($xmlEnvioBOLETA),
                'folios' => array_map(function($dte) {
                    return $dte->getFolio();
                }, $dtes)
            ];
            
            return $result;
            
        } catch (Exception $e) {
            $errors = Log::readAll();
            throw new Exception("Error al generar/enviar múltiples boletas: " . $e->getMessage() . ". Detalles LibreDTE: " . implode(", ", $errors));
        } finally {
            // Limpiar archivos temporales
            if (isset($certFile) && file_exists($certFile)) {
                @unlink($certFile);
            }
        }
    }
    
    /**
     * Prepara el documento para una boleta electrónica
     * 
     * @param array $emisor Datos del emisor
     * @param array $receptor Datos del receptor
     * @param array $detalle Detalle de la boleta
     * @param int $folio Número de folio
     * @param string $fechaEmision Fecha de emisión (formato Y-m-d)
     * @param int $indServicio Indicador de servicio
     * @param array $referencias Referencias (opcional)
     * @return array Documento preparado
     */
    private function prepararDocumentoBoleta(
        array $emisor, 
        array $receptor, 
        array $detalle, 
        int $folio, 
        string $fechaEmision = null,
        int $indServicio = 3,
        array $referencias = []
    ): array {
        $emisorFormateado = [
            'RUTEmisor' => $emisor['RUTEmisor'], 
            'RznSocEmisor' => $emisor['RznSoc'], 
            'GiroEmisor' => $emisor['GiroEmis'], 
            'Acteco' => $emisor['Acteco'] ?? null,
            'DirOrigen' => $emisor['DirOrigen'], 
            'CmnaOrigen' => $emisor['CmnaOrigen'],
            'CiudadOrigen' => $emisor['CiudadOrigen'] ?? $emisor['CmnaOrigen'] 
        ];
        
        if ($emisorFormateado['Acteco'] === null) {
            unset($emisorFormateado['Acteco']);
        }

        $detalleNormalizado = [];
        $todosExentos = true; 
        
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
            
            if (!isset($item['IndExe']) || $item['IndExe'] != 1) { 
                $todosExentos = false; 
            }
            
            $detalleNormalizado[] = $item; 
        }
        
        $tipoDTE = $todosExentos ? 41 : 39;

        $documento = [
            'Encabezado' => [
                'IdDoc' => [ 
                    'TipoDTE' => $tipoDTE, 
                    'Folio' => $folio, 
                    'FchEmis' => $fechaEmision ?? date('Y-m-d'), 
                    'IndServicio' => $indServicio
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
            // Validar y formatear referencias
            $referenciasNormalizadas = [];
            foreach ($referencias as $index => $ref) {
                if (!isset($ref['NroLinRef'])) {
                    $ref['NroLinRef'] = $index + 1;
                }
                $referenciasNormalizadas[] = $ref;
            }
            
            $documento['Referencia'] = $referenciasNormalizadas;
        }
        
        return $documento;
    }
}