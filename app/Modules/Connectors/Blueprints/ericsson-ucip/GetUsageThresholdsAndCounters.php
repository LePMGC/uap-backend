<?php

return [
    'method' => 'GetUsageThresholdsAndCounters',
    'action' => 'view',
    'description' => 'Retrieve usage counters and thresholds information',
    'system_params' => [
        'originNodeType' => 'EXT',
        'originHostName' => '{host_name}',
        'originTransactionID' => '{auto_gen_id}',
        'originTimeStamp' => '{auto_gen_iso8601}',
    ],
    'request_payload' => '<?xml version="1.0"?>
<methodCall>
	<methodName>GetUsageThresholdsAndCounters</methodName>
	<params>
		<param>
			<value>
				<struct>
					<member>
						<name>originNodeType</name>
						<value>
							<string>EXT</string>
						</value>
					</member>
					<member>
						<name>originHostName</name>
						<value>
							<string>LEAP</string>
						</value>
					</member>
					<member>
						<name>originTransactionID</name>
						<value>
							<string>11271947355991</string>
						</value>
					</member>
					<member>
						<name>originTimeStamp</name>
						<value>
							<dateTime.iso8601>20260224T15:35:56+0100</dateTime.iso8601>
						</value>
					</member>
					<member>
						<name>subscriberNumberNAI</name>
						<value>
							<int>2</int>
						</value>
					</member>
					<member>
						<name>subscriberNumber</name>
						<value>
							<string>065605712</string>
						</value>
					</member>
				</struct>
			</value>
		</param>
	</params>
</methodCall>'
];