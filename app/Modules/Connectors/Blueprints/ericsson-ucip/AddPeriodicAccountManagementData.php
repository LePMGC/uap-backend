<?php

return [
    'method' => 'AddPeriodicAccountManagementData',
    'action' => 'create',
    'description' => 'Add periodic account management tasks for a subscriber',
    
    // System parameters required by the Ericsson AIR node for every request
    'system_params' => [
        'originNodeType'      => 'EXT',
        'originHostName'      => '{host_name}',
        'originTransactionID' => '{auto_gen_id}', // Updated here
        'originTimeStamp'     => '{auto_gen_iso8601}',
        'requestedOwner'      => 1,
    ],

    'request_payload' => '<?xml version="1.0"?>
<methodCall>
	<methodName>AddPeriodicAccountManagementData</methodName>
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
							<string>LEAP7369847919</string>
						</value>
					</member>
					<member>
						<name>originTransactionID</name>
						<value>
							<string>0112717719473098096</string>
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
							<string>069229595</string>
						</value>
					</member>
					<member>
						<name>pamInformationList</name>
						<value>
							<array>
								<data>
									<value>
										<struct>
											<member>
												<name>pamServiceID</name>
												<value>
													<int>3</int>
												</value>
											</member>
											<member>
												<name>pamClassID</name>
												<value>
													<int>3</int>
												</value>
											</member>
											<member>
												<name>scheduleID</name>
												<value>
													<int>3</int>
												</value>
											</member>
										</struct>
									</value>
								</data>
							</array>
						</value>
					</member>
				</struct>
			</value>
		</param>
	</params>
</methodCall>'
];