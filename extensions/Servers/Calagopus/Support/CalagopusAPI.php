<?php

namespace Paymenter\Extensions\Servers\Calagopus\Support;

use Illuminate\Support\Facades\Http;

class CalagopusAPI
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Make a GET request to the Calagopus API.
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('get', $endpoint, $query);
    }

    /**
     * Make a POST request to the Calagopus API.
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('post', $endpoint, $data);
    }

    /**
     * Make a PATCH request to the Calagopus API.
     */
    public function patch(string $endpoint, array $data = []): array
    {
        return $this->request('patch', $endpoint, $data);
    }

    /**
     * Make a PUT request to the Calagopus API.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('put', $endpoint, $data);
    }

    /**
     * Make a DELETE request to the Calagopus API.
     */
    public function delete(string $endpoint, array $data = []): array
    {
        return $this->request('delete', $endpoint, $data);
    }

    /**
     * @throws CalagopusAPIException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->$method($this->baseUrl . $endpoint, $data);

        if (!$response->successful()) {
            $body = $response->json();
            $errors = $body['errors'] ?? ['Unknown API error'];

            throw new CalagopusAPIException(
                $response->status(),
                'Calagopus API Error (HTTP ' . $response->status() . '): ' . implode(', ', $errors),
            );
        }

        return $response->json() ?? [];
    }

    /** Users */

    /**
     * Find a user by their external ID (billing system user ID).
     */
    public function getUserByExternalId(string $externalId): ?array
    {
        try {
            $response = $this->get('/api/admin/users/external/' . urlencode($externalId));
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return $response['user'] ?? null;
    }

    /**
     * Create a new panel user.
     */
    public function createUser(array $data): array
    {
        return $this->post('/api/admin/users', $data)['user'];
    }

    /**
     * Search users by email or username.
     */
    public function searchUsers(string $search): array
    {
        $response = $this->get('/api/admin/users', [
            'page' => 1,
            'per_page' => 10,
            'search' => $search,
        ]);

        return $response['users']['data'] ?? [];
    }

    /**
     * Update a user by UUID.
     */
    public function updateUser(string $userUuid, array $data): array
    {
        return $this->patch('/api/admin/users/' . $userUuid, $data);
    }

    /**
     * Find or create a user, keyed by external ID.
     *
     * Handles the case where a user with the same email/username already exists on the
     * panel (409 conflict) by searching for the existing user and linking them via external_id.
     */
    public function findOrCreateUser(string $externalId, string $email, string $firstName, string $lastName, string $username, string $language = 'en'): array
    {
        // 1. Try lookup by external ID first
        $existing = $this->getUserByExternalId($externalId);
        if ($existing) {
            return $existing;
        }

        // 2. Try creating the user
        try {
            return $this->createUser([
                'external_id' => $externalId,
                'username' => $username,
                'email' => $email,
                'name_first' => $firstName,
                'name_last' => $lastName,
                'admin' => false,
                'send_email' => true,
                'language' => $language,
            ]);
        } catch (CalagopusAPIException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
        }

        // 3. User already exists on the panel — find them by email
        $matched = null;
        foreach ($this->searchUsers($email) as $user) {
            if (strcasecmp($user['email'] ?? '', $email) === 0) {
                $matched = $user;
                break;
            }
        }

        // 4. If no email match, try by username
        if (!$matched) {
            foreach ($this->searchUsers($username) as $user) {
                if (strcasecmp($user['username'] ?? '', $username) === 0) {
                    $matched = $user;
                    break;
                }
            }
        }

        if (!$matched) {
            throw new CalagopusAPIException(409, 'User with this email/username already exists on the panel but could not be found via search.');
        }

        // 5. Link the existing panel user to this billing account by setting external_id
        $this->updateUser($matched['uuid'], ['external_id' => $externalId]);

        return $matched;
    }

    /** OAuth links */

    /**
     * Find the panel user linked to an OAuth provider by the provider-side identifier.
     */
    public function getOAuthLinkedUser(string $providerUuid, string $identifier): ?array
    {
        try {
            $response = $this->get('/api/admin/oauth-providers/' . $providerUuid . '/users/identifier/' . urlencode($identifier));
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return $response['user_oauth_link']['user'] ?? null;
    }

    /**
     * Link a panel user to an OAuth provider.
     */
    public function createOAuthLink(string $userUuid, string $providerUuid, string $identifier): array
    {
        return $this->post('/api/admin/users/' . $userUuid . '/oauth-links', [
            'oauth_provider_uuid' => $providerUuid,
            'identifier' => $identifier,
        ]);
    }

    /** Servers */

    /**
     * Create a server with an explicit node and allocation.
     */
    public function createServer(array $data): array
    {
        return $this->post('/api/admin/servers', $data)['server'];
    }

    /**
     * Deploy a server with automatic node/allocation selection.
     */
    public function deployServer(array $data): array
    {
        return $this->post('/api/admin/servers/deploy', $data)['server'];
    }

    /**
     * Get server details by external ID.
     */
    public function getServerByExternalId(string $externalId): ?array
    {
        try {
            $response = $this->get('/api/admin/servers/external/' . urlencode($externalId));
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return $response['server'] ?? null;
    }

    /**
     * Update a server (name, limits, suspend, etc.).
     */
    public function updateServer(string $serverUuid, array $data): array
    {
        return $this->patch('/api/admin/servers/' . $serverUuid, $data);
    }

    /**
     * Suspend a server.
     */
    public function suspendServer(string $serverUuid): array
    {
        return $this->updateServer($serverUuid, ['suspended' => true]);
    }

    /**
     * Unsuspend a server.
     */
    public function unsuspendServer(string $serverUuid): array
    {
        return $this->updateServer($serverUuid, ['suspended' => false]);
    }

    /**
     * Delete a server.
     */
    public function deleteServer(string $serverUuid, bool $force = false, bool $deleteBackups = true): array
    {
        return $this->delete('/api/admin/servers/' . $serverUuid, [
            'force' => $force,
            'delete_backups' => $deleteBackups,
        ]);
    }

    /** Nests, eggs, nodes and locations */

    /**
     * List all nests.
     */
    public function getNests(): array
    {
        $response = $this->get('/api/admin/nests', ['page' => 1, 'per_page' => 100]);

        return $response['nests']['data'] ?? [];
    }

    /**
     * List eggs for a specific nest.
     */
    public function getEggs(string $nestUuid): array
    {
        $response = $this->get('/api/admin/nests/' . $nestUuid . '/eggs', ['page' => 1, 'per_page' => 100]);

        return $response['eggs']['data'] ?? [];
    }

    /**
     * Get a single egg's details.
     */
    public function getEgg(string $nestUuid, string $eggUuid): array
    {
        return $this->get('/api/admin/nests/' . $nestUuid . '/eggs/' . $eggUuid)['egg'];
    }

    /**
     * List all locations.
     */
    public function getLocations(int $perPage = 100): array
    {
        $response = $this->get('/api/admin/locations', ['page' => 1, 'per_page' => $perPage]);

        return $response['locations']['data'] ?? [];
    }

    /**
     * List all nodes.
     */
    public function getNodes(): array
    {
        $response = $this->get('/api/admin/nodes', ['page' => 1, 'per_page' => 100]);

        return $response['nodes']['data'] ?? [];
    }

    /**
     * List available allocations on a node.
     */
    public function getAvailableAllocations(string $nodeUuid, int $perPage = 10): array
    {
        $response = $this->get('/api/admin/nodes/' . $nodeUuid . '/allocations/available', [
            'page' => 1,
            'per_page' => $perPage,
        ]);

        return $response['allocations']['data'] ?? [];
    }

    /**
     * Get the panel URL for a server (for "Go to Server" buttons).
     */
    public function getServerUrl(string $serverUuid): string
    {
        return $this->baseUrl . '/server/' . $serverUuid;
    }
}
