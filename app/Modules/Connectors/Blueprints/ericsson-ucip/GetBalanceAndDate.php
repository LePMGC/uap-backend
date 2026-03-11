<?php

return [
    'method' => 'GetBalanceAndDate',
    'action' => 'view',
    'description' => 'Retrieve account balances information',
    'system_params' => [
        'originNodeType' => 'EXT',
        'originHostName' => '{host_name}',
        'originTransactionID' => '{auto_gen_id}',
        'originTimeStamp' => '{auto_gen_iso8601}',
    ],
    'request_payload' => '<?xml version="1.0"?>
<methodCall>
	<methodName>GetBalanceAndDate</methodName>
	<params>
		<param>
			<value>
				<struct>
					<member>
						<name>originNodeType</name>
						<value>
							<string>EXT154</string>
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
							<string>0142717719473443940</string>
						</value>
					</member>
					<member>
						<name>originTimeStamp</name>
						<value>
							<dateTime.iso8601>20260224T15:35:49+0100</dateTime.iso8601>
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
							<string>065029221</string>
						</value>
					</member>
				</struct>
			</value>
		</param>
	</params>
</methodCall>'
];