<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\Facades\Http;
use Exception;
use SimpleXMLElement;
use DOMDocument;
use DOMXPath;

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
     * Builds the UCIP XML-RPC payload by injecting parameters into the 
     * template payload without altering original XML tag types (<string>, <i4>, etc.).
     */
    protected function buildPayload(array $commandDef, array $params): string
    {
        $pool = [
            'originNodeType'      => $this->config['origin_node_type'] ?? 'EXT',
            'originHostName'      => 'UAP',
            'originTransactionID' => $this->generateTransactionId(),
            'originTimeStamp'     => now()->format('Ymd\TH:i:s+0100'),
            'originOperatorID'    => $this->config['dynamic_operator_id']
                                 ?? $this->config['origin_operator_id']
                                 ?? 'UAPAdmin',
        ];

        $allowedSystemKeys = $commandDef['system_params'] ?? [];
        if (isset($commandDef['meta']['system_keys'])) {
            $allowedSystemKeys = array_fill_keys($commandDef['meta']['system_keys'], true);
        }

        $authorizedSystemParams = [];
        foreach ($allowedSystemKeys as $key => $placeholder) {
            if (array_key_exists($key, $pool)) {
                $authorizedSystemParams[$key] = $pool[$key];
            }
        }

        $finalParams = array_merge($authorizedSystemParams, $params);
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
     * Replaces node text content in sample XML template while keeping tag types intact.
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
        $members = $xpath->query('//member');

        foreach ($members as $member) {
            $nameNode = $xpath->query('name', $member)->item(0);
            if (!$nameNode) {
                continue;
            }

            $paramName = trim($nameNode->textContent);

            if (array_key_exists($paramName, $params)) {
                $valueNode = $xpath->query('value', $member)->item(0);
                if ($valueNode && $valueNode->hasChildNodes()) {
                    foreach ($valueNode->childNodes as $childNode) {
                        if ($childNode->nodeType === XML_ELEMENT_NODE) {
                            $val = $params[$paramName];
                            if (is_bool($val)) {
                                $val = $val ? '1' : '0';
                            }
                            $childNode->nodeValue = (string)$val;
                            break;
                        }
                    }
                }
            }
        }

        return $dom->saveXML();
    }

    protected function generateTransactionId(): string
    {
        return substr(str_shuffle("0123456789"), 0, 6);
    }

    private function encodeValue($value): string
    {
        if (is_array($value)) {
            $isList = !empty($value) && array_keys($value) === range(0, count($value) - 1);

            if ($isList) {
                $xml = "<array><data>";
                foreach ($value as $item) {
                    $xml .= "<value>" . $this->encodeValue($item) . "</value>";
                }
                return $xml . "</data></array>";
            } else {
                $struct = "<struct>";
                foreach ($value as $k => $v) {
                    $struct .= "<member><name>{$k}</name><value>{$this->encodeValue($v)}</value></member>";
                }
                return $struct . "</struct>";
            }
        }

        if (is_int($value)) {
            return "<i4>{$value}</i4>";
        }

        if (is_bool($value)) {
            return "<boolean>" . ($value ? "1" : "0") . "</boolean>";
        }

        if (is_string($value) && preg_match('/^\d{8}T\d{2}:\d{2}:\d{2}/', $value)) {
            return "<dateTime.iso8601>{$value}</dateTime.iso8601>";
        }

        return "<string>{$value}</string>";
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

    private function handleProtocolFault(SimpleXMLElement $faultXml): array
    {
        $faultData = $this->flattenXmlStruct($faultXml->value->struct);
        $code = (int)($faultData['faultCode'] ?? 999);

        return [
            'success' => false,
            'code'    => $code,
            'message' => "Protocol Fault: " . ($this->statusRegistry['faults'][$code] ?? $faultData['faultString'] ?? 'Unknown Error'),
            'data'    => $faultData
        ];
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

    private function flattenXmlStruct(SimpleXMLElement $container): array
    {
        $result = [];
        $members = $container->xpath('.//member');

        foreach ($members as $member) {
            $name = (string)$member->name;
            $valNode = $member->value->children();
            $result[$name] = (string)$valNode[0];
        }

        return $result;
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
        ];

        foreach ($map as $key => $placeholder) {
            $pattern = "/<name>{$key}<\/name>\s*<value>\s*<[^>]+>([^<]+)<\/[^>]+>\s*<\/value>/i";
            if (preg_match($pattern, $rawPayload)) {
                $detected[$key] = $placeholder;
            }
        }

        return $detected;
    }

    private function extractXmlValue(\SimpleXMLElement $valueNode)
    {
        if (!$valueNode->children()->count()) {
            $val = (string)$valueNode;
            return ctype_digit($val) ? (int)$val : $val;
        }

        $child = $valueNode->children()[0];
        $type = strtolower($child->getName());
        $value = (string)$child;

        switch ($type) {
            case 'int':
            case 'i4':
                return (int)$value;

            case 'boolean':
                return $value === '1' || strtolower($value) === 'true';

            case 'double':
                return (float)$value;

            case 'dateTime.iso8601':
                return $value;

            case 'string':
            default:
                return $value;
        }
    }

    public function parseSamplePayload(string $rawPayload): array
    {
        try {
            if (empty($rawPayload)) {
                return [
                    'method'        => "",
                    'params'        => [],
                    'system_params' => [],
                    'raw_payload'   => ""
                ];
            }

            if (strpos($rawPayload, '<?xml') !== 0) {
                $xmlStart = strpos($rawPayload, '<?xml');
                if ($xmlStart !== false) {
                    $rawPayload = substr($rawPayload, $xmlStart);
                }
            }

            $xml = new \SimpleXMLElement($rawPayload);
            $methodName = (string)$xml->methodName;

            $struct = $xml->params->param->value->struct;
            if (!$struct) {
                return [
                    'method'        => $methodName,
                    'params'        => [],
                    'system_params' => [],
                    'raw_payload'   => $rawPayload
                ];
            }

            $systemMap = [
                'originNodeType'      => 'EXT',
                'originHostName'      => 'UAP',
                'originTransactionID' => $this->generateTransactionId(),
                'originTimeStamp'     => now()->format('Ymd\TH:i:s+0100'),
                'originOperatorID'    => $this->config['dynamic_operator_id']
                                 ?? $this->config['origin_operator_id']
                                 ?? 'UAPAdmin',
            ];

            $detectedSystemParams = [];
            $userParams = [];

            foreach ($struct->member as $member) {
                $name = (string)$member->name;
                $value = $this->extractXmlValue($member->value);

                if (array_key_exists($name, $systemMap)) {
                    $newValue = $systemMap[$name];

                    if ($member->value->children()->count()) {
                        $child = $member->value->children()[0];
                        $child[0] = $newValue;
                    } else {
                        $member->value = $newValue;
                    }

                    $detectedSystemParams[$name] = $newValue;
                } else {
                    $userParams[$name] = $value;
                }
            }

            return [
                'method'        => $methodName,
                'params'        => $userParams,
                'system_params' => $detectedSystemParams,
                'raw_payload'   => $xml->asXML()
            ];

        } catch (\Exception $e) {
            throw new \Exception("Failed to parse UCIP sample: " . $e->getMessage());
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
            // Remove anything before the XML declaration if necessary.
            if (strpos($rawPayload, '<?xml') !== 0) {
                $xmlStart = strpos($rawPayload, '<?xml');

                if ($xmlStart !== false) {
                    $rawPayload = substr($rawPayload, $xmlStart);
                }
            }

            libxml_use_internal_errors(true);

            $xml = new \SimpleXMLElement($rawPayload);

            /*
            * UCIP target subscriber is normally represented as:
            *
            * <member>
            *     <name>subscriberNumber</name>
            *     <value>
            *         <string>...</string>
            *     </value>
            * </member>
            *
            * Search recursively because the parameter may be nested
            * inside a struct/array depending on the command.
            */
            $members = $xml->xpath(
                '//member[name="subscriberNumber"]'
            );

            if (!empty($members)) {
                foreach ($members as $member) {
                    if (!isset($member->value)) {
                        continue;
                    }

                    $value = $member->value;

                    // XML-RPC typed value: <string>, <i4>, etc.
                    if ($value->children()->count() > 0) {
                        $identifier = trim(
                            (string) $value->children()[0]
                        );
                    } else {
                        $identifier = trim((string) $value);
                    }

                    if ($identifier !== '') {
                        return $identifier;
                    }
                }
            }

        } catch (\Throwable $e) {
            \Log::warning(
                "UCIP identifier extraction failed: " . $e->getMessage()
            );
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
                'originTimeStamp'
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