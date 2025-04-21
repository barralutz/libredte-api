<?php
namespace App\Adapters\LibreDTE\Core;

use App\Adapters\ConfigAdapter;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use Exception;

class CertificateManager
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
     * Obtiene el archivo de certificado desde base64 o path
     * 
     * @param array $certificadoData Datos del certificado
     * @return string Ruta al archivo del certificado
     * @throws Exception Si no se puede obtener el certificado
     */
    public function obtenerCertificado(array $certificadoData): string
    {
        if (isset($certificadoData['data'])) {
            return $this->configAdapter->saveCertificate($certificadoData['data']); 
        } else if (isset($certificadoData['file'])) {
            if (!file_exists($certificadoData['file'])) {
                throw new Exception("El archivo de certificado especificado no existe: " . $certificadoData['file']);
            }
            return $certificadoData['file'];
        } else {
            throw new Exception("Debe proporcionar el certificado en base64 (certificado.data) o la ruta al archivo (certificado.file)");
        }
    }
    
    /**
     * Carga la firma electrónica
     * 
     * @param string $certFile Ruta al archivo del certificado
     * @param string $password Contraseña del certificado
     * @return FirmaElectronica Objeto de firma electrónica
     * @throws Exception Si no se puede cargar la firma
     */
    public function cargarFirma(string $certFile, string $password): FirmaElectronica
    {
        $firma = new FirmaElectronica(['file' => $certFile, 'pass' => $password]);
        if (!$firma->getID()) {
            $errors = Log::readAll();
            throw new Exception("Error al cargar el certificado/firma: " . implode(", ", $errors));
        }
        return $firma;
    }
}