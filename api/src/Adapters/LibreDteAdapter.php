<?php
namespace App\Adapters;

use App\Adapters\LibreDTE\Core\Environment;
use App\Adapters\LibreDTE\Core\CertificateManager;
use App\Adapters\LibreDTE\Core\FoliosManager;
use App\Adapters\LibreDTE\Core\DteGenerator;
use App\Adapters\LibreDTE\Documents\BoletaPreparer;
use App\Adapters\LibreDTE\Documents\FacturaPreparer;
use App\Adapters\LibreDTE\Documents\NotaCreditoPreparer; // Asegurar que esté importado
use App\Adapters\LibreDTE\Output\XmlGenerator;
use App\Adapters\LibreDTE\Output\PdfGenerator;
use App\Adapters\LibreDTE\Communication\SiiCommunicator;

use sasco\LibreDTE\Log;
use Exception;

class LibreDteAdapter
{
    private $environment;
    private $certificateManager;
    private $foliosManager;
    private $dteGenerator;
    private $boletaPreparer;
    private $facturaPreparer;
    private $notaCreditoPreparer; // Añadir instancia
    private $xmlGenerator;
    private $pdfGenerator;
    private $siiCommunicator;
    private $configAdapter;

    public function __construct(ConfigAdapter $configAdapter)
    {
        $this->configAdapter = $configAdapter;
        $this->environment = new Environment();
        $this->certificateManager = new CertificateManager($configAdapter);
        $this->foliosManager = new FoliosManager($configAdapter);
        $this->dteGenerator = new DteGenerator();
        $this->boletaPreparer = new BoletaPreparer();
        $this->facturaPreparer = new FacturaPreparer();
        $this->notaCreditoPreparer = new NotaCreditoPreparer(); // Inicializar
        $this->xmlGenerator = new XmlGenerator($configAdapter);
        $this->pdfGenerator = new PdfGenerator($configAdapter);
        $this->siiCommunicator = new SiiCommunicator($configAdapter);
    }

    /**
     * Genera y/o envía una boleta electrónica
     *
     * @param array $data Datos para generar la boleta
     * @param bool $previsualizar Si es true, solo genera sin enviar
     * @return array Resultado de la operación (incluye DTE y sii_result o preview_data)
     */
    public function generarEnviarBoleta(array $data, bool $previsualizar = false): array
    {
        $certFile = null;
        $cafFile = null;
        $cleanupFiles = []; // Track files to clean up

        try {
            $this->environment->setup($data['certificacion'] ?? true);

            $certFile = $this->certificateManager->obtenerCertificado($data['certificado']);
            if (isset($data['certificado']['data'])) $cleanupFiles[] = $certFile; // Mark for cleanup only if created from base64

            $cafFile = $this->foliosManager->obtenerCaf($data['caf'], $data['caf_file'] ?? null);
             if (isset($data['caf']['data'])) $cleanupFiles[] = $cafFile; // Mark for cleanup only if created from base64

            $firma = $this->certificateManager->cargarFirma($certFile, $data['certificado']['pass']);
            $folios = $this->foliosManager->cargarFolios($cafFile);

            $folioSolicitado = $data['folio'] ?? $folios->getDesde();
            $this->foliosManager->validarFolio($folioSolicitado, $folios);

            $documento = $this->boletaPreparer->prepararDocumento(
                $data['emisor'],
                $data['receptor'] ?? [],
                $data['detalle'],
                $folioSolicitado,
                $data['referencias'] ?? []
            );

            $dte = $this->dteGenerator->generar($documento, $firma, $folios);

            if ($previsualizar) {
                // generarVistaPrevia ahora devuelve ['DTE' => $dte, 'preview_data' => [...]]
                return $this->xmlGenerator->generarVistaPrevia($dte, $firma, $data['emisor'], $data['opciones'] ?? []);
            } else {
                $this->validarCamposEmisionSii($data['emisor']);
                // enviarDocumento ahora devuelve ['DTE' => $dte, 'sii_result' => [...]]
                return $this->siiCommunicator->enviarDocumento($dte, $firma, $data['emisor'], 'boleta', $data['opciones'] ?? []);
            }
        } catch (Exception $e) {
            $errors = Log::readAll();
            throw new Exception("Error al procesar boleta: " . $e->getMessage() . ". Detalles LibreDTE: " . implode(", ", $errors));
        } finally {
            // Limpieza de archivos temporales creados desde base64
            foreach ($cleanupFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Genera y/o envía una factura electrónica
     *
     * @param array $data Datos para generar la factura
     * @param bool $previsualizar Si es true, solo genera sin enviar
     * @return array Resultado de la operación (incluye DTE y sii_result o preview_data)
     */
    public function generarEnviarFactura(array $data, bool $previsualizar = false): array
    {
        $certFile = null;
        $cafFile = null;
        $cleanupFiles = [];

        try {
            $this->environment->setup($data['certificacion'] ?? true);

            $certFile = $this->certificateManager->obtenerCertificado($data['certificado']);
             if (isset($data['certificado']['data'])) $cleanupFiles[] = $certFile;

            $cafFile = $this->foliosManager->obtenerCaf($data['caf'], $data['caf_file'] ?? null);
             if (isset($data['caf']['data'])) $cleanupFiles[] = $cafFile;

            $firma = $this->certificateManager->cargarFirma($certFile, $data['certificado']['pass']);
            $folios = $this->foliosManager->cargarFolios($cafFile);

            $folioSolicitado = $data['folio'] ?? $folios->getDesde();
            $this->foliosManager->validarFolio($folioSolicitado, $folios);

            $documento = $this->facturaPreparer->prepararDocumento(
                $data['emisor'],
                $data['receptor'], // Receptor es obligatorio para factura
                $data['detalle'],
                $folioSolicitado,
                $data['referencias'] ?? []
            );

            $dte = $this->dteGenerator->generar($documento, $firma, $folios);

            if ($previsualizar) {
                return $this->xmlGenerator->generarVistaPrevia($dte, $firma, $data['emisor'], $data['opciones'] ?? []);
            } else {
                $this->validarCamposEmisionSii($data['emisor']);
                return $this->siiCommunicator->enviarDocumento($dte, $firma, $data['emisor'], 'factura', $data['opciones'] ?? []);
            }
        } catch (Exception $e) {
            $errors = Log::readAll();
            throw new Exception("Error al procesar factura: " . $e->getMessage() . ". Detalles LibreDTE: " . implode(", ", $errors));
        } finally {
             foreach ($cleanupFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }


    /**
     * Genera y/o envía una nota de crédito electrónica
     *
     * @param array $data Datos para generar la nota de crédito
     * @param bool $previsualizar Si es true, solo genera sin enviar
     * @return array Resultado de la operación (incluye DTE y sii_result o preview_data)
     */
    public function generarEnviarNotaCredito(array $data, bool $previsualizar = false): array
    {
        $certFile = null;
        $cafFile = null;
        $cleanupFiles = [];

        try {
            $this->environment->setup($data['certificacion'] ?? true);

            $certFile = $this->certificateManager->obtenerCertificado($data['certificado']);
             if (isset($data['certificado']['data'])) $cleanupFiles[] = $certFile;

            $cafFile = $this->foliosManager->obtenerCaf($data['caf'], $data['caf_file'] ?? null);
             if (isset($data['caf']['data'])) $cleanupFiles[] = $cafFile;

            $firma = $this->certificateManager->cargarFirma($certFile, $data['certificado']['pass']);
            $folios = $this->foliosManager->cargarFolios($cafFile);

            $folioSolicitado = $data['folio'] ?? $folios->getDesde();
            $this->foliosManager->validarFolio($folioSolicitado, $folios);

            // Usar NotaCreditoPreparer (asegurado en constructor)
            $documento = $this->notaCreditoPreparer->prepararDocumento(
                $data['emisor'],
                $data['receptor'],
                $data['detalle'],
                $folioSolicitado,
                $data['referencias'] // Referencias son obligatorias para NC
            );

            $dte = $this->dteGenerator->generar($documento, $firma, $folios);

            if ($previsualizar) {
                return $this->xmlGenerator->generarVistaPrevia($dte, $firma, $data['emisor'], $data['opciones'] ?? []);
            } else {
                $this->validarCamposEmisionSii($data['emisor']);
                // Usar tipo 'nota_credito' para el método de envío y directorios
                return $this->siiCommunicator->enviarDocumento($dte, $firma, $data['emisor'], 'nota_credito', $data['opciones'] ?? []);
            }
        } catch (Exception $e) {
            $errors = Log::readAll();
            throw new Exception("Error al procesar nota de crédito: " . $e->getMessage() . ". Detalles LibreDTE: " . implode(", ", $errors));
        } finally {
             foreach ($cleanupFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Valida que los campos necesarios para enviar al SII estén presentes
     */
    private function validarCamposEmisionSii(array $emisor): void
    {
        // Permitir NroResol 0
        if (!isset($emisor['NroResol']) || ($emisor['NroResol'] === '' && $emisor['NroResol'] !== 0)) {
            throw new Exception("El campo 'emisor.NroResol' es requerido para enviar al SII.");
        }
        if (empty($emisor['FchResol'])) {
            throw new Exception("El campo 'emisor.FchResol' es requerido para enviar al SII.");
        }
    }

    /**
     * Método proxy para configurar el ambiente
     *
     * @param bool $certificacion Si es true, usa ambiente de certificación
     */
    public function setupEnvironment(bool $certificacion): void
    {
        $this->environment->setup($certificacion);
    }
}