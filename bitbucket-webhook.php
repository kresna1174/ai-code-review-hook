<?php
require __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Handle Bitbucket webhook
$payload = json_decode(file_get_contents('php://input'), true);

if (isset($payload['pullrequest'])) {
    $prId = $payload['pullrequest']['id'];
    $repoSlug = $payload['repository']['name'];
    $workspace = $payload['repository']['workspace']['slug'];

    // Proses AI Review
    reviewWithAI($workspace, $repoSlug, $prId);
}

function reviewWithAI($workspace, $repoSlug, $prId) {
    $bitbucketToken = $_ENV['BITBUCKET_TOKEN'];
    $openaiApiKey = $_ENV['OPENAI_API_KEY'];

    // Step 1: Ambil diff dari Bitbucket API
    $client = new Client();
    $diffUrl = "https://api.bitbucket.org/2.0/repositories/{$workspace}/{$repoSlug}/pullrequests/{$prId}/diff";
    $response = $client->get($diffUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $bitbucketToken,
            'Accept' => 'text/plain',
        ]
    ]);
    $diffContent = $response->getBody()->getContents();

    // Step 2: Kirim ke OpenAI untuk review
    $prompt = "Berikan review kode berikut dalam bahasa Indonesia, fokus pada bug, security, dan best practices:\n\n{$diffContent}";
    $aiResponse = $client->post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $openaiApiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
        ]
    ]);
    $reviewComment = json_decode($aiResponse->getBody(), true)['choices'][0]['message']['content'];

    // Step 3: Post komentar ke Bitbucket
    $commentUrl = "https://api.bitbucket.org/2.0/repositories/{$workspace}/{$repoSlug}/pullrequests/{$prId}/comments";
    $client->post($commentUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $bitbucketToken,
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'content' => [
                'raw' => "[AI Code Review]\n" . $reviewComment
            ]
        ]
    ]);
}