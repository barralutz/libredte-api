<?php
namespace App\Adapters\LibreDTE\Output;

use App\Adapters\ConfigAdapter;
use App\Adapters\LibreDTE\Core\DteGenerator;
use sasco\LibreDTE\Sii\Dte;
use Exception;

class PdfGenerator
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
     * Genera un PDF de vista previa del DTE
     * 
     * @param Dte $dte Objeto DTE
     * @param array $emisor Datos del emisor
     * @param array $opciones Opciones adicionales
     * @return array Resultado con contenido PDF
     * @throws Exception Si hay errores en la generación
     */
    public function generarPdfVistaPrevia(Dte $dte, array $emisor, array $opciones): array
    {
        $folio = $dte->getFolio();
        $tipo = $dte->getTipo();
        $tipoNombre = $this->dteGenerator->obtenerNombreTipoDTE($tipo);
        
        // Sanitizar nombre para que sea seguro usarlo en rutas de directorio
        $tipoNombreSanitizado = preg_replace('/[^a-z0-9_]/', '_', strtolower($tipoNombre));
        
        // Directorio base para documentos de este tipo
        $baseDir = "data/docs/{$tipoNombreSanitizado}s";
        $dirPdf = $this->configAdapter->getTempPath("{$baseDir}/pdf");
        
        // Asegurar que el directorio exista
        if (!is_dir($dirPdf)) {
            if (!mkdir($dirPdf, 0777, true)) {
                throw new Exception("No se pudo crear el directorio para PDF: {$dirPdf}");
            }
        }
        
        // Determinar el tipo de papel - CLAVE: El constructor de PDF\Dte solo recibe UN parámetro
        $papelContinuo = isset($opciones['papel_continuo']) ? (int)$opciones['papel_continuo'] : 0;
        
        // Debug para ver qué valor se está pasando
        error_log("DEBUG: generarPdfVistaPrevia usando papel_continuo = " . $papelContinuo);
        
        // IMPORTANTE: Sólo pasamos un único parámetro al constructor
        $pdf = new \sasco\LibreDTE\Sii\Dte\PDF\Dte($papelContinuo);
        
        if (isset($emisor['FchResol']) && ($emisor['NroResol'] === 0 || !empty($emisor['NroResol']))) {
            $pdf->setResolucion([
                'FchResol' => $emisor['FchResol'], 
                'NroResol' => (int)$emisor['NroResol']
            ]);
        } else { 
            error_log("Advertencia PDF preview: FchResol/NroResol faltantes (Tipo: {$tipo}, Folio: {$folio})."); 
        }
        
        if (!empty($opciones['logo_path'])) {
            if (file_exists($opciones['logo_path'])) { 
                $pdf->setLogo($opciones['logo_path']); 
            } else { 
                error_log("Advertencia PDF preview: Logo no encontrado: " . $opciones['logo_path']); 
            }
        }
        
        $ted = $dte->getTED();
        if (!$ted) { 
            throw new Exception("Error al obtener TED para PDF preview (Tipo: {$tipo}, Folio: {$folio})"); 
        }
        
        $pdf->agregar($dte->getDatos(), $ted);
        
        // Usar el mismo nombre sanitizado para el archivo
        $pdfFilename = "{$tipoNombreSanitizado}_preview_{$tipo}_{$folio}_" . date('YmdHis') . '.pdf';
        $pdfPath = $dirPdf . '/' . $pdfFilename;
        
        try { 
            $pdf->Output($pdfPath, 'F'); 
        } catch (Exception $e) { 
            throw new Exception("Error al generar PDF preview (Tipo: {$tipo}, Folio: {$folio}): " . $e->getMessage()); 
        }
        
        if (!file_exists($pdfPath)) { 
            throw new Exception("No se pudo generar PDF preview en: {$pdfPath}"); 
        }
        
        $pdfContent = file_get_contents($pdfPath);
        if ($pdfContent === false) { 
            throw new Exception("No se pudo leer PDF preview: {$pdfPath}"); 
        }
        
        return [
            'pdf_content' => base64_encode($pdfContent),
            'pdf_path' => $pdfPath
        ];
    }

    /**
     * Genera un PDF para un DTE ya enviado
     * 
     * @param Dte $dte Objeto DTE
     * @param array $emisor Datos del emisor
     * @param array $opciones Opciones adicionales
     * @param string $tipoDocumento Tipo de documento (boleta/factura/nota_credito/etc)
     * @return array Resultado con contenido PDF
     */
    public function generarPdfEnviado(Dte $dte, array $emisor, array $opciones, string $tipoDocumento): array
    {
        $folio = $dte->getFolio();
        $tipo = $dte->getTipo();
        
        // Sanitizar nombre para que sea seguro usarlo en rutas de directorio
        $tipoDocumentoSanitizado = preg_replace('/[^a-z0-9_]/', '_', strtolower($tipoDocumento));
        
        // Directorio base para documentos de este tipo
        $baseDir = "data/docs/{$tipoDocumentoSanitizado}s";
        $dirPdf = $this->configAdapter->getTempPath("{$baseDir}/pdf");
        
        // Asegurar que el directorio exista
        if (!is_dir($dirPdf)) {
            if (!mkdir($dirPdf, 0777, true)) {
                error_log("Error: No se pudo crear el directorio para PDF: {$dirPdf}");
                return [
                    'pdf_path' => null,
                    'pdf_content' => null
                ];
            }
        }
        
        // Determinar el tipo de papel - CLAVE: El constructor de PDF\Dte solo recibe UN parámetro
        $papelContinuo = isset($opciones['papel_continuo']) ? (int)$opciones['papel_continuo'] : 0;
        
        // Debug para ver qué valor se está pasando
        error_log("DEBUG: generarPdfEnviado usando papel_continuo = " . $papelContinuo);
        
        // IMPORTANTE: Sólo pasamos un único parámetro al constructor
        $pdf = new \sasco\LibreDTE\Sii\Dte\PDF\Dte($papelContinuo);
        
        $pdf->setResolucion([
            'FchResol' => $emisor['FchResol'], 
            'NroResol' => (int)$emisor['NroResol']
        ]);
        
        if (!empty($opciones['logo_path'])) {
            if (file_exists($opciones['logo_path'])) { 
                $pdf->setLogo($opciones['logo_path']); 
            } else { 
                error_log("Advertencia PDF envío: Logo no encontrado: " . $opciones['logo_path']); 
            }
        }
        
        $ted = $dte->getTED();
        $pdfPath = null;
        $pdfContent = null;
        
        if (!$ted) { 
            error_log("Advertencia PDF envío: No se pudo obtener TED (Tipo: {$tipo}, Folio: {$folio})"); 
        } else { 
            $pdf->agregar($dte->getDatos(), $ted); 
        }
        
        $pdfFilename = "{$tipoDocumentoSanitizado}_{$tipo}_{$folio}_" . date('YmdHis') . '.pdf';
        $pdfPath = $dirPdf . '/' . $pdfFilename;
        
        try { 
            if ($ted) { 
                $pdf->Output($pdfPath, 'F'); 
            } 
        } catch (Exception $e) { 
            error_log("Error al generar PDF envío (Tipo: {$tipo}, Folio: {$folio}): " . $e->getMessage()); 
            $pdfPath = null; 
        }

        if ($pdfPath && file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            if ($pdfContent === false) { 
                error_log("Error al leer PDF envío (Tipo: {$tipo}, Folio: {$folio}): {$pdfPath}"); 
                $pdfPath = null; 
                $pdfContent = null; 
            }
        } else { 
            $pdfPath = null; 
            $pdfContent = null; 
        }
        
        return [
            'pdf_path' => $pdfPath,
            'pdf_content' => $pdfContent ? base64_encode($pdfContent) : null
        ];
    }
}