<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Symfony\Component\DomCrawler\Crawler;

class StickeeScraperController extends Controller
{

    public function scrape(Request $request)
    {
        $baseUrl = 'https://www.magpiehq.com/developer-challenge/smartphones/';
        $client = new Client();
        $allProducts = [];
        $page = 1;
        $totalPages = 1;

        do {
            $url = $baseUrl . '?page=' . $page;
            // Fetch the webpage content
            $response = $client->get($url);
            if ($response->getStatusCode() !== 200) {
                return response()->json(['error' => 'Failed to fetch the webpage'], 500);
            }

            $htmlContent = $response->getBody()->getContents();
            $crawler = new Crawler($htmlContent);

            // Parse the product items on the current page
            $products = $crawler->filter('.product')->each(function (Crawler $node) {
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

                $productsByColor = [];
                foreach ($colors as $color) {
                    $productsByColor[] = [
                        'title' => $title . ' ' . $capacityText, // write name as on product card.
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

                return $productsByColor;
            });

            // Flatten the products array for the current page
            $flattenedProducts = !empty($products) ? array_merge(...$products) : [];

            // Filter out duplicate products based on title and colour
            foreach ($flattenedProducts as $product) {
                $isDuplicate = array_filter($allProducts, function ($existingProduct) use ($product) {
                    return $existingProduct['title'] === $product['title'] &&
                        $existingProduct['colour'] === $product['colour'];
                });

                if (empty($isDuplicate)) {
                    $allProducts[] = $product;
                }
            }

            // Determine the total number of pages by extracting from pagination
            if ($page === 1) {
                $totalPages = $crawler->filter('#pages a')->each(function (Crawler $pageNode) {
                    return intval(trim($pageNode->text('')));
                });

                $totalPages = max($totalPages); // Get the maximum page number
            }

            // Increment the page counter
            $page++;

        } while ($page <= $totalPages);
        // Simple return on browser
        // return response()->json($allProducts, 200, [], JSON_PRETTY_PRINT);

        // Convert the products array to JSON with pretty print
        $jsonData = json_encode($allProducts, JSON_PRETTY_PRINT);

        // Save the JSON data to a file
        $filePath = public_path('output.json');
        file_put_contents($filePath, $jsonData);
        // Return a success response
        // return response()->json(['message' => 'Data exported successfully!', 'file' => $filePath], 200);
        return response()->json(['message' => 'Data exported successfully!', 'file' => $filePath, 'data' => $allProducts], 200);

    }

    private function makeAbsoluteUrl($baseUrl, $relativeUrl)
    {
        $normalizedRelativeUrl = ltrim($relativeUrl, './');
        return rtrim($baseUrl, '/') . '/' . $normalizedRelativeUrl;
    }

}
