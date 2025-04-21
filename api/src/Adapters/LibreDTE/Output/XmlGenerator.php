<?php
namespace App\Adapters\LibreDTE\Output;

use App\Adapters\ConfigAdapter;
use App\Adapters\LibreDTE\Core\DteGenerator;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\FirmaElectronica;
use Exception;

class XmlGenerator
{
    private $configAdapter;
    private $dteGenerator;

    /**
     * Constructor
     *
     * @param ConfigAdapter $configAdapter
     */
    public function __construct(ConfigAdapter $configAdapter)
    {
        $this->configAdapter = $configAdapter;
        $this->dteGenerator = new DteGenerator();
    }

    /**
     * Genera una vista previa del DTE (XML, opcionalmente PDF y objeto DTE)
     *
     * @param Dte $dte Objeto DTE
     * @param FirmaElectronica $firma Objeto de firma electrónica
     * @param array $emisor Datos del emisor
     * @param array $opciones Opciones adicionales
     * @return array Resultado con contenido XML/PDF y el objeto DTE
     * @throws Exception Si hay errores en la generación
     */
    public function generarVistaPrevia(Dte $dte, FirmaElectronica $firma, array $emisor, array $opciones): array
    {
        $folio = $dte->getFolio();
        $tipo = $dte->getTipo();
        $tipoNombre = $this->dteGenerator->obtenerNombreTipoDTE($tipo);
        $tipoNombreSanitizado = preg_replace('/[^a-z0-9_]/', '_', strtolower($tipoNombre));

        $baseDir = "data/docs/{$tipoNombreSanitizado}s";
        $dirXml = $this->configAdapter->getTempPath("{$baseDir}/xml");

        if (!is_dir($dirXml)) {
            if (!mkdir($dirXml, 0777, true)) {
                throw new Exception("No se pudo crear el directorio para XML: {$dirXml}");
            }
        }

        $xmlContent = $dte->saveXML();
        if (!$xmlContent) {
            throw new Exception("Error al generar XML preview");
        }

        $xmlFilename = "{$tipoNombreSanitizado}_preview_{$tipo}_{$folio}_" . date('YmdHis') . '.xml';
        $xmlPath = $dirXml . '/' . $xmlFilename;

        if (file_put_contents($xmlPath, $xmlContent) === false) {
            throw new Exception("No se pudo guardar XML preview: {$xmlPath}");
        }

        // Preparar resultado base incluyendo el DTE
        $result = [
            'DTE' => $dte, // <-- Incluir el objeto DTE
            'preview_data' => [ // <-- Agrupar datos de preview
                'xml_content' => base64_encode($xmlContent),
                'xml_path' => $xmlPath,
                'folio' => $folio,
                'tipo' => $tipo,
                'pdf_content' => null, // Inicializar
                'pdf_path' => null    // Inicializar
            ]
        ];

        // Generar PDF si se solicita
        if (($opciones['formato'] ?? 'pdf') === 'pdf') {
            $pdfGenerator = new PdfGenerator($this->configAdapter);
            try {
                $pdfResult = $pdfGenerator->generarPdfVistaPrevia($dte, $emisor, $opciones);
                // Añadir resultados del PDF al array de preview_data
                $result['preview_data']['pdf_content'] = $pdfResult['pdf_content']; // Ya viene en base64 o null
                $result['preview_data']['pdf_path'] = $pdfResult['pdf_path'];
            } catch (Exception $pdfEx) {
                 error_log("Advertencia: No se pudo generar PDF de vista previa (Folio: {$folio}, Tipo: {$tipo}): " . $pdfEx->getMessage());
                 // Mantenemos pdf_content y pdf_path como null
            }
        }

        return $result;
    }
}