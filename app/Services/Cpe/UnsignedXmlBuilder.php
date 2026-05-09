<?php

namespace App\Services\Cpe;

use Exception;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Retention\Retention;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Reversion;
use Greenter\Xml\Builder\BuilderInterface;
use Greenter\Xml\Builder\DespatchBuilder;
use Greenter\Xml\Builder\InvoiceBuilder;
use Greenter\Xml\Builder\NoteBuilder;
use Greenter\Xml\Builder\RetentionBuilder;
use Greenter\Xml\Builder\SummaryBuilder;
use Greenter\Xml\Builder\VoidedBuilder;

/**
 * Construye el XML UBL SIN FIRMAR de un documento Greenter.
 *
 * Usado cuando Company.cpe_provider == 'validapse': el facturador construye
 * el XML plano con Greenter (mismo builder que ya usa hoy) y lo manda a
 * ValidaPSE para que ValidaPSE lo firme con su certificado PSE.
 *
 * # Por qué mapeo manual en lugar de XmlBuilderResolver::find()
 *
 * El resolver de greenter/lite arma el FQCN del builder por concatenación
 * de string y hace `new $fqcn($options)`. Si el autoload del paquete
 * greenter/xml no resuelve la clase concreta en runtime (caso visto en
 * producción: deploy con autoload regenerado parcial), el `new` falla
 * con "Class not found" o el resolver devuelve algo inutilizable. Esto
 * llevó a un error en prod: "no encontró builder para Greenter\Model\Sale\Invoice".
 *
 * Este mapeo manual usa `use` statements explícitos en el top del archivo,
 * lo que fuerza el autoload de las clases en parse time del archivo PHP.
 * Si alguna no existe, el error es preciso y temprano: "Class
 * Greenter\Xml\Builder\InvoiceBuilder not found", apuntando al problema
 * real (paquete greenter/xml mal instalado).
 *
 * # Por qué no extender GreenterService::buildUnsignedXml
 *
 * El constructor de GreenterService instancia See, que carga el certificado
 * y lanza CertificateException si no existe. Empresas NRUS con ValidaPSE
 * no tienen certificado y no podrían pasar el constructor.
 *
 * Doc: docs/INTEGRACION-VALIDAPSE-NRUS.md (Paso 9 + auditoría post-prod)
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
     * soportado por nuestro flujo (Invoice/boleta, Note, Summary, etc.).
     *
     * @param DocumentInterface $document Documento Greenter ya armado.
     *
     * @return string XML UBL plano (UTF-8), sin <ds:Signature>.
     *
     * @throws Exception Si la clase del documento no tiene builder mapeado.
     */
    public static function build(DocumentInterface $document): string
    {
        $builder = self::resolveBuilder($document);
        return $builder->build($document);
    }

    /**
     * Mapea explícitamente cada clase de documento a su builder concreto.
     * Mismo mapping que XmlBuilderResolver de greenter/lite, pero verificable
     * estáticamente y sin armado de FQCN por string.
     */
    private static function resolveBuilder(DocumentInterface $document): BuilderInterface
    {
        $class = get_class($document);

        return match (true) {
            // Sale: Invoice cubre tanto Factura (tipoDoc=01) como Boleta (tipoDoc=03).
            $document instanceof Invoice => new InvoiceBuilder(self::TWIG_OPTIONS),
            $document instanceof Note => new NoteBuilder(self::TWIG_OPTIONS),
            // Resumen diario de boletas (Greenter\Model\Summary\Summary).
            $document instanceof Summary => new SummaryBuilder(self::TWIG_OPTIONS),
            // Comunicación de baja (Greenter\Model\Voided\Reversion).
            $document instanceof Reversion => new VoidedBuilder(self::TWIG_OPTIONS),
            // Retention (no se usa con ValidaPSE, pero lo mapeo por completitud).
            $document instanceof Retention => new RetentionBuilder(self::TWIG_OPTIONS),
            // Despatch (guías, no aplica a NRUS pero idem).
            $document instanceof Despatch => new DespatchBuilder(self::TWIG_OPTIONS),
            default => throw new Exception(
                "UnsignedXmlBuilder: no hay builder mapeado para la clase {$class}. " .
                'Mapear explícitamente en resolveBuilder().'
            ),
        };
    }
}
