# RADIUS (FreeRADIUS) setup

isp306 can drive a router either over the MikroTik REST API or through RADIUS. RADIUS works with any
NAS/BRAS that authenticates PPPoE or Hotspot users with RADIUS (MikroTik, Huawei, Cisco, Juniper…).
Both kinds can be mixed: each router picks its driver under **Network Setup → Routers → Managed by**.

## What the billing system writes

FreeRADIUS SQL tables (standard FreeRADIUS 3 MySQL schema; created by `php artisan migrate` when missing):

| Table | Rows |
|---|---|
| `radcheck` | `Cleartext-Password` per user; `Auth-Type := Reject` while the connection is not active; `Calling-Station-Id` for a hotspot MAC lock |
| `radreply` | `Framed-IP-Address` for a static IP |
| `radusergroup` | user → package group `isp-pkg-<id>` (or the package's network profile) |
| `radgroupreply` | speed of the package per NAS type: `Mikrotik-Rate-Limit`, `Huawei-Input/Output-Average-Rate`, `Cisco-AVPair` (`ip:sub-qos-policy-in=isp-up-<N>M` / `-out=isp-down-<N>M`, policy-maps needed on the BNG) |
| `nas` | every active RADIUS router, with its secret (FreeRADIUS reads clients from here) |

It reads `radacct` (online status, session history with IP / MAC / data used) and `radpostauth` (why a login failed).

Suspension, renames and IP changes end the live session with a **Disconnect-Request** (RFC 5176) to the NAS's CoA
port (3799 by default). A package change on a MikroTik NAS is applied live with a **CoA-Request**
(`Mikrotik-Rate-Limit`); other NAS types, or a NAS that refuses the CoA, get a disconnect and the user reconnects
with the new speed. If the NAS does not answer, the connection shows "Sync failed" and the push is retried.

## 1. Database

By default the RADIUS tables live in the app database. To use the FreeRADIUS server's own database, set in `.env`:

```
RADIUS_DB_HOST=10.0.0.5
RADIUS_DB_DATABASE=radius
RADIUS_DB_USERNAME=isp306
RADIUS_DB_PASSWORD=...
```

then run `php artisan migrate` (existing FreeRADIUS tables are left as they are).

## 2. FreeRADIUS (3.x, Debian/Ubuntu paths)

```
apt install freeradius freeradius-mysql freeradius-utils
cd /etc/freeradius/3.0
ln -s ../mods-available/sql mods-enabled/sql
```

In `mods-available/sql`:

```
dialect = "mysql"
driver = "rlm_sql_${dialect}"
server = "<db host>"
login = "<db user>"
password = "<db password>"
radius_db = "<database with the radius tables>"
read_clients = yes          # NAS list comes from the nas table the billing system fills
```

(Remove or fill in the `tls { }` block inside `mysql { }` if the database has no TLS.)
The default site already calls `-sql` in authorize, accounting, session and post-auth. Restart FreeRADIUS.

Check: `radtest <pppoe user> <password> 127.0.0.1 0 <secret>` → `Access-Accept` with the rate attribute
(add a `client localhost` entry, or a RADIUS router for 127.0.0.1, to run it on the server).

## 3. The NAS

- Add it under **Routers**: Managed by *RADIUS*, NAS IP (the NAS-IP-Address it sends), NAS type, RADIUS secret, CoA port.
- Point its RADIUS authentication **and accounting** at the FreeRADIUS server with the same secret, interim updates
  every 5 minutes or so (for usage), and allow incoming CoA/Disconnect from the billing server.
- MikroTik: `/radius add service=ppp,hotspot address=<freeradius> secret=<secret>`,
  `/radius incoming set accept=yes port=3799`, `/ppp aaa set use-radius=yes accounting=yes interim-update=5m`.

Site / IP blocks and the live terminal need the MikroTik API and are not available on a RADIUS NAS.
