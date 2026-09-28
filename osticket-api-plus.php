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

    /**
     * Side-loaded instance config captured during bootstrap.
     * Named separately from Plugin::$config; PluginManager nulls that after boot.
     *
     * @var OsticketApiPlusConfig|null
     */
    private static $cachedInstanceConfig = null;

    public function bootstrap()
    {
        self::$instance = $this;
        // Capture before PluginManager nulls $this->config after all instances boot.
        $sideLoaded = $this->getConfig();
        if ($sideLoaded) {
            self::$cachedInstanceConfig = $sideLoaded;
        }
        require_once __DIR__ . '/api.php';
        Signal::connect('api', array('OsticketApiPlusPlugin', 'onApiDispatch'));
    }

    /**
     * @return OsticketApiPlusConfig|null
     */
    public static function conf()
    {
        if (self::$cachedInstanceConfig) {
            return self::$cachedInstanceConfig;
        }

        if (!self::$instance) {
            return null;
        }

        // Fallback if side-load was cleared: first active instance config.
        if (method_exists(self::$instance, 'getActiveInstances')) {
            foreach (self::$instance->getActiveInstances() as $pluginInstance) {
                $conf = $pluginInstance->getConfig();
                if ($conf) {
                    self::$cachedInstanceConfig = $conf;
                    return self::$cachedInstanceConfig;
                }
            }
        }

        return null;
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

            // Health (no staff/user toggle)
            $dispatcher->append(
                url_get(
                    '^/api-plus/health\.json$',
                    array('OsticketApiPlusController', 'health')
                )
            );

            // Staff catalogues (specific paths before ticket id routes)
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/statuses\.json$',
                    array('OsticketApiPlusController', 'staffStatuses')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/depts\.json$',
                    array('OsticketApiPlusController', 'staffDepts')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/staff\.json$',
                    array('OsticketApiPlusController', 'staffDirectory')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/canned\.json$',
                    array('OsticketApiPlusController', 'staffCanned')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/priorities\.json$',
                    array('OsticketApiPlusController', 'staffPriorities')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/topics\.json$',
                    array('OsticketApiPlusController', 'staffTopics')
                )
            );

            // Staff by public ticket number
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/reply\.json$',
                    array('OsticketApiPlusController', 'staffReplyByNumber')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/status\.json$',
                    array('OsticketApiPlusController', 'staffStatusByNumber')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/note\.json$',
                    array('OsticketApiPlusController', 'staffNoteByNumber')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/assign\.json$',
                    array('OsticketApiPlusController', 'staffAssignByNumber')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/priority\.json$',
                    array('OsticketApiPlusController', 'staffPriorityByNumber')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/topic\.json$',
                    array('OsticketApiPlusController', 'staffTopicByNumber')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/attachments/(?P<file_id>\d+)\.json$',
                    array('OsticketApiPlusController', 'staffDownloadAttachmentByNumber')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)\.json$',
                    array('OsticketApiPlusController', 'staffGetTicketByNumber')
                )
            );

            // Staff by internal id
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/reply\.json$',
                    array('OsticketApiPlusController', 'staffReply')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/status\.json$',
                    array('OsticketApiPlusController', 'staffStatus')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/note\.json$',
                    array('OsticketApiPlusController', 'staffNote')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/assign\.json$',
                    array('OsticketApiPlusController', 'staffAssign')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/priority\.json$',
                    array('OsticketApiPlusController', 'staffPriority')
                )
            );
            $dispatcher->append(
                url_post(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/topic\.json$',
                    array('OsticketApiPlusController', 'staffTopic')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/tickets/(?P<id>\d+)/attachments/(?P<file_id>\d+)\.json$',
                    array('OsticketApiPlusController', 'staffDownloadAttachment')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/tickets/(?P<id>\d+)\.json$',
                    array('OsticketApiPlusController', 'staffGetTicket')
                )
            );
            $dispatcher->append(
                url_get(
                    '^/api-plus/staff/tickets\.json$',
                    array('OsticketApiPlusController', 'staffListTickets')
                )
            );

            // User-scoped: attachment download before ticket-number catch-all
            $dispatcher->append(
                url_get(
                    '^/api-plus/tickets/(?P<number>[^/]+)/attachments/(?P<file_id>\d+)\.json$',
                    array('OsticketApiPlusController', 'userDownloadAttachment')
                )
            );

            // User-scoped: GET and POST share the ticket-number URL
            $dispatcher->append(
                url(
                    '^/api-plus/tickets/(?P<number>[^/]+)\.json$',
                    array('OsticketApiPlusController', 'userTicket')
                )
            );
            $dispatcher->append(
                url_get(
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
