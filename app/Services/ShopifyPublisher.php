<?php

namespace App\Services;

use App\Models\ShopifyStore;

/**
 * Makes a product visible on the store's website.
 *
 * A product created through the API belongs to NO sales channel, so even when it is set to
 * Active it does not show on the Online Store (and is missing from the website's collections
 * and "latest" lists). It has to be published to the "Online Store" channel, which needs the
 * read_publications + write_publications permissions.
 */
class ShopifyPublisher
{
    private ?string $publicationId = null;
    private ?string $error = null;
    private bool $looked = false;

    public function __construct(private ShopifyStore $store) {}

    /** Why publishing is not possible (null when it is). */
    public function problem(): ?string
    {
        $this->lookup();
        return $this->error;
    }

    private function lookup(): void
    {
        if ($this->looked) return;
        $this->looked = true;
        try {
            $data = $this->store->graphql('{ publications(first: 50) { nodes { id name } } }');
            foreach ($data['publications']['nodes'] ?? [] as $p) {
                if (strcasecmp(trim($p['name'] ?? ''), 'Online Store') === 0) { $this->publicationId = $p['id']; break; }
            }
            if (!$this->publicationId) $this->error = 'The store has no "Online Store" sales channel.';
        } catch (\Throwable $e) {
            $denied = stripos($e->getMessage(), 'access denied') !== false || stripos($e->getMessage(), 'scope') !== false;
            $this->error = 'Could not read the sales channels' . ($denied ? '' : ' (' . $e->getMessage() . ')')
                . ' — add the read_publications and write_publications permissions to the Shopify app, release the version, '
                . 'then click Re-authorize on the Shopify Stores page.';
        }
    }

    /** Publish one product (gid or numeric id) to the Online Store. Returns an error text, or null when done. */
    public function publish(string $productId): ?string
    {
        $this->lookup();
        if ($this->error) return $this->error;

        $gid = str_starts_with($productId, 'gid://') ? $productId : 'gid://shopify/Product/' . $productId;
        try {
            $data = $this->store->graphql(<<<'GQL'
                mutation Publish($id: ID!, $input: [PublicationInput!]!) {
                  publishablePublish(id: $id, input: $input) { userErrors { field message } }
                }
                GQL, ['id' => $gid, 'input' => [['publicationId' => $this->publicationId]]]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'access denied') !== false || stripos($e->getMessage(), 'scope') !== false) {
                $this->error = 'The Shopify app is missing the write_publications permission — add it, release the version, then Re-authorize.';
                return $this->error;
            }
            return $e->getMessage();
        }

        $errs = $data['publishablePublish']['userErrors'] ?? [];
        return $errs ? collect($errs)->pluck('message')->filter()->implode('; ') : null;
    }
}
