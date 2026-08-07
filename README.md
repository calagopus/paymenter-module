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
| Failed | The panel rejected the request, the reason is shown on the page |
