<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Symfony\Component\DomCrawler\Crawler;

class StickeeScraperV2Controller extends Controller
{
    public function scrape(Request $request)
    {
        $baseUrl = 'https://www.magpiehq.com/developer-challenge/smartphones/';
        $client = new Client();
        try {

            $allProducts = $this->fetchAllProducts($client, $baseUrl);
            $filePath = $this->exportToJson($allProducts, 'outputV2.json');

            return response()->json(['message' => 'Data exported successfully!', 'file' => $filePath, 'data' => $allProducts], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function fetchAllProducts($client, $baseUrl)
    {
        $allProducts = [];
        $page = 1;

        do {
            $url = $baseUrl . '?page=' . $page;
            $response = $client->get($url);
            $htmlContent = $response->getBody()->getContents();
            $crawler = new Crawler($htmlContent);
            $products = $this->parseProducts($crawler);

            // Avoid adding duplicate products
            foreach ($products as $product) {
                // Check if the product with the same title and colour already exists in $allProducts
                $isDuplicate = array_filter($allProducts, function ($existingProduct) use ($product) {
                    return $existingProduct['title'] === $product['title'] &&
                        $existingProduct['colour'] === $product['colour'];
                });

                if (empty($isDuplicate)) {
                    // Add product only if it's not a duplicate
                    $allProducts[] = $product;
                }
            }

            $totalPages = $this->getTotalPages($crawler);
            $page++;
        } while ($page <= $totalPages);

        return $allProducts;
    }

    private function parseProducts(Crawler $crawler)
    {
        $productsByColor = [];
        $crawler->filter('.product')->each(function (Crawler $node) use (&$productsByColor) {
            $title = $node->filter('.product-name')->text('');
            $capacityText = $node->filter('.product-capacity')->text('');
            $capacityMB = intval(preg_replace('/[^0-9]/', '', $capacityText)) * 1024;

            $relativeImageUrl = $node->filter('img')->attr('src');
            $imageUrl = $this->makeAbsoluteUrl('https://www.magpiehq.com/developer-challenge/', $relativeImageUrl);

            $priceText = $node->filter('div.my-8.block.text-center.text-lg')->text('');
            $price = floatval(preg_replace('/[^0-9.]/', '', $priceText));

            $availabilityText = $node->filter('div.my-4.text-sm.block.text-center')->first()->text('');
            // Removing the Availability:
            $cleanedAvailabilityText = trim(str_replace('Availability:', '', $availabilityText));
            // checking if in stock or not
            $isAvailable = stripos($availabilityText, 'in stock') !== false;
            $shippingText = $node->filter('div.my-4.text-sm.block.text-center')->last()->text('');
            // Getting the date if any
            $shippingDate = null;
            // Try to extract the date (including formats like 2024-12-10 and human-readable dates)
            preg_match('/(\d{1,2}\w{0,2} \w+ \d{4}|\d{4}-\d{2}-\d{2})/', $shippingText, $matches);
            if (!empty($matches[1])) {
                // Try parsing the human-readable date (e.g., "2nd Jan 2025") or the ISO format (YYYY-MM-DD)
                $shippingDate = date('Y-m-d', strtotime($matches[1]));
            } else {
                // If no date found, set shippingDate to null
                $shippingDate = null;
            }
            // Getting all the color options available for creating seperate products
            $colors = $node->filter('.my-4 .px-2 span')->each(function (Crawler $colorNode) {
                return $colorNode->attr('data-colour');
            });

            foreach ($colors as $color) {
                $productsByColor[] = [
                    'title' => $title . ' ' . $capacityText,
                    'price' => $price,
                    'imageUrl' => $imageUrl,
                    'capacityMB' => $capacityMB,
                    'colour' => $color,
                    'availabilityText' => $cleanedAvailabilityText,
                    'isAvailable' => $isAvailable,
                    'shippingText' => $shippingText,
                    'shippingDate' => $shippingDate,
                ];
            }
        });

        return $productsByColor;
    }

    private function getTotalPages(Crawler $crawler)
    {
        $pages = $crawler->filter('#pages a')->each(function (Crawler $pageNode) {
            return intval(trim($pageNode->text('')));
        });
        return $pages ? max($pages) : 1;
    }

    private function exportToJson(array $data, $filename)
    {
        $filePath = public_path($filename);

        if (file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT)) === false) {
            throw new \Exception('Failed to write data to file: ' . $filePath);
        }

        return $filePath;
    }

    private function makeAbsoluteUrl($baseUrl, $relativeUrl)
    {
        $normalizedRelativeUrl = ltrim($relativeUrl, './');
        return rtrim($baseUrl, '/') . '/' . $normalizedRelativeUrl;
    }
}
