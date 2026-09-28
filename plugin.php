<?php
/**
 * osTicket API Plus – plugin metadata.
 *
 * Open-source extension of the stock osTicket HTTP API:
 * list, view, reply, status, notes, assign, priority, topic, catalogues,
 * and attachment metadata / download.
 *
 * Safe for production: does not patch core files. Routes register only
 * when this plugin is installed and enabled.
 *
 * MIT License – see LICENSE
 */
return array(
    'id'          => 'opensource:osticket-api-plus',
    'version'     => '1.2.0',
    'name'        => 'osTicket API Plus',
    'author'      => 'osTicket API Plus contributors',
    'description' => 'REST endpoints to list, view, reply, assign, update, and download ticket attachments via the stock API key. Does not modify core files.',
    'url'         => 'https://github.com/HairyDuck/osticket-api-plus',
    'plugin'      => 'osticket-api-plus.php:OsticketApiPlusPlugin',
);
