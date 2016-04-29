<?php
ini_set('memory_limit', '200M');

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\WebSocket;

// Includes the Composer autoload file
require_once dirname(__FILE__) . '/../../bqbot.php';
include __DIR__ . '/../vendor/autoload.php';

$bqbot = [
	'appClientId',
	'appClientSecret',
	'botUsername',
	'botSecret',
	'botToken'
]; // nazwy zmiennych z hasłami itd.

// Init the Discord instance.
$discord = new Discord($botToken);
// Init the WebSocket instance.
$ws = new WebSocket($discord);

$ws->on(
	'ready',
	function ($discord) use ($ws)
	{
		// In here we can access any of the WebSocket events.
		//
		// There is a list of event constants that you can
		// find here: https://teamreflex.github.io/DiscordPHP/classes/Discord.WebSockets.Event.html
		//
		// We will echo to the console that the WebSocket is ready.
		echo 'Discord WebSocket is ready!' . PHP_EOL;
		$discord->getClient()->updatePresence($ws, "with Sad Panda", false);
		echo 'Game set' . PHP_EOL;
		// Here we will just log all messages.
		$ws->on(
			Event::MESSAGE_CREATE,
			function ($message, $discord, $newdiscord)
			{
				// We are just checking if the message equils to ping and replying to the user with a pong!
				if ($message->content == 'ping')
				{
					$message->reply('pong!');
				}

				if ($message->content == '!pogoda')
				{
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
					$resp = "\nWejherowo : [" . $response[0]->Date . "] : " . $response[0]->Value;
					$message->reply($resp);
				}

				if ($message->content == '!bot')
				{
					$message->reply("\nBlueQueen Discord Bot v.0.1\n\nDostepne komendy: \n\nping, !pogoda, !bot");
				}

				$reply = $message->timestamp->format('d/m/y H:i:s') . ' - '; // Format the message timestamp.
				$reply .= $message->full_channel->guild->name . ' - ';
				$reply .= $message->author->username . ' - '; // Add the message author's username onto the string.
				$reply .= $message->content; // Add the message content.
				echo $reply . PHP_EOL; // Finally, echo the message with a PHP end of line.

			}
		);
	}
);

$ws->on(
	'error',
	function ($error, $ws)
	{
		dump($error);
		exit(1);
	}
);

// Now we will run the ReactPHP Event Loop!

$ws->run();

