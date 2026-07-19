<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Core\Dashboard\Services\HealthService;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\DataSource;
use App\Modules\Connectors\Models\ProviderInstance;
use Cron\CronExpression;
use App\Modules\Connectors\Services\BlueprintService;
use App\Modules\Connectors\Providers\ProviderFactory;
use Exception;

class BatchReadinessService
{
    /**
     * Provider validation.
     */

    public function __construct(
        protected HealthService $health
    ) {
    }

    /**
     * Check whether a batch can be created.
     *
     * For scheduled jobs:
     *  - Queue infrastructure is advisory only.
     *
     * For immediate jobs:
     *  - Queue infrastructure is mandatory.
     */
    public function check(
        bool $scheduled,
        int $providerInstanceId,
        int $commandId,
        int $dataSourceId,
        ?string $cronExpression = null
    ): array {

        $required = [];
        $warnings = [];

        /*
        |--------------------------------------------------------------------------
        | Mandatory platform checks
        |--------------------------------------------------------------------------
        */

        $required[] = $this->health->checkDatabaseHealth();

        /*
        |--------------------------------------------------------------------------
        | Queue infrastructure
        |--------------------------------------------------------------------------
        */

        if ($scheduled) {

            // Scheduler can still register the template even if Horizon
            // is currently stopped.

            $warnings[] = $this->health->checkRedisHealth();
            $warnings[] = $this->health->checkQueueHealth();
            $warnings[] = $this->health->checkHorizonHealth();

        } else {

            // Immediate execution needs the queue now.

            $required[] = $this->health->checkRedisHealth();
            $required[] = $this->health->checkQueueHealth();
            $required[] = $this->health->checkHorizonHealth();
        }

        /*
        |--------------------------------------------------------------------------
        | Scheduled jobs
        |--------------------------------------------------------------------------
        */

        if ($scheduled) {
            $required[] = $this->checkCronExpression($cronExpression);
        }

        /*
        |--------------------------------------------------------------------------
        | Business validation
        |--------------------------------------------------------------------------
        */

        $required[] = $this->checkProvider($providerInstanceId);
        $required[] = $this->checkCommand($commandId);
        $required[] = $this->checkDataSource($dataSourceId);

        /*
        |--------------------------------------------------------------------------
        | Overall readiness
        |--------------------------------------------------------------------------
        */

        $ready = collect($required)
            ->every(fn ($item) => $item['status_type'] !== 'danger');

        return [

            'ready' => $ready,

            'required_checks' => $required,

            'warnings' => $warnings,

            'checks' => array_merge($required, $warnings),
        ];
    }

    /**
     * Validate cron expression.
     */
    protected function checkCronExpression(?string $expression): array
    {
        if (blank($expression)) {
            return [
                'name' => 'Schedule',
                'status' => 'Missing',
                'status_type' => 'danger',
                'message' => 'Cron expression is required.'
            ];
        }

        if (!CronExpression::isValid($expression)) {
            return [
                'name' => 'Schedule',
                'status' => 'Invalid',
                'status_type' => 'danger',
                'message' => 'Cron expression is invalid.'
            ];
        }

        return [
            'name' => 'Schedule',
            'status' => 'Ready',
            'status_type' => 'healthy',
            'message' => null
        ];
    }

    protected function checkProvider(int $id): array
    {
        $instance = ProviderInstance::find($id);

        if (!$instance) {
            return [
                'name' => 'Provider',
                'status' => 'Missing',
                'status_type' => 'danger',
                'message' => 'Provider instance does not exist.'
            ];
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | Build Provider Driver
            |--------------------------------------------------------------------------
            */

            $blueprint = app(BlueprintService::class)
                ->getCategoryBlueprint($instance->category_slug);

            if (!$blueprint) {
                return [
                    'name' => 'Provider',
                    'status' => 'Invalid',
                    'status_type' => 'danger',
                    'message' => "Blueprint '{$instance->category_slug}' not found."
                ];
            }

            $config = $instance->connection_settings;
            $config['instance_id'] = $instance->id;

            $provider = ProviderFactory::make($config, $blueprint);

            /*
            |--------------------------------------------------------------------------
            | Perform live connectivity check
            |--------------------------------------------------------------------------
            */

            $online = $provider->checkConnectivity();

            $instance->refresh();

            if (!$online) {

                return [
                    'name' => 'Provider',
                    'status' => 'Offline',
                    'status_type' => 'danger',
                    'message' => 'Provider health check failed.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Optional latency warning
            |--------------------------------------------------------------------------
            */

            if (!is_null($instance->latency_ms) && $instance->latency_ms > 2000) {

                return [
                    'name' => 'Provider',
                    'status' => "{$instance->latency_ms}ms",
                    'status_type' => 'warning',
                    'message' => 'Provider is reachable but responding slowly.'
                ];
            }

            return [
                'name' => 'Provider',
                'status' => "{$instance->latency_ms}ms",
                'status_type' => 'healthy',
                'message' => null
            ];

        } catch (Exception $e) {

            return [
                'name' => 'Provider',
                'status' => 'Error',
                'status_type' => 'danger',
                'message' => $e->getMessage()
            ];
        }
    }


    /**
     * Command validation.
     */
    protected function checkCommand(int $id): array
    {
        $command = Command::find($id);

        if (!$command) {
            return [
                'name' => 'Command',
                'status' => 'Missing',
                'status_type' => 'danger',
                'message' => 'Command does not exist.'
            ];
        }

        if (property_exists($command, 'is_active') && !$command->is_active) {
            return [
                'name' => 'Command',
                'status' => 'Inactive',
                'status_type' => 'danger',
                'message' => 'Command is disabled.'
            ];
        }

        return [
            'name' => 'Command',
            'status' => 'Ready',
            'status_type' => 'healthy',
            'message' => null
        ];
    }

    /**
     * Data source validation.
     */
    protected function checkDataSource(int $id): array
    {
        $source = DataSource::find($id);

        if (!$source) {
            return [
                'name' => 'Data Source',
                'status' => 'Missing',
                'status_type' => 'danger',
                'message' => 'Data source does not exist.'
            ];
        }

        if (!$source->is_active) {
            return [
                'name' => 'Data Source',
                'status' => 'Inactive',
                'status_type' => 'danger',
                'message' => 'Data source is disabled.'
            ];
        }

        return [
            'name' => 'Data Source',
            'status' => 'Ready',
            'status_type' => 'healthy',
            'message' => null
        ];
    }
}
