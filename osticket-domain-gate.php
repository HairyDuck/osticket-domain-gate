<?php
/**
 * osTicket Domain Gate – main plugin class.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.signal.php';
require_once __DIR__ . '/config.php';

class OsticketDomainGatePlugin extends Plugin
{
    public $config_class = 'OsticketDomainGateConfig';

    /** @var OsticketDomainGatePlugin|null */
    private static $instance = null;

    /** @var OsticketDomainGateConfig|null */
    private static $cachedInstanceConfig = null;

    public function bootstrap()
    {
        self::$instance = $this;
        $sideLoaded = $this->getConfig();
        if ($sideLoaded) {
            self::$cachedInstanceConfig = $sideLoaded;
        }

        require_once __DIR__ . '/include/class.domain_gate.php';

        Signal::connect('ticket.created', array('DomainGateEngine', 'onTicketCreated'));

        // Staff allow command via internal note
        Signal::connect(
            'object.created',
            array('DomainGateEngine', 'onThreadEntryCreated'),
            'ThreadEntry'
        );
    }

    /**
     * @return OsticketDomainGateConfig|null
     */
    public static function conf()
    {
        if (self::$cachedInstanceConfig) {
            return self::$cachedInstanceConfig;
        }

        if (!self::$instance) {
            return null;
        }

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
     * Path to runtime allowlist extras (staff DOMAIN-GATE-ALLOW).
     */
    public static function extraAllowlistPath()
    {
        return __DIR__ . '/data/allowlist-extra.txt';
    }

    /**
     * Validate domain list text; returns first bad token or null.
     */
    public static function validateDomainList($text)
    {
        require_once __DIR__ . '/include/class.domain_gate.php';
        return DomainGateEngine::validateDomainList($text);
    }
}
