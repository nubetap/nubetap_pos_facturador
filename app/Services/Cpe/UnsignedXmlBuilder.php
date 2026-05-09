<?php

namespace App\Services\Cpe;

use Exception;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Xml\Builder\BuilderInterface;

/**
 * Construye el XML UBL SIN FIRMAR de un documento Greenter.
 *
 * Usado cuando Company.cpe_provider == 'validapse': el facturador construye
 * el XML plano con Greenter (mismo builder que ya usa hoy) y lo manda a
 * ValidaPSE para que ValidaPSE lo firme con su certificado PSE.
 *
 * Por qué no usar GreenterService::getXmlSigned():
 *   - getXmlSigned() también firma. Necesitamos el XML PRE-firma.
 *
 * Por qué no usar See::getFactory()->getBuilder():
 *   - El builder en FeFactory es null hasta que See::getXmlSigned() lo
 *     resuelve internamente vía XmlBuilderResolver.
 *
 * Por qué no extender GreenterService con un buildUnsignedXml:
 *   - El constructor de GreenterService instancia See, que carga el
 *     certificado y lanza CertificateException si no existe. Empresas NRUS
 *     con ValidaPSE no tienen certificado → no podrían instanciar el service.
 *
 * Solución: invocar XmlBuilderResolver directo (camino soportado por
 * greenter/lite ^5.1, ver See::getXmlSigned línea ~150 del upstream).
 *
 * Doc: docs/INTEGRACION-VALIDAPSE-NRUS.md (Paso 9)
 */
class UnsignedXmlBuilder
{
    /**
     * Twig options pasados al builder. Coinciden con los defaults internos
     * de Greenter para asegurar XML byte-idéntico al que generaría See.
     *
     * @var array<string,mixed>
     */
    private const TWIG_OPTIONS = [
        'autoescape' => false,
        'strict_variables' => true,
    ];

    /**
     * Construye el XML UBL sin firmar para cualquier documento Greenter
     * soportado (Invoice, Note, Summary, Reversion, etc.).
     *
     * @param object $document Documento Greenter (Invoice, Note, Summary, ...).
     *                         Debe ser una clase para la cual XmlBuilderResolver
     *                         tenga un builder mapeado.
     *
     * @return string XML UBL plano (UTF-8), sin <ds:Signature>.
     *
     * @throws Exception Si el resolver no encuentra builder para esa clase.
     */
    public static function build(object $document): string
    {
        $resolver = new XmlBuilderResolver(self::TWIG_OPTIONS);
        $builder = $resolver->find(get_class($document));

        if (!$builder instanceof BuilderInterface) {
            throw new Exception(
                'XmlBuilderResolver no encontró builder para ' . get_class($document)
            );
        }

        return $builder->build($document);
    }
}
