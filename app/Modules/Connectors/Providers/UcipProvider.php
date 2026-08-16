<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\Facades\Http;
use Exception;
use SimpleXMLElement;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Redis;

class UcipProvider extends BaseProvider
{
    protected bool $isStateful = false;
    protected array $statusRegistry;

    public function __construct(array $config, array $blueprint)
    {
        parent::__construct($config, $blueprint);
        $this->statusRegistry = require __DIR__ . '/../Config/ucip_codes.php';
    }

    protected function login(): void
    {
    }

    protected function logout(): void
    {
    }

    /**
     * Replaces placeholders in raw text with live system values and ensures
     * missing mandatory system nodes like originOperatorID are present in the XML.
     */
    public function injectSystemParams(string $rawPayload, ?string $operatorId = null): string
    {
        $resolvedOperatorId = $operatorId ?? $this->resolveUcipOperatorId();

        $rawPayload = parent::injectSystemParams($rawPayload, $resolvedOperatorId);
        $rawPayload = str_replace('{origin_operator_id}', $resolvedOperatorId, $rawPayload);

        // Guarantee originOperatorID node exists in XML structure for raw execution
        if (str_contains($rawPayload, '<methodCall>') && !str_contains($rawPayload, '<name>originOperatorID</name>')) {
            $rawPayload = $this->injectValuesIntoXmlTemplate($rawPayload, [
                'originOperatorID' => $resolvedOperatorId,
            ]);
        }

        return $rawPayload;
    }

    /**
     * Builds the UCIP XML-RPC payload by injecting parameters into the
     * template payload and appending missing parameters if necessary.
     */
    protected function buildPayload(
        array $commandDef,
        array $params,
        ?string $operatorId = null
    ): string {
        $resolvedOperatorId = $operatorId ?? $this->resolveUcipOperatorId();

        $pool = [
            'originNodeType'      => $this->config['origin_node_type'] ?? 'EXT',
            'originHostName'      => 'UAP',
            'originTransactionID' => $this->generateTransactionId(),
            'originTimeStamp'     => now()->format('Ymd\TH:i:s+0100'),
            'originOperatorID'    => $resolvedOperatorId,
        ];

        $rawSystemKeys = $commandDef['system_params'] ?? $commandDef['meta']['system_keys'] ?? [];
        $authorizedSystemParams = [];

        if (is_array($rawSystemKeys)) {
            foreach ($rawSystemKeys as $k => $v) {
                $keyName = is_numeric($k) ? $v : $k;
                if (array_key_exists($keyName, $pool)) {
                    $authorizedSystemParams[$keyName] = $pool[$keyName];
                }
            }
        }

        // Always guarantee system defaults are present in authorized params
        foreach ($pool as $sysKey => $sysValue) {
            if (!isset($authorizedSystemParams[$sysKey])) {
                $authorizedSystemParams[$sysKey] = $sysValue;
            }
        }

        $finalParams = array_merge($authorizedSystemParams, $params);

        // Explicitly enforce originOperatorID regardless of request input/config
        if (empty($finalParams['originOperatorID'])) {
            $finalParams['originOperatorID'] = $resolvedOperatorId;
        }

        $templateXml = $commandDef['sample_payload'] ?? $commandDef['raw_payload'] ?? $commandDef['request_payload'] ?? null;

        if (!empty($templateXml)) {
            return $this->injectValuesIntoXmlTemplate($templateXml, $finalParams);
        }

        $method = $commandDef['meta']['method']
            ?? $commandDef['method']
            ?? $commandDef['command_key']
            ?? $commandDef['name'];

        $xml = "<?xml version=\"1.0\"?>\n<methodCall>\n<methodName>{$method}</methodName>\n<params>\n<param>\n<value><struct>\n";

        foreach ($finalParams as $key => $value) {
            $xml .= "<member><name>{$key}</name><value>";
            $xml .= $this->encodeValue($value);
            $xml .= "</value></member>\n";
        }

        return $xml . "</struct></value></param></params>\n</methodCall>";
    }

    /**
     * Resolves the UCIP-specific originOperatorID according to standard format:
     * "UAP" + user_id + uppercase(clean_username)
     * Example: User ID 1 (admin_uap) -> UAP1ADMINUAP
     */
    protected function resolveUcipOperatorId(): string
    {
        $userId = $this->config['user_id'] ?? null;
        $jobInstanceId = $this->config['job_instance_id'] ?? null;

        if ($userId && $userId > 0) {
            $user = \App\Modules\Core\UserManagement\Models\User::find($userId);
            if ($user && !empty($user->username)) {
                $cleanUsername = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $user->username));
                return "UAP{$user->id}{$cleanUsername}";
            }
        }

        if ($jobInstanceId) {
            $instance = \App\Models\BatchJobInstance::where('identifier_id', $jobInstanceId)->first();
            if ($instance && $instance->batchJob && $instance->batchJob->user) {
                $user = $instance->batchJob->user;
                if (!empty($user->username)) {
                    $cleanUsername = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $user->username));
                    return "UAP{$user->id}{$cleanUsername}";
                }
            }
        }

        return $this->config['origin_operator_id'] ?? 'UAPSYSTEM';
    }

    /**
     * Replaces node text content in sample XML template while keeping tag types intact,
     * and automatically appends any missing parameter nodes (e.g. originOperatorID)
     * directly to the primary XML-RPC struct.
     */
    private function injectValuesIntoXmlTemplate(string $templateXml, array $params): string
    {
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (strpos($templateXml, '<?xml') !== 0) {
            $xmlStart = strpos($templateXml, '<?xml');
            if ($xmlStart !== false) {
                $templateXml = substr($templateXml, $xmlStart);
            }
        }

        if (!@$dom->loadXML($templateXml)) {
            return $templateXml;
        }

        $xpath = new DOMXPath($dom);
        
        // Target top-level struct members in the XML-RPC payload
        $members = $xpath->query('//methodCall/params/param/value/struct/member') 
            ?? $xpath->query('//member');
            
        $processedParams = [];

        foreach ($members as $member) {
            $nameNode = $xpath->query('name', $member)->item(0);
            if (!$nameNode) {
                continue;
            }

            $paramName = trim($nameNode->textContent);

            if (array_key_exists($paramName, $params)) {
                $processedParams[$paramName] = true;
                $val = $params[$paramName];

                $oldValueNode = $xpath->query('value', $member)->item(0);

                if (is_array($val)) {
                    // Complex/Composite parameter (Array or Struct): Build full XML-RPC structure
                    $xmlFragment = "<value>" . $this->encodeValue($val) . "</value>";
                    $fragment = $dom->createDocumentFragment();
                    
                    if ($fragment->appendXML($xmlFragment)) {
                        if ($oldValueNode) {
                            $member->replaceChild($fragment, $oldValueNode);
                        } else {
                            $member->appendChild($fragment);
                        }
                    }
                } else {
                    // Scalar parameter (string, int, boolean, iso8601)
                    if ($oldValueNode && $oldValueNode->hasChildNodes()) {
                        $updated = false;
                        foreach ($oldValueNode->childNodes as $childNode) {
                            if ($childNode->nodeType === XML_ELEMENT_NODE) {
                                if (is_bool($val)) {
                                    $val = $val ? '1' : '0';
                                }
                                $childNode->nodeValue = htmlspecialchars((string)$val, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                                $updated = true;
                                break;
                            }
                        }
                        
                        if (!$updated) {
                            $oldValueNode->nodeValue = htmlspecialchars((string)$val, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                        }
                    } else {
                        $xmlFragment = "<value>" . $this->encodeValue($val) . "</value>";
                        $fragment = $dom->createDocumentFragment();
                        if ($fragment->appendXML($xmlFragment)) {
                            if ($oldValueNode) {
                                $member->replaceChild($fragment, $oldValueNode);
                            } else {
                                $member->appendChild($fragment);
                            }
                        }
                    }
                }
            }
        }

        // Append missing parameters that were not present in the template XML
        $structNode = $xpath->query('//struct')->item(0);
        if ($structNode) {
            foreach ($params as $key => $value) {
                if (!isset($processedParams[$key])) {
                    $memberNode = $dom->createElement('member');

                    $nameNode = $dom->createElement('name', htmlspecialchars($key, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
                    $memberNode->appendChild($nameNode);

                    $xmlFragment = "<value>" . $this->encodeValue($value) . "</value>";
                    $fragment = $dom->createDocumentFragment();
                    
                    if ($fragment->appendXML($xmlFragment)) {
                        $memberNode->appendChild($fragment);
                        $structNode->appendChild($memberNode);
                    }
                }
            }
        }

        return $dom->saveXML();
    }

    protected function generateTransactionId(): string
    {
        $timestamp = now()->format('YmdHis'); // 14 digits
        $key = 'ucip:transaction_sequence:' . $timestamp;

        $sequence = Redis::incr($key);

        if ($sequence === 1) {
            Redis::expire($key, 120);
        }

        // Pad to 6 digits to keep total length at exactly 20 digits (14 + 6)
        return $timestamp . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT);
    }

    private function encodeValue(mixed $value): string
    {
        if (is_null($value)) {
            return "<string></string>";
        }

        if (is_array($value)) {
            if (empty($value)) {
                return "<array><data></data></array>";
            }

            // Check if sequential numeric list vs associative struct
            $isList = array_keys($value) === range(0, count($value) - 1);

            if ($isList) {
                $xml = "<array><data>";
                foreach ($value as $item) {
                    $xml .= "<value>" . $this->encodeValue($item) . "</value>";
                }
                return $xml . "</data></array>";
            } else {
                $struct = "<struct>";
                foreach ($value as $k => $v) {
                    $struct .= "<member><name>" . htmlspecialchars((string)$k, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</name><value>" . $this->encodeValue($v) . "</value></member>";
                }
                return $struct . "</struct>";
            }
        }

        if (is_int($value)) {
            return "<i4>{$value}</i4>";
        }

        if (is_float($value)) {
            return "<double>{$value}</double>";
        }

        if (is_bool($value)) {
            return "<boolean>" . ($value ? "1" : "0") . "</boolean>";
        }

        if (is_string($value) && preg_match('/^\d{8}T\d{2}:\d{2}:\d{2}/', $value)) {
            return "<dateTime.iso8601>{$value}</dateTime.iso8601>";
        }

        return "<string>" . htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</string>";
    }

    protected function send(string $payload): string
    {
        return Http::withHeaders([
            'User-Agent'   => $this->config['user_agent'] ?? 'UAP/1.0',
            'Content-Type' => 'text/xml',
            'Connection'   => 'Keep-Alive',
        ])
        ->withBasicAuth($this->config['username'], $this->config['password'])
        ->withBody($payload, 'text/xml')
        ->post("http://{$this->config['host']}:{$this->config['port']}/Air")
        ->body();
    }

    protected function parseResponse(array $commandDef, string $rawResponse, array $userParams): array
    {
        try {
            $xml = new SimpleXMLElement($rawResponse);

            if (isset($xml->fault)) {
                return $this->handleFault($xml->fault->value->struct);
            }

            $struct = $xml->params->param->value->struct;
            $data = $this->parseXmlStruct($struct);
            $responseCode = isset($data['responseCode']) ? (int)$data['responseCode'] : 0;
            $description = $this->statusRegistry['responses'][$responseCode] ?? "Unknown Error ({$responseCode})";

            $data['response_message'] = $description;
            $isSuccessful = $responseCode === 0 || $responseCode === 1 || $responseCode === 2;

            \App\Modules\Core\Auditing\Services\UapLogger::log(
                'EricssonUCIP',
                'PROVIDER_RESPONSE',
                $isSuccessful ? 'info' : 'error',
                [
                    'code'    => $responseCode,
                    'message' => $description,
                    'msisdn'  => $userParams['subscriberNumber'] ?? $userParams['msisdn'] ?? 'N/A',
                ],
                $isSuccessful ? 'SUCCESS' : 'FAILURE'
            );

            return [
                'success' => $isSuccessful,
                'code'    => $responseCode,
                'message' => $description,
                'data'    => $data,
                'raw'     => $rawResponse
            ];
        } catch (\Exception $e) {
            throw new \Exception("XML Parsing Error: " . $e->getMessage());
        }
    }

    private function parseXmlStruct(SimpleXMLElement $struct): array
    {
        $result = [];
        foreach ($struct->member as $member) {
            $name = (string)$member->name;
            $result[$name] = $this->parseXmlValue($member->value);
        }
        return $result;
    }

    private function parseXmlValue(SimpleXMLElement $value): mixed
    {
        $child = $value->children()[0] ?? null;
        if (!$child) {
            return (string)$value;
        }

        $type = strtolower($child->getName());

        return match ($type) {
            'struct'    => $this->parseXmlStruct($child),
            'array'     => $this->parseXmlArray($child->data),
            'i4', 'int' => (int)$child,
            'boolean'   => (bool)$child,
            default     => (string)$child,
        };
    }

    private function parseXmlArray(SimpleXMLElement $data): array
    {
        $items = [];
        foreach ($data->value as $value) {
            $items[] = $this->parseXmlValue($value);
        }
        return $items;
    }

    private function handleFault(SimpleXMLElement $faultStruct): array
    {
        $faultData = $this->parseXmlStruct($faultStruct);
        $code = (int)($faultData['faultCode'] ?? 999);
        $faultString = $faultData['faultString'] ?? 'Unknown Protocol Error';

        return [
            'success' => false,
            'code'    => $code,
            'message' => "Protocol Fault: " . ($this->statusRegistry['faults'][$code] ?? $faultString),
            'data'    => $faultData,
            'raw'     => $faultStruct->asXML()
        ];
    }

    public function checkHealth(): bool
    {
        try {
            $response = $this->send($this->buildHeartbeatPayload());
            return str_contains($response, 'methodResponse');
        } catch (\Exception $e) {
            return false;
        }
    }

    private function buildHeartbeatPayload(): string
    {
        return "<?xml version='1.0'?><methodCall><methodName>GetCapabilities</methodName></methodCall>";
    }

    public function extractSystemParams(string $rawPayload): array
    {
        $detected = [];
        $map = [
            'originNodeType'      => 'EXT',
            'originHostName'      => 'UAP',
            'originTransactionID' => '{auto_gen_id}',
            'originTimeStamp'     => '{auto_gen_iso8601}',
            'originOperatorID'    => '{auto_gen_id}',
        ];

        foreach ($map as $key => $placeholder) {
            $pattern = "/<name>{$key}<\/name>\s*<value>\s*<[^>]+>([^<]+)<\/[^>]+>\s*<\/value>/i";
            if (preg_match($pattern, $rawPayload)) {
                $detected[$key] = $placeholder;
            }
        }

        return $detected;
    }

    private function extractXmlValue(\SimpleXMLElement $valueNode): mixed
    {
        /*
        * XML-RPC <value> can contain:
        *
        * <string>
        * <int>
        * <i4>
        * <i8>
        * <boolean>
        * <double>
        * <dateTime.iso8601>
        * <base64>
        * <nil>
        * <struct>
        * <array>
        */

        $children = $valueNode->children();

        /*
        * Empty <value> or untyped XML-RPC value.
        */
        if ($children->count() === 0) {
            $value = (string) $valueNode;

            return ctype_digit($value)
                ? (int) $value
                : $value;
        }

        $child = $children[0];
        $type = strtolower($child->getName());

        switch ($type) {

            /*
            * Scalar values
            */
            case 'string':
                return (string) $child;

            case 'int':
            case 'i4':
            case 'i8':
                return (int) $child;

            case 'boolean':
                $value = strtolower(trim((string) $child));

                return $value === '1' || $value === 'true';

            case 'double':
                return (float) $child;

            case 'datetime.iso8601':
                return (string) $child;

            case 'base64':
                return (string) $child;

            case 'nil':
                return null;

            /*
            * XML-RPC struct
            *
            * <value>
            *   <struct>
            *     <member>
            *       <name>usageCounterID</name>
            *       <value>
            *         <int>37022</int>
            *       </value>
            *     </member>
            *     ...
            *   </struct>
            * </value>
            */
            case 'struct':
                $result = [];

                foreach ($child->member as $member) {
                    $name = trim((string) $member->name);

                    if ($name === '') {
                        continue;
                    }

                    $result[$name] = $this->extractXmlValue(
                        $member->value
                    );
                }

                return $result;

            /*
            * XML-RPC array
            *
            * <value>
            *   <array>
            *     <data>
            *       <value>...</value>
            *       <value>...</value>
            *     </data>
            *   </array>
            * </value>
            */
            case 'array':
                $result = [];

                if (isset($child->data)) {
                    foreach ($child->data->value as $item) {
                        $result[] = $this->extractXmlValue($item);
                    }
                }

                return $result;

            /*
            * Unknown XML-RPC/custom type.
            *
            * Try to preserve nested structures rather than
            * converting them to whitespace.
            */
            default:
                if ($child->children()->count() > 0) {
                    return $this->extractXmlValue($child);
                }

                return trim((string) $child);
        }
    }

    public function parseSamplePayload(string $rawPayload): array
    {
        try {
            /*
            * Empty payload
            */
            if (empty(trim($rawPayload))) {
                return [
                    'method'        => '',
                    'params'        => [],
                    'system_params' => [],
                    'raw_payload'   => '',
                ];
            }

            /*
            * If anything exists before <?xml, remove it.
            */
            if (strpos($rawPayload, '<?xml') !== 0) {
                $xmlStart = strpos($rawPayload, '<?xml');

                if ($xmlStart !== false) {
                    $rawPayload = substr($rawPayload, $xmlStart);
                }
            }

            /*
            * Parse XML.
            */
            $xml = new \SimpleXMLElement($rawPayload);

            $methodName = (string) $xml->methodName;

            /*
            * Locate the root struct:
            *
            * methodCall
            *   params
            *     param
            *       value
            *         struct
            */
            $struct = $xml->params->param->value->struct;

            if (!$struct || $struct->member->count() === 0) {
                return [
                    'method'        => $methodName,
                    'params'        => [],
                    'system_params' => [],
                    'raw_payload'   => $xml->asXML(),
                ];
            }

            /*
            * Runtime/system parameters.
            *
            * These values are generated instead of being taken
            * from the sample payload.
            */
            $systemMap = [
                'originNodeType'      => 'EXT',
                'originHostName'      => 'UAP',
                'originTransactionID' => $this->generateTransactionId(),
                'originTimeStamp'     => now()->format('Ymd\TH:i:s+0100'),
                'originOperatorID'    => $this->resolveUcipOperatorId(),
            ];

            $detectedSystemParams = [];
            $userParams = [];

            /*
            * Parse every member of the root struct.
            */
            foreach ($struct->member as $member) {

                $name = trim((string) $member->name);

                if ($name === '') {
                    continue;
                }

                /*
                * IMPORTANT:
                *
                * This now recursively handles:
                *
                * array -> struct -> member -> value
                *
                * instead of converting an array to its whitespace.
                */
                $value = $this->extractXmlValue(
                    $member->value
                );

                /*
                * System parameters
                */
                if (array_key_exists($name, $systemMap)) {

                    $newValue = $systemMap[$name];

                    $detectedSystemParams[$name] = $newValue;

                    /*
                    * We intentionally do NOT mutate the XML here.
                    *
                    * The generated system values are returned through
                    * system_params and merged by the controller.
                    */
                    continue;
                }

                /*
                * User parameters preserve their complete parsed
                * structure.
                */
                $userParams[$name] = $value;
            }

            return [
                'method'        => $methodName,
                'params'        => $userParams,
                'system_params' => $detectedSystemParams,
                'raw_payload'   => $xml->asXML(),
            ];

        } catch (\Throwable $e) {
            throw new \Exception(
                'Failed to parse UCIP sample: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function getMappingBlueprint(string $rawPayload): array
    {
        try {
            $xml = new \SimpleXMLElement($rawPayload);
            $struct = $xml->params->param->value->struct;
            if (!$struct) {
                return [];
            }

            return $this->flattenUcipStruct($struct);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function flattenUcipStruct(\SimpleXMLElement $struct, string $prefix = '', int $level = 0): array
    {
        $params = [];
        $systemKeys = ['originNodeType', 'originHostName', 'originTransactionID', 'originTimeStamp', 'originOperatorID'];

        foreach ($struct->member as $member) {
            $name = (string)$member->name;

            if (in_array($name, $systemKeys)) {
                continue;
            }

            $key = $prefix ? "{$prefix}.{$name}" : $name;
            $valueNode = $member->value->children()[0] ?? null;
            $type = $valueNode ? strtolower($valueNode->getName()) : 'string';
            $sampleValue = $this->extractXmlValue($member->value);

            if ($type === 'struct') {
                $params[] = [
                    'key'      => $key,
                    'type'     => 'Struct',
                    'level'    => $level,
                    'isParent' => true,
                    'value'    => null
                ];
                $params = array_merge($params, $this->flattenUcipStruct($valueNode, $key, $level + 1));
            } elseif ($type === 'array') {
                $params[] = [
                    'key'      => $key,
                    'type'     => 'Array',
                    'level'    => $level,
                    'isParent' => true,
                    'value'    => null
                ];
                if (isset($valueNode->data->value->struct)) {
                    $params = array_merge($params, $this->flattenUcipStruct($valueNode->data->value->struct, $key, $level + 1));
                }
            } else {
                $params[] = [
                    'key'         => $key,
                    'type'        => ($type === 'i4' || $type === 'int') ? 'Integer' : ucfirst($type),
                    'level'       => $level,
                    'isParent'    => false,
                    'is_required' => true,
                    'value'       => $sampleValue
                ];
            }
        }
        return $params;
    }

    public function extractIdentifier(string $rawPayload): ?string
    {
        if (empty(trim($rawPayload))) {
            return null;
        }

        try {
            if (strpos($rawPayload, '<?xml') !== 0) {
                $xmlStart = strpos($rawPayload, '<?xml');

                if ($xmlStart !== false) {
                    $rawPayload = substr($rawPayload, $xmlStart);
                }
            }

            libxml_use_internal_errors(true);
            $xml = new \SimpleXMLElement($rawPayload);

            $members = $xml->xpath('//member[name="subscriberNumber"]');

            if (!empty($members)) {
                foreach ($members as $member) {
                    if (!isset($member->value)) {
                        continue;
                    }

                    $value = $member->value;

                    if ($value->children()->count() > 0) {
                        $identifier = trim((string)$value->children()[0]);
                    } else {
                        $identifier = trim((string)$value);
                    }

                    if ($identifier !== '') {
                        return $identifier;
                    }
                }
            }

        } catch (\Throwable $e) {
            \Log::warning("UCIP identifier extraction failed: " . $e->getMessage());
        }

        return null;
    }

    public function validateSamplePayload(string $payload): array
    {
        $errors = [];

        if (empty(trim($payload))) {
            return [
                'valid'  => false,
                'errors' => ['Payload cannot be empty']
            ];
        }

        libxml_use_internal_errors(true);

        try {
            $xml = new \SimpleXMLElement($payload);

            if ($xml->getName() !== 'methodCall') {
                $errors[] = 'Root element must be <methodCall>';
            }

            if (!isset($xml->methodName) || empty((string)$xml->methodName)) {
                $errors[] = 'Missing <methodName>';
            }

            if (!isset($xml->params->param->value->struct)) {
                $errors[] = 'Missing XML-RPC struct payload';
            }

            $requiredSystemFields = [
                'originNodeType',
                'originHostName',
                'originTransactionID',
                'originTimeStamp',
            ];

            $foundFields = [];

            if (isset($xml->params->param->value->struct->member)) {
                foreach ($xml->params->param->value->struct->member as $member) {
                    $foundFields[] = (string)$member->name;
                }
            }

            foreach ($requiredSystemFields as $field) {
                if (!in_array($field, $foundFields)) {
                    $errors[] = "Missing required UCIP field: {$field}";
                }
            }

        } catch (\Exception $e) {
            $errors[] = "Invalid XML: " . $e->getMessage();
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    public function validateCommandKeyOnPayload(string $commandKey, string $payload): array
    {
        $errors = [];

        try {
            if (empty(trim($payload))) {
                return ['valid' => false, 'errors' => ['Payload cannot be empty.']];
            }

            if (strpos($payload, '<?xml') !== 0) {
                $xmlStart = strpos($payload, '<?xml');
                if ($xmlStart !== false) {
                    $payload = substr($payload, $xmlStart);
                }
            }

            libxml_use_internal_errors(true);
            $xml = new \SimpleXMLElement($payload);

            $xmlMethodName = isset($xml->methodName) ? trim((string)$xml->methodName) : null;

            if (!$xmlMethodName) {
                $errors[] = "The XML payload is missing the mandatory <methodName> tag.";
            } elseif (strcasecmp($xmlMethodName, trim($commandKey)) !== 0) {
                $errors[] = sprintf(
                    "Command key mismatch! The structural key is '%s', but the payload defines <methodName>%s</methodName>.",
                    $commandKey,
                    $xmlMethodName
                );
            }

        } catch (\Exception $e) {
            $errors[] = "Failed to parse payload for key validation: " . $e->getMessage();
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }
}