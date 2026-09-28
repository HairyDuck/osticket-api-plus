<?php
/**
 * HTTP API controller for osTicket API Plus.
 *
 * Extends ApiController so X-API-Key + IP checks match stock behaviour.
 * All routes live under /api/http.php/api-plus/... and never replace
 * stock POST /api/tickets.json.
 */

require_once INCLUDE_DIR . 'class.api.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.staff.php';
require_once INCLUDE_DIR . 'class.thread.php';
require_once INCLUDE_DIR . 'class.list.php';

class OsticketApiPlusController extends ApiController
{
    /**
     * GET /api-plus/tickets.json?email=
     */
    public function userListTickets()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireUserApiEnabled();

        $email = $this->queryEmail();
        $user = User::lookupByEmail($email);
        if (!$user) {
            return $this->json(200, array('tickets' => array(), 'count' => 0));
        }

        $qs = Ticket::objects()
            ->filter(array('user_id' => $user->getId()))
            ->order_by('-created');

        $tickets = array();
        foreach ($this->paginate($qs) as $ticket) {
            $tickets[] = $this->serializeTicket($ticket, false);
        }

        return $this->json(200, array(
            'tickets' => $tickets,
            'count'   => count($tickets),
            'email'   => $email,
        ));
    }

    /**
     * GET/POST /api-plus/tickets/{number}.json?email=
     */
    public function userTicket($number)
    {
        $this->requireApiKey();
        $this->requireUserApiEnabled();

        $email = $this->queryEmail();
        $ticket = $this->lookupTicketByNumber($number);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }

        if (!$this->ticketOwnedByEmail($ticket, $email)) {
            return $this->json(403, array('error' => 'Ticket does not belong to this email'));
        }

        if ($this->isPost()) {
            return $this->userReply($ticket, $email);
        }

        $this->requireGet();
        return $this->json(200, $this->serializeTicket($ticket, true));
    }

    /**
     * GET /api-plus/staff/tickets.json?status=open|closed|all
     */
    public function staffListTickets()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $status = strtolower(trim(isset($_GET['status']) ? $_GET['status'] : 'open'));
        $qs = Ticket::objects()->order_by('-created');

        if ($status === 'open') {
            $qs->filter(array('status__state' => 'open'));
        } elseif ($status === 'closed') {
            $qs->filter(array('status__state' => 'closed'));
        } elseif ($status !== 'all') {
            return $this->json(400, array('error' => 'status must be open, closed, or all'));
        }

        if (!empty($_GET['email'])) {
            $email = strtolower(trim($_GET['email']));
            $user = User::lookupByEmail($email);
            if (!$user) {
                return $this->json(200, array('tickets' => array(), 'count' => 0, 'status' => $status));
            }
            $qs->filter(array('user_id' => $user->getId()));
        }

        $tickets = array();
        foreach ($this->paginate($qs) as $ticket) {
            $tickets[] = $this->serializeTicket($ticket, false);
        }

        return $this->json(200, array(
            'tickets' => $tickets,
            'count'   => count($tickets),
            'status'  => $status,
        ));
    }

    /**
     * GET /api-plus/staff/tickets/{id}.json
     */
    public function staffGetTicket($id)
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $ticket = Ticket::lookup((int) $id);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }

        return $this->json(200, $this->serializeTicket($ticket, true));
    }

    /**
     * POST /api-plus/staff/tickets/{id}/reply.json
     * Body JSON: { "message": "...", "status": "closed"|status_id, "alert": true }
     */
    public function staffReply($id)
    {
        $this->requirePost();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $staff = $this->loadConfiguredStaff();

        $ticket = Ticket::lookup((int) $id);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }

        $data = $this->readJsonBody();
        $message = isset($data['message']) ? trim((string) $data['message']) : '';
        if ($message === '') {
            return $this->json(400, array('error' => 'message is required'));
        }

        $alert = !isset($data['alert']) || (bool) $data['alert'];
        $errors = array();
        $vars = array(
            'response' => new TextThreadEntryBody($message),
            'staffId'  => $staff->getId(),
            'poster'   => $staff,
        );

        if (isset($data['status']) && $data['status'] !== '' && $data['status'] !== null) {
            $statusId = $this->resolveStatusId($data['status']);
            if (!$statusId) {
                return $this->json(400, array('error' => 'Unknown status'));
            }
            $vars['reply_status_id'] = $statusId;
        }

        global $thisstaff;
        $previousStaff = isset($thisstaff) ? $thisstaff : null;
        $thisstaff = $staff;

        try {
            $response = $ticket->postReply($vars, $errors, $alert, false);
        } finally {
            $thisstaff = $previousStaff;
        }

        if (!$response || $errors) {
            return $this->json(400, array(
                'error'  => 'Unable to post reply',
                'detail' => $errors,
            ));
        }

        return $this->json(200, array(
            'ok'      => true,
            'ticket'  => $this->serializeTicket(Ticket::lookup((int) $id), false),
            'entry_id'=> $response->getId(),
        ));
    }

    /**
     * POST /api-plus/staff/tickets/{id}/status.json
     * Body JSON: { "status": "closed"|status_id, "comments": "optional" }
     */
    public function staffStatus($id)
    {
        $this->requirePost();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $staff = $this->loadConfiguredStaff();

        $ticket = Ticket::lookup((int) $id);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }

        $data = $this->readJsonBody();
        if (!isset($data['status']) || $data['status'] === '') {
            return $this->json(400, array('error' => 'status is required'));
        }

        $statusId = $this->resolveStatusId($data['status']);
        if (!$statusId) {
            return $this->json(400, array('error' => 'Unknown status'));
        }

        $status = TicketStatus::lookup($statusId);
        if (!$status) {
            return $this->json(400, array('error' => 'Unknown status'));
        }

        $comments = isset($data['comments']) ? (string) $data['comments'] : '';
        $errors = array();

        global $thisstaff;
        $previousStaff = isset($thisstaff) ? $thisstaff : null;
        $thisstaff = $staff;

        try {
            $ok = $ticket->setStatus($status, $comments, $errors, true, true);
        } finally {
            $thisstaff = $previousStaff;
        }

        if (!$ok || $errors) {
            return $this->json(400, array(
                'error'  => 'Unable to set status',
                'detail' => $errors,
            ));
        }

        return $this->json(200, array(
            'ok'     => true,
            'ticket' => $this->serializeTicket(Ticket::lookup((int) $id), false),
        ));
    }

    /**
     * POST /api-plus/staff/tickets/{id}/note.json
     * Body JSON: { "note": "...", "alert": false }
     */
    public function staffNote($id)
    {
        $this->requirePost();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $staff = $this->loadConfiguredStaff();

        $ticket = Ticket::lookup((int) $id);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }

        $data = $this->readJsonBody();
        $note = isset($data['note']) ? trim((string) $data['note']) : '';
        if ($note === '') {
            return $this->json(400, array('error' => 'note is required'));
        }

        $alert = !empty($data['alert']);
        $errors = array();
        $vars = array(
            'note'    => new TextThreadEntryBody($note),
            'staffId' => $staff->getId(),
        );

        global $thisstaff;
        $previousStaff = isset($thisstaff) ? $thisstaff : null;
        $thisstaff = $staff;

        try {
            $entry = $ticket->postNote($vars, $errors, $staff, $alert);
        } finally {
            $thisstaff = $previousStaff;
        }

        if (!$entry || $errors) {
            return $this->json(400, array(
                'error'  => 'Unable to post note',
                'detail' => $errors,
            ));
        }

        return $this->json(200, array(
            'ok'       => true,
            'entry_id' => $entry->getId(),
            'ticket'   => $this->serializeTicket(Ticket::lookup((int) $id), false),
        ));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function userReply(Ticket $ticket, $email)
    {
        $this->requirePost();

        $data = $this->readJsonBody();
        $message = isset($data['message']) ? trim((string) $data['message']) : '';
        if ($message === '') {
            return $this->json(400, array('error' => 'message is required'));
        }

        $user = User::lookupByEmail($email);
        if (!$user) {
            return $this->json(400, array('error' => 'User not found for email'));
        }

        $errors = array();
        $vars = array(
            'userId'  => $user->getId(),
            'message' => new TextThreadEntryBody($message),
            'poster'  => (string) $user->getName(),
        );

        $entry = $ticket->postMessage($vars, 'API');
        if (!$entry) {
            return $this->json(400, array(
                'error'  => 'Unable to post message',
                'detail' => $errors,
            ));
        }

        return $this->json(200, array(
            'ok'       => true,
            'entry_id' => $entry->getId(),
            'ticket'   => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
        ));
    }

    private function requireUserApiEnabled()
    {
        if (!$this->configEnabled('enable_user_api')) {
            $this->json(403, array('error' => 'User API is disabled'));
        }
    }

    private function requireStaffApiEnabled()
    {
        if (!$this->configEnabled('enable_staff_api')) {
            $this->json(403, array('error' => 'Staff API is disabled'));
        }
    }

    /**
     * PluginConfig booleans are often stored as "0"/"1" strings.
     */
    private function configEnabled($key)
    {
        $conf = OsticketApiPlusPlugin::conf();
        if (!$conf) {
            return false;
        }
        $value = $conf->get($key);
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return Staff
     */
    private function loadConfiguredStaff()
    {
        $conf = OsticketApiPlusPlugin::conf();
        $username = $conf ? trim((string) $conf->get('staff_username')) : '';
        if ($username === '') {
            $this->json(500, array('error' => 'staff_username is not configured'));
        }

        $staff = Staff::lookup(array('username' => $username));
        if (!$staff || !$staff->isActive()) {
            $this->json(500, array('error' => 'Configured staff account is missing or inactive'));
        }

        return $staff;
    }

    private function queryEmail()
    {
        $email = '';
        if (isset($_GET['email'])) {
            $email = trim((string) $_GET['email']);
        }
        if ($email === '' && $this->isPost()) {
            $data = $this->readJsonBody(false);
            if (!empty($data['email'])) {
                $email = trim((string) $data['email']);
            }
        }
        $email = strtolower($email);
        if ($email === '' || !Validator::is_email($email)) {
            $this->json(400, array('error' => 'Valid email query parameter is required'));
        }

        return $email;
    }

    private function ticketOwnedByEmail(Ticket $ticket, $email)
    {
        $ownerEmail = strtolower(trim((string) $ticket->getEmail()));
        return $ownerEmail !== '' && strcasecmp($ownerEmail, $email) === 0;
    }

    private function lookupTicketByNumber($number)
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }

        $id = Ticket::getIdByNumber($number);
        if (!$id) {
            return null;
        }

        return Ticket::lookup($id);
    }

    /**
     * @param QuerySet $qs
     * @return array|Traversable
     */
    private function paginate($qs)
    {
        $conf = OsticketApiPlusPlugin::conf();
        $default = $conf ? (int) $conf->get('default_list_limit') : 25;
        if ($default < 1) {
            $default = 25;
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $default;
        $limit = max(1, min(100, $limit));
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        $qs->limit($limit);
        if ($offset) {
            $qs->offset($offset);
        }

        return $qs->all();
    }

    private function resolveStatusId($status)
    {
        if (is_numeric($status)) {
            $s = TicketStatus::lookup((int) $status);
            return $s ? $s->getId() : null;
        }

        $name = strtolower(trim((string) $status));
        if ($name === '') {
            return null;
        }

        // Prefer state match for open/closed, else name match
        foreach (TicketStatusList::getStatuses() as $s) {
            if (strtolower($s->getState()) === $name) {
                return $s->getId();
            }
        }
        foreach (TicketStatusList::getStatuses() as $s) {
            if (strtolower($s->getName()) === $name) {
                return $s->getId();
            }
        }

        return null;
    }

    private function serializeTicket(Ticket $ticket, $withThread)
    {
        $status = $ticket->getStatus();
        $data = array(
            'id'         => (int) $ticket->getId(),
            'number'     => (string) $ticket->getNumber(),
            'subject'    => (string) $ticket->getSubject(),
            'status'     => $status ? (string) $status->getName() : null,
            'status_id'  => (int) $ticket->getStatusId(),
            'state'      => $status ? (string) $status->getState() : null,
            'email'      => (string) $ticket->getEmail(),
            'name'       => $ticket->getName() ? (string) $ticket->getName() : null,
            'created'    => (string) $ticket->getCreateDate(),
            'updated'    => (string) $ticket->getUpdateDate(),
            'dept_id'    => (int) $ticket->getDeptId(),
            'priority_id'=> method_exists($ticket, 'getPriorityId') ? (int) $ticket->getPriorityId() : null,
        );

        if ($withThread) {
            $data['thread'] = array();
            $thread = $ticket->getThread();
            if ($thread && ($entries = $thread->getEntries())) {
                foreach ($entries as $entry) {
                    $body = $entry->getBody();
                    $text = is_object($body) ? (string) $body : (string) $body;
                    $data['thread'][] = array(
                        'id'      => (int) $entry->getId(),
                        'type'    => (string) $entry->getType(),
                        'poster'  => (string) $entry->getPoster(),
                        'body'    => $text,
                        'created' => (string) $entry->getCreateDate(),
                    );
                }
            }
        }

        return $data;
    }

    private function readJsonBody($required = true)
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            if ($required) {
                $this->json(400, array('error' => 'JSON body required'));
            }
            return array();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->json(400, array('error' => 'Invalid JSON body'));
        }

        return $data;
    }

    private function isPost()
    {
        return strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') === 0;
    }

    private function isGet()
    {
        return strcasecmp($_SERVER['REQUEST_METHOD'], 'GET') === 0;
    }

    private function requirePost()
    {
        if (!$this->isPost()) {
            $this->json(405, array('error' => 'POST required'));
        }
    }

    private function requireGet()
    {
        if (!$this->isGet()) {
            $this->json(405, array('error' => 'GET required'));
        }
    }

    private function json($code, $payload)
    {
        Http::response($code, json_encode($payload), 'application/json');
        exit();
    }
}
