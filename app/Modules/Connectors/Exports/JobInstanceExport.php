<?php

namespace App\Modules\Connectors\Exports;

use App\Modules\Connectors\Models\JobInstance;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class JobInstanceExport implements WithMultipleSheets
{
    protected $instance;

    public function __construct(JobInstance $instance)
    {
        $this->instance = $instance;
    }

    public function sheets(): array
    {
        // Now these classes exist and are in the same namespace!
        return [
            new JobSummarySheet($this->instance),
            new JobDataSheet($this->instance, 'source'),
            new JobDataSheet($this->instance, 'success'),
            new JobDataSheet($this->instance, 'failed'),
        ];
    }
}