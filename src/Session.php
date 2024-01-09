<?php

namespace Pinga\Session;

use Pinga\Http\ResponseHeader;
use Predis\Client as RedisClient;

final class Session
{
    private static $redis;
    private static $sessionLifetime = 3600; // default session lifetime is 1 hour

    private function __construct()
    {
    }

    public static function initialize($sessionLifetime = 3600)
    {
        self::$sessionLifetime = $sessionLifetime;

        self::$redis = new RedisClient([
            "scheme" => "tcp",
            "host" => "127.0.0.1",
            "port" => 6379,
        ]);

        session_set_save_handler(
            function ($savePath, $sessionName) {
                return true;
            }, // Open
            function () {
                return true;
            }, // Close
            function ($sessionID) {
                $data = self::$redis->get("sessions:$sessionID");
                if ($data === null) {
                    return ""; // Return an empty string if no data is found
                }
                return igbinary_unserialize($data); // Return the unserialized data
            },
            function ($sessionID, $sessionData) {
                $serializedData = igbinary_serialize($sessionData);
                self::$redis->setex(
                    "sessions:$sessionID",
                    self::$sessionLifetime,
                    $serializedData
                );
                return true; // Return true after successful write
            },
            function ($sessionID) {
                self::$redis->del("sessions:$sessionID");
                return true; // Return true after successful deletion
            },
            function ($maxlifetime) {
                // Implement your garbage collection logic here
                // Return true if garbage collection is successful or not necessary
                return true;
            }
        );

        register_shutdown_function("session_write_close");

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function start(
        $sameSiteRestriction = \Pinga\Cookie\Cookie::SAME_SITE_RESTRICTION_LAX
    ) {
        // Ensure that the session is initialized (Redis and custom handler setup)
        self::initialize();

        // Only start the session if it's not already active
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Rewrite the cookie header for enhanced cookie management
        self::rewriteCookieHeader($sameSiteRestriction);
    }

    private static function rewriteCookieHeader(
        $sameSiteRestriction = \Pinga\Cookie\Cookie::SAME_SITE_RESTRICTION_LAX
    ) {
        // get and remove the original cookie header set by PHP
        $originalCookieHeader = ResponseHeader::take(
            "Set-Cookie",
            \session_name() . "="
        );

        // if a cookie header has been found
        if (isset($originalCookieHeader)) {
            // parse it into a cookie instance
            $parsedCookie = \Pinga\Cookie\Cookie::parse($originalCookieHeader);

            // if the cookie has successfully been parsed
            if (isset($parsedCookie)) {
                // apply the supplied same-site restriction
                $parsedCookie->setSameSiteRestriction($sameSiteRestriction);

                if (
                    $parsedCookie->getSameSiteRestriction() ===
                        \Pinga\Cookie\Cookie::SAME_SITE_RESTRICTION_NONE &&
                    !$parsedCookie->isSecureOnly()
                ) {
                    \trigger_error(
                        'You may have to enable the \'session.cookie_secure\' directive in the configuration in \'php.ini\' or via the \'ini_set\' function',
                        \E_USER_WARNING
                    );
                }

                // save the cookie
                $parsedCookie->save();
            }
        }
    }

    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public static function get($key, $defaultValue = null)
    {
        return $_SESSION[$key] ?? $defaultValue;
    }

    public static function has($key)
    {
        return isset($_SESSION[$key]);
    }

    public static function delete($key)
    {
        unset($_SESSION[$key]);
    }

    public static function take($key, $defaultValue = null)
    {
        if (isset($_SESSION[$key])) {
            $value = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $value;
        }
        return $defaultValue;
    }

    public static function id($newId = null)
    {
        if ($newId !== null) {
            session_id($newId);
        }
        return session_id();
    }

    public static function regenerate($deleteOldSession = false)
    {
        session_regenerate_id($deleteOldSession);
    }

    public static function destroy()
    {
        // Clear session data from the global $_SESSION array
        $_SESSION = [];

        // Remove the session data from Redis
        $sessionId = session_id();
        if ($sessionId) {
            self::$redis->del("sessions:$sessionId");
        }

        // Destroy the session
        session_destroy();
    }

    public static function isActive()
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public static function getAll()
    {
        return $_SESSION;
    }

    public static function setAll($data)
    {
        $_SESSION = $data;
    }
}
