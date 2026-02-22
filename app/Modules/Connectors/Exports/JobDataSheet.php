<?php

namespace App\Modules\Connectors\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class JobDataSheet implements FromCollection, WithTitle, WithHeadings
{
    protected $instance;
    protected $type;

    public function __construct($instance, $type)
    {
        $this->instance = $instance;
        $this->type = $type;
    }

    /**
     * Helper to get the file path
     */
    protected function getFilePath()
    {
        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$this->instance->id}";
        $filename = $this->type === 'source' ? "source.csv" : "results_{$this->type}.csv";
        return "{$dir}/{$filename}";
    }

    public function headings(): array
    {
        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$this->instance->id}";
        $filename = $this->type === 'source' ? "source.csv" : "results_{$this->type}.csv";
        $path = Storage::path("{$dir}/{$filename}");

        if (!Storage::exists("{$dir}/{$filename}")) {
            return ['Message'];
        }

        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0); //
        return $csv->getHeader(); 
    }

    public function collection()
    {
        $path = $this->getFilePath();

        if (!Storage::exists($path)) {
            return collect([['No records found for this section.']]);
        }

        $csv = Reader::createFromPath(Storage::path($path), 'r');
        $csv->setHeaderOffset(0);

        $data = [];
        foreach ($csv->getRecords() as $record) {
            $data[] = $record;
        }
        return collect($data);
    }

    public function title(): string
    {
        return ucfirst($this->type) . ' Data';
    }


    public function styles(Worksheet $sheet)
    {
        return [
            // Bold the header row
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ]],
        ];
    }
}