<?php
namespace App\Adapters\LibreDTE\Output;

use sasco\LibreDTE\Sii\Dte;
use SimpleXMLElement;

class DteToJsonFormatter
{
    public function formatToJson(Dte $dte, array $emisor, array $opciones = []): array
    {
        $datosDte = $dte->getDatos();
        $anchoPapel = isset($opciones['papel_continuo']) ? (int)$opciones['papel_continuo'] : ($opciones['ancho_papel'] ?? 80);

        // --- INICIO: Añadir Datos del TED con LOGGING ---
        $tedDataString = null;
        $tedXmlString = null;
        try {
            $tedXmlString = $dte->getTED();
        } catch (\Exception $e) {
             error_log("DteToJsonFormatter: Excepción al llamar a \$dte->getTED(): " . $e->getMessage());
             $tedXmlString = false;
        }

        if ($tedXmlString) {
            try {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($tedXmlString);

                if ($xml === false) {
                     $xml_errors = [];
                     foreach(libxml_get_errors() as $error) {
                         $xml_errors[] = $error->message;
                     }
                     libxml_clear_errors();
                     error_log("DteToJsonFormatter: Falló simplexml_load_string. Errores de Libxml: " . implode("; ", $xml_errors) . ". TED XML: " . $tedXmlString);
                     $tedDataString = "Error parseando TED";
                } elseif (!isset($xml->DD)) {
                     error_log("DteToJsonFormatter: El XML del TED fue parseado pero falta el nodo <DD>. TED XML: " . $tedXmlString);
                     $tedDataString = "Nodo DD no encontrado";
                } else {
                     // ***** CAMBIO CLAVE AQUÍ *****
                     // Usar asXML() para obtener el XML del nodo DD y su contenido
                     $tedDataString = $xml->DD->asXML();

                     // Verificar si asXML() devolvió algo útil
                     if ($tedDataString === false || trim($tedDataString) === '') {
                          error_log("DteToJsonFormatter: asXML() en el nodo DD falló o devolvió vacío. TED XML: " . $tedXmlString);
                          $tedDataString = "Error extrayendo contenido DD como XML";
                     } else {
                          error_log("DteToJsonFormatter: Nodo <DD> extraído como XML correctamente. Longitud: " . strlen($tedDataString));
                          // Opcional: Puedes querer remover saltos de línea/espacios extra si la librería PDF417 es sensible
                          // $tedDataString = preg_replace('/\s+/', ' ', trim($tedDataString));
                     }
                }

            } catch (\Exception $e) {
                error_log("DteToJsonFormatter: Excepción al procesar TED XML: " . $e->getMessage());
                $tedDataString = "Excepción al procesar TED";
            }
        } else {
             error_log("DteToJsonFormatter: El método getTED() no devolvió un string XML o falló.");
             $tedDataString = "TED no disponible";
        }
        // --- FIN: Añadir Datos del TED con LOGGING ---

        // Estructura base del documento para impresión
         $documentoJson = [
            'metadata' => [
                'ancho_papel' => $anchoPapel,
                'version' => '1.0',
                'fecha_generacion' => date('Y-m-d H:i:s'),
            ],
            'documento' => [
                'tipo' => $datosDte['Encabezado']['IdDoc']['TipoDTE'] ?? null,
                'folio' => $datosDte['Encabezado']['IdDoc']['Folio'] ?? null,
                'fecha_emision' => $datosDte['Encabezado']['IdDoc']['FchEmis'] ?? null,
                'tipo_nombre' => $this->obtenerNombreTipoDocumento($datosDte['Encabezado']['IdDoc']['TipoDTE'] ?? null),
                'fecha_vencimiento' => $datosDte['Encabezado']['IdDoc']['FchVenc'] ?? null,
            ],
            'emisor' => [
                'rut' => $datosDte['Encabezado']['Emisor']['RUTEmisor'] ?? null,
                'razon_social' => $datosDte['Encabezado']['Emisor']['RznSoc']
                                    ?? $datosDte['Encabezado']['Emisor']['RznSocEmisor']
                                    ?? '',
                'giro' => $datosDte['Encabezado']['Emisor']['GiroEmis']
                            ?? $datosDte['Encabezado']['Emisor']['GiroEmisor']
                            ?? '',
                'direccion' => $datosDte['Encabezado']['Emisor']['DirOrigen'] ?? null,
                'comuna' => $datosDte['Encabezado']['Emisor']['CmnaOrigen'] ?? null,
                'ciudad' => $datosDte['Encabezado']['Emisor']['CiudadOrigen'] ?? $datosDte['Encabezado']['Emisor']['CmnaOrigen'] ?? null,
                'telefono' => $datosDte['Encabezado']['Emisor']['Telefono'] ?? null,
                'email' => $datosDte['Encabezado']['Emisor']['CorreoEmisor'] ?? null,
                'resolucion' => [
                    'numero' => $emisor['NroResol'] ?? null,
                    'fecha' => $emisor['FchResol'] ?? null,
                ],
            ],
            'receptor' => $this->formatearReceptor($datosDte['Encabezado']['Receptor'] ?? []),
            'detalle' => $this->formatearDetalle($datosDte['Detalle'] ?? []),
            'totales' => $this->formatearTotales($datosDte['Encabezado']['Totales'] ?? []),

            // --- INICIO: Añadir bloque TED al JSON ---
            'ted' => [
                // Si $tedDataString sigue siendo null después de los checks, se incluirá como null.
                // Si hubo error, tendrá el string de error. Si tuvo éxito, tendrá los datos.
                'data_string' => $tedDataString,
                'resolucion_numero' => $emisor['NroResol'] ?? null,
                'resolucion_fecha' => $emisor['FchResol'] ?? null,
                'texto_verificacion' => 'Verifique documento: www.sii.cl'
            ],
            // --- FIN: Añadir bloque TED al JSON ---
        ];

         // Agregar referencias si existen
        if (!empty($datosDte['Referencia'])) {
            $documentoJson['referencias'] = $this->formatearReferencias($datosDte['Referencia']);
        }

        // Agregar descuentos/recargos globales si existen
        if (!empty($datosDte['DscRcgGlobal'])) {
            $documentoJson['descuentos_recargos'] = $this->formatearDescuentosRecargos($datosDte['DscRcgGlobal']);
        }

        // Agregar información adicional según tipo de documento
        $this->agregarInfoAdicionalPorTipo($documentoJson, $datosDte, $emisor);

        return $this->removeNullValuesRecursive($documentoJson);
    }

    // ... (Resto de los métodos formatearReceptor, formatearDetalle, etc., permanecen igual que en la versión anterior) ...

    /**
     * Formatea los datos del receptor
     *
     * @param array $receptor Datos del receptor
     * @return array Datos formateados
     */
    private function formatearReceptor(array $receptor): array
    {
        if (empty($receptor)) return [];

        $receptorFormateado = [
            'rut' => $receptor['RUTRecep'] ?? null,
            'razon_social' => $receptor['RznSocRecep'] ?? null,
            'giro' => $receptor['GiroRecep'] ?? null,
            'direccion' => $receptor['DirRecep'] ?? null,
            'comuna' => $receptor['CmnaRecep'] ?? null,
            'ciudad' => $receptor['CiudadRecep'] ?? null,
            'contacto' => $receptor['Contacto'] ?? null,
            'email' => $receptor['CorreoRecep'] ?? null,
        ];

        return $receptorFormateado;
    }

    /**
     * Formatea el detalle del documento
     *
     * @param array|null $detalle Detalle del documento (puede ser null)
     * @return array Detalle formateado
     */
    private function formatearDetalle($detalle): array
    {
         if (empty($detalle)) return [];

        if (!isset($detalle[0]) && isset($detalle['NmbItem'])) {
            $detalle = [$detalle];
        } elseif (!isset($detalle[0])) {
             error_log("DteToJsonFormatter: Se recibió un formato inesperado para detalle: " . print_r($detalle, true));
             return [];
        }

        $detalleFormateado = [];

        foreach ($detalle as $item) {
             if (!is_array($item)) continue;

            $itemFormateado = [
                'nombre' => $item['NmbItem'] ?? null,
                'cantidad' => $item['QtyItem'] ?? 1,
                'precio_unitario' => $item['PrcItem'] ?? null,
                'monto' => $item['MontoItem'] ?? null,
                'descuento' => $item['DescuentoMonto'] ?? null,
                'descuento_porcentaje' => $item['DescuentoPct'] ?? null,
                'recargo' => $item['RecargoMonto'] ?? null,
                'recargo_porcentaje' => $item['RecargoPct'] ?? null,
                'unidad' => $item['UnmdItem'] ?? null,
                'es_exento' => isset($item['IndExe']) && $item['IndExe'] == 1,
                'descripcion' => $item['DscItem'] ?? null,
            ];

            if (!empty($item['CdgItem'])) {
                $itemFormateado['codigos'] = $this->formatearCodigosItem($item['CdgItem']);
            }

            $detalleFormateado[] = $itemFormateado;
        }

        return $detalleFormateado;
    }

    /**
     * Formatea los códigos de un item
     *
     * @param array $codigos Códigos del item
     * @return array Códigos formateados
     */
    private function formatearCodigosItem($codigos): array
    {
        if (empty($codigos)) return [];

        if (!isset($codigos[0]) && isset($codigos['TpoCodigo'])) {
            $codigos = [$codigos];
        } elseif (!isset($codigos[0])) {
             error_log("DteToJsonFormatter: Se recibió un formato inesperado para CdgItem: " . print_r($codigos, true));
            return [];
        }

        $codigosFormateados = [];

        foreach ($codigos as $codigo) {
             if (!is_array($codigo)) continue;
            if (!empty($codigo['VlrCodigo'])) {
                $codigosFormateados[] = [
                    'tipo' => $codigo['TpoCodigo'] ?? 'INTERNO',
                    'valor' => $codigo['VlrCodigo'],
                ];
            }
        }

        return $codigosFormateados;
    }

    /**
     * Formatea los totales del documento
     *
     * @param array $totales Totales del documento
     * @return array Totales formateados
     */
    private function formatearTotales(array $totales): array
    {
        if (empty($totales)) return [];

        $totalesFormateados = [
            'neto' => $totales['MntNeto'] ?? null,
            'exento' => $totales['MntExe'] ?? null,
            'iva' => $totales['IVA'] ?? null,
            'tasa_iva' => $totales['TasaIVA'] ?? null,
            'iva_no_retenido' => $totales['IVANoRet'] ?? null,
            'total' => $totales['MntTotal'] ?? null,
        ];

        if (!empty($totales['ImptoReten'])) {
            $totalesFormateados['impuestos_adicionales'] = $this->formatearImpuestosAdicionales($totales['ImptoReten']);
        }

        return $totalesFormateados;
    }

    /**
     * Formatea los impuestos adicionales
     *
     * @param array $impuestos Impuestos adicionales
     * @return array Impuestos formateados
     */
    private function formatearImpuestosAdicionales($impuestos): array
    {
        if (empty($impuestos)) return [];

        if (!isset($impuestos[0]) && isset($impuestos['TipoImp'])) {
            $impuestos = [$impuestos];
        } elseif (!isset($impuestos[0])) {
             error_log("DteToJsonFormatter: Se recibió un formato inesperado para ImptoReten: " . print_r($impuestos, true));
            return [];
        }

        $impuestosFormateados = [];

        foreach ($impuestos as $impuesto) {
             if (!is_array($impuesto) || empty($impuesto['TipoImp'])) continue;
            $impuestosFormateados[] = [
                'tipo' => $impuesto['TipoImp'],
                'tasa' => $impuesto['TasaImp'] ?? null,
                'monto' => $impuesto['MontoImp'] ?? null,
            ];
        }

        return $impuestosFormateados;
    }

     /**
     * Formatea las referencias del documento (Manejo defensivo de índices)
     *
     * @param array|null $referencias Referencias (puede ser null si no hay)
     * @return array Referencias formateadas
     */
    private function formatearReferencias($referencias): array
    {
        if (empty($referencias)) return [];

        if (!isset($referencias[0]) && isset($referencias['NroLinRef'])) {
            $referencias = [$referencias];
        } elseif (!isset($referencias[0])) {
            error_log("DteToJsonFormatter: Se recibió un formato inesperado para referencias: " . print_r($referencias, true));
            return [];
        }

        $referenciasFormateadas = [];

        foreach ($referencias as $referencia) {
            if (!is_array($referencia)) continue;

            $tipoDocRef = $referencia['TpoDocRef'] ?? null;

            $referenciaFormateada = [
                'numero_linea' => $referencia['NroLinRef'] ?? null,
                'tipo_documento' => $tipoDocRef,
                'tipo_documento_nombre' => $tipoDocRef ? $this->obtenerNombreTipoDocumento($tipoDocRef) : 'REFERENCIA',
                'folio' => $referencia['FolioRef'] ?? null,
                'fecha' => $referencia['FchRef'] ?? null,
                'codigo' => $referencia['CodRef'] ?? null,
                'razon' => $referencia['RazonRef'] ?? null,
            ];

            $referenciasFormateadas[] = $referenciaFormateada;
        }

        return $referenciasFormateadas;
    }

    /**
     * Formatea los descuentos y recargos globales
     *
     * @param array $descuentosRecargos Descuentos y recargos
     * @return array Descuentos y recargos formateados
     */
    private function formatearDescuentosRecargos($descuentosRecargos): array
    {
         if (empty($descuentosRecargos)) return [];

        if (!isset($descuentosRecargos[0]) && isset($descuentosRecargos['TpoMov'])) {
            $descuentosRecargos = [$descuentosRecargos];
        } elseif (!isset($descuentosRecargos[0])) {
            error_log("DteToJsonFormatter: Se recibió un formato inesperado para DscRcgGlobal: " . print_r($descuentosRecargos, true));
             return [];
        }

        $formateados = [];

        foreach ($descuentosRecargos as $dr) {
            if (!is_array($dr) || empty($dr['TpoMov'])) continue;
            $formateado = [
                'tipo' => ($dr['TpoMov'] ?? '') == 'D' ? 'descuento' : 'recargo',
                'glosa' => $dr['GlosaDR'] ?? null,
                'valor' => $dr['ValorDR'] ?? null,
                'es_porcentaje' => ($dr['TpoValor'] ?? '') == '%',
                'afecta_exento' => !empty($dr['IndExeDR']),
            ];

            $formateados[] = $formateado;
        }

        return $formateados;
    }

    /**
     * Agrega información adicional según el tipo de documento
     *
     * @param array &$documentoJson Documento JSON a modificar
     * @param array $datosDte Datos del DTE
     * @param array $emisor Datos del emisor
     */
     private function agregarInfoAdicionalPorTipo(array &$documentoJson, array $datosDte, array $emisor): void
    {
        $tipo = $datosDte['Encabezado']['IdDoc']['TipoDTE'] ?? null;
        if ($tipo === null) return;

        $impresionBase = [
            'copia' => 'COPIA CLIENTE - NO VÁLIDA COMO DOCUMENTO TRIBUTARIO',
            'tipo_letra_titulo' => 'b',
            'tamaño_letra_titulo' => 12,
        ];

        if ($tipo == 39 || $tipo == 41) {
            $documentoJson['impresion'] = array_merge($impresionBase, [
                'titulo' => $tipo == 39 ? 'BOLETA ELECTRÓNICA' : 'BOLETA EXENTA ELECTRÓNICA',
                 'copia' => 'COPIA CLIENTE',
            ]);
        }
        else if ($tipo == 33 || $tipo == 34) {
            $documentoJson['impresion'] = array_merge($impresionBase, [
                'titulo' => $tipo == 33 ? 'FACTURA ELECTRÓNICA' : 'FACTURA EXENTA ELECTRÓNICA',
            ]);
            if (!empty($datosDte['Encabezado']['IdDoc'])) {
                $idDoc = $datosDte['Encabezado']['IdDoc'];
                $pagoData = [
                    'medio' => $idDoc['MedioPago'] ?? null,
                    'forma' => $idDoc['FmaPago'] ?? null,
                    'dias' => $idDoc['TermPagoDias'] ?? null,
                    'vencimiento' => $idDoc['FchVenc'] ?? null,
                ];
                 if (array_filter($pagoData)) {
                     $documentoJson['pago'] = $pagoData;
                 }
            }
        }
        else if ($tipo == 61) {
            $documentoJson['impresion'] = array_merge($impresionBase, [
                'titulo' => 'NOTA DE CRÉDITO ELECTRÓNICA',
            ]);
        }
        else if ($tipo == 56) {
            $documentoJson['impresion'] = array_merge($impresionBase, [
                'titulo' => 'NOTA DE DÉBITO ELECTRÓNICA',
            ]);
        }
        else if ($tipo == 52) {
            $documentoJson['impresion'] = array_merge($impresionBase, [
                'titulo' => 'GUÍA DE DESPACHO ELECTRÓNICA',
            ]);
             if (!empty($datosDte['Encabezado']['IdDoc']['TipoDespacho'])) {
                 $idDoc = $datosDte['Encabezado']['IdDoc'];
                 $despachoData = [
                      'tipo' => $idDoc['TipoDespacho'] ?? null,
                      'ind_traslado' => $idDoc['IndTraslado'] ?? null,
                 ];
                 if (array_filter($despachoData)) {
                      $documentoJson['despacho'] = $despachoData;
                 }
             }
             if (!empty($datosDte['Encabezado']['Transporte'])) {
                   $documentoJson['transporte'] = $datosDte['Encabezado']['Transporte'];
             }
        }
    }

    /**
     * Obtiene el nombre descriptivo de un tipo de documento
     *
     * @param int|string|null $tipo Código del tipo de documento
     * @return string Nombre del tipo de documento
     */
    private function obtenerNombreTipoDocumento($tipo): string
    {
        if ($tipo === null || $tipo === '') {
            return 'DOCUMENTO DESCONOCIDO';
        }

        $tipos = [
            33 => 'FACTURA ELECTRÓNICA',
            34 => 'FACTURA EXENTA ELECTRÓNICA',
            39 => 'BOLETA ELECTRÓNICA',
            41 => 'BOLETA EXENTA ELECTRÓNICA',
            56 => 'NOTA DE DÉBITO ELECTRÓNICA',
            61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
            52 => 'GUÍA DE DESPACHO ELECTRÓNICA',
        ];

        return $tipos[$tipo] ?? 'DOCUMENTO TIPO ' . $tipo;
    }

    /**
     * Remueve recursivamente las claves con valor null o false de un array.
     * Mantiene el 0 (cero) y el string vacío ''.
     *
     * @param array $array Array a limpiar
     * @return array Array sin claves null o false
     */
     private function removeNullValuesRecursive(array $array): array
     {
         foreach ($array as $key => &$value) {
             if (is_array($value)) {
                 $value = $this->removeNullValuesRecursive($value);
                 // Si el sub-array quedó vacío después de limpiar, eliminarlo también
                 if (empty($value) && $value !== 0 && $value !== '0') { // Asegurar que 0 no se elimine
                      unset($array[$key]);
                 }
             // Mantener 0, '0' y '' (string vacío), eliminar solo null y false
             } elseif ($value === null || $value === false) {
                 unset($array[$key]);
             }
         }
         unset($value);
         return $array;
     }
}