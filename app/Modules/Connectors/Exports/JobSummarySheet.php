<?php

namespace App\Modules\Connectors\Exports;

use App\Modules\Connectors\Models\JobInstance;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class JobSummarySheet implements FromCollection, WithHeadings, WithTitle
{
    protected $instance;

    public function __construct(JobInstance $instance)
    {
        $this->instance = $instance;
    }

    public function title(): string { return 'Job Execution Summary'; }

    public function headings(): array { return ['Field', 'Details']; }

    // Inside JobSummarySheet.php
    public function collection()
    {
        $template = $this->instance->template;
        
        $steps = is_array($template->workflow_steps) 
            ? $template->workflow_steps 
            : json_decode($template->workflow_steps, true);
        
        $commands = !empty($steps) ? implode(', ', $steps) : 'N/A';

        return collect([
            ['REPORT GENERATED', now()->format('Y-m-d H:i:s')],
            ['---', '---'],
            ['JOB INFO', ''],
            ['Instance ID', (string) $this->instance->id],
            ['Template Name', $template->name],
            ['Status', strtoupper($this->instance->status)],
            ['---', '---'],
            ['TIMESTAMPS', ''],
            ['Started At', $this->instance->started_at?->format('Y-m-d H:i:s') ?? 'N/A'],
            ['Completed At', $this->instance->completed_at?->format('Y-m-d H:i:s') ?? 'N/A'],
            ['---', '---'],
            ['EXECUTION STATS', ''],
            ['Total Input Records', ($this->instance->total_records != 0) ? (int) $this->instance->total_records : '0'],
            ['Successfully Processed', ($this->instance->processed_records != 0) ? (int) $this->instance->processed_records : '0'],
            ['Failed Records', ($this->instance->failed_records != 0) ? (int) $this->instance->failed_records : '0'], 
            ['---', '---'],
            ['INFRASTRUCTURE', ''],
            ['Provider Name', $template->providerInstance?->name],
            ['Command Executed', $commands],
        ]);
    }


    public function styles(Worksheet $sheet)
    {
        return [
            // Bold the headers (Row 1)
            1    => ['font' => ['bold' => true]],
            // Bold the labels in Column A
            'A'  => ['font' => ['bold' => true]],
        ];
    }
}