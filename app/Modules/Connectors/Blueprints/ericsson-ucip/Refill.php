<?php

return [
    'method' => 'Refill',
    'action' => 'run',
    'description' => 'Refill a subscriber\'s account balance',
    
    // System parameters required by the Ericsson AIR node for every request
    'system_params' => [
        'originNodeType'      => 'EXT',
        'originHostName'      => '{host_name}',
        'originTransactionID' => '{auto_gen_id}', // Updated here
        'originTimeStamp'     => '{auto_gen_iso8601}',
        'requestedOwner'      => 1,
    ],

    // This command requires no user input to function
    'request_payload' => '<?xml version="1.0"?>
<methodCall>
	<methodName>Refill</methodName>
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
							<string>2571947350673</string>
						</value>
					</member>
					<member>
						<name>originTimeStamp</name>
						<value>
							<dateTime.iso8601>20260224T15:35:59+0100</dateTime.iso8601>
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
							<string>065030972</string>
						</value>
					</member>
					<member>
						<name>externalData1</name>
						<value>
							<string>150</string>
						</value>
					</member>
					<member>
						<name>externalData2</name>
						<value>
							<string>80140</string>
						</value>
					</member>
					<member>
						<name>externalData3</name>
						<value>
							<string>0</string>
						</value>
					</member>
					<member>
						<name>externalData4</name>
						<value>
							<string>0</string>
						</value>
					</member>
					<member>
						<name>transactionAmount</name>
						<value>
							<string>0</string>
						</value>
					</member>
					<member>
						<name>transactionCurrency</name>
						<value>
							<string>CFA</string>
						</value>
					</member>
					<member>
						<name>refillProfileID</name>
						<value>
							<string>DB00</string>
						</value>
					</member>
				</struct>
			</value>
		</param>
	</params>
</methodCall>
'
];