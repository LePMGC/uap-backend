<?php

namespace App\Modules\Core\JobEngine;

use App\Modules\Connectors\Providers\ProviderInterface;
use App\Modules\Transformation\RowValidator;

class SingleExecutionService {
    public function __construct(
        protected ProviderInterface $provider,
        protected RowValidator $validator
    ) {}

    public function run(array $input) {
        // 1. Transform/Validate
        $cleanData = $this->validator->clean($input);
        
        // 2. Execute via Connector
        $response = $this->provider->execute($input['template'], $cleanData);
        
        // 3. Audit log logic goes here...
        return $response;
    }
}
