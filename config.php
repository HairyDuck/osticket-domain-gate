<?php
/**
 * Admin configuration for osTicket Domain Gate.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.canned.php';

class OsticketDomainGateConfig extends PluginConfig
{
    public function getOptions()
    {
        if (!class_exists('Staff')) {
            require_once INCLUDE_DIR . 'class.staff.php';
        }

        $responses = array('0' => __('Use fallback text below'));
        if (class_exists('Canned') && method_exists('Canned', 'getCannedResponses')) {
            foreach (Canned::getCannedResponses() as $id => $title) {
                $responses[(string) $id] = $title;
            }
        }

        $staffChoices = array('' => __('— Select agent —'));
        if (class_exists('Staff')) {
            foreach (Staff::objects() as $s) {
                $uname = self::staffUsername($s);
                if ($uname === '') {
                    continue;
                }
                $label = method_exists($s, 'getName') ? (string) $s->getName() : $uname;
                $staffChoices[$uname] = sprintf('%s (%s)', $label, $uname);
            }
        }

        $statuses = array();
        if (class_exists('TicketStatus')) {
            foreach (TicketStatus::objects()->values_flat('id', 'name') as $row) {
                list($id, $name) = $row;
                $statuses[(string) $id] = $name;
            }
        }
        if (!$statuses) {
            $statuses = array('3' => __('Closed'));
        }

        $defaultBlock = implode("\n", array(
            'gmail.com',
            'googlemail.com',
            'outlook.com',
            'hotmail.com',
            'live.com',
            'msn.com',
            'yahoo.com',
            'yahoo.co.uk',
            'icloud.com',
            'me.com',
            'mac.com',
            'aol.com',
            'proton.me',
            'protonmail.com',
            'pm.me',
            'gmx.com',
            'gmx.co.uk',
            'mail.com',
            'yandex.com',
            'zoho.com',
        ));

        return array(
            // --- Essentials ---
            'sec_essentials' => new SectionBreakField(array(
                'label' => __('1. Essentials'),
                'hint'  => __('Turn the gate on and choose who sends rejection replies.'),
            )),
            'enabled' => new BooleanField(array(
                'label'   => __('Enable Domain Gate'),
                'default' => true,
                'hint'    => __('Master switch. Leave the plugin installed but disable here if needed.'),
            )),
            'staff_username' => new ChoiceField(array(
                'label'   => __('Reply as agent'),
                'default' => '',
                'choices' => $staffChoices,
                'hint'    => __('Required when enabled. Author for rejection replies and Domain Gate notes.'),
            )),
            'mode' => new ChoiceField(array(
                'label'   => __('Policy mode'),
                'default' => 'allowlist',
                'choices' => array(
                    'allowlist' => __('Allowlist only (recommended)'),
                    'blocklist' => __('Blocklist only (free-mail style)'),
                    'both'      => __('Both (must be allowlisted and not blocklisted)'),
                ),
                'hint' => __('Allowlist: only listed / org domains may open tickets. Blocklist: only listed free-mail domains are blocked.'),
            )),

            // --- Domains ---
            'sec_domains' => new SectionBreakField(array(
                'label' => __('2. Domains'),
                'hint'  => __('One domain per line, no @. Lines starting with # are comments.'),
            )),
            'allowlist' => new TextareaField(array(
                'label'   => __('Allowlist domains'),
                'default' => "example.com\n",
                'hint'    => __('Used in Allowlist and Both modes. Any mailbox on these domains is allowed. Staff DOMAIN-GATE-ALLOW appends to data/allowlist-extra.txt.'),
                'configuration' => array('html' => false, 'rows' => 8, 'cols' => 40),
            )),
            'blocklist' => new TextareaField(array(
                'label'   => __('Blocklist domains'),
                'default' => $defaultBlock,
                'hint'    => __('Used in Blocklist and Both modes only. Ignored when mode is Allowlist only.'),
                'configuration' => array('html' => false, 'rows' => 6, 'cols' => 40),
            )),
            'honour_org_domains' => new BooleanField(array(
                'label'   => __('Also allow Organisation domains'),
                'default' => true,
                'hint'    => __('If the sender domain is mapped on an osTicket Organisation, allow even when not in the text allowlist.'),
            )),
            'honour_ticket_refs' => new BooleanField(array(
                'label'   => __('Allow mail that references an existing ticket'),
                'default' => true,
                'hint'    => __('If subject/body contains an existing ticket number (e.g. #23836), allow (reply-style mail).'),
            )),
            'skip_agents' => new BooleanField(array(
                'label'   => __('Never gate staff / agents'),
                'default' => true,
                'hint'    => __('Skip gating when the creator is an authenticated agent.'),
            )),

            // --- Rejection reply ---
            'sec_reply' => new SectionBreakField(array(
                'label' => __('3. Rejection reply'),
                'hint'  => __('Sent when a new ticket is blocked, then the ticket is closed.'),
            )),
            'response' => new ChoiceField(array(
                'label'   => __('Canned rejection response'),
                'default' => '0',
                'choices' => $responses,
                'hint'    => __('Pick a Knowledgebase canned reply, or use the fallback text. Save to refresh the preview below.'),
            )),
            'response_preview' => new FreeTextField(array(
                'label' => __('Canned response preview'),
                'configuration' => array(
                    'content' => $this->buildResponsePreviewHtml(),
                ),
            )),
            'fallback_body' => new TextareaField(array(
                'label'   => __('Fallback rejection body'),
                'default' => "Hello,\n\nThank you for contacting support.\n\nWe only accept new support requests from recognised organisation email addresses. Please resend your message from your work email address.\n\nKind regards,\nSupport\n",
                'hint'    => __('Used when no canned response is selected, or if the canned reply fails to post.'),
                'configuration' => array('html' => false, 'rows' => 6, 'cols' => 40),
            )),
            'closed_status_id' => new ChoiceField(array(
                'label'   => __('Close blocked tickets as'),
                'default' => '3',
                'choices' => $statuses,
                'hint'    => __('Status applied after the rejection reply is posted.'),
            )),

            // --- Channels ---
            'sec_channels' => new SectionBreakField(array(
                'label' => __('4. Channels'),
                'hint'  => __('Choose which ticket sources Domain Gate applies to.'),
            )),
            'gate_email' => new BooleanField(array(
                'label'   => __('Gate Email'),
                'default' => true,
                'hint'    => __('Apply policy to tickets created from email (recommended).'),
            )),
            'gate_web' => new BooleanField(array(
                'label'   => __('Gate Web Forms'),
                'default' => false,
                'hint'    => __('Apply policy to client portal / web form tickets.'),
            )),
            'gate_api' => new BooleanField(array(
                'label'   => __('Gate API'),
                'default' => false,
                'hint'    => __('Apply policy to API-created tickets.'),
            )),

            // --- Advanced overrides ---
            'sec_overrides' => new SectionBreakField(array(
                'label' => __('5. Address overrides (optional)'),
                'hint'  => __('Full email addresses, one per line. These override domain rules.'),
            )),
            'allow_emails' => new TextareaField(array(
                'label'   => __('Always allow these emails'),
                'default' => '',
                'hint'    => __('e.g. vip@gmail.com'),
                'configuration' => array('html' => false, 'rows' => 3, 'cols' => 40),
            )),
            'deny_emails' => new TextareaField(array(
                'label'   => __('Always deny these emails'),
                'default' => '',
                'hint'    => __('Checked before allow-email and domain rules.'),
                'configuration' => array('html' => false, 'rows' => 3, 'cols' => 40),
            )),
        );
    }

    /**
     * Static HTML preview of the currently saved canned (or fallback) reply.
     */
    private function buildResponsePreviewHtml()
    {
        $note = '<p style="margin:0 0 8px;color:#666"><em>'
            . __('Preview of the currently saved selection. Change the canned reply above, then Save to refresh.')
            . '</em></p>';

        $rid = (int) $this->get('response');
        if ($rid > 0 && class_exists('Canned')) {
            $canned = Canned::lookup($rid);
            if ($canned) {
                $title = method_exists('Format', 'htmlchars')
                    ? Format::htmlchars($canned->getTitle())
                    : htmlspecialchars($canned->getTitle(), ENT_QUOTES, 'UTF-8');
                $body = (string) $canned->getResponse();
                return $note
                    . '<p style="margin:0 0 6px"><strong>' . $title . '</strong></p>'
                    . '<div style="border:1px solid #ccc;padding:10px;background:#fafafa;max-height:260px;overflow:auto">'
                    . $body
                    . '</div>';
            }

            return $note . '<p><em>' . __('Selected canned response was not found.') . '</em></p>';
        }

        $fallback = (string) $this->get('fallback_body');
        if ($fallback === '') {
            $fallback = __('(No fallback text configured yet.)');
        }
        $plain = method_exists('Format', 'htmlchars')
            ? Format::htmlchars($fallback)
            : htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');

        return $note
            . '<p style="margin:0 0 6px"><em>' . __('Using fallback text:') . '</em></p>'
            . '<pre style="border:1px solid #ccc;padding:10px;background:#fafafa;max-height:260px;overflow:auto;white-space:pre-wrap;margin:0">'
            . $plain
            . '</pre>';
    }

    /**
     * @param Staff $staff
     * @return string
     */
    private static function staffUsername($staff)
    {
        if (method_exists($staff, 'getUserName')) {
            return trim((string) $staff->getUserName());
        }
        if (method_exists($staff, 'getUsername')) {
            return trim((string) $staff->getUsername());
        }
        if (isset($staff->username)) {
            return trim((string) $staff->username);
        }
        return '';
    }

    public function pre_save(&$config, &$errors)
    {
        global $msg;

        // Presentation-only keys must not be persisted.
        unset(
            $config['sec_essentials'],
            $config['sec_domains'],
            $config['sec_reply'],
            $config['sec_channels'],
            $config['sec_overrides'],
            $config['response_preview']
        );

        if (!empty($config['staff_username'])) {
            if (!class_exists('Staff')) {
                require_once INCLUDE_DIR . 'class.staff.php';
            }
            $staff = Staff::lookup(array('username' => trim($config['staff_username'])));
            if (!$staff) {
                $errors['err'] = __('Selected agent username was not found.');
                return false;
            }
        }

        if (!empty($config['enabled']) && empty($config['staff_username'])) {
            $errors['err'] = __('Domain Gate requires an agent for rejection replies.');
            return false;
        }

        foreach (array('allowlist', 'blocklist') as $key) {
            if (!isset($config[$key])) {
                continue;
            }
            $bad = OsticketDomainGatePlugin::validateDomainList((string) $config[$key]);
            if ($bad) {
                $errors['err'] = sprintf(__('Invalid domain in %s: %s'), $key, $bad);
                return false;
            }
        }

        if (isset($config['closed_status_id']) && $config['closed_status_id'] !== ''
            && class_exists('TicketStatus')
        ) {
            $st = TicketStatus::lookup((int) $config['closed_status_id']);
            if (!$st) {
                $errors['err'] = __('Selected closed status was not found.');
                return false;
            }
        }

        if (!$errors) {
            $msg = __('Configuration updated successfully');
        }

        return true;
    }
}
