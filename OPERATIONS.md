# Server worker and monitoring

Run on the hosting server under the site's existing service account, with its normal PHP CLI version/extensions and filesystem permissions. Do not run a persistent job on an administrator's laptop. The CLI bootstraps the active platform and uses its stored bridge settings: no shared secret belongs in a command, crontab or log. The endpoint accepts CLI only and responds 404 over HTTP.

## Verify before scheduling

Replace all example paths with your server paths:

```sh
/usr/bin/php /srv/wordpress/wp-content/plugins/woocommerce-prestashop-bridge/includes/worker-cli.php --platform=woo --root=/srv/wordpress --command=tick
/usr/bin/php /srv/prestashop/modules/wd29woobridge/includes/worker-cli.php --platform=ps --root=/srv/prestashop --command=tick
```

Each invocation processes a bounded scan and event batch, then wakes its peer through the existing signed HTTPS channel. MySQL advisory locks prevent overlapping workers on the same store. Schedule both stores independently when possible so an outage of one does not stop local capture on the other. Existing WordPress cron may coexist; the worker lock avoids overlap. This script disables WordPress cron spawning only in its own PHP process; it does not change the site's cron configuration.

Example entries in the site's service-account crontab (create the private log directory with mode 0700 first; adapt paths):

```cron
* * * * * umask 077; /usr/bin/php /srv/wordpress/wp-content/plugins/woocommerce-prestashop-bridge/includes/worker-cli.php --platform=woo --root=/srv/wordpress --command=tick >> /var/log/wd29/woo-worker.log 2>&1
* * * * * umask 077; /usr/bin/php /srv/prestashop/modules/wd29woobridge/includes/worker-cli.php --platform=ps --root=/srv/prestashop --command=tick >> /var/log/wd29/ps-worker.log 2>&1
```

Install only the entry for the store present on each server. Configure private log rotation. Do not rely on cron output alone for alerts: connect the hosting monitor to the health command's exit status and JSON codes, with notification destination configured by the shop administrator. No notification is sent by this plugin.

## Monitoring and recovery

Use the same command with `--command=health` for a read-only check. Exit 0 means healthy/enabled, 2 means actionable diagnostics or disabled bridge, 1 means boot/worker failure, and 64 means incorrect arguments. Health contains queue counts and ages, worker age and internal record identifiers; it excludes event payloads, customer details and credentials. Signed peer health also includes these diagnostics. A worker older than five minutes is stale. Incoming pending events in audit mode intentionally remain unapplied and do not alone trigger a queue-age warning.

- `capture_failed`: inspect the named source record and private capture notice. Correct the fields; successful recapture of that same record clears its issue. Success for unrelated records cannot clear it.
- `peer_unreachable`: check HTTPS availability and pairing settings. Successful peer wake clears this issue only.
- `event_retrying`: delivery or application is backing off, starting at 60 seconds and capped at one hour. Review its journal error; no repeated manual retries are needed for a transient outage.
- `retry_exhausted`: eight failures stop automatic retry. Correct the cause then use the admin retry action. Later events for the same record wait; unrelated records can progress.
- `record_conflict`: review both versions before choosing the catalog source. Never automatically force order or customer conflicts.
- `queue_delayed`: check both workers, earlier blocked events and catalog size. Each run is intentionally bounded; large imports require repeated runs.

The legacy notice string is historical, while structured operational issues are current. Neither a successful unrelated capture nor a successful peer connection proves all records are valid. Queue diagnostics remain until recovery.

## Boundaries

Receiving an event acknowledges durable receipt, not successful native application. Inspect both peers' diagnostics and reconciliation reports. A timeout after receipt is safe to resend with the same event ID: the unique event/direction key suppresses duplicates. Native writes and queue completion share a database transaction, but third-party hooks and external payment effects cannot be rolled back by MySQL. Never enable external financial side effects when replaying mirrored orders.

No production scheduler has been installed merely by shipping these files. Hosting shell/cron access and a successful server-side run are required to claim scheduling is operational.

## Technical order upgrades

Use **Relancer les mises à jour de commandes équivalentes** (Retry equivalent order updates before 0.4) for conflicts introduced by new empty fields or source line identities. The bridge compares full canonical business data and checks actual native mirror financial/address/line values before retrying. It repeats the guard during application. A changed status, amount, address, populated metadata/refund or known line identity remains conflicted. The diagnostics table summarizes differences without exposing customer/custom values. This action never selects a financial winner.

## Deleted products and gallery recovery

Permanent deletion of a mapped original emits an archive marker from its last captured catalog. The mirror is unpublished without changing stock or removing media; stale catalog updates cannot resurrect it. Missing snapshots require review and are diagnosed. A deleted source is never automatically recreated. Use a new source product identity for a deliberate replacement.

Optional gallery removal is reversible through **Rattacher les images détachées** (Restore detached gallery images before 0.4) with a mapped product/variation key. Files and manual images remain. See GALLERY.md.
