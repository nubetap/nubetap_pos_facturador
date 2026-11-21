<?php

use App\Models\Boleta;
use App\Models\Company;
use App\Models\VoidedDocument;
use App\Services\FileService;
use App\Services\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Dos empresas con la misma serie y número emitidos el mismo día compartían
// ruta y se pisaban el XML, el CDR y el PDF (70 XML pisados en producción).

function storagePaths(): FileService
{
    return new class(app(StorageService::class)) extends FileService {
        public function path($document, string $extension, string $format = 'A4'): string
        {
            return $this->generatePath($document, $extension, $format);
        }
    };
}

function unsavedBoleta(Company $company): Boleta
{
    $boleta = new Boleta([
        'company_id' => $company->id,
        'tipo_documento' => '03',
        'numero_completo' => 'B001-00000056',
        'fecha_emision' => '2026-10-14',
    ]);
    $boleta->setRelation('company', $company);

    return $boleta;
}

test('el mismo número de dos empresas el mismo día va a archivos distintos', function () {
    $puntoFijo = Company::factory()->create(['ruc' => '10447797297']);
    $selah = Company::factory()->create(['ruc' => '20612345678']);
    $files = storagePaths();

    foreach (['xml', 'zip', 'pdf'] as $ext) {
        expect($files->path(unsavedBoleta($puntoFijo), $ext))
            ->not->toBe($files->path(unsavedBoleta($selah), $ext));
    }

    expect($files->path(unsavedBoleta($puntoFijo), 'xml'))
        ->toBe('boletas/xml/14102026/10447797297-B001-00000056.xml');
    expect($files->path(unsavedBoleta($puntoFijo), 'zip'))
        ->toBe('boletas/cdr/14102026/R-10447797297-B001-00000056.zip');
    expect($files->path(unsavedBoleta($puntoFijo), 'pdf', 'ticket'))
        ->toBe('boletas/pdf/14102026/10447797297-B001-00000056_ticket.pdf');
});

test('las comunicaciones de baja se nombran por su identificador, no ".xml"', function () {
    $company = Company::factory()->create(['ruc' => '20600000001']);
    $voided = new VoidedDocument([
        'company_id' => $company->id,
        'identificador' => 'RA-20261014-1',
        'fecha_emision' => '2026-10-14',
    ]);
    $voided->setRelation('company', $company);

    expect(storagePaths()->path($voided, 'xml'))
        ->toBe('otros-comprobantes/xml/14102026/20600000001-RA-20261014-1.xml');
});
