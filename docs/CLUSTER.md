# Several servers

When one server can no longer collect every event, Aggregate can run on several
servers that share one database. This guide sets one up on ordinary Linux
servers, with Nginx or Caddy, PHP-FPM, systemd, MariaDB, MySQL or PostgreSQL,
and `ufw`. [Plesk](../PLESK-DEPLOYMENT.md#several-servers) has its own section.
Neither needs PRAGMA or another hosting panel.

Read [server roles](CONFIGURATION.md#server-roles) first: it says what each role
serves and runs. [Load testing](LOAD-TESTING.md#database-headroom) shows how
much more a shared database takes before it becomes the limit; on MySQL and
MariaDB anonymous collection that headroom is small.

> This guide has been followed in CI (`tests/Integration/cluster.php`, on one
> machine with loopback addresses), not yet on separate fresh servers.

## The servers

| Server | Example private address | Public | Runs |
| --- | --- | --- | --- |
| Database | `10.0.0.5` | No | MariaDB, MySQL or PostgreSQL |
| Administration | `10.0.0.10` | `admin.example.com` | Aggregate (`administration`): dashboard, setup, updates, the scheduled task |
| Collection | `10.0.0.11`, `10.0.0.12` | No | Aggregate (`collection`): `/api/receive` and the scripts |
| Worker (optional) | `10.0.0.21` | No | Aggregate (`worker`): queue consumers |
| Balancer | `10.0.0.2` | `collect.example.com` | Nginx or Caddy only |

The servers talk over a private network. Websites send events to the balancer
at `collect.example.com`; people sign in at `admin.example.com`. Every server
running Aggregate has the same release.

### Choose the queue

- **`sync://` (simpler, recommended).** Each collection server writes enhanced
  events itself, during the request. No worker server.
- **`doctrine://` with worker servers.** Collection servers queue enhanced
  events in the shared database and worker servers write them. The queue's
  polling, locking and deleting run on the database that takes every insert:
  the [database headroom](LOAD-TESTING.md#database-headroom) runs measured it
  as the costliest setup for a shared database, three to four times the
  database CPU of `sync://`, serialized by `NOTIFY` on PostgreSQL. Choose it only when collection servers must answer faster than
  an enhanced insert allows.

Every server uses the same queue: `queue` in the join settings below.

## 1. The database server

Give every Aggregate server its own database user, allowed from that
server's address only, and open the database port to those addresses only.

**MariaDB or MySQL.** Listen on the private address, in
`/etc/mysql/mariadb.conf.d/60-aggregate.cnf` (MySQL: `/etc/mysql/mysql.conf.d/`):

```ini
[mysqld]
bind-address = 10.0.0.5
max_connections = 300
```

```sql
CREATE DATABASE aggregate CHARACTER SET utf8mb4;
CREATE USER 'aggregate'@'10.0.0.10' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON aggregate.* TO 'aggregate'@'10.0.0.10';
-- the same for 10.0.0.11, 10.0.0.12 and 10.0.0.21
```

**PostgreSQL.** In `postgresql.conf`, `listen_addresses = 'localhost,10.0.0.5'`
and `max_connections = 300`; in `pg_hba.conf`, one line per server:

```text
host  aggregate  aggregate  10.0.0.10/32  scram-sha-256
host  aggregate  aggregate  10.0.0.11/32  scram-sha-256
```

```sql
CREATE ROLE aggregate LOGIN PASSWORD 'a-long-random-password';
CREATE DATABASE aggregate OWNER aggregate;
```

**Firewall:**

```bash
sudo ufw default deny incoming
sudo ufw allow OpenSSH
for address in 10.0.0.10 10.0.0.11 10.0.0.12 10.0.0.21; do
  sudo ufw allow from "$address" to any port 3306 proto tcp   # 5432 for PostgreSQL
done
sudo ufw enable
```

**Connections.** PHP-FPM opens one connection per busy worker, so the
servers' `pm.max_children` added up, plus queue consumers and the scheduled
task, must stay under `max_connections`. PostgreSQL keeps connections open:
put PgBouncer in transaction mode in front of it for more than a few servers.
`app:server:role` shows how many connections are open and warns above 70%.

The database URL is
`mysql://aggregate:PASSWORD@10.0.0.5:3306/aggregate?serverVersion=10.11.6-MariaDB`
or `postgresql://aggregate:PASSWORD@10.0.0.5:5432/aggregate?serverVersion=16`,
with the password URL-encoded. Do not add `charset=utf8mb4` to a MariaDB or
MySQL URL: one of Aggregate's migrations then fails with "Illegal mix of
collations".

## 2. The administration server

1. Install Aggregate as for one server ([release ZIP](../DEPLOYMENT.md#release-zip-on-a-web-host)
   or [native](../DEPLOYMENT.md#native-deployment)), with `admin.example.com`
   and, on the setup page, the shared database.
2. Create the cluster's shared key, and make this the administration server.
   Put the settings in a file only you can read, so the key never appears on a
   command line:

   ```bash
   php bin/console app:cluster:key
   php bin/console app:cluster:key --show          # the key, for the files below
   install -m 600 /dev/null join.json && nano join.json
   ```

   ```json
   {"role": "administration", "name": "admin", "cluster_key": "THE-KEY", "queue": "sync"}
   ```

   ```bash
   php bin/console app:cluster:join --stdin < join.json && shred -u join.json
   sudo systemctl reload php8.3-fpm
   ```

3. Tell it where websites send events, and which servers may pull its
   configuration (each by the address it connects from):

   ```bash
   php bin/console app:collection-address https://collect.example.com
   php bin/console app:cluster:server add collect-1 10.0.0.11 --role=collection
   php bin/console app:cluster:server add collect-2 10.0.0.12 --role=collection
   php bin/console app:cluster:server add worker-1 10.0.0.21 --role=worker    # doctrine:// only
   php bin/console messenger:setup-transports                                 # doctrine:// only
   ```

4. Run the [scheduled task](SCHEDULED-TASKS.md) every five minutes, here only,
   and `app:cluster:sync` every minute (`docs/systemd/aggregate-cluster-sync.*`;
   change `User`, `Group`, `WorkingDirectory` and the PHP path):

   ```bash
   sudo cp docs/systemd/aggregate-cluster-sync.service docs/systemd/aggregate-cluster-sync.timer /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now aggregate-cluster-sync.timer
   ```

5. Firewall: 80 and 443 from anywhere, SSH.

## 3. Each collection server

1. Unpack the same release into `/var/www/aggregate`, owned by the web server's
   user. Do not open the setup page: the join below configures the server.
2. Make `admin.example.com` resolve to the administration server's private
   address, so pulls arrive from this server's private address:

   ```bash
   echo '10.0.0.10 admin.example.com' | sudo tee -a /etc/hosts
   ```

3. Join, as the web server's user, with a settings file as above:

   ```json
   {
     "role": "collection",
     "name": "collect-1",
     "administration_url": "https://admin.example.com",
     "cluster_key": "THE-KEY",
     "database_url": "mysql://aggregate:PASSWORD@10.0.0.5:3306/aggregate?serverVersion=10.11.6-MariaDB",
     "trusted_proxies": "10.0.0.2",
     "app_secret": "THE-ADMINISTRATION-SERVER'S-APP_SECRET",
     "queue": "sync"
   }
   ```

   ```bash
   sudo -u www-data php bin/console app:cluster:join --stdin < join.json && shred -u join.json
   sudo -u www-data php bin/console app:cluster:sync      # the first pull
   sudo -u www-data php bin/console app:server:role       # every check should pass
   ```

   `app_secret` must be the administration server's `APP_SECRET` (from its
   `.env.local`): organization-traffic codes made on one collection server are
   checked on whichever the balancer picks next. `trusted_proxies` is the
   balancer's private address, so rate limits and geography see the visitor's
   address. The join removes the cache built for the previous role.

4. Serve the collection host name on the private address only, with the
   single-server site ([`docs/nginx/aggregate-analytics.conf`](nginx/aggregate-analytics.conf))
   changed to `listen 10.0.0.11:80;` and `server_name collect.example.com;`.
   TLS ends at the balancer. Reload Nginx and PHP-FPM.
5. Install the `aggregate-cluster-sync` timer as on the administration server.
   No scheduled task runs here.
6. Firewall: port 80 from the balancer only, so its rate limit cannot be
   skipped:

   ```bash
   sudo ufw default deny incoming
   sudo ufw allow OpenSSH
   sudo ufw allow from 10.0.0.2 to any port 80 proto tcp
   sudo ufw enable
   ```

## 4. Each worker server (`doctrine://` only)

1. Unpack the release, add the `/etc/hosts` line, and join as above with
   `"role": "worker"`, its own name, and `"queue": "doctrine"`. A worker needs
   no `trusted_proxies` and no web server.
2. Run the consumers with [`docs/systemd/aggregate-worker.service`](systemd/aggregate-worker.service)
   ([worker setup](../DEPLOYMENT.md#worker-process-setup)), and the
   `aggregate-cluster-sync` timer. A worker whose configuration is out of date
   stops its consumers until it catches up.
3. Firewall: no inbound port but SSH.

## 5. The balancer

1. Point `collect.example.com` at the balancer's public address.
2. On the administration server, print its configuration
   ([the balancer](CONFIGURATION.md#the-balancer) says what it does):

   ```bash
   php bin/console app:server:balancer-config --nginx > aggregate-balancer.conf
   php bin/console app:server:balancer-config --caddy > Caddyfile
   ```

3. **Nginx:** install Nginx, get a certificate with the default site still in
   place (`sudo certbot certonly --webroot -w /var/www/html -d collect.example.com`),
   then install the file:

   ```bash
   sudo cp aggregate-balancer.conf /etc/nginx/sites-available/
   sudo ln -s /etc/nginx/sites-available/aggregate-balancer.conf /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   ```

   **Caddy:** copy the Caddyfile to `/etc/caddy/Caddyfile` and
   `sudo systemctl reload caddy`; Caddy gets the certificate itself. Stock
   Caddy cannot limit per address; see [the balancer](CONFIGURATION.md#the-balancer).
4. Firewall: 80 and 443 from anywhere, SSH.

Print and install the configuration again whenever you add or remove a
collection server.

## Check it

- `php bin/console app:server:role` on each server exits with 0.
- `php bin/console app:cluster:server` on the administration server lists
  every server with the newest configuration; so does the **Servers** panel on
  its Updates page.
- `curl -s https://collect.example.com/api/health` answers `{"status":"healthy",...` through the balancer.
- Copy the tracking code again (the collection address changed) and watch
  events arrive.

## Update

The administration server first, then each other server with `--files-only`,
collection servers one at a time: see [update several servers](UPDATES.md#update-several-servers).

## Troubleshooting

- **A pull answers 404.** The administration server could not match the
  request: check `CLUSTER_KEY`, the server's name and address in
  `app:cluster:server`, that the pull comes from that address (the
  `/etc/hosts` line), and both clocks (a request more than a minute old is
  refused).
- **A collection server answers 503** on `/api/receive` and `/api/health`: it
  has not confirmed its configuration for `cluster_config_max_age_minutes`.
  Run `app:cluster:sync` there and read what it says; the administration
  server alerts about it too ([alerts](ALERTS.md)).
- **`app:server:role` says the cache was built for another role.** Remove it
  (`rm -rf var/cache/prod`) and reload PHP-FPM. `cache:clear` run right after
  the change can keep the old build.
- **"Too many connections" or 500s at the balancer.** The connection budget
  above is exceeded: lower `pm.max_children`, raise `max_connections`, or use
  PgBouncer.
