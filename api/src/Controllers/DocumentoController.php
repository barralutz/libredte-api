<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Adapters\LibreDTE\Output\DteToJsonFormatter; // Importar el formateador
use sasco\LibreDTE\Sii\Dte; // Importar DTE
use Exception;
use InvalidArgumentException;

/**
 * Controlador base abstracto para documentos electrónicos (boletas, facturas, etc.)
 */
abstract class DocumentoController
{
    /**
     * Servicio específico para el tipo de documento
     * @var mixed
     */
    protected $documentoService;

    /**
     * Nombre del tipo de documento (boleta, factura, etc.)
     * @var string
     */
    protected $tipoDocumentoNombre;

    /**
     * Emite un documento electrónico y lo envía al SII
     *
     * @param Request $request Objeto de la solicitud
     * @param Response $response Objeto de respuesta
     * @return Response Respuesta con resultado de la operación
     */
    public function emitir(Request $request, Response $response): Response
    {
        $data = null; // Inicializar por si falla getParsedBody

        try {
            // Obtener datos de la solicitud
            $data = $request->getParsedBody();
            if ($data === null) {
                 throw new InvalidArgumentException("No se recibieron datos JSON válidos en el cuerpo de la solicitud.", 400);
            }

            // Verificar si se debe generar datos para impresión (default: true)
            $generatePrintData = isset($data['generate_print_data']) ? (bool)$data['generate_print_data'] : true;
            $opciones = $data['opciones'] ?? []; // Obtener opciones

            // Validar datos requeridos para emisión
            $this->validateRequest($data, false); // false indica que es para emisión

            // === Cambio Principal: Procesar una sola vez ===
            // Llamar al método abstracto que usa el servicio específico. $esPreview = false
            // El servicio ahora devuelve ['DTE' => DteObject, 'sii_result' => [...]]
            $result = $this->procesarDocumento($data, false);

            // Extraer el objeto DTE y los datos del resultado del SII
            $dteObject = $result['DTE'] ?? null;
            $siiData = $result['sii_result'] ?? [];

            // Validar que obtuvimos datos del SII
            if (empty($siiData) || !isset($siiData['track_id'])) {
                 // Loguear el resultado completo para depuración
                 error_log("Error: procesarDocumento en modo emisión no devolvió 'sii_result' esperado. Resultado: " . print_r($result, true));
                 throw new Exception("El procesamiento del documento no devolvió el resultado esperado del SII.");
            }
            // === Fin Cambio Principal ===


            // Preparar la respuesta base con los datos del SII
            $responseData = [
                'success' => true,
                'message' => $this->tipoDocumentoNombre . ' emitida y envío iniciado correctamente al SII.',
                'data' => $siiData // Usar los datos del SII directamente
            ];

            // Si se solicitó datos para impresión Y obtuvimos el objeto DTE
            if ($generatePrintData && $dteObject instanceof Dte) {
                try {
                    // Instanciar el formateador directamente
                    $jsonFormatter = new DteToJsonFormatter();
                    // Formatear el DTE capturado
                    $printData = $jsonFormatter->formatToJson(
                        $dteObject,
                        $data['emisor'],
                        $opciones // Pasar opciones originales (papel_continuo, etc.)
                    );
                    $responseData['print_data'] = $printData;
                } catch (Exception $printError) {
                    // Si falla la generación de datos para impresión, agregar info de error pero continuar
                    $responseData['print_data_error'] = "Error generando datos para impresión: " . $printError->getMessage();
                    error_log("Error generando datos para impresión en DocumentoController::emitir: " . $printError->getMessage() . "\nTrace: " . $printError->getTraceAsString());
                }
            } elseif ($generatePrintData && !$dteObject) {
                 $responseData['print_data_error'] = "No se pudo generar datos para impresión porque el objeto DTE no fue devuelto por el proceso de emisión.";
                 error_log("Advertencia: No se generaron datos de impresión porque DTE era nulo en DocumentoController::emitir.");
            }

            $payload = json_encode($responseData);

            $response->getBody()->write($payload);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (InvalidArgumentException $e) {
             return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error en " . get_class($this) . "::emitir: " . $e->getMessage() . "\nInput Data: " . json_encode($data) . "\nTrace: " . $e->getTraceAsString());
            return $this->errorResponse($response, "Error interno al procesar la " . strtolower($this->tipoDocumentoNombre) . ": " . $e->getMessage(), 500);
        }
    }

    /**
     * Genera una vista previa del documento sin enviarlo al SII
     *
     * @param Request $request Objeto de la solicitud
     * @param Response $response Objeto de respuesta
     * @return Response Respuesta con el PDF o JSON del documento
     */
    public function preview(Request $request, Response $response): Response
    {
        try {
            // Obtener datos de la solicitud
            $data = $request->getParsedBody();
            if ($data === null) {
                throw new InvalidArgumentException("No se recibieron datos JSON válidos en el cuerpo de la solicitud.", 400);
            }

            // Validar datos requeridos (modo preview)
            $this->validateRequest($data, true);

            // Asegurar que 'previsualizar' sea true en las opciones pasadas al servicio
            $opciones = ($data['opciones'] ?? []);
            $opciones['previsualizar'] = true;
            $data['opciones'] = $opciones; // Actualizar datos con opciones modificadas

            // Procesar la solicitud con el servicio específico (modo previsualización)
            // El servicio ahora devuelve ['DTE' => DteObject, 'preview_data' => [...]]
            $result = $this->procesarDocumento($data, true);

            // Extraer los datos de la vista previa
            $previewData = $result['preview_data'] ?? [];

            // Validar que obtuvimos datos de preview
             if (empty($previewData) || (!isset($previewData['xml_content']) && !isset($previewData['pdf_content']))) {
                 error_log("Error: procesarDocumento en modo preview no devolvió 'preview_data' esperado. Resultado: " . print_r($result, true));
                 throw new Exception("El procesamiento de la vista previa no devolvió los datos esperados.");
            }

            // Determinar el tipo de respuesta (PDF o JSON con XML)
            $format = $opciones['formato'] ?? 'pdf';

            if ($format === 'pdf' && isset($previewData['pdf_content']) && $previewData['pdf_content']) {
                // Devolver el PDF
                $pdfContent = base64_decode($previewData['pdf_content']);
                $response->getBody()->write($pdfContent);
                $filename = strtolower($this->tipoDocumentoNombre) . '_preview_' . ($previewData['tipo'] ?? 'NA') . '_' . ($previewData['folio'] ?? 'NA') . '.pdf';
                return $response
                    ->withHeader('Content-Type', 'application/pdf')
                    ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                    ->withStatus(200);
            } else {
                // Devolver JSON con el XML (y opcionalmente PDF en base64 si se generó pero no se pidió)
                $responseData = [
                    'success' => true,
                    'message' => 'Vista previa de ' . strtolower($this->tipoDocumentoNombre) . ' generada.',
                    'data' => [
                        'folio' => $previewData['folio'] ?? null,
                        'tipo' => $previewData['tipo'] ?? null,
                        'xml_content' => $previewData['xml_content'] ?? null,
                        // Devolver PDF content sólo si NO se pidió formato PDF explícitamente y existe
                        'pdf_content' => ($format !== 'pdf' && isset($previewData['pdf_content'])) ? $previewData['pdf_content'] : null,
                    ]
                ];

                $payload = json_encode($responseData);
                $response->getBody()->write($payload);
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(200);
            }

        } catch (InvalidArgumentException $e) {
             return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
             error_log("Error en " . get_class($this) . "::preview: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return $this->errorResponse($response, "Error interno al generar la vista previa: " . $e->getMessage(), 500);
        }
    }

    /**
     * Método abstracto para procesar un documento (implementar en clases hijas)
     * Debe llamar al método procesarX del servicio correspondiente.
     *
     * @param array $data Datos del documento
     * @param bool $esPreview Si es true, solo genera sin enviar
     * @return array Resultado de la operación desde el servicio/adapter
     */
    abstract protected function procesarDocumento(array $data, bool $esPreview): array;

    /**
     * Valida los datos requeridos para la emisión/preview de documentos
     * Las clases hijas pueden sobrescribir este método para añadir validaciones específicas
     *
     * @param array $data Datos de la solicitud
     * @param bool $isPreview Indica si la validación es para preview (true) o emisión (false)
     * @throws InvalidArgumentException Si faltan datos requeridos o son inválidos
     */
    protected function validateRequest(array $data, bool $isPreview = false): void
    {
        // Validar certificado
        if (empty($data['certificado']) || !is_array($data['certificado'])) {
             throw new InvalidArgumentException("El campo 'certificado' es requerido y debe ser un objeto.", 400);
        }
        if (empty($data['certificado']['data'])) {
            throw new InvalidArgumentException("El campo 'certificado.data' (contenido en base64) es requerido.", 400);
        }
        if (!isset($data['certificado']['pass'])) { // Permitir contraseña vacía si es intencional
            throw new InvalidArgumentException("El campo 'certificado.pass' (contraseña) es requerido.", 400);
        }
        if (!$this->isBase64($data['certificado']['data'])) {
            throw new InvalidArgumentException("El contenido de 'certificado.data' debe estar codificado en base64.", 400);
        }

        // Validar CAF
         if (empty($data['caf']) || !is_array($data['caf'])) {
             throw new InvalidArgumentException("El campo 'caf' es requerido y debe ser un objeto.", 400);
        }
        if (empty($data['caf']['data'])) {
            throw new InvalidArgumentException("El campo 'caf.data' (contenido en base64) es requerido.", 400);
        }
        if (!$this->isBase64($data['caf']['data'])) {
            throw new InvalidArgumentException("El contenido de 'caf.data' debe estar codificado en base64.", 400);
        }

        // Validar Emisor
        $requiredEmisorFields = ['RUTEmisor', 'RznSoc', 'GiroEmis', 'DirOrigen', 'CmnaOrigen'];
        // Campos adicionales requeridos SOLO para emisión (no preview)
        if (!$isPreview) {
            $requiredEmisorFields[] = 'FchResol';
            $requiredEmisorFields[] = 'NroResol';
        }
        if (empty($data['emisor']) || !is_array($data['emisor'])) {
            throw new InvalidArgumentException("El campo 'emisor' es requerido y debe ser un objeto.", 400);
        }
        foreach ($requiredEmisorFields as $field) {
            // NroResol puede ser 0, así que necesita una comprobación especial
             if ($field === 'NroResol') {
                 if (!isset($data['emisor'][$field]) || ($data['emisor'][$field] === '' && $data['emisor'][$field] !== 0)) {
                      throw new InvalidArgumentException("El campo 'emisor.{$field}' es requerido y no puede estar vacío (puede ser 0).", 400);
                 }
             } elseif (!isset($data['emisor'][$field]) || $data['emisor'][$field] === '') {
                 throw new InvalidArgumentException("El campo 'emisor.{$field}' es requerido y no puede estar vacío.", 400);
             }
        }
        if (isset($data['emisor']['RUTEmisor']) && !$this->validarRut($data['emisor']['RUTEmisor'])) {
            throw new InvalidArgumentException("El formato de 'emisor.RUTEmisor' no es válido.", 400);
        }

        // Validar Detalle
        if (empty($data['detalle']) || !is_array($data['detalle'])) {
            throw new InvalidArgumentException("El campo 'detalle' es requerido y debe ser un arreglo no vacío.", 400);
        }
        if (count($data['detalle']) === 0) {
             throw new InvalidArgumentException("El campo 'detalle' no puede ser un arreglo vacío.", 400);
        }

        foreach ($data['detalle'] as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("Cada elemento en 'detalle' debe ser un objeto (arreglo asociativo). Error en índice {$index}.", 400);
            }
            if (empty($item['NmbItem'])) {
                throw new InvalidArgumentException("Cada item en 'detalle' debe contener 'NmbItem'. Error en índice {$index}.", 400);
            }

            // Debe tener PrcItem o MontoItem
            if (!isset($item['PrcItem']) && !isset($item['MontoItem'])) {
                throw new InvalidArgumentException("Cada item en 'detalle' debe contener 'PrcItem' o 'MontoItem'. Error en índice {$index}.", 400);
            }
             // Si tiene PrcItem, también debería tener QtyItem (o asumir 1)
            if (isset($item['PrcItem']) && !isset($item['QtyItem'])) {
                 // Podríamos asumir 1 aquí si quisiéramos ser permisivos, pero es mejor requerirlo
                 // throw new InvalidArgumentException("Si se proporciona 'PrcItem', también se requiere 'QtyItem' en el detalle. Error en índice {$index}.", 400);
                 // Opcionalmente: $data['detalle'][$index]['QtyItem'] = $item['QtyItem'] ?? 1; // Modificaría $data
                 // Por ahora, dejaremos que el Preparer lo maneje si tiene lógica para ello.
            }


            if (isset($item['MontoItem']) && (!is_numeric($item['MontoItem']) || $item['MontoItem'] <= 0)) {
                throw new InvalidArgumentException("Si se proporciona 'MontoItem', debe ser un número positivo en el detalle. Error en índice {$index}.", 400);
            }
            if (isset($item['PrcItem']) && !is_numeric($item['PrcItem'])) {
                throw new InvalidArgumentException("Si se proporciona 'PrcItem', debe ser un número. Error en índice {$index}.", 400);
            }
            if (isset($item['QtyItem']) && (!is_numeric($item['QtyItem']) || $item['QtyItem'] <= 0)) {
                throw new InvalidArgumentException("Si se proporciona 'QtyItem', debe ser un número positivo. Error en índice {$index}.", 400);
            }
            if (isset($item['IndExe']) && !in_array($item['IndExe'], [1, null, ''], true)) {
                throw new InvalidArgumentException("Si se proporciona 'IndExe', debe ser 1 (exento) o no incluirse/ser null/vacío (afecto). Error en índice {$index}.", 400);
            }
        }

        // Validar papel_continuo si está presente
        if (isset($data['opciones']['papel_continuo'])) {
            // Convertir a entero para comparación estricta
            $papelContinuo = filter_var($data['opciones']['papel_continuo'], FILTER_VALIDATE_INT);
             // 0 es válido (carta), los otros son mm
            if ($papelContinuo === false || !in_array($papelContinuo, [0, 57, 75, 80, 110], true)) {
                throw new InvalidArgumentException("El valor de 'opciones.papel_continuo' debe ser 0 (carta), 57, 75, 80 o 110.", 400);
            }
        }
    }

    /**
     * Verifica si un string parece estar codificado en base64
     *
     * @param string $data String a verificar
     * @return bool True si parece base64, false si no
     */
    protected function isBase64($data): bool
    {
        if (!is_string($data) || empty($data)) {
            return false;
        }
        // Expresión regular mejorada para manejar saltos de línea y padding opcional
        return (bool) preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data) && base64_decode($data, true) !== false;
    }

    /**
     * Valida un RUT chileno (formato XXXXXXXX-X)
     * @param string $rut
     * @return bool
     */
    protected function validarRut(string $rut): bool
    {
        $rut = preg_replace('/[^kK0-9]/i', '', $rut); // Limpiar puntos y guión, mantener K
        if (!preg_match('/^\d{7,8}[kK0-9]$/', $rut)) { // Validar formato numérico + DV
            return false;
        }
        $dv = strtoupper(substr($rut, -1)); // Obtener DV y pasarlo a mayúscula
        $numero = substr($rut, 0, strlen($rut) - 1); // Obtener número
        $i = 2;
        $suma = 0;
        foreach (array_reverse(str_split($numero)) as $v) {
            $suma += $v * $i;
            $i++;
            if ($i == 8) $i = 2; // Reiniciar ciclo 2-7
        }
        $dvr = 11 - ($suma % 11); // Calcular dígito verificador esperado
        if ($dvr == 11) $dvr = '0';
        if ($dvr == 10) $dvr = 'K';

        return $dv == $dvr; // Comparar DV calculado con el proporcionado
    }

    /**
     * Crea una respuesta JSON de error estandarizada
     *
     * @param Response $response El objeto Response
     * @param string $message Mensaje de error
     * @param int $statusCode Código de estado HTTP
     * @return Response
     */
    protected function errorResponse(Response $response, string $message, int $statusCode): Response
    {
         $payload = json_encode([
            'success' => false,
            'message' => $message
        ]);

        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * Genera datos JSON del documento para impresión térmica (Endpoint dedicado)
     * Este método AHORA usa el Trait, que llama al servicio/adapter en modo preview.
     *
     * @param Request $request Objeto de la solicitud
     * @param Response $response Objeto de respuesta
     * @return Response Respuesta con el JSON del documento
     */
    public function jsonParaImpresion(Request $request, Response $response): Response
    {
        try {
            // Obtener datos de la solicitud
            $data = $request->getParsedBody();
            if ($data === null) {
                throw new InvalidArgumentException("No se recibieron datos JSON válidos en el cuerpo de la solicitud.", 400);
            }

            // Validar datos requeridos (usamos validación de preview que es menos estricta con campos SII)
            $this->validateRequest($data, true);

            // *** Usar el método del Trait ***
            // El trait se encargará de llamar al adapter en modo preview
            // y formatear el DTE resultante. Necesita que XmlGenerator devuelva DTE.
            $jsonData = $this->documentoService->obtenerJsonParaImpresion($data);

            // Devolver el JSON
            $responseData = [
                'success' => true,
                'message' => 'Datos para impresión térmica generados correctamente.',
                'data' => $jsonData
            ];

            $payload = json_encode($responseData);
            $response->getBody()->write($payload);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error en " . get_class($this) . "::jsonParaImpresion: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return $this->errorResponse($response, "Error interno al generar datos para impresión: " . $e->getMessage(), 500);
        }
    }
}