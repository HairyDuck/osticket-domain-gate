<?php
/**
 * osTicket Domain Gate – plugin metadata.
 *
 * Domain allowlist / blocklist for inbound tickets, with canned rejection
 * replies, organisation-domain recognition, ticket-number bypass, and a
 * staff note command to allow a domain.
 *
 * Safe for production: does not patch core files.
 *
 * MIT License – see LICENSE
 */
return array(
    'id'          => 'opensource:osticket-domain-gate',
    'version'     => '1.0.2',
    'name'        => 'osTicket Domain Gate',
    'author'      => 'osTicket Domain Gate contributors',
    'description' => 'Allow or block new tickets by sender domain, notify with a canned reply, recognise organisation domains, and let staff allow a domain via an internal note.',
    'url'         => 'https://github.com/HairyDuck/osticket-domain-gate',
    'plugin'      => 'osticket-domain-gate.php:OsticketDomainGatePlugin',
);
