<?php

return [
    'method' => 'UpdateBalanceAndDate',
    'action' => 'update',
    'description' => 'Update accounts of a subscriber',
    
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
	<methodName>UpdateBalanceAndDate</methodName>
	<params>
		<param>
			<value>
				<struct>
					<member>
						<name>originNodeType</name>
						<value>
							<string>EXT222</string>
						</value>
					</member>
					<member>
						<name>originHostName</name>
						<value>
							<string>LEAPDIYVOICE</string>
						</value>
					</member>
					<member>
						<name>originTransactionID</name>
						<value>
							<string>21371947304793</string>
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
							<string>066373094</string>
						</value>
					</member>
					<member>
						<name>transactionCurrency</name>
						<value>
							<string>CFA</string>
						</value>
					</member>
					<member>
						<name>transactionType</name>
						<value>
							<string>DIYVOICEONNETKDO065925751</string>
						</value>
					</member>
					<member>
						<name>transactionCode</name>
						<value>
							<string>36000</string>
						</value>
					</member>
					<member>
						<name>dedicatedAccountUpdateInformation</name>
						<value>
							<array>
								<data>
									<value>
										<struct>
											<member>
												<name>dedicatedAccountID</name>
												<value>
													<int>3600</int>
												</value>
											</member>
											<member>
												<name>adjustmentAmountRelative</name>
												<value>
													<string>48000</string>
												</value>
											</member>
											<member>
												<name>expiryDate</name>
												<value>
													<dateTime.iso8601>20260225T15:35:49+0100</dateTime.iso8601>
												</value>
											</member>
											<member>
												<name>dedicatedAccountUnitType</name>
												<value>
													<int>1</int>
												</value>
											</member>
										</struct>
									</value>
								</data>
							</array>
						</value>
					</member>
					<member>
						<name>externalData1</name>
						<value>
							<string>100F1D</string>
						</value>
					</member>
				</struct>
			</value>
		</param>
	</params>
</methodCall>'
];