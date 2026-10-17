![Calagopus Logo](https://calagopus.com/fulllogo.svg)

# Calagopus Paymenter Module

<https://calagopus.com>

## Installation

1. Upload the `extensions/Servers/Calagopus/` directory to your Paymenter installation at:
   ```sh
   /path/to/paymenter/extensions/Servers/Calagopus/
   ```

2. In Paymenter Admin, go to **Servers → New server** and create one using "Calagopus".

3. Configure the extension with your panel URL and API key.

4. Create a product and select "Calagopus" as the server extension, then configure the product settings.

## Setting up OAuth Linking (Optional)

OAuth linking allows your users to log into the Calagopus panel using their Paymenter account, providing a seamless experience. To set this up:

1. Download the Paymenter OAuth Provider template from this repository.

2. In your Calagopus panel, go to **Admin → OAuth Providers → Import** to import the template.

3. After importing, click the OAuth Provider and edit all the URLs to point to your Paymenter installation.

4. Now, save the provider and copy the Redirect URL.

5. In Paymenter Admin, go to **Oauth Clients → New oauth client** and paste the Redirect URL into the "Redirect" field. Fill out the name and save the client.

6. Copy the Client ID and Secret from the OAuth client you just created in Paymenter.

7. In Calagopus, edit the OAuth Provider you imported and paste the Client ID and Secret into the corresponding fields. Save the provider.

8. Copy the UUID of the OAuth Provider in Calagopus and paste it into the "OAuth Provider UUID" field in your Paymenter Calagopus server configuration.

9. Save the server configuration in Paymenter.

10. Profit, now when a new server is created for a user, Paymenter will automatically link their account to Calagopus using the OAuth provider, allowing them to log in to the panel with their Paymenter credentials.

### Linking existing customers

Linking happens automatically at server creation, so customers who already had a server before you set up OAuth will not have a link yet. To backfill them:

- **All at once** - on the Calagopus server configuration, click **Sync All Users** next to the "OAuth Provider UUID" field. This queues a background sweep over every customer who owns a Calagopus service, so it needs a running queue worker.

- **One customer at a time** - go to **Extensions → Calagopus OAuth Links**, find the customer and click **Sync** on their row.

Both routes only ever add a missing link; they never create panel accounts. The **Calagopus OAuth Links** page also shows sweep progress and the last result for each customer:

| Result | Meaning |
| --- | --- |
| Linked | An OAuth link was created |
| Already linked | The customer was already linked, nothing to do |
| No panel account | No Calagopus user matches this customer, so there was nothing to link |
| Email mismatch | The matching Calagopus user has a different email than the customer, so it was not linked automatically |
| Email unverified | The customer has not verified their Paymenter email, so it was not linked automatically. Email verification is off on a default Paymenter install, so most customers will land here until they verify or you link them by hand on the panel |
| Failed | The panel rejected the request, the reason is shown on the page |

## Migrating from the Pterodactyl Extension

If you sold servers through Paymenter's built-in **Pterodactyl** extension and have migrated your panel to Calagopus, this extension can move your existing products and services over.

Prerequisites:

1. Your panel has been migrated with the Calagopus Pterodactyl importer. It keeps server, user, node, nest and egg UUIDs and server external IDs, so services keep working without changes. Eggs and nodes are matched by UUID (then by name), locations by name (short name first).
2. The Pterodactyl server in Paymenter still has its URL and application API key, and that panel is still reachable. It is used to translate the stored Pterodactyl IDs.
3. A Calagopus server has been added in Paymenter.

Go to **Extensions → Calagopus Pterodactyl Import**, pick the Pterodactyl and Calagopus servers and click **Preview** to see what would happen without changing anything. Click **Import** to apply it.

The import:

- Moves each product to the Calagopus server and converts its settings (`io` → IO Weight, databases/backups limits, additional allocations → total allocations, CPU pinning ranges expanded, skip scripts → skip installer). Schedules get a limit of 10, like servers migrated by the panel importer.
- Checks each service's server on the panel. Servers that lost their external ID are found through Pterodactyl and given it back.
- Links each server owner to their Paymenter account (sets the panel user's external ID), but only when the owner isn't linked to anything yet, isn't an admin, has the same email, and the customer's email is verified. Run **Sync All Users** afterwards to also create OAuth links.

Anything that cannot be matched is skipped and listed in the results. Fix it on the panel and run the import again; tick **Also re-check services** to retry the services of products that were already moved.

Notes:

- Config options named after Pterodactyl settings (`io`, `databases`, `backups`, `additional_allocations`, `cpu_pinning`, `skip_scripts`, `servername`, `node`, `location`, `egg_id`, `nest_id`) are listed as warnings and must be renamed to the Calagopus names by hand (`io_weight`, `database_limit`, `backup_limit`, `allocations_limit`, ...). Otherwise customers who paid for extras lose them on their next upgrade. `allocations_limit` is the total, so `additional_allocations` values need one added.
- Products linked by upgrades should be migrated together; the results warn about upgrade paths that still point at Pterodactyl.
- `port_array`, `port_range`, `dedicated_ip` and `oom_killer` have no Calagopus equivalent and are dropped. Use egg configurations to assign ports.
