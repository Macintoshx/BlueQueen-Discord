<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016 David Cole <david@team-reflex.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\WebSockets;

use Discord\Cache\Cache;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Helpers\Guzzle;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Guild;
use Discord\Parts\User\Member;
use Discord\Voice\VoiceClient;
use Discord\WSClient\Factory as WsFactory;
use Discord\WSClient\WebSocket as WebSocketInstance;
use Evenement\EventEmitter;
use Ratchet\WebSocket\Version\RFC6455\Frame;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

/**
 * This class is the base for the Discord WebSocket.
 */
class WebSocket extends EventEmitter
{
    /**
     * The WebSocket event loop.
     *
     * @var \React\EventLoop\Factory The Event Loop.
     */
    public $loop;

    /**
     * The WebSocket factory.
     *
     * @var WsFactory The WebSocket factory.
     */
    protected $wsfactory;

    /**
     * The WebSocket instance.
     *
     * @var WebSocketInstance The WebSocket client instance.
     */
    protected $ws;

    /**
     * The Discord instance.
     *
     * @var \Discord\Discord The Discord REST client instance.
     */
    protected $discord;

    /**
     * The Discord WebSocket gateway.
     *
     * @var string The Discord WebSocket gateway.
     */
    protected $gateway;

    /**
     * The event handlers.
     *
     * @var Handlers The Handlers class.
     */
    protected $handlers;

    /**
     * The amount of times that the WebSocket has attempted to reconnect.
     *
     * @var int Reconnect count.
     */
    protected $reconnectCount = 0;

    /**
     * If the WebSocket is reconnecting.
     *
     * @var bool Whether the WebSocket is reconnecting.
     */
    protected $reconnecting = false;

    /**
     * The reconnect reset timer.
     *
     * @var TimerInterface THe reconnect reset timer.
     */
    protected $reconnectResetTimer;

    /**
     * The Voice Client instance.
     *
     * @var VoiceClient The Voice Client.
     */
    protected $voice;

    /**
     * The WebSocket heartbeat.
     *
     * @var TimerInterface The WebSocket heartbeat.
     */
    protected $heartbeat;

    /**
     * Unavailable servers.
     *
     * @var array Unavailable servers.
     */
    protected $unavailableServers = [];

    /**
     * Timer that waits for unavailable servers to come online.
     *
     * @var Timer The timer.
     */
    protected $unavailableTimer;

    /**
     * Constructs the WebSocket instance.
     *
     * @param Discord            $discord The Discord REST client instance.
     * @param LoopInterface|null $loop    The ReactPHP Event Loop.
     *
     * @return void
     */
    public function __construct(Discord $discord, LoopInterface &$loop = null)
    {
        $this->discord   = $discord;
        $this->gateway   = $this->getGateway();
        $loop            = (is_null($loop)) ? LoopFactory::create() : $loop;
        $this->wsfactory = new WsFactory($loop);

        $this->handlers = new Handlers();

        $this->wsfactory->createConnection($this->gateway)->then(
            [$this, 'handleWebSocketConnection'],
            [$this, 'handleWebSocketError']
        );

        $this->loop = $loop;
    }

    /**
     * Handles a WebSocket connection.
     *
     * @param WebSocketInstance $ws The WebSocket instance.
     *
     * @return void
     */
    public function handleWebSocketConnection(WebSocketInstance &$ws)
    {
        $ws->on('message', function ($data, $ws) {
            $this->emit('raw', [$data, $this->discord]);
            $data = json_decode($data);

            if (isset($data->d->unavailable)) {
                $this->emit('unavailable', [$data->t, $data->d]);

                if ($data->t == Event::GUILD_DELETE) {
                    $discord = $this->discord;

                    foreach ($discord->guilds as $index => $guild) {
                        if ($guild->id == $data->d->id) {
                            $discord->guilds->pull($index);
                            break;
                        }
                    }

                    $this->discord = $discord;
                }

                return;
            }

            if (! is_null($handlerSettings = $this->handlers->getHandler($data->t))) {
                $handler = new $handlerSettings['class']();
                $handlerData = $handler->getData($data->d, $this->discord);
                $newDiscord = $handler->updateDiscordInstance($handlerData, $this->discord);
                $this->emit($data->t, [$handlerData, $this->discord, $newDiscord]);

                foreach ($handlerSettings['alternatives'] as $alternative) {
                    $this->emit($alternative, [$handlerData, $this->discord, $newDiscord]);
                }

                if ($data->t == Event::MESSAGE_CREATE && (strpos($handlerData->content, '<@'.$this->discord->id.'>') !== false)) {
                    $this->emit('mention', [$handlerData, $this->discord, $newDiscord]);
                }

                if ($data->t == Event::VOICE_STATE_UPDATE) {
                    if (! is_null($this->voice)) {
                        $this->voice->handleVoiceStateUpdate($data->d);
                    }
                }

                $this->discord = $newDiscord;
            }

            if ($data->t == Event::READY) {
                if (! is_null($this->reconnectResetTimer)) {
                    $this->loop->cancelTimer($this->reconnectResetTimer);
                }

                $this->reconnectResetTimer = $this->loop->addTimer(60 * 2, function () {
                    $this->reconnectCount = 0;
                });

                $tts = $data->d->heartbeat_interval / 1000;
                $this->heartbeat = $this->loop->addPeriodicTimer($tts, function () use ($ws) {
                    $time = microtime(true);
                    $this->send([
                        'op' => 1,
                        'd'  => $time,
                    ]);
                    $this->emit('heartbeat', [$time]);
                });

                // don't want to reparse ready
                if ($this->reconnecting) {
                    $this->reconnecting = false;

                    return;
                }

                $content = $data->d;

                // guilds
                $guilds = new Collection();

                foreach ($content->guilds as $guild) {
                    if (isset($guild->unavailable) && $guild->unavailable) {
                        $this->emit('unavailable', ['READY', $guild->id, $this]);
                        $this->unavailableServers[$guild->id] = $guild->id;

                        continue;
                    }

                    $guildPart = new Guild((array) $guild, true);

                    $channels = new Collection();

                    foreach ($guild->channels as $channel) {
                        $channel = (array) $channel;
                        $channel['guild_id'] = $guild->id;
                        $channelPart = new Channel($channel, true);

                        $channels->push($channelPart);

                        Cache::set("channels.{$channelPart->id}", $channelPart);
                    }

                    $guildPart->setCache('channels', $channels);

                    // guild members
                    $members = new Collection();

                    foreach ($guild->members as $member) {
                        $member = (array) $member;
                        $member['guild_id'] = $guild->id;
                        $member['status'] = 'offline';
                        $member['game'] = null;
                        $memberPart = new Member($member, true);

                        // check for presences

                        foreach ($guild->presences as $presence) {
                            if ($presence->user->id == $member['user']->id) {
                                $memberPart->status = $presence->status;
                                $memberPart->game = $presence->game;
                            }
                        }

                        $members->push($memberPart);

                        Cache::set("guild.{$memberPart->guild_id}.members.{$memberPart->id}", $memberPart);
                    }

                    $guildPart->setCache('members', $members);

                    $guilds->push($guildPart);

                    Cache::set("guild.{$guildPart->id}", $guildPart);
                }

                $this->discord->setCache('guilds', $guilds);

                if (count($this->unavailableServers) > 1) {
                    $this->unavailableTimer = $this->loop->addTimer(60 * 2, function () {
                        $this->emit('ready', [$this->discord]);
                    });

                    $handleGuildCreate = function ($guild) use (&$handleGuildCreate) {
                        if (! isset($this->unavailableServers[$guild->id])) {
                            return;
                        }

                        unset($this->unavailableServers[$guild->id]);
                        $this->emit('available', [$guild->id, $this]);

                        if (count($this->unavailableServers) < 1) {
                            $this->loop->cancelTimer($this->unavailableTimer);
                            $this->removeListener(Event::GUILD_CREATE, $handleGuildCreate);
                            $this->emit('ready', [$this->discord]);
                        }
                    };

                    $this->on(Event::GUILD_CREATE, $handleGuildCreate);
                } else {
                    $this->emit('ready', [$this->discord]);
                }
            }
        });

        $ws->on('close', function ($ws, $reason) {
            $this->emit('close', [$ws, $reason, $this->discord]);

            if (! is_null($this->heartbeat)) {
                $this->loop->cancelTimer($this->heartbeat);
            }

            if ($this->reconnectCount >= 4) {
                $this->emit('ws-reconnect-max', [$this->discord]);
                $this->loop->stop();

                return;
            }

            if (! $this->reconnecting) {
                $this->emit('reconnecting', [$this->discord]);

                $this->reconnecting = true;
                $this->gateway = $this->getGateway();
                $this->wsfactory->createConnection($this->gateway)->then([$this, 'handleWebSocketConnection'], [$this, 'handleWebSocketError']);
                ++$this->reconnectCount;
            }
        });

        $ws->on('error', function ($error, $ws) {
            $this->emit('error', [$error, $ws, $this->discord]);
        });

        $this->ws = $ws;

        $this->sendLoginFrame();
    }

    /**
     * Handles a WebSocket error.
     *
     * @param \Exception $e The error.
     *
     * @return void
     */
    public function handleWebSocketError($e)
    {
        $this->emit('ws-connect-error', [$e]);
    }

    /**
     * Joins a voice channel.
     *
     * @param Channel $channel The channel to join.
     * @param bool    $mute    Whether you should be mute when you join the channel.
     * @param bool    $deaf    Whether you should be deaf when you join the channel.
     *
     * @return \React\Promise\Promise
     */
    public function joinVoiceChannel(Channel $channel, $mute = false, $deaf = false)
    {
        $deferred = new Deferred();
        $arr      = ['user_id' => $this->discord->id, 'deaf' => $deaf, 'mute' => $mute];

        if ($channel->type != Channel::TYPE_VOICE) {
            $deferred->reject(new \Exception('You cannot join a Text channel.'));

            return $deferred->promise();
        }

        $closure = function ($message) use (&$closure, &$arr, $deferred, $channel) {
            $data = json_decode($message);

            if ($data->t == 'VOICE_STATE_UPDATE') {
                $arr['session'] = $data->d->session_id;
            } elseif ($data->t == 'VOICE_SERVER_UPDATE') {
                $arr['token']    = $data->d->token;
                $arr['endpoint'] = $data->d->endpoint;

                $vc = new VoiceClient($this, $this->loop, $channel, $arr);
                $vc->once('ready', function () use ($vc, $deferred, $channel) {
                    $vc->setBitrate($channel->bitrate)->then(function () use ($vc, $deferred) {
                        $deferred->resolve($vc);
                    });
                });
                $vc->once('error', function ($e) use ($deferred) {
                    $deferred->reject($e);
                });
                $this->voice = $vc;

                $this->ws->removeListener('message', $closure);
            }
        };

        $this->ws->on('message', $closure);

        $this->send([
            'op' => 4,
            'd'  => [
                'guild_id'   => $channel->guild_id,
                'channel_id' => $channel->id,
                'self_mute'  => $mute,
                'self_deaf'  => $deaf,
            ],
        ]);

        return $deferred->promise();
    }

    /**
     * Runs the Event Loop.
     *
     * @return void
     */
    public function run()
    {
        $this->loop->run();
    }

    /**
     * Sends the login frame to the WebSocket.
     *
     * @return void
     */
    public function sendLoginFrame()
    {
        $this->send([
            'op' => 2,
            'd'  => [
                'token'      => DISCORD_TOKEN,
                'v'          => 4,
                'properties' => [
                    '$os'               => PHP_OS,
                    '$browser'          => Guzzle::getUserAgent(),
                    '$device'           => '',
                    '$referrer'         => 'https://github.com/teamreflex/DiscordPHP',
                    '$referring_domain' => 'https://github.com/teamreflex/DiscordPHP',
                ],
                'large_threshold' => 100,
                'compress'        => true,
            ],
        ]);
    }

    /**
     * Sends data over the WebSocket.
     *
     * @param array $data Data to send to the WebSocket.
     *
     * @return void
     */
    public function send($data)
    {
        $frame = new Frame(json_encode($data), true);
        $this->ws->send($frame);
    }

    /**
     * Gets the WebSocket gateway.
     *
     * @return string The Discord WebSocket gateway.
     */
    public function getGateway()
    {
        $url  = Guzzle::get('gateway')->url;
        $url .= '/?v=4';

        return $url;
    }
}
