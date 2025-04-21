<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\EnvioMultipleService;
use Exception;
use InvalidArgumentException;

/**
 * Controlador para manejar el envío múltiple de boletas en un solo sobre
 */
class EnvioMultipleController
{
    private $envioMultipleService;

    /**
     * Constructor
     *
     * @param EnvioMultipleService $envioMultipleService
     */
    public function __construct(EnvioMultipleService $envioMultipleService)
    {
        $this->envioMultipleService = $envioMultipleService;
    }

    /**
     * Emite múltiples boletas electrónicas en un solo sobre y las envía al SII
     * 
     * @param Request $request Objeto de la solicitud
     * @param Response $response Objeto de respuesta
     * @return Response Respuesta con resultado de la operación
     */
    public function emitirMultiple(Request $request, Response $response): Response
    {
        try {
            // Obtener datos de la solicitud
            $data = $request->getParsedBody();
            if ($data === null) {
                throw new InvalidArgumentException("No se recibieron datos JSON válidos en el cuerpo de la solicitud.", 400);
            }
            
            // Validar datos requeridos
            $this->validateRequest($data);
            
            // Procesar la solicitud con el servicio
            $result = $this->envioMultipleService->generarEnviarMultipleBoletas($data);
            
            // Preparar respuesta exitosa
            $payload = json_encode([
                'success' => true,
                'message' => 'Múltiples boletas emitidas y envío iniciado correctamente al SII.',
                'data' => $result
            ]);
            
            $response->getBody()->write($payload);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
                
        } catch (InvalidArgumentException $e) {
             return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error en EnvioMultipleController::emitirMultiple: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return $this->errorResponse($response, "Error interno al procesar el envío múltiple: " . $e->getMessage(), 500);
        }
    }
    
    /**
     * Valida los datos requeridos para el envío múltiple
     * 
     * @param array $data Datos de la solicitud
     * @throws InvalidArgumentException Si faltan datos requeridos o son inválidos
     */
    private function validateRequest(array $data): void
    {
        // Validar certificado
        if (empty($data['certificado']['data'])) {
            throw new InvalidArgumentException("El campo 'certificado.data' (contenido en base64) es requerido.", 400);
        }
        if (!isset($data['certificado']['pass']) || $data['certificado']['pass'] === '') { 
            throw new InvalidArgumentException("El campo 'certificado.pass' (contraseña) es requerido.", 400);
        }
        if (!$this->isBase64($data['certificado']['data'])) {
            throw new InvalidArgumentException("El contenido de 'certificado.data' debe estar codificado en base64.", 400);
        }

        // Validar Emisor
        $requiredEmisorFields = ['RUTEmisor', 'RznSoc', 'GiroEmis', 'DirOrigen', 'CmnaOrigen', 'FchResol', 'NroResol'];
        if (empty($data['emisor']) || !is_array($data['emisor'])) {
            throw new InvalidArgumentException("El campo 'emisor' es requerido y debe ser un objeto.", 400);
        }
        foreach ($requiredEmisorFields as $field) {
            if (!isset($data['emisor'][$field]) || ($data['emisor'][$field] === '' && $data['emisor'][$field] !== 0)) { 
                throw new InvalidArgumentException("El campo 'emisor.{$field}' es requerido y no puede estar vacío.", 400);
            }
        }
        if (isset($data['emisor']['RUTEmisor']) && !$this->validarRut($data['emisor']['RUTEmisor'])) {
            throw new InvalidArgumentException("El formato de 'emisor.RUTEmisor' no es válido.", 400);
        }

        // Validar Caratula
        if (empty($data['caratula']) || !is_array($data['caratula'])) {
            throw new InvalidArgumentException("El campo 'caratula' es requerido y debe ser un objeto.", 400);
        }

        // Validar Boletas
        if (empty($data['boletas']) || !is_array($data['boletas']) || count($data['boletas']) == 0) {
            throw new InvalidArgumentException("El campo 'boletas' es requerido y debe ser un arreglo no vacío.", 400);
        }
        
        foreach ($data['boletas'] as $index => $boleta) {
            // Validar CAF para cada boleta
            if (empty($boleta['caf']['data'])) {
                throw new InvalidArgumentException("El campo 'boletas[{$index}].caf.data' (contenido en base64) es requerido.", 400);
            }
            if (!$this->isBase64($boleta['caf']['data'])) {
                throw new InvalidArgumentException("El contenido de 'boletas[{$index}].caf.data' debe estar codificado en base64.", 400);
            }
            
            // Validar folio
            if (!isset($boleta['folio']) || !is_numeric($boleta['folio'])) {
                throw new InvalidArgumentException("El campo 'boletas[{$index}].folio' es requerido y debe ser numérico.", 400);
            }
            
            // Validar Receptor
            if (isset($boleta['receptor']) && !is_array($boleta['receptor'])) {
                throw new InvalidArgumentException("El campo 'boletas[{$index}].receptor' debe ser un objeto.", 400);
            }
            
            // Validar formato RUT Receptor si se proporciona
            if (!empty($boleta['receptor']['RUTRecep']) && !$this->validarRut($boleta['receptor']['RUTRecep'])) {
                throw new InvalidArgumentException("El formato de 'boletas[{$index}].receptor.RUTRecep' no es válido.", 400);
            }
            
            // Validar Detalle
            if (empty($boleta['detalle']) || !is_array($boleta['detalle'])) {
                throw new InvalidArgumentException("El campo 'boletas[{$index}].detalle' es requerido y debe ser un arreglo no vacío.", 400);
            }
            
            foreach ($boleta['detalle'] as $itemIndex => $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException("Cada elemento en 'boletas[{$index}].detalle' debe ser un objeto. Error en índice {$itemIndex}.", 400);
                }
                if (empty($item['NmbItem'])) {
                    throw new InvalidArgumentException("Cada item en 'boletas[{$index}].detalle' debe contener 'NmbItem'. Error en índice {$itemIndex}.", 400);
                }
                
                if (!isset($item['PrcItem']) && !isset($item['MontoItem'])) {
                    throw new InvalidArgumentException("Cada item en 'boletas[{$index}].detalle' debe contener 'PrcItem' o 'MontoItem'. Error en índice {$itemIndex}.", 400);
                }
            }
            
            // Validar Referencias (si existen)
            if (!empty($boleta['referencias'])) {
                if (!is_array($boleta['referencias'])) {
                    throw new InvalidArgumentException("El campo 'boletas[{$index}].referencias' debe ser un arreglo.", 400);
                }
                
                foreach ($boleta['referencias'] as $refIndex => $ref) {
                    if (!is_array($ref)) {
                        throw new InvalidArgumentException("Cada elemento en 'boletas[{$index}].referencias' debe ser un objeto. Error en índice {$refIndex}.", 400);
                    }
                }
            }
        }
    }

    /**
     * Verifica si un string parece estar codificado en base64
     * 
     * @param string $data String a verificar
     * @return bool True si parece base64, false si no
     */
    private function isBase64($data): bool
    {
        if (!is_string($data) || empty($data)) {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data) && base64_decode($data, true) !== false;
    }

    /**
     * Valida un RUT chileno (formato XXXXXXXX-X)
     * @param string $rut
     * @return bool
     */
    private function validarRut(string $rut): bool
    {
        $rut = preg_replace('/[^k0-9]/i', '', $rut);
        if (!preg_match('/^\d{7,8}[kK0-9]$/', $rut)) {
            return false;
        }
        $dv = substr($rut, -1);
        $numero = substr($rut, 0, strlen($rut) - 1);
        $i = 2;
        $suma = 0;
        foreach (array_reverse(str_split($numero)) as $v) {
            if ($i == 8) $i = 2;
            $suma += $v * $i;
            ++$i;
        }
        $dvr = 11 - ($suma % 11);
        if ($dvr == 11) $dvr = 0;
        if ($dvr == 10) $dvr = 'K';

        return strtoupper($dv) == $dvr;
    }

    /**
     * Crea una respuesta JSON de error estandarizada
     *
     * @param Response $response El objeto Response
     * @param string $message Mensaje de error
     * @param int $statusCode Código de estado HTTP
     * @return Response
     */
    private function errorResponse(Response $response, string $message, int $statusCode): Response
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
}