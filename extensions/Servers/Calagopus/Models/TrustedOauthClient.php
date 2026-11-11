<?php

namespace Paymenter\Extensions\Servers\Calagopus\Models;

use App\Helpers\ExtensionHelper;
use App\Models\OauthClient;
use App\Models\Server as ServerModel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Skips the "authorize this application" screen when a Calagopus panel configured in
 * Paymenter logs users in, so they go straight back to the panel after signing in.
 * A client counts as Calagopus when one of its redirect URIs points to a configured panel host.
 */
class TrustedOauthClient extends OauthClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        if ($this->revoked) {
            return false;
        }

        $redirectHosts = array_filter(array_map(
            fn ($uri) => strtolower((string) parse_url((string) $uri, PHP_URL_HOST)),
            (array) ($this->redirect_uris ?? []),
        ));

        if (empty($redirectHosts)) {
            return false;
        }

        foreach (ServerModel::where('extension', 'Calagopus')->get() as $server) {
            $settings = ExtensionHelper::settingsToArray($server->settings);
            $panelHost = strtolower((string) parse_url((string) ($settings['host'] ?? ''), PHP_URL_HOST));

            if ($panelHost !== '' && in_array($panelHost, $redirectHosts, true)) {
                return true;
            }
        }

        return false;
    }
}
