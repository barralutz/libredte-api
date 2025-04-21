<?php
namespace App\Adapters\LibreDTE\Communication;

use App\Adapters\ConfigAdapter;
use App\Adapters\LibreDTE\Core\DteGenerator;
use App\Adapters\LibreDTE\Output\PdfGenerator;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use Exception;

class SiiCommunicator
{
    private $configAdapter;
    private $dteGenerator;
    private $pdfGenerator;

    /**
     * Constructor
     *
     * @param ConfigAdapter $configAdapter
     */
    public function __construct(ConfigAdapter $configAdapter)
    {
        $this->configAdapter = $configAdapter;
        $this->dteGenerator = new DteGenerator();
        $this->pdfGenerator = new PdfGenerator($configAdapter);
    }

    /**
     * Envía un documento al SII
     *
     * @param Dte $dte Objeto DTE
     * @param FirmaElectronica $firma Objeto de firma electrónica
     * @param array $emisor Datos del emisor
     * @param string $tipoDocumento Tipo de documento ('boleta', 'factura', 'nota_credito', etc.)
     * @param array $opciones Opciones adicionales
     * @return array Resultado del envío, incluyendo el objeto DTE
     * @throws Exception Si hay errores en el envío
     */
    public function enviarDocumento(Dte $dte, FirmaElectronica $firma, array $emisor, string $tipoDocumento, array $opciones = []): array
    {
        try {
            // Sanitizamos el nombre del tipo de documento para directorios
            $tipoDocumentoSanitizado = preg_replace('/[^a-z0-9_]/', '_', strtolower($tipoDocumento));

            // Verificamos si el directorio existe y lo creamos si no
            $dirXml = $this->configAdapter->getTempPath("data/docs/{$tipoDocumentoSanitizado}s/xml");
            if (!is_dir($dirXml)) {
                if (!mkdir($dirXml, 0777, true)) {
                    throw new Exception("No se pudo crear el directorio para XML: {$dirXml}");
                }
            }

            // Crear EnvioDTE
            $EnvioDTE = new EnvioDte();
            $EnvioDTE->agregar($dte);
            $EnvioDTE->setFirma($firma);

            $fchResol = $emisor['FchResol'];
            $nroResol = (int)$emisor['NroResol'];

            // Preparar caratula
            $caratula = [
                'RutEmisor' => $emisor['RUTEmisor'],
                'RutEnvia' => $firma->getID(),
                'RutReceptor' => '60803000-K',
                'FchResol' => $fchResol,
                'NroResol' => $nroResol,
                'TmstFirmaEnv' => date('Y-m-d\TH:i:s'),
                'SubTotDTE' => [ 'TpoDTE' => $dte->getTipo(), 'NroDTE' => 1 ]
            ];

            $EnvioDTE->setCaratula($caratula);

            // Generar XML
            $xmlEnvioDTE = $EnvioDTE->generar();
            if (!$xmlEnvioDTE) {
                $errors = Log::readAll();
                throw new Exception("Error al generar XML EnvioDTE: " . implode(", ", $errors));
            }

            // Convertir a EnvioBOLETA si es necesario (solo para boletas)
            if ($tipoDocumentoSanitizado == 'boleta') {
                $xmlEnvioDTE = $this->convertirAEnvioBoleta($xmlEnvioDTE);
            }

            $folio = $dte->getFolio();
            $tipo = $dte->getTipo();

            // Guardar XML
            $xmlFilename = "envio_{$tipoDocumentoSanitizado}_{$tipo}_{$folio}_" . date('YmdHis') . '.xml';
            $xmlPath = $dirXml . '/' . $xmlFilename;

            // Verificar que el XML sea una cadena válida antes de guardarlo
            if (!is_string($xmlEnvioDTE) || empty($xmlEnvioDTE)) {
                throw new Exception("El XML generado no es válido (está vacío o no es una cadena)");
            }

            $bytesEscritos = file_put_contents($xmlPath, $xmlEnvioDTE);
            if ($bytesEscritos === false) {
                throw new Exception("No se pudo guardar XML envío: {$xmlPath}");
            }

            // Obtener token y enviar
            $token = \sasco\LibreDTE\Sii\Autenticacion::getToken($firma);
            if (!$token) {
                $errors = Log::readAll();
                throw new Exception("Error al obtener token SII: " . implode(", ", $errors));
            }

            $rutEnvia = $firma->getID();
            $rutEmisor = $emisor['RUTEmisor'];
            $trackId = \sasco\LibreDTE\Sii::enviar($rutEnvia, $rutEmisor, $xmlEnvioDTE, $token);

            if (!$trackId) {
                $errors = Log::readAll();
                throw new Exception("Error al enviar al SII: " . implode(", ", $errors));
            }

            // Generar PDF post-envío (si falla, no debería impedir el éxito del envío)
            $pdfResult = ['pdf_path' => null, 'pdf_content' => null];
            try {
                 $pdfResult = $this->pdfGenerator->generarPdfEnviado($dte, $emisor, $opciones, $tipoDocumentoSanitizado);
            } catch (Exception $pdfEx) {
                error_log("Advertencia: Falló la generación del PDF post-envío (Folio: {$folio}, Tipo: {$tipo}): " . $pdfEx->getMessage());
                // Continuamos sin el PDF
            }

            // Preparar resultado, incluyendo el objeto DTE
            return [
                'DTE' => $dte, // <-- Incluir el objeto DTE
                'sii_result' => [ // <-- Agrupar resultados del SII
                    'track_id' => $trackId,
                    'folio' => $folio,
                    'tipo' => $tipo,
                    'xml_path' => $xmlPath,
                    'pdf_path' => $pdfResult['pdf_path'],
                    'xml_content' => base64_encode($xmlEnvioDTE),
                    'pdf_content' => $pdfResult['pdf_content'] // Ya viene en base64 o null desde PdfGenerator
                ]
            ];

        } catch (Exception $e) {
            $errors = Log::readAll();
            // Log detallado del error
            error_log("Error SiiCommunicator::enviarDocumento: " . $e->getMessage() . ". Detalles LibreDTE: " . implode(", ", $errors) . ". Trace: " . $e->getTraceAsString());
            throw new Exception("Error durante envío SII: " . $e->getMessage()); // Mensaje más corto para el cliente
        }
    }

    /**
     * Convierte un EnvioDTE en EnvioBOLETA para boletas electrónicas
     *
     * @param string $xmlEnvioDTE XML de EnvioDTE
     * @return string XML convertido a EnvioBOLETA
     */
    private function convertirAEnvioBoleta(string $xmlEnvioDTE): string
    {
        $xmlEnvioBOLETA = str_replace(
            '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd" version="1.0">',
            '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd" version="1.0">',
            $xmlEnvioDTE
        );

        $xmlEnvioBOLETA = str_replace('</EnvioDTE>', '</EnvioBOLETA>', $xmlEnvioBOLETA);

        return $xmlEnvioBOLETA;
    }
}