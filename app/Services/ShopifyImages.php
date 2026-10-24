<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ShopifyStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product pictures → Shopify.
 *
 * The picture FILE is uploaded to Shopify (staged upload), so it works even when
 * Shopify cannot open the software's own address (APP_URL = localhost, private
 * server, login wall …). Pictures already on a Shopify product are never doubled.
 */
class ShopifyImages
{
    public function __construct(private ShopifyStore $store) {}

    /**
     * Upload a product's pictures; returns ['files' => FileSetInput[], 'errors' => string[]].
     * Pictures that are full web links (https://…) are passed to Shopify as they are.
     */
    public function stageAll(Product $product): array
    {
        $files = []; $errors = [];
        foreach ($product->images as $img) {
            try {
                $files[] = ['originalSource' => $this->stage($img), 'contentType' => 'IMAGE', 'alt' => $product->name];
            } catch (\Throwable $e) {
                $errors[] = 'picture ' . basename((string) $img->image_path) . ': ' . $e->getMessage();
            }
        }
        return ['files' => $files, 'errors' => $errors];
    }

    /** One picture → a Shopify-hosted URL to use as originalSource. */
    public function stage(ProductImage $img): string
    {
        $raw = (string) $img->image_path;
        if (preg_match('#^https?://#i', $raw)) return $raw;

        $path = preg_replace('#^(public/|storage/)#', '', ltrim(str_replace('\\', '/', $raw), '/'));
        $disk = Storage::disk('public');
        if ($path === '' || !$disk->exists($path)) {
            throw new \RuntimeException('file not found on the server (storage/app/public/' . $path . ')');
        }

        $bytes    = $disk->get($path);
        $filename = basename($path);
        $mime     = $disk->mimeType($path) ?: 'image/jpeg';

        $data = $this->store->graphql(<<<'GQL'
            mutation Stage($input: [StagedUploadInput!]!) {
              stagedUploadsCreate(input: $input) {
                stagedTargets { url resourceUrl parameters { name value } }
                userErrors { field message }
              }
            }
            GQL, ['input' => [[
                'filename'   => $filename,
                'mimeType'   => $mime,
                'httpMethod' => 'POST',
                'resource'   => 'IMAGE',
                'fileSize'   => (string) strlen($bytes),
            ]]]);

        $errs = $data['stagedUploadsCreate']['userErrors'] ?? [];
        if ($errs) throw new \RuntimeException(collect($errs)->pluck('message')->implode('; '));
        $target = $data['stagedUploadsCreate']['stagedTargets'][0] ?? null;
        if (!$target || empty($target['url']) || empty($target['resourceUrl'])) {
            throw new \RuntimeException('Shopify gave no upload address');
        }

        // form fields first, the file last (required by the storage service)
        $req = Http::timeout(120)->asMultipart();
        $fields = [];
        foreach ($target['parameters'] ?? [] as $p) $fields[] = ['name' => $p['name'], 'contents' => (string) $p['value']];
        $fields[] = ['name' => 'file', 'contents' => $bytes, 'filename' => $filename, 'headers' => ['Content-Type' => $mime]];
        $res = $req->send('POST', $target['url'], ['multipart' => $fields]);
        if (!$res->successful()) {
            throw new \RuntimeException('upload refused (HTTP ' . $res->status() . ') ' . Str::limit(strip_tags($res->body()), 120));
        }

        return $target['resourceUrl'];
    }

    /** Number of pictures/media already on a Shopify product. */
    public function mediaCount(string $shopifyProductId): int
    {
        $data = $this->store->graphql(<<<'GQL'
            query Media($id: ID!) { product(id: $id) { id media(first: 50) { nodes { id } } } }
            GQL, ['id' => 'gid://shopify/Product/' . $shopifyProductId]);
        if (empty($data['product'])) throw new \RuntimeException('product not found on Shopify');
        return count($data['product']['media']['nodes'] ?? []);
    }

    /**
     * Add the software's pictures to a product that is already on Shopify, but only when
     * that Shopify product has no pictures yet. Returns 'added' | 'has-pictures' | 'no-pictures'.
     */
    public function addToExisting(Product $product): array
    {
        if ($product->images->isEmpty()) return ['result' => 'no-pictures', 'errors' => []];
        if ($this->mediaCount((string) $product->shopify_product_id) > 0) return ['result' => 'has-pictures', 'errors' => []];

        ['files' => $files, 'errors' => $errors] = $this->stageAll($product);
        if (!$files) return ['result' => 'failed', 'errors' => $errors ?: ['no picture could be uploaded']];

        $data = $this->store->graphql(<<<'GQL'
            mutation AddMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
              productUpdate(product: $product, media: $media) {
                product { id }
                userErrors { field message }
              }
            }
            GQL, [
                'product' => ['id' => 'gid://shopify/Product/' . $product->shopify_product_id],
                'media'   => array_map(fn ($f) => ['originalSource' => $f['originalSource'], 'mediaContentType' => 'IMAGE', 'alt' => $f['alt']], $files),
            ]);

        $errs = $data['productUpdate']['userErrors'] ?? [];
        if ($errs) return ['result' => 'failed', 'errors' => array_merge($errors, [collect($errs)->pluck('message')->implode('; ')])];

        return ['result' => 'added', 'errors' => $errors, 'count' => count($files)];
    }
}
