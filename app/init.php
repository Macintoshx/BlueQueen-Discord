<?php
use Discord\Parts\Channel\Channel;
use Discord\Voice\VoiceClient;

ini_set('memory_limit', '200M');

include __DIR__ . '/../vendor/autoload.php';
require_once dirname(__FILE__) . '/../../bqbot.php';

$bqbot = [
    'appClientId',
    'appClientSecret',
    'botUsername',
    'botSecret',
    'botToken'
]; // nazwy zmiennych z hasłami itd.



$discord = new \Discord\Discord([
    'token' => "$botToken",
]);

$discord->on('ready', function ($discord) {
    /** @var $discord \Discord\Discord */
    $game = $discord->factory(\Discord\Parts\User\Game::class, ['name' => 'with Sad Panda', 'type' => 0]);
    $discord->updatePresence($game, false);
    $guild   = $discord->guilds->first();
    $channel = $guild->channels->get('name', 'Main');
    /** @var $channel Channel */
    $discord->joinVoiceChannel($channel)->then(function (VoiceClient $vc) {
        echo "Joined voice channel.\r\n";
        $vc->playFile('sounds/hohoho.mp3');
    }, function ($e) {
        echo "There was an error joining the voice channel: {$e->getMessage()}\r\n";
    });
    echo "Bot is ready.", PHP_EOL;


    // Listen for events here
    $discord->on('message', function ($message) {
        /** @var $message \Discord\Parts\Channel\Message */
        echo "Recieved a message from {$message->author->username}: {$message->content}", PHP_EOL;
        // We are just checking if the message equils to ping and replying to the user with a pong!
        if ($message->content == 'hohoho') {

        }

        if ($message->content == '!pogoda') {
            try {
                $ch = curl_init();
                // set url
                curl_setopt($ch, CURLOPT_URL, "https://api.bluequeen.tk/v1/weather?start=-1");
                //return the transfer as a string
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_USERAGENT,
                    'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
                // $output contains the output string
                $response = curl_exec($ch);
                // close curl resource to free up system resources
                curl_close($ch);

                $response = json_decode($response);
                $resp = "\n[" . $response[0]->Date . "] Wejherowo : " . $response[0]->Value . "°C";
            } catch (Exception $e) {
                $resp = "\nUsługa chwilowo niedostepna";
            }
            $message->reply($resp);
        }

        if ($message->content == '!bot') {
            $message->reply("\nBlueQueen Discord Bot v.0.2\n\nDostepne komendy: \n\nping, !pogoda, !bot");
        }
    });
});

$discord->run();
