<?php

// RADIUS NAS vendors: how each one takes a package's speed, and what it accepts live (RFC 5176).
// Adding a vendor is a new entry here, never an `if ($vendor === ...)` in code (see NasVendor).
//
//  label       shown on the router form
//  checkrad    FreeRADIUS `nas.type` (checkrad type for simultaneous-use checks)
//  rate        reply attributes for "up/down Mbps": [attribute, op, value template]; placeholders
//              {up} {down} (Mbps), {up_kbps} {down_kbps}, {up_bps} {down_bps}. Every attribute must
//              be known to RadiusClient (VENDOR_ATTRIBUTES / ATTRIBUTES) when coa_rate is on.
//  own_group   true when the rate uses standard attributes other vendors read differently (Filter-Id
//              is a firewall chain on MikroTik): this vendor's users get their own package group.
//              Vendor-specific attributes (VSAs) are ignored by other vendors, so those share one group.
//  coa_rate    a live session's speed is changed with a CoA carrying `rate`; a NAK falls back to a disconnect
//  disconnect  the NAS accepts Disconnect-Request; false = a suspended user drops at the NAS's next
//              re-authentication (see note)
//  note        setup hint shown on the router form
return [
    'mikrotik' => [
        'label' => 'MikroTik RouterOS',
        'checkrad' => 'mikrotik',
        'rate' => [['Mikrotik-Rate-Limit', ':=', '{up}M/{down}M']],
        'own_group' => false,
        'coa_rate' => true,
        'disconnect' => true,
        'note' => 'PPP → Secrets → PPP Authentication & Accounting → Use RADIUS; RADIUS → Incoming → Accept (port 3799).',
    ],
    'huawei' => [
        'label' => 'Huawei BRAS / ME60 / NE',
        'checkrad' => 'huawei',
        'rate' => [['Huawei-Input-Average-Rate', ':=', '{up_bps}'], ['Huawei-Output-Average-Rate', ':=', '{down_bps}']],
        'own_group' => false,
        'coa_rate' => true,
        'disconnect' => true,
        'note' => 'Enable the RADIUS server group for the domain and "radius-server authorization" for CoA from this server.',
    ],
    'cisco' => [
        'label' => 'Cisco IOS-XE / IOS-XR BNG',
        'checkrad' => 'cisco',
        'rate' => [['Cisco-AVPair', '+=', 'ip:sub-qos-policy-in=isp-up-{up}M'], ['Cisco-AVPair', '+=', 'ip:sub-qos-policy-out=isp-down-{down}M']],
        'own_group' => false,
        'coa_rate' => false,
        'disconnect' => true,
        'note' => 'Create policy-maps named isp-up-<N>M / isp-down-<N>M for every package speed; enable "aaa server radius dynamic-author".',
    ],
    'juniper' => [
        'label' => 'Juniper MX / BNG',
        'checkrad' => 'juniper',
        'rate' => [['ERX-Ingress-Policy-Name', ':=', 'isp-up-{up}M'], ['ERX-Egress-Policy-Name', ':=', 'isp-down-{down}M']],
        'own_group' => false,
        'coa_rate' => false,
        'disconnect' => true,
        'note' => 'Create firewall policers / filters named isp-up-<N>M / isp-down-<N>M in the dynamic profile; allow dynamic-request from this server.',
    ],
    'accel-ppp' => [
        'label' => 'VyOS / Linux (accel-ppp, FRRouting)',
        'checkrad' => 'other',
        'rate' => [['Filter-Id', ':=', '{down_kbps}/{up_kbps}']],
        'own_group' => true,
        'coa_rate' => true,
        'disconnect' => true,
        'note' => 'Shaper on Filter-Id (VyOS: "authentication radius rate-limit enable"); enable the dynamic-author (dae-server) on port 3799.',
    ],
    'pfsense' => [
        'label' => 'pfSense / OPNsense',
        'checkrad' => 'other',
        'rate' => [['WISPr-Bandwidth-Max-Up', ':=', '{up_bps}'], ['WISPr-Bandwidth-Max-Down', ':=', '{down_bps}']],
        'own_group' => false,
        'coa_rate' => false,
        'disconnect' => false,
        'note' => 'No Disconnect/CoA: in the captive portal turn on "Reauthenticate connected users every minute" and per-user bandwidth, so a suspension takes effect within a minute.',
    ],
    'other' => [
        'label' => 'Other (speeds set on the NAS)',
        'checkrad' => 'other',
        'rate' => [],
        'own_group' => false,
        'coa_rate' => false,
        'disconnect' => true,
        'note' => 'Billing only allows or rejects the login; set speeds on the NAS.',
    ],
];
