<?php
/**
 * osTicket API Plus – main plugin class.
 *
 * Registers HTTP API routes via the core `api` signal only.
 * Does not alter stock ticket-create behaviour.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.signal.php';
require_once __DIR__ . '/config.php';

class OsticketApiPlusPlugin extends Plugin
{
    public $config_class = 'OsticketApiPlusConfig';

    /** @var OsticketApiPlusPlugin|null */
    private static $instance = null;

    public function bootstrap()
    {
        self::$instance = $this;
        require_once __DIR__ . '/api.php';
        Signal::connect('api', array('OsticketApiPlusPlugin', 'onApiDispatch'));
    }

    /**
     * @return OsticketApiPlusConfig|null
     */
    public static function conf()
    {
        if (!self::$instance) {
            return null;
        }

        return self::$instance->getConfig();
    }

    /**
     * Append routes onto the core API dispatcher.
     * Failures here must not break stock /api/tickets.json create.
     *
     * @param mixed $dispatcher
     */
    public static function onApiDispatch($dispatcher)
    {
        try {
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'append')) {
                return;
            }

            // Staff (specific paths first)
            $dispatcher->append(
                url(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/reply\.json$',
                    array('OsticketApiPlusController', 'staffReply')
                )
            );
            $dispatcher->append(
                url(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/status\.json$',
                    array('OsticketApiPlusController', 'staffStatus')
                )
            );
            $dispatcher->append(
                url(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/note\.json$',
                    array('OsticketApiPlusController', 'staffNote')
                )
            );
            $dispatcher->append(
                url(
                    '^/api-plus/staff/tickets/(?P<id>\d+)\.json$',
                    array('OsticketApiPlusController', 'staffGetTicket')
                )
            );
            $dispatcher->append(
                url(
                    '^/api-plus/staff/tickets\.json$',
                    array('OsticketApiPlusController', 'staffListTickets')
                )
            );

            // User-scoped
            $dispatcher->append(
                url(
                    '^/api-plus/tickets/(?P<number>[^/]+)\.json$',
                    array('OsticketApiPlusController', 'userTicket')
                )
            );
            $dispatcher->append(
                url(
                    '^/api-plus/tickets\.json$',
                    array('OsticketApiPlusController', 'userListTickets')
                )
            );
        } catch (Throwable $e) {
            // Never break stock API dispatch if plugin registration fails
            if (isset($GLOBALS['ost']) && $GLOBALS['ost']) {
                $GLOBALS['ost']->logError(
                    'osTicket API Plus',
                    'Failed to register routes: ' . $e->getMessage(),
                    false
                );
            }
        }
    }
}
