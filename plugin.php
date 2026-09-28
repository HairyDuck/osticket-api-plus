<?php
/**
 * osTicket API Plus – plugin metadata.
 *
 * Open-source extension of the stock osTicket HTTP API:
 * list, view, reply, status, and internal notes.
 *
 * Safe for production: does not patch core files. Routes register only
 * when this plugin is installed and enabled.
 *
 * MIT License – see LICENSE
 */
return array(
    'id'          => 'opensource:osticket-api-plus',
    'version'     => '1.0.0',
    'name'        => 'osTicket API Plus',
    'author'      => 'osTicket API Plus contributors',
    'description' => 'Adds REST endpoints to list, view, reply to, and update tickets via the stock API key. Does not modify core osTicket files.',
    'url'         => 'https://github.com/osticket-api-plus/osticket-api-plus',
    'plugin'      => 'osticket-api-plus.php:OsticketApiPlusPlugin',
);
