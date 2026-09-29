<?php
/**
 * Domain Gate policy engine.
 */

require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.staff.php';
require_once INCLUDE_DIR . 'class.thread.php';
require_once INCLUDE_DIR . 'class.canned.php';
require_once INCLUDE_DIR . 'class.organization.php';

class DomainGateEngine
{
    const NOTE_MARKER = 'Domain Gate';
    const ALLOW_COMMAND = 'DOMAIN-GATE-ALLOW';

    /** @var array */
    private static $handledTicketIds = array();

    /**
     * @param Ticket $ticket
     */
    public static function onTicketCreated($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return;
        }

        $conf = OsticketDomainGatePlugin::conf();
        if (!$conf || !self::configTruthy($conf->get('enabled'))) {
            return;
        }

        $id = (int) $ticket->getId();
        if ($id && isset(self::$handledTicketIds[$id])) {
            return;
        }
        if ($id) {
            self::$handledTicketIds[$id] = true;
        }

        $decision = self::evaluateTicket($ticket, $conf);
        if ($decision['allow']) {
            return;
        }

        self::blockTicket($ticket, $conf, $decision['reason']);
    }

    /**
     * Staff posts an internal note whose first line is DOMAIN-GATE-ALLOW.
     *
     * @param mixed $entry
     */
    public static function onThreadEntryCreated($entry)
    {
        if (!is_object($entry) || !method_exists($entry, 'getType')) {
            return;
        }
        // Internal notes only
        if ((string) $entry->getType() !== 'N') {
            return;
        }

        $body = '';
        if (method_exists($entry, 'getBody')) {
            $raw = $entry->getBody();
            $body = is_object($raw) ? (string) $raw : (string) $raw;
        }
        $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8'));
        $first = strtok($body, "\r\n");
        if ($first === false || strcasecmp(trim($first), self::ALLOW_COMMAND) !== 0) {
            return;
        }

        $conf = OsticketDomainGatePlugin::conf();
        if (!$conf || !self::configTruthy($conf->get('enabled'))) {
            return;
        }

        $ticket = null;
        if (method_exists($entry, 'getThread') && ($thread = $entry->getThread())) {
            if (method_exists($thread, 'getObject') && ($obj = $thread->getObject()) && $obj instanceof Ticket) {
                $ticket = $obj;
            } elseif (method_exists($thread, 'getTicket')) {
                $ticket = $thread->getTicket();
            }
        }
        if (!$ticket instanceof Ticket) {
            return;
        }

        $email = strtolower(trim((string) $ticket->getEmail()));
        $domain = self::emailDomain($email);
        if ($domain === '') {
            self::staffNote(
                $ticket,
                __('Domain Gate could not read a sender domain to allow.'),
                $conf
            );
            return;
        }

        if (self::appendExtraAllowDomain($domain)) {
            self::staffNote(
                $ticket,
                sprintf(
                    __('Domain Gate: allowed domain "%s" (saved to data/allowlist-extra.txt). Future new tickets from this domain will pass the allowlist.'),
                    $domain
                ),
                $conf
            );
        } else {
            self::staffNote(
                $ticket,
                sprintf(
                    __('Domain Gate: domain "%s" was already allowed, or the extras file could not be written.'),
                    $domain
                ),
                $conf
            );
        }
    }

    /**
     * @return array{allow:bool,reason:string}
     */
    public static function evaluateTicket(Ticket $ticket, $conf)
    {
        if (self::configTruthy($conf->get('skip_agents'))) {
            global $thisstaff;
            if ($thisstaff && $thisstaff instanceof Staff) {
                return array('allow' => true, 'reason' => 'agent');
            }
        }

        $source = method_exists($ticket, 'getSource') ? (string) $ticket->getSource() : '';
        $sourceNorm = strtolower(trim($source));
        $isApi = ($sourceNorm === 'api');
        $isWeb = ($sourceNorm === 'web');

        if ($isApi) {
            if (!self::configTruthy($conf->get('gate_api'))) {
                return array('allow' => true, 'reason' => 'api-channel-disabled');
            }
        } elseif ($isWeb) {
            if (!self::configTruthy($conf->get('gate_web'))) {
                return array('allow' => true, 'reason' => 'web-channel-disabled');
            }
        } else {
            // Email and any other/unknown source honour the email toggle
            if (!self::configTruthy($conf->get('gate_email'))) {
                return array('allow' => true, 'reason' => 'email-channel-disabled');
            }
        }

        $email = strtolower(trim((string) $ticket->getEmail()));
        $domain = self::emailDomain($email);
        $subject = method_exists($ticket, 'getSubject') ? (string) $ticket->getSubject() : '';
        $body = '';
        $thread = method_exists($ticket, 'getThread') ? $ticket->getThread() : null;
        if ($thread && method_exists($thread, 'getEntries')) {
            foreach ($thread->getEntries() as $entry) {
                if (method_exists($entry, 'getBody')) {
                    $raw = $entry->getBody();
                    $body .= ' ' . (is_object($raw) ? (string) $raw : (string) $raw);
                }
                break; // first entry is enough for refs
            }
        }

        return self::evaluateSender($email, $domain, $subject . ' ' . $body, $conf);
    }

    /**
     * Pure policy check (also used by smoke tests via reflection-free helpers).
     *
     * @return array{allow:bool,reason:string}
     */
    public static function evaluateSender($email, $domain, $haystack, $conf)
    {
        $email = strtolower(trim((string) $email));
        $domain = strtolower(trim((string) $domain));

        $denyEmails = self::parseEmailList($conf->get('deny_emails'));
        if ($email !== '' && isset($denyEmails[$email])) {
            return array('allow' => false, 'reason' => 'deny-email');
        }

        $allowEmails = self::parseEmailList($conf->get('allow_emails'));
        if ($email !== '' && isset($allowEmails[$email])) {
            return array('allow' => true, 'reason' => 'allow-email');
        }

        if (self::configTruthy($conf->get('honour_ticket_refs')) && self::haystackHasExistingTicketRef($haystack)) {
            return array('allow' => true, 'reason' => 'ticket-ref');
        }

        if (self::configTruthy($conf->get('honour_org_domains')) && $domain !== '' && self::organisationOwnsDomain($domain)) {
            return array('allow' => true, 'reason' => 'organisation-domain');
        }

        $mode = (string) $conf->get('mode');
        if ($mode === '') {
            $mode = 'allowlist';
        }

        $allowDomains = self::mergedAllowDomains($conf);
        $blockDomains = self::parseDomainList($conf->get('blocklist'));

        $onAllow = ($domain !== '' && isset($allowDomains[$domain]));
        $onBlock = ($domain !== '' && isset($blockDomains[$domain]));

        if ($mode === 'blocklist') {
            if ($onBlock) {
                return array('allow' => false, 'reason' => 'blocklist-domain');
            }
            return array('allow' => true, 'reason' => 'not-blocklisted');
        }

        if ($mode === 'both') {
            if (!$onAllow) {
                return array('allow' => false, 'reason' => 'not-allowlisted');
            }
            if ($onBlock) {
                return array('allow' => false, 'reason' => 'blocklist-domain');
            }
            return array('allow' => true, 'reason' => 'allowlist-and-not-blocklisted');
        }

        // allowlist (default)
        if ($onAllow) {
            return array('allow' => true, 'reason' => 'allowlist-domain');
        }
        return array('allow' => false, 'reason' => 'not-allowlisted');
    }

    /**
     * Static helpers for offline tests (no PluginConfig required).
     *
     * @param array $opts
     * @return array{allow:bool,reason:string}
     */
    public static function evaluateSenderArray($email, $domain, $haystack, array $opts)
    {
        $stub = new DomainGateConfigStub($opts);
        return self::evaluateSender($email, $domain, $haystack, $stub);
    }

    private static function blockTicket(Ticket $ticket, $conf, $reason)
    {
        $staff = self::loadStaff($conf);
        if (!$staff) {
            self::sysLog('Domain Gate: staff_username missing or invalid; cannot notify on block.');
            return;
        }

        global $thisstaff;
        $previous = isset($thisstaff) ? $thisstaff : null;
        $thisstaff = $staff;

        try {
            $cannedId = (int) $conf->get('response');
            $sent = false;
            if ($cannedId > 0) {
                $canned = Canned::lookup($cannedId);
                if ($canned) {
                    $threadId = method_exists($ticket, 'getThreadId') ? $ticket->getThreadId() : null;
                    $sent = (bool) $ticket->postCannedReply($canned, $threadId, true);
                }
            }

            if (!$sent) {
                $body = trim((string) $conf->get('fallback_body'));
                if ($body === '') {
                    $body = "Hello,\n\nThank you for contacting support.\n\nWe only accept new support requests from recognised organisation email addresses. Please resend your message from your work email address.\n\nKind regards,\nSupport\n";
                }
                $errors = array();
                $vars = array(
                    'response' => class_exists('TextThreadEntryBody')
                        ? new TextThreadEntryBody($body)
                        : $body,
                    'staffId'  => $staff->getId(),
                    'poster'   => $staff,
                );
                if (method_exists($ticket, 'postReply')) {
                    $sent = (bool) $ticket->postReply($vars, $errors, true, false);
                }
            }

            $statusId = (int) $conf->get('closed_status_id');
            if ($statusId > 0 && class_exists('TicketStatus')) {
                $status = TicketStatus::lookup($statusId);
                if ($status && method_exists($ticket, 'setStatus')) {
                    $cerr = array();
                    $ticket->setStatus($status, false, $cerr, null, false);
                } elseif (method_exists($ticket, 'setStatusId')) {
                    $ticket->setStatusId($statusId);
                    if (method_exists($ticket, 'save')) {
                        $ticket->save();
                    }
                }
            }

            $domain = self::emailDomain((string) $ticket->getEmail());
            $note = sprintf(
                "Domain Gate blocked this ticket.\nReason: %s\nDomain: %s\n\nTo permanently allow this domain for future tickets, post an internal note whose first line is exactly:\n%s\n",
                $reason,
                $domain !== '' ? $domain : '(none)',
                self::ALLOW_COMMAND
            );
            self::staffNote($ticket, $note, $conf, $staff);
        } finally {
            $thisstaff = $previous;
        }
    }

    private static function staffNote(Ticket $ticket, $text, $conf, $staff = null)
    {
        if (!$staff) {
            $staff = self::loadStaff($conf);
        }
        if (!$staff || !method_exists($ticket, 'postNote')) {
            return;
        }
        $errors = array();
        $vars = array(
            'note' => class_exists('TextThreadEntryBody')
                ? new TextThreadEntryBody($text)
                : $text,
        );
        $ticket->postNote($vars, $errors, $staff, false);
    }

    private static function loadStaff($conf)
    {
        $username = trim((string) $conf->get('staff_username'));
        if ($username === '') {
            return null;
        }
        $staff = Staff::lookup(array('username' => $username));
        if (!$staff || (method_exists($staff, 'isActive') && !$staff->isActive())) {
            return null;
        }
        return $staff;
    }

    public static function emailDomain($email)
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }
        $parts = explode('@', $email, 2);
        return isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    }

    public static function parseDomainList($text)
    {
        $out = array();
        foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $line = strtolower(ltrim($line, '@'));
            if ($line !== '') {
                $out[$line] = true;
            }
        }
        return $out;
    }

    public static function parseEmailList($text)
    {
        $out = array();
        foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $out[$line] = true;
        }
        return $out;
    }

    public static function validateDomainList($text)
    {
        foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $line = ltrim($line, '@');
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $line)) {
                return $line;
            }
        }
        return null;
    }

    private static function mergedAllowDomains($conf)
    {
        $base = self::parseDomainList($conf->get('allowlist'));
        $extraFile = OsticketDomainGatePlugin::extraAllowlistPath();
        if (is_file($extraFile)) {
            $extra = self::parseDomainList(file_get_contents($extraFile));
            foreach ($extra as $d => $_) {
                $base[$d] = true;
            }
        }
        return $base;
    }

    private static function appendExtraAllowDomain($domain)
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || self::validateDomainList($domain) !== null) {
            return false;
        }

        $path = OsticketDomainGatePlugin::extraAllowlistPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $existing = is_file($path) ? self::parseDomainList(file_get_contents($path)) : array();
        if (isset($existing[$domain])) {
            return false;
        }

        $line = $domain . "\n";
        return @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    private static function organisationOwnsDomain($domain)
    {
        if (!class_exists('Organization') || !method_exists('Organization', 'forDomain')) {
            return false;
        }
        try {
            $org = Organization::forDomain($domain);
            return (bool) $org;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function haystackHasExistingTicketRef($haystack)
    {
        $haystack = (string) $haystack;
        if ($haystack === '') {
            return false;
        }
        if (!preg_match_all('/(?:#|ticket\s*#?\s*)(\d{4,})\b/i', $haystack, $matches)) {
            return false;
        }
        foreach ($matches[1] as $number) {
            $number = trim((string) $number);
            if ($number === '') {
                continue;
            }
            if (method_exists('Ticket', 'getIdByNumber')) {
                $id = Ticket::getIdByNumber($number);
                if ($id) {
                    return true;
                }
            }
            if (Ticket::lookup(array('number' => $number))) {
                return true;
            }
        }
        return false;
    }

    private static function configTruthy($value)
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function sysLog($message)
    {
        if (isset($GLOBALS['ost']) && $GLOBALS['ost'] && method_exists($GLOBALS['ost'], 'logWarning')) {
            $GLOBALS['ost']->logWarning('Domain Gate', $message, false);
        }
    }
}

/**
 * Minimal config stub for offline unit-style checks.
 */
class DomainGateConfigStub
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }
}
