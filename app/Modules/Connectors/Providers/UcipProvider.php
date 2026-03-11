<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\Facades\Http;
use Exception;
use SimpleXMLElement;

class UcipProvider extends BaseProvider
{
    protected bool $isStateful = false;
    protected array $statusRegistry;

    public function __construct(array $config, array $blueprint)
    {
        parent::__construct($config, $blueprint);
        // Manually load the specialized config file
        $this->statusRegistry = require __DIR__ . '/../Config/ucip_codes.php';
    }

    protected function login(): void {} 
    protected function logout(): void {}

    /**
     * Builds the UCIP XML-RPC payload, injecting ONLY authorized system parameters.
     */
    protected function buildPayload(array $commandDef, array $params): string
    {
        $method = $commandDef['method'];
        
        // 1. Define the Available System Parameter Pool
        $pool = [
            'originNodeType'      => $this->config['origin_node_type'] ?? 'EXT',
            'originHostName'      => $this->config['host'] ?? 'UAP-Server',
            'originTransactionID' => $this->generateTransactionId(),
            'originTimeStamp'     => now()->format('Ymd\TH:i:s+0000'),
        ];

        // 2. Filter the pool based on what is defined in the command
        // We look into 'system_params' which we will pass from the Executor
        $allowedSystemKeys = $commandDef['system_params'] ?? [];
        $authorizedSystemParams = [];

        foreach ($allowedSystemKeys as $key => $placeholder) {
            // If the key exists in our pool, we include it
            if (array_key_exists($key, $pool)) {
                $authorizedSystemParams[$key] = $pool[$key];
            }
        }

        // 3. Merge only the authorized ones with user params
        $finalParams = array_merge($authorizedSystemParams, $params);

        $xml = "<?xml version=\"1.0\"?>\n<methodCall>\n<methodName>{$method}</methodName>\n<params>\n<param>\n<value><struct>\n";
        
        foreach ($finalParams as $key => $value) {
            $xml .= "<member><name>{$key}</name><value>";
            $xml .= $this->encodeValue($value);
            $xml .= "</value></member>\n";
        }

        return $xml . "</struct></value></param></params>\n</methodCall>";
    }

    /**
     * Helper to generate a unique transaction ID for UCIP
     */
    private function generateTransactionId(): string
    {
        // UCIP often expects a numeric or alphanumeric string
        return (string)mt_rand(100000000, 999999999);
    }

    /**
     * Handles UCIP Data Types: string, int, boolean, dateTime, and structs
     */
    private function encodeValue($value): string
    {
        // If it's an array, it's a struct
        if (is_array($value)) {
            $struct = "<struct>";
            foreach ($value as $k => $v) {
                $struct .= "<member><name>{$k}</name><value>{$this->encodeValue($v)}</value></member>";
            }
            return $struct . "</struct>";
        }

        // Explicitly handle Integers
        if (is_int($value)) {
            return "<i4>{$value}</i4>";
        }

        // Explicitly handle Booleans
        if (is_bool($value)) {
            return "<boolean>" . ($value ? "1" : "0") . "</boolean>";
        }

        // Handle ISO8601 strings
        if (is_string($value) && preg_match('/^\d{8}T\d{2}:\d{2}:\d{2}/', $value)) {
            return "<dateTime.iso8601>{$value}</dateTime.iso8601>";
        }

        // Default to string
        return "<string>{$value}</string>";
    }

    protected function send(string $payload): string
    {
        return Http::withHeaders([
            'User-Agent' => $this->config['user_agent'] ?? 'UAP-Server/1.0',
            'Content-Type' => 'text/xml',
            'Connection' => 'Keep-Alive',
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
            
            // Handle Protocol-level faults (e.g., authentication or method errors)
            if (isset($xml->fault)) {
                return $this->handleFault($xml->fault->value->struct);
            }

            // Navigate to the standard XML-RPC response structure
            $struct = $xml->params->param->value->struct;
            $data = $this->parseXmlStruct($struct);

            // Extract the responseCode; default to 0 (Success) if not present
            $responseCode = isset($data['responseCode']) ? (int)$data['responseCode'] : 0;
            
            // Map the numeric code to the human-readable description from ucip_codes.php
            $description = $this->statusRegistry['responses'][$responseCode] ?? "Unknown Error ({$responseCode})";

            // Inject the description into the data array so it is saved in the 'response_payload' column
            $data['response_message'] = $description;

            $isSuccessful = $responseCode === 0 || $responseCode === 1 || $responseCode === 2;
            
            //log user parameters and response code for telecom auditing purposes
            \Log::info("userParams: " . json_encode($commandDef['params'] ?? []));

            // TELECOM LOGGING: Log the specific provider code and its meaning
            \App\Modules\Core\Auditing\Services\UapLogger::log(
                'EricssonUCIP', 
                'PROVIDER_RESPONSE', 
                $isSuccessful ? 'info' : 'error', 
                [
                    'code'    => $responseCode,
                    'message' => $description,
                    // Now you can get it from the original request parameters!
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
        // Get the first child node (e.g., <string>, <i4>, <array>, <struct>)
        $child = $value->children()[0] ?? null;
        if (!$child) return (string)$value;

        $type = $child->getName();

        return match ($type) {
            'struct' => $this->parseXmlStruct($child),
            'array'  => $this->parseXmlArray($child->data),
            'i4', 'int' => (int)$child,
            'boolean' => (bool)$child,
            default => (string)$child,
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

    /**
     * Handles XML-RPC Fault responses.clear
     * These occur when the protocol itself fails (e.g., Method not found, Auth failed).
     * * @param SimpleXMLElement $faultStruct The <struct> inside the <fault> tag
     * @return array Standardized error response
     */
    private function handleFault(SimpleXMLElement $faultStruct): array
    {
        // Use the recursive parser we built to get the faultCode and faultString
        $faultData = $this->parseXmlStruct($faultStruct);
        
        $code = (int)($faultData['faultCode'] ?? 999);
        $faultString = $faultData['faultString'] ?? 'Unknown Protocol Error';

        return [
            'success' => false,
            'code'    => $code,
            // Try to find a friendly message in our registry, otherwise use the raw fault string
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
            
            // Basic handling: if child is a struct/array, we might need recursion later
            // For now, we flatten the immediate value
            $result[$name] = (string)$valNode[0];
        }

        return $result;
    }

    public function checkHealth(): bool
    {
        // We use a dummy command or a basic XML-RPC call
        // If we get a responseCode (even an error like 'Subscriber Not Found'), 
        // it means the SERVER is alive.
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
        
        // The specific keys to look for in UCIP XML
        $map = [
            'originNodeType'      => 'EXT',
            'originHostName'      => '{host_name}',
            'originTransactionID' => '{auto_gen_id}',
            'originTimeStamp'     => '{auto_gen_iso8601}',
        ];

        foreach ($map as $key => $placeholder) {
            // Regex to find content inside <name>key</name><value><type>value</type></value>
            $pattern = "/<name>{$key}<\/name>\s*<value>\s*<[^>]+>([^<]+)<\/[^>]+>\s*<\/value>/i";
            
            // If the key exists in the raw payload, we assign it the defined platform value
            if (preg_match($pattern, $rawPayload)) {
                $detected[$key] = $placeholder;
            }
        }

        return $detected;
    }
}