<?php
namespace App\Adapters\LibreDTE\Core;

class Environment
{
    /**
     * Configura el ambiente de LibreDTE (certificación o producción)
     * 
     * @param bool $certificacion Si es true, usa ambiente de certificación
     */
    public function setup(bool $certificacion): void
    {
        if ($certificacion) {
            \sasco\LibreDTE\Sii::setAmbiente(\sasco\LibreDTE\Sii::CERTIFICACION);
            \sasco\LibreDTE\Sii::setServidor('maullin');
        } else {
            \sasco\LibreDTE\Sii::setAmbiente(\sasco\LibreDTE\Sii::PRODUCCION);
            \sasco\LibreDTE\Sii::setServidor('palena');
        }
    }
}