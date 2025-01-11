<?php

$config = getContents(__DIR__ . '/config.json');

try {
    $dateToday = (new DateTime())->setTimezone(new DateTimeZone($config['timezone']))->format('Y-m-d');

} catch (DateInvalidTimeZoneException $dateInvalidTimeZoneException) {
    echo sprintf('Invalid timezone! Details: %s', $dateInvalidTimeZoneException->getMessage()) . PHP_EOL;
    exit();
}

$numOfDays = 2; //Number of days to search

$discordWebhook = $config['discord']['webhook'];

$tmdbBaseUrl       = $config['tmdb']['base_url'];
$tmdbPosterBaseUrl = $config['tmdb']['poster_base_url'];
$tmdbApiKey        = $config['tmdb']['api_key'];

$traktBaseUrl  = $config['trakt']['base_url'];
$tratkClientId = $config['trakt']['client_id'];
$traktHeader   = buildTraktHeader($traktBaseUrl, $tratkClientId, $config['trakt']['client_secret']);

echo "Pulling TV Shows from Trakt..." . PHP_EOL;
$episodes = sendRequest("$traktBaseUrl/calendars/my/shows/$dateToday/$numOfDays", 'GET', [], $traktHeader);

if(!$episodes['response'] || $episodes['status'] !== 200) {
    echo 'Error while attempting to pull episodes from Trakt' . PHP_EOL;
    exit();
}

try {
    $episodes = json_decode($episodes['response'], true, 512, JSON_THROW_ON_ERROR);

} catch (JsonException $jsonException) {
    echo sprintf("Not able to read episodes payload from Trakt. Details: %s", $jsonException->getMessage())
        . PHP_EOL;

    exit();
}

foreach($episodes as $episode) {
    $tmdbId        = $episode['show']['ids']['tmdb'];
    $showTitle     = $episode['show']['title'];
    $episodeTitle  = $episode['episode']['title'];
    $seasonNumber  = $episode['episode']['season'];
    $episodeNumber = $episode['episode']['number'];
    $airDateTime   = convertStringToEasternDateTime($episode['first_aired'], $config['timezone']);

    //Only work with episodes airing on current date
    if($dateToday !== $airDateTime->format('Y-m-d')) {
        echo "Episode not airing today, skipping..." . PHP_EOL;
        continue;
    }

    //Pull poster and streaming network
    echo "Pulling details for TV Show $showTitle..." . PHP_EOL;
    $tmdbData = sendRequest("$tmdbBaseUrl/$tmdbId?api_key=$tmdbApiKey", 'GET');

    if(!$tmdbData['response'] || $tmdbData['status'] !== 200) {
        echo "Not able to pull poster and network info from TMDB, skipping..." . PHP_EOL;
        continue;
    }

    try {
        $episodeDetails = json_decode($tmdbData['response'], true, 512, JSON_THROW_ON_ERROR);

    } catch (JsonException $jsonException) {
        echo sprintf("Not able to read episode details from TMDB. Details: %s", $jsonException->getMessage()) .
            PHP_EOL;

        echo "Skipping..." . PHP_EOL;
        continue;
    }

    $networkName    = $episodeDetails['networks'][0]['name'];
    $networkPath    = $tmdbPosterBaseUrl . $episodeDetails['networks'][0]['logo_path'];
    $posterPath     = $tmdbPosterBaseUrl . $episodeDetails['poster_path'];

    //Send to Discord
    try {
        $discordNotification = sendRequest($discordWebhook, 'POST', [
            'embeds' => [
                [
                    'title' => "🚨 $showTitle 🚨",
                    'description' => "**New Episode**
                        Season $seasonNumber: Episode $episodeNumber: $episodeTitle
                        Next Air Date: " . $airDateTime->format('l, F jS, Y \a\t g:i A T'),
                        //Sunday, January 12th, 2025 at 10:00 PM EST
                    'color' => 9838011,
                    'image' => [
                        'url' => $posterPath
                    ],
                    'footer' => [
                        'text' => "Streaming on $networkName",
                        'icon_url' => $networkPath
                    ]
                ]
            ]
        ], ['Content-Type: application/json']);

    } catch (JsonException $jsonException) {
        echo sprintf("Not able to send notification to discord. Details: %s", $jsonException->getMessage()) .
            PHP_EOL;

        echo "Skipping..." . PHP_EOL;
        continue;
    }

    if($discordNotification['status'] === 204) {
        echo "Successfully sent notification to Discord..." . PHP_EOL;
    }
}

echo "Finished" . PHP_EOL;

/**
 * @param string $file
 * @return mixed|void
 */
function getContents(string $file)
{
    if (!file_exists($file)) {
        echo 'Config file not found' . PHP_EOL;
        exit();
    }

    try {
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    } catch (JsonException $jsonException) {
        echo sprintf('Invalid JSON file - Details: %s', $jsonException->getMessage()) . PHP_EOL;
        exit();
    }
}

/**
 * @param string $file
 * @param array $contents
 * @return true
 */
function putContents(string $file, array $contents): true
{
    try {
        $data = json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $didSave = file_put_contents($file, $data);

        if(is_int($didSave)) {
            return true;
        }

    } catch (JsonException $jsonException) {
        echo sprintf('Error while attempting to save JSON - Details: %s', $jsonException->getMessage())
        . PHP_EOL;

        exit();
    }

    echo 'Error while attempting to save JSON'. PHP_EOL;
    exit();
}


/**
 * @param string $url
 * @param string $clientId
 * @param string $clientSecret
 * @return array
 * @throws JsonException
 */
function buildTraktHeader(string $url, string $clientId, string $clientSecret): array
{
    $token = getContents(__DIR__ . '/token.json');

    if (time() > $token['expires_at']) {
        echo "Trakt access token expired. Refreshing..." . PHP_EOL;

        $newToken = sendRequest("$url/oauth/token", 'POST', [
            'refresh_token' => $token['refresh_token'],
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => "refresh_token",
        ]);

        if($newToken['response'] && $newToken['status'] === 200) {
            echo "Access token refreshed successfully. Saving..." . PHP_EOL;

            try {
                $payload = json_decode($newToken['response'], true, 512, JSON_THROW_ON_ERROR);

            } catch (JsonException $jsonException) {
                echo sprintf(
                    "Not able to read access token from Trakt. Details: %s",
                    $jsonException->getMessage()
                    ) . PHP_EOL;

                exit();
            }

            // Update expiration time
            $payload['expires_at'] = time() + $payload['expires_in'];
            $token['access_token'] = $payload['access_token'];

            //Store new token
            putContents(__DIR__ . '/token.json', $payload);

        } else {
            echo "Error while attempting to refresh Trakt token" . PHP_EOL;
            exit();
        }

    } else {
        echo "Using existing access token" . PHP_EOL;
    }

    return [
        "Authorization: Bearer " . $token['access_token'],
        "trakt-api-version: 2",
        "trakt-api-key: $clientId"
    ];
}


/**
 * @param string $url
 * @param string $method
 * @param array $body
 * @param array $headers
 * @return array
 * @throws JsonException
 */
function sendRequest(string $url, string $method, array $body = [], array $headers = []): array
{
    $curlHandle = curl_init();

    curl_setopt($curlHandle, CURLOPT_URL, $url);
    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $method);

    if ($headers) {
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headers);
    }

    if ($body) {
        //Handle JSON payloads
        if($headers && $headers[0] === 'Content-Type: application/json') {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        } else {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
        }
    }

    $response = curl_exec($curlHandle);
    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    curl_close($curlHandle);

    return [
        'response' => $response,
        'status'   => $httpCode
    ];
}

/**
 * @param string $dateTime
 * @param string $timezone
 * @return DateTime
 */
function convertStringToEasternDateTime(string $dateTime, string $timezone): DateTime
{
    try {
        //Create DateTime object based off UTC string
        $date = new DateTime($dateTime, new DateTimeZone('UTC'));

        // Convert to users timezone
        try {
            return $date->setTimezone(new DateTimeZone($timezone));
        } catch (DateInvalidTimeZoneException $dateInvalidTimeZoneException) {
            echo sprintf('Invalid timezone! Details: %s', $dateInvalidTimeZoneException->getMessage()) . PHP_EOL;
            exit();
        }

    } catch (DateMalformedStringException $malformedStringException) {
        echo sprintf('Not able to convert episode air time to EST: %s', $malformedStringException->getMessage())
        . PHP_EOL;

        exit();
    }
}