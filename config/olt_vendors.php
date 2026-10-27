<?php

// OLT vendors. Reading is SNMP v2c (App\Services\Network\SnmpOltDriver); changes are CLI commands over
// SSH or Telnet (App\Services\Network\OltCli). Vendor differences are data: add a brand (VSOL, C-Data,
// Nokia, …) with an entry carrying the OIDs from its MIB and the commands from its CLI manual.
// A vendor with no 'oids' is a record-only OLT: ONUs are entered by hand.
//
// rows       'port_onu' = the table is indexed {PON port ifIndex}.{ONU id}[.more] (GPON style);
//            'onu_if'   = every ONU is its own interface, indexed {ONU ifIndex}; the PON port and ONU id
//                         come from its ifDescr through 'onu_if_pattern' (e.g. BDCOM "EPON0/1:5").
// oids       column => table OID (any may be left out): serial, mac, name, status, rx_power, tx_power, distance.
// status_map raw status value => online | offline | los | dying_gasp | auth_failed (others: unknown).
// power      dBm = raw * scale + offset; signed16 treats raw as a signed 16-bit number first;
//            raw values in 'invalid' mean "no reading" (ONU offline).
//
// cli        login     prompt regex => what to type ({username}, {password}, {enable_password}); Telnet only,
//                      SSH logs in itself and only 'enable' prompts may follow
//            prompt    regex the CLI shows when it waits for a command (end of output)
//            setup     commands after login (enable, no paging, configuration mode)
//            more      pager regex answered with a space; confirm: question regex => answer
//            error     regex: output matching it stops the run and shows the output
//            port      regex with named groups (frame, slot, port) taken from the ONU's PON port
//            commands  action => command lines, with {frame} {slot} {port} {onu_id} {serial} {serial_hex}
//                      {mac} {mac_dotted} {name} {name_token} {line_profile} {service_profile} {vlan}
//                      {gemport} {onu_type}; 'unregistered' is the one command that lists ONUs waiting
//                      for authorisation, read with 'unregistered_pattern' (named groups pon + serial or mac)
//
// The OIDs and commands below come from the vendors' MIBs and CLI manuals; firmware differs, so check
// them on your OLT first: the OLT page previews every command before it is sent.
return [
    'huawei' => [
        'label' => 'Huawei (MA5600T / MA5683T / MA5800, GPON)',
        'rows' => 'port_onu',
        'oids' => [
            'serial' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',
            'name' => '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9',
            'status' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
            'distance' => '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
            'rx_power' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
            'tx_power' => '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3',
        ],
        'status_map' => [1 => 'online', 2 => 'offline'],
        'power' => ['scale' => 0.01, 'offset' => 0, 'invalid' => [2147483647]],
        'cli' => [
            'login' => ['/User name:\s*$/i' => '{username}', '/User password:\s*$/i' => '{password}'],
            'prompt' => '/[\w.\-]+(\([\w.\-\/:]+\))?[>#]\s*$/',
            'setup' => ['enable', 'scroll 512', 'config'],
            'more' => '/-+\s*More.*-+/i',
            'confirm' => ['/\(y\/n\)\[n\]:\s*$/i' => 'y', '/\{\s*<cr>[^}]*\}:\s*$/' => ''],
            'error' => '/(% Unknown command|% Parameter error|% Incomplete command|Failure:|Error:)/i',
            'port' => '/(?<frame>\d+)\/(?<slot>\d+)\/(?<port>\d+)/',
            'commands' => [
                'reboot' => ['interface gpon {frame}/{slot}', 'ont reset {port} {onu_id}', 'quit'],
                'rename' => ['interface gpon {frame}/{slot}', 'ont modify {port} {onu_id} desc "{name}"', 'quit'],
                'delete' => ['undo service-port port {frame}/{slot}/{port} ont {onu_id}', 'interface gpon {frame}/{slot}', 'ont delete {port} {onu_id}', 'quit'],
                'authorize' => [
                    'interface gpon {frame}/{slot}',
                    'ont add {port} {onu_id} sn-auth {serial_hex} omci ont-lineprofile-id {line_profile} ont-srvprofile-id {service_profile} desc "{name}"',
                    'quit',
                    'service-port vlan {vlan} gpon {frame}/{slot}/{port} ont {onu_id} gemport {gemport} multi-service user-vlan {vlan} tag-transform translate',
                ],
                'unregistered' => 'display ont autofind all',
            ],
            'unregistered_pattern' => '/F\/S\/P\s*:\s*(?<pon>\d+\/\d+\/\d+)\s+Ont SN\s*:\s*(?<serial>[0-9A-F]{16})/i',
            'pon_label' => 'GPON {pon}',
            'max_onu_id' => 127,
        ],
    ],

    'zte' => [
        'label' => 'ZTE (C300 / C320 / C600, GPON)',
        'rows' => 'port_onu',
        'oids' => [
            'name' => '1.3.6.1.4.1.3902.1012.3.28.1.1.2',
            'serial' => '1.3.6.1.4.1.3902.1012.3.28.1.1.5',
            'status' => '1.3.6.1.4.1.3902.1012.3.28.2.1.4',
            'rx_power' => '1.3.6.1.4.1.3902.1012.3.50.12.1.1.10',
        ],
        // 1 logging, 2 los, 3 syncMib, 4 working, 5 dyingGasp, 6 authFailed, 7 offline
        'status_map' => [1 => 'offline', 2 => 'los', 3 => 'offline', 4 => 'online', 5 => 'dying_gasp', 6 => 'auth_failed', 7 => 'offline'],
        'power' => ['scale' => 0.002, 'offset' => -30, 'signed16' => true, 'invalid' => [65535]],
        'cli' => [
            'login' => ['/Username:\s*$/i' => '{username}', '/Password:\s*$/i' => '{password}'],
            'prompt' => '/[\w.\-]+(\([\w.\-\/:]+\))?#\s*$/',
            'setup' => ['terminal length 0', 'configure terminal'],
            'more' => '/--More--/i',
            'confirm' => ['/\[yes\/no\]:?\s*$/i' => 'yes'],
            'error' => '/(%Error|% Invalid|%Code|Invalid input)/i',
            'port' => '/(?<frame>\d+)\/(?<slot>\d+)\/(?<port>\d+)/',
            'commands' => [
                'reboot' => ['pon-onu-mng gpon-onu_{frame}/{slot}/{port}:{onu_id}', 'reboot', 'exit'],
                'rename' => ['interface gpon-onu_{frame}/{slot}/{port}:{onu_id}', 'name {name_token}', 'exit'],
                'delete' => ['interface gpon-olt_{frame}/{slot}/{port}', 'no onu {onu_id}', 'exit'],
                'authorize' => [
                    'interface gpon-olt_{frame}/{slot}/{port}',
                    'onu {onu_id} type {onu_type} sn {serial}',
                    'exit',
                    'interface gpon-onu_{frame}/{slot}/{port}:{onu_id}',
                    'name {name_token}',
                    'tcont 1 profile {line_profile}',
                    'gemport {gemport} tcont 1',
                    'service-port 1 vport 1 user-vlan {vlan} vlan {vlan}',
                    'exit',
                ],
                'unregistered' => 'show gpon onu uncfg',
            ],
            'unregistered_pattern' => '/gpon-onu_(?<pon>\d+\/\d+\/\d+):\d+\s+(?<serial>[A-Z0-9]{12})\b/i',
            'pon_label' => 'gpon_{pon}',
            'max_onu_id' => 128,
        ],
    ],

    'bdcom' => [
        'label' => 'BDCOM (P3310 / P3600 / GP3600, EPON)',
        'rows' => 'onu_if',
        'onu_if_pattern' => '/^(?:EPON|GPON)\s*(\d+\/\d+):(\d+)$/i',
        'oids' => [
            'mac' => '1.3.6.1.4.1.3320.101.10.1.1.3',
            'name' => '1.3.6.1.2.1.31.1.1.1.18', // ifAlias
            'status' => '1.3.6.1.2.1.2.2.1.8', // ifOperStatus of the ONU interface
            'distance' => '1.3.6.1.4.1.3320.101.10.1.1.27',
            'rx_power' => '1.3.6.1.4.1.3320.101.10.5.1.5',
        ],
        'status_map' => [1 => 'online', 2 => 'offline'],
        'power' => ['scale' => 0.1, 'offset' => 0, 'invalid' => [-65535, 0]],
        'cli' => [
            'login' => ['/Username:\s*$/i' => '{username}', '/Password:\s*$/i' => '{password}'],
            'prompt' => '/[\w.\-]+(\([\w.\-\/:]+\))?[>#]\s*$/',
            'setup' => ['enable', 'terminal length 0', 'config'],
            'more' => '/--More--/i',
            'confirm' => ['/\[Y\/N\]\??:?\s*$/i' => 'y', '/\(y\/n\)\??:?\s*$/i' => 'y'],
            'error' => '/(% Invalid|% Unknown|% Incomplete|Error:)/i',
            'port' => '/(?<slot>\d+)\/(?<port>\d+)/',
            'commands' => [
                'reboot' => ['interface EPON{slot}/{port}:{onu_id}', 'epon reboot onu', 'exit'],
                'rename' => ['interface EPON{slot}/{port}:{onu_id}', 'description {name_token}', 'exit'],
                'delete' => ['interface EPON{slot}/{port}', 'no epon bind-onu sequence {onu_id}', 'exit'],
                'authorize' => ['interface EPON{slot}/{port}', 'epon bind-onu sequence {onu_id} mac {mac_dotted}', 'exit'],
                'unregistered' => 'show epon inactive-onu',
            ],
            'unregistered_pattern' => '/EPON(?<pon>\d+\/\d+)\S*\s+(?<mac>[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4})/i',
            'pon_label' => 'EPON{pon}',
            'max_onu_id' => 64,
        ],
    ],

    'other' => [
        'label' => 'Other brand (ONUs entered by hand)',
    ],
];
