<?php

return [
    'method' => 'GetAccountDetails',
    'action' => 'view',
    'description' => 'Retrieve account information',
    'system_params' => [
        'originNodeType' => 'EXT',
        'originHostName' => '{host_name}',
        'originTransactionID' => '{auto_gen_id}',
        'originTimeStamp' => '{auto_gen_iso8601}',
    ],
    'request_payload' => '<?xml version="1.0" encoding="UTF-8"?>
<methodCall>
    <methodName>GetAccountDetails</methodName>
    <params>
        <param>
            <value>
                <struct>
                    <member>
                        <name>originTransactionID</name>
                        <value>
                            <string>17719473490554987</string>
                        </value>
                    </member>
                    <member>
                        <name>originTimeStamp</name>
                        <value>
                            <dateTime.iso8601>20260224T16:35:49+0100</dateTime.iso8601>
                        </value>
                    </member>
                    <member>
                        <name>originHostName</name>
                        <value>
                            <string>COMVIVA</string>
                        </value>
                    </member>
                    <member>
                        <name>originNodeType</name>
                        <value>
                            <string>COMVIVA</string>
                        </value>
                    </member>
                    <member>
                        <name>subscriberNumber</name>
                        <value>
                            <string>061195314</string>
                        </value>
                    </member>
                </struct>
            </value>
        </param>
    </params>
</methodCall>'
];