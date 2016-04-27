<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016 David Cole <david@team-reflex.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord;

use Carbon\Carbon;
use Discord\Exceptions\InviteInvalidException;
use Discord\Helpers\Guzzle;
use Discord\Parts\Guild\Invite;
use Discord\Parts\Part;
use Discord\Parts\User\Client;

/**
 * The Discord class is the base of the client. This is the class that you
 * will start off with when you do anything with the client.
 *
 * @see \Discord\Parts\User\Client Most functions are forwarded onto the Client class.
 */
class Discord
{
    /**
     * The current version of the API.
     *
     * @var string The current version of the API.
     */
    const VERSION = 'v3.2.0';

    /**
     * The Discord epoch value.
     *
     * @var int
     */
    const DISCORD_EPOCH = 1420070400000;

    /**
     * The Client instance.
     *
     * @var Client The Discord Client instance.
     */
    protected $client;

    /**
     * Logs into the Discord servers.
     *
     * @param string $email    The Email for the account you are logging into.
     * @param string $password The password for the account you are logging into.
     * @param string $token    The account's token (optional)
     *
     * @return void
     */
    public function __construct($email = null, $password = null, $token = null)
    {
        $this->setToken($email, $password, $token);

        $request = Guzzle::get('users/@me');

        $this->client = new Client((array) $request, true);
    }

    /**
     * Check the filesystem for the token.
     *
     * @param string $email The Email that will be checked for token caching
     *
     * @return string|null The Discord token or null.
     */
    protected function checkForCaching($email)
    {
        if (file_exists(getcwd().'/discord/'.md5($email))) {
            $file = file_get_contents(getcwd().'/discord/'.md5($email));

            return $file;
        }
    }

    /**
     * Sets the token for the API.
     *
     * @param string $email    The Email for the account you are logging into.
     * @param string $password The password for the account you are logging into.
     * @param string $token    The account's token (optional)
     *
     * @return void
     */
    protected function setToken($email, $password, $token)
    {
        if (! is_null($token)) {
            @define('DISCORD_TOKEN', $token);

            return;
        }

        if (! is_null($token = $this->checkForCaching($email))) {
            @define('DISCORD_TOKEN', $token);

            return;
        }

        $request = Guzzle::post('auth/login', [
            'email'    => $email,
            'password' => $password,
        ], true);

        try {
            if (! file_exists(getcwd().'/discord')) {
                mkdir(getcwd().'/discord');
            }

            file_put_contents(getcwd().'/discord/'.md5($email), $request->token);
        } catch (\Exception $e) {
        }

        @define('DISCORD_TOKEN', $request->token);

        return;
    }

    /**
     * Logs out of Discord.
     *
     * @return bool Whether the login succeeded or failed.
     */
    public function logout()
    {
        $request = Guzzle::post('auth/logout', [
            'token' => DISCORD_TOKEN,
        ]);

        $this->client = null;

        return true;
    }

    /**
     * Accepts a Discord channel invite.
     *
     * @param string $code The invite code. (not including the URL)
     *
     * @return Invite The invite that was accepted, in \Discord\Parts\Guild\Invite format.
     *
     * @throws InviteInvalidException Thrown when the invite is invalid or not found.
     *
     * @see \Discord\Parts\Guild\Invite The type that the invite is returned in.
     */
    public function acceptInvite($code)
    {
        if ($code instanceof Invite) {
            $code = $invite->code;
        }

        if (preg_match('/https:\/\/discord.gg\/(.+)/', $code, $matches)) {
            $code = $matches[1];
        }

        try {
            $request = Guzzle::post("invite/{$code}");
        } catch (\Exception $e) {
            throw new InviteInvalidException('The invite is invalid or has expired.');
        }

        return new Invite((array) $request, true);
    }

    /**
     * Returns the date an object with an ID was created.
     *
     * @param Part|int $id The Part of ID to get the timestamp for.
     *
     * @return \Carbon\Carbon|null Carbon timestamp or null if can't be found.
     */
    public static function getTimestamp($id)
    {
        if ($id instanceof Part) {
            $id = $id->id;
        }

        if (! is_int($id)) {
            return;
        }

        $ms = ($id >> 22) + self::DISCORD_EPOCH;

        return new Carbon(date('r', $ms / 1000));
    }

    /**
     * Creates a Discord OAuth application.
     *
     * @param string $token Your authentication token.
     * @param string $name  Your OAuth app name.
     */
    public static function createOauthApp($token, $name)
    {
        $response = Guzzle::post('oauth2/applications', [
            'name' => $name,
        ], true, [
            'authorization' => $token,
        ]);

        return $response;
    }

    /**
     * Handles dynamic calls to the class.
     *
     * @param string $name The function name.
     * @param array  $name The function arguments.
     *
     * @return mixed The result of the function.
     */
    public function __call($name, array $args = [])
    {
        if (is_null($this->client)) {
            return false;
        }

        return call_user_func_array([$this->client, $name], $args);
    }

    /**
     * Handles dynamic variable calls to the class.
     *
     * @param string $name The variable name.
     *
     * @return mixed The variable or false if it does not exist.
     */
    public function __get($name)
    {
        if (is_null($this->client)) {
            return false;
        }

        return $this->client->getAttribute($name);
    }

    /**
     * Handles dynamic set calls to the class.
     *
     * @param string $variable The variable name.
     * @param mixed  $value    The value to set.
     *
     * @return void
     */
    public function __set($variable, $value)
    {
        $this->client->setAttribute($variable, $value);
    }
}
