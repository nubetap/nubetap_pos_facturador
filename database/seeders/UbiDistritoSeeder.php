<?php

namespace Database\Seeders;

use App\Models\UbiDistrito;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UbiDistritoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar si ya existen datos
        if (\DB::table('ubi_distritos')->count() > 0) {
            if ($this->command) {
                $this->command->info('Distritos ya existen, omitiendo seeder.');
            }
            return;
        }

        $filePath = public_path('data_ubi.txt');

        if (!file_exists($filePath)) {
            if ($this->command) {
                $this->command->error('File data_ubi.txt not found in public directory');
            }
            return;
        }

        // Temporalmente desactivar las restricciones de clave foránea
        // PostgreSQL usa una sintaxis diferente a MySQL
        if (\DB::getDriverName() === 'mysql') {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } else {
            // PostgreSQL
            \DB::statement('SET session_replication_role = replica;');
        }
        
        $file = fopen($filePath, 'r');
        $header = fgetcsv($file, 0, '|'); // Skip header line
        
        $batchSize = 1000;
        $batch = [];
        $count = 0;
        
        while (($row = fgetcsv($file, 0, '|')) !== false) {
            if (count($row) === 5) {
                $batch[] = [
                    'id' => trim($row[0]),
                    'nombre' => trim($row[1]),
                    'info_busqueda' => trim($row[2]),
                    'provincia_id' => trim($row[3]),
                    'region_id' => trim($row[4]),
                ];
                
                $count++;
                
                if (count($batch) >= $batchSize) {
                    UbiDistrito::insert($batch);
                    $batch = [];
                    $this->command && $this->command->info("Inserted batch of {$batchSize} records. Total: {$count}");
                }
            }
        }
        
        // Insert remaining records
        if (!empty($batch)) {
            UbiDistrito::insert($batch);
        }
        
        fclose($file);

        // Reactivar las restricciones de clave foránea
        if (\DB::getDriverName() === 'mysql') {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } else {
            // PostgreSQL
            \DB::statement('SET session_replication_role = DEFAULT;');
        }
        
        $this->command && $this->command->info("Successfully imported {$count} districts from data_ubi.txt");
    }
}
