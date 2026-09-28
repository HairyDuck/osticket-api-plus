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
require_once INCLUDE_DIR . 'class.dept.php';
require_once INCLUDE_DIR . 'class.team.php';
require_once INCLUDE_DIR . 'class.canned.php';
require_once INCLUDE_DIR . 'class.topic.php';
require_once INCLUDE_DIR . 'class.priority.php';

class OsticketApiPlusController extends ApiController
{
    /**
     * GET /api-plus/health.json
     */
    public function health()
    {
        $this->requireGet();
        $this->requireApiKey();

        $meta = include __DIR__ . '/plugin.php';
        $payload = array(
            'ok'       => true,
            'plugin'   => 'osticket-api-plus',
            'version'  => isset($meta['version']) ? $meta['version'] : null,
            'user_api' => $this->configEnabled('enable_user_api'),
            'staff_api'=> $this->configEnabled('enable_staff_api'),
        );

        if (defined('THIS_VERSION')) {
            $payload['osticket'] = THIS_VERSION;
        } elseif (isset($GLOBALS['ost']) && method_exists($GLOBALS['ost'], 'getVersion')) {
            $payload['osticket'] = $GLOBALS['ost']->getVersion();
        }

        return $this->json(200, $payload);
    }

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
            return $this->json(200, $this->emptyListPayload(array('email' => $email)));
        }

        $qs = Ticket::objects()
            ->filter(array('user_id' => $user->getId()))
            ->order_by('-created');

        $status = strtolower(trim(isset($_GET['status']) ? $_GET['status'] : 'all'));
        $statusErr = $this->applyStateFilter($qs, $status);
        if ($statusErr) {
            return $this->json(400, array('error' => $statusErr));
        }

        list($tickets, $page) = $this->paginateList($qs);

        return $this->json(200, array_merge(array(
            'tickets' => $tickets,
            'email'   => $email,
            'status'  => $status,
        ), $page));
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
     * GET /api-plus/staff/tickets.json
     */
    public function staffListTickets()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $qs = Ticket::objects()->order_by('-created');

        $hasStatusId = isset($_GET['status_id']) && $_GET['status_id'] !== '';
        $hasStatusName = isset($_GET['status_name']) && trim((string) $_GET['status_name']) !== '';

        if ($hasStatusId && $hasStatusName) {
            return $this->json(400, array('error' => 'Use status_id or status_name, not both'));
        }

        $status = 'all';
        if ($hasStatusId) {
            $sid = (int) $_GET['status_id'];
            if (!TicketStatus::lookup($sid)) {
                return $this->json(400, array('error' => 'Unknown status_id'));
            }
            $qs->filter(array('status_id' => $sid));
            $status = 'status_id:' . $sid;
        } elseif ($hasStatusName) {
            $sid = $this->resolveStatusId($_GET['status_name']);
            if (!$sid) {
                return $this->json(400, array('error' => 'Unknown status_name'));
            }
            $qs->filter(array('status_id' => $sid));
            $status = 'status_name:' . trim((string) $_GET['status_name']);
        } else {
            $status = strtolower(trim(isset($_GET['status']) ? $_GET['status'] : 'open'));
            $statusErr = $this->applyStateFilter($qs, $status);
            if ($statusErr) {
                return $this->json(400, array('error' => $statusErr));
            }
        }

        if (!empty($_GET['email'])) {
            $email = strtolower(trim($_GET['email']));
            $user = User::lookupByEmail($email);
            if (!$user) {
                return $this->json(200, $this->emptyListPayload(array('status' => $status)));
            }
            $qs->filter(array('user_id' => $user->getId()));
        }

        if (isset($_GET['dept_id']) && $_GET['dept_id'] !== '') {
            $qs->filter(array('dept_id' => (int) $_GET['dept_id']));
        }

        if (isset($_GET['topic_id']) && $_GET['topic_id'] !== '') {
            $qs->filter(array('topic_id' => (int) $_GET['topic_id']));
        }

        if (isset($_GET['priority_id']) && $_GET['priority_id'] !== '') {
            $qs->filter(array('cdata__priority' => (int) $_GET['priority_id']));
        }

        if (!empty($_GET['updated_since'])) {
            $since = $this->parseTimestamp($_GET['updated_since']);
            if (!$since) {
                return $this->json(400, array('error' => 'updated_since must be ISO-8601, Y-m-d H:i:s, or unix timestamp'));
            }
            $qs->filter(array('lastupdate__gte' => $since));
        }

        list($tickets, $page) = $this->paginateList($qs);

        return $this->json(200, array_merge(array(
            'tickets' => $tickets,
            'status'  => $status,
        ), $page));
    }

    public function staffGetTicket($id)
    {
        return $this->staffGetTicketResolved($this->requireStaffTicketById($id));
    }

    public function staffGetTicketByNumber($number)
    {
        return $this->staffGetTicketResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffGetTicketResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }
        $this->requireGet();
        return $this->json(200, $this->serializeTicket($ticket, true));
    }

    public function staffReply($id)
    {
        return $this->staffReplyResolved($this->requireStaffTicketById($id));
    }

    public function staffReplyByNumber($number)
    {
        return $this->staffReplyResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffReplyResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $staff = $this->loadConfiguredStaff();

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

        return $this->withStaff($staff, function () use ($ticket, $vars, &$errors, $alert) {
            $response = $ticket->postReply($vars, $errors, $alert, false);
            if (!$response || $errors) {
                return $this->json(400, array(
                    'error'  => 'Unable to post reply',
                    'detail' => $errors,
                ));
            }

            return $this->json(200, array(
                'ok'       => true,
                'ticket'   => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
                'entry_id' => $response->getId(),
            ));
        });
    }

    public function staffStatus($id)
    {
        return $this->staffStatusResolved($this->requireStaffTicketById($id));
    }

    public function staffStatusByNumber($number)
    {
        return $this->staffStatusResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffStatusResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $staff = $this->loadConfiguredStaff();

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

        return $this->withStaff($staff, function () use ($ticket, $status, $comments, &$errors) {
            $ok = $ticket->setStatus($status, $comments, $errors, true, true);
            if (!$ok || $errors) {
                return $this->json(400, array(
                    'error'  => 'Unable to set status',
                    'detail' => $errors,
                ));
            }

            return $this->json(200, array(
                'ok'     => true,
                'ticket' => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
            ));
        });
    }

    public function staffNote($id)
    {
        return $this->staffNoteResolved($this->requireStaffTicketById($id));
    }

    public function staffNoteByNumber($number)
    {
        return $this->staffNoteResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffNoteResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $staff = $this->loadConfiguredStaff();

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

        return $this->withStaff($staff, function () use ($ticket, $vars, &$errors, $staff, $alert) {
            $entry = $ticket->postNote($vars, $errors, $staff, $alert);
            if (!$entry || $errors) {
                return $this->json(400, array(
                    'error'  => 'Unable to post note',
                    'detail' => $errors,
                ));
            }

            return $this->json(200, array(
                'ok'       => true,
                'entry_id' => $entry->getId(),
                'ticket'   => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
            ));
        });
    }

    public function staffAssign($id)
    {
        return $this->staffAssignResolved($this->requireStaffTicketById($id));
    }

    public function staffAssignByNumber($number)
    {
        return $this->staffAssignResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffAssignResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $actor = $this->loadConfiguredStaff();
        $data = $this->readJsonBody();

        $claim = !empty($data['claim']);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : 0;
        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : 0;
        $deptId = isset($data['dept_id']) ? (int) $data['dept_id'] : 0;
        $note = isset($data['note']) ? trim((string) $data['note']) : '';
        $alert = !isset($data['alert']) || (bool) $data['alert'];

        if (!$claim && !$staffId && !$teamId && !$deptId) {
            return $this->json(400, array(
                'error' => 'Provide claim=true, staff_id, team_id, and/or dept_id',
            ));
        }

        if ($claim && ($staffId || $teamId)) {
            return $this->json(400, array(
                'error' => 'claim cannot be combined with staff_id or team_id',
            ));
        }

        return $this->withStaff($actor, function () use (
            $ticket, $actor, $claim, $staffId, $teamId, $deptId, $note, $alert
        ) {
            if ($deptId) {
                if (!Dept::lookup($deptId)) {
                    return $this->json(400, array('error' => 'Unknown dept_id'));
                }
                if ($deptId !== (int) $ticket->getDeptId()) {
                    if (!$ticket->setDeptId($deptId)) {
                        return $this->json(400, array('error' => 'Unable to set department'));
                    }
                }
            }

            if ($claim) {
                if (!$ticket->assignToStaff($actor, $note, $alert)) {
                    return $this->json(400, array('error' => 'Unable to claim ticket'));
                }
            } elseif ($staffId) {
                $assignee = Staff::lookup($staffId);
                if (!$assignee || !$assignee->isAvailable()) {
                    return $this->json(400, array('error' => 'staff_id is missing or unavailable'));
                }
                if (!$ticket->assignToStaff($assignee, $note, $alert)) {
                    return $this->json(400, array('error' => 'Unable to assign to staff'));
                }
            } elseif ($teamId) {
                $team = Team::lookup($teamId);
                if (!$team || !$team->isActive()) {
                    return $this->json(400, array('error' => 'team_id is missing or inactive'));
                }
                if (!$ticket->assignToTeam($team, $note, $alert)) {
                    return $this->json(400, array('error' => 'Unable to assign to team'));
                }
            }

            return $this->json(200, array(
                'ok'     => true,
                'ticket' => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
            ));
        });
    }

    public function staffPriority($id)
    {
        return $this->staffPriorityResolved($this->requireStaffTicketById($id));
    }

    public function staffPriorityByNumber($number)
    {
        return $this->staffPriorityResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffPriorityResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $staff = $this->loadConfiguredStaff();
        $data = $this->readJsonBody();

        $raw = null;
        if (isset($data['priority_id']) && $data['priority_id'] !== '') {
            $raw = $data['priority_id'];
        } elseif (isset($data['priority']) && $data['priority'] !== '') {
            $raw = $data['priority'];
        } else {
            return $this->json(400, array('error' => 'priority_id is required'));
        }

        $priority = $this->resolvePriority($raw);
        if (!$priority) {
            return $this->json(400, array('error' => 'Unknown priority'));
        }

        return $this->withStaff($staff, function () use ($ticket, $priority) {
            $answer = $ticket->getAnswer('priority');
            if (!$answer) {
                return $this->json(400, array('error' => 'Priority field is not available on this ticket'));
            }

            $oldId = (int) $ticket->getPriorityId();
            $newId = (int) $priority->getId();
            if ($oldId === $newId) {
                return $this->json(200, array(
                    'ok'     => true,
                    'ticket' => $this->serializeTicket($ticket, false),
                ));
            }

            // Priority lives on dynamic form answers (value + value_id), not a ticket column.
            $answer->set('value', $priority->getDesc());
            $answer->set('value_id', $newId);
            if (!$answer->save(true)) {
                return $this->json(400, array('error' => 'Unable to update priority'));
            }

            if (class_exists('TicketForm') && method_exists('TicketForm', 'updateDynamicDataView')) {
                TicketForm::updateDynamicDataView($answer, array());
            }

            $ticket->lastupdate = SqlFunction::NOW();
            $ticket->save();
            $ticket->logEvent('edited', array(
                'fields' => array('priority' => array($oldId, $newId)),
            ));

            // Reload so serialize reflects the new answer (avoid stale in-memory forms).
            $fresh = Ticket::lookup($ticket->getId());
            if ((int) $fresh->getPriorityId() !== $newId) {
                return $this->json(400, array('error' => 'Priority save did not persist'));
            }

            return $this->json(200, array(
                'ok'     => true,
                'ticket' => $this->serializeTicket($fresh, false),
            ));
        });
    }

    public function staffTopic($id)
    {
        return $this->staffTopicResolved($this->requireStaffTicketById($id));
    }

    public function staffTopicByNumber($number)
    {
        return $this->staffTopicResolved($this->requireStaffTicketByNumber($number));
    }

    private function staffTopicResolved($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return $ticket;
        }

        $this->requirePost();
        $staff = $this->loadConfiguredStaff();
        $data = $this->readJsonBody();

        if (!isset($data['topic_id']) || $data['topic_id'] === '') {
            return $this->json(400, array('error' => 'topic_id is required'));
        }

        $topic = Topic::lookup((int) $data['topic_id']);
        if (!$topic) {
            return $this->json(400, array('error' => 'Unknown topic_id'));
        }
        if (method_exists($topic, 'isActive') && !$topic->isActive()) {
            return $this->json(400, array('error' => 'Topic is not active'));
        }

        return $this->withStaff($staff, function () use ($ticket, $topic) {
            $old = (int) $ticket->getTopicId();
            $new = (int) $topic->getId();
            if ($old === $new) {
                return $this->json(200, array(
                    'ok'     => true,
                    'ticket' => $this->serializeTicket($ticket, false),
                ));
            }

            $ticket->topic_id = $new;
            if (!$ticket->save()) {
                return $this->json(400, array('error' => 'Unable to update topic'));
            }

            $ticket->logEvent('edited', array(
                'topic_id' => array($old, $new),
            ));

            return $this->json(200, array(
                'ok'     => true,
                'ticket' => $this->serializeTicket(Ticket::lookup($ticket->getId()), false),
            ));
        });
    }

    /**
     * GET /api-plus/staff/statuses.json
     */
    public function staffStatuses()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $items = array();
        foreach (TicketStatusList::getStatuses() as $status) {
            $row = array(
                'id'    => (int) $status->getId(),
                'name'  => (string) $status->getName(),
                'state' => (string) $status->getState(),
            );
            if (method_exists($status, 'getMode')) {
                $row['mode'] = $status->getMode();
            }
            $items[] = $row;
        }

        return $this->json(200, array('statuses' => $items, 'count' => count($items)));
    }

    /**
     * GET /api-plus/staff/depts.json
     */
    public function staffDepts()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $items = array();
        foreach (Dept::getActiveDepartments() as $id => $name) {
            $items[] = array(
                'id'   => (int) $id,
                'name' => (string) $name,
            );
        }

        return $this->json(200, array('departments' => $items, 'count' => count($items)));
    }

    /**
     * GET /api-plus/staff/staff.json
     */
    public function staffDirectory()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $actor = $this->loadConfiguredStaff();

        $items = array();
        return $this->withStaff($actor, function () use (&$items, $actor) {
            $members = Staff::objects()->filter(array('isactive' => 1))->order_by('lastname', 'firstname');
            if (method_exists($actor, 'applyDeptVisibility')) {
                $members = $actor->applyDeptVisibility($members);
            }

            foreach ($members as $member) {
                $items[] = array(
                    'id'       => (int) $member->getId(),
                    'username' => (string) $member->getUserName(),
                    'name'     => (string) $member->getName(),
                    'dept_id'  => (int) $member->getDeptId(),
                    'active'   => (bool) $member->isActive(),
                );
            }

            return $this->json(200, array('staff' => $items, 'count' => count($items)));
        });
    }

    /**
     * GET /api-plus/staff/canned.json
     */
    public function staffCanned()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $actor = $this->loadConfiguredStaff();

        return $this->withStaff($actor, function () {
            $items = array();
            $rows = Canned::objects()
                ->filter(array('isenabled' => true))
                ->order_by('title');

            $staffDepts = array(0);
            global $thisstaff;
            if ($thisstaff && method_exists($thisstaff, 'getDepts')) {
                $staffDepts = array_merge($thisstaff->getDepts(), array(0));
            }
            $rows->filter(array('dept_id__in' => $staffDepts));

            foreach ($rows as $canned) {
                $items[] = array(
                    'id'      => (int) $canned->getId(),
                    'title'   => (string) $canned->getTitle(),
                    'dept_id' => (int) $canned->getDeptId(),
                    'response'=> method_exists($canned, 'getPlainText')
                        ? (string) $canned->getPlainText()
                        : (string) $canned->getResponse(),
                );
            }

            return $this->json(200, array('canned' => $items, 'count' => count($items)));
        });
    }

    /**
     * GET /api-plus/staff/priorities.json
     */
    public function staffPriorities()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $items = array();
        foreach (Priority::objects() as $priority) {
            $items[] = array(
                'id'      => (int) $priority->getId(),
                'name'    => (string) $priority->getDesc(),
                'tag'     => method_exists($priority, 'getTag') ? (string) $priority->getTag() : null,
                'urgency' => isset($priority->priority_urgency) ? (int) $priority->priority_urgency : null,
            );
        }

        return $this->json(200, array('priorities' => $items, 'count' => count($items)));
    }

    /**
     * GET /api-plus/staff/topics.json
     */
    public function staffTopics()
    {
        $this->requireGet();
        $this->requireApiKey();
        $this->requireStaffApiEnabled();

        $items = array();
        $topics = Topic::getHelpTopics(false, false, true, array(), true);
        if (is_array($topics)) {
            foreach ($topics as $id => $row) {
                if (is_array($row) && !empty($row['disabled'])) {
                    continue;
                }
                $topic = Topic::lookup((int) $id);
                $items[] = array(
                    'id'      => (int) $id,
                    'name'    => $topic ? (string) $topic->getFullName() : (string) (is_array($row) ? $row['topic'] : $row),
                    'parent_id'=> is_array($row) && isset($row['pid']) ? (int) $row['pid'] : null,
                    'dept_id' => is_array($row) && isset($row['dept_id']) ? (int) $row['dept_id'] : null,
                );
            }
        } else {
            foreach (Topic::getHelpTopics(false, false) as $id => $name) {
                $items[] = array(
                    'id'   => (int) $id,
                    'name' => (string) $name,
                );
            }
        }

        return $this->json(200, array('topics' => $items, 'count' => count($items)));
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

    private function withStaff(Staff $staff, $callback)
    {
        global $thisstaff;
        $previousStaff = isset($thisstaff) ? $thisstaff : null;
        $thisstaff = $staff;
        try {
            return $callback();
        } finally {
            $thisstaff = $previousStaff;
        }
    }

    private function requireStaffTicketById($id)
    {
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $ticket = Ticket::lookup((int) $id);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }
        return $ticket;
    }

    private function requireStaffTicketByNumber($number)
    {
        $this->requireApiKey();
        $this->requireStaffApiEnabled();
        $ticket = $this->lookupTicketByNumber($number);
        if (!$ticket) {
            return $this->json(404, array('error' => 'Ticket not found'));
        }
        return $ticket;
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
     * @return string|null error message
     */
    private function applyStateFilter($qs, $status)
    {
        if ($status === 'open') {
            $qs->filter(array('status__state' => 'open'));
            return null;
        }
        if ($status === 'closed') {
            $qs->filter(array('status__state' => 'closed'));
            return null;
        }
        if ($status === 'all') {
            return null;
        }
        return 'status must be open, closed, or all';
    }

    /**
     * @return array [serializedTickets, pageMeta]
     */
    private function paginateList($qs)
    {
        $conf = OsticketApiPlusPlugin::conf();
        $default = $conf ? (int) $conf->get('default_list_limit') : 25;
        if ($default < 1) {
            $default = 25;
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $default;
        $limit = max(1, min(100, $limit));
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        $total = (int) $qs->count();
        $qs->limit($limit);
        if ($offset) {
            $qs->offset($offset);
        }

        $tickets = array();
        foreach ($qs->all() as $ticket) {
            $tickets[] = $this->serializeTicket($ticket, false);
        }

        return array($tickets, array(
            'count'    => count($tickets),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => ($offset + $limit) < $total,
        ));
    }

    private function emptyListPayload($extra = array())
    {
        $conf = OsticketApiPlusPlugin::conf();
        $default = $conf ? (int) $conf->get('default_list_limit') : 25;
        if ($default < 1) {
            $default = 25;
        }
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $default;
        $limit = max(1, min(100, $limit));
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        return array_merge(array(
            'tickets'  => array(),
            'count'    => 0,
            'total'    => 0,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => false,
        ), $extra);
    }

    private function parseTimestamp($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
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

    private function resolvePriority($raw)
    {
        if (is_numeric($raw)) {
            return Priority::lookup((int) $raw);
        }

        $name = strtolower(trim((string) $raw));
        if ($name === '') {
            return null;
        }

        foreach (Priority::objects() as $priority) {
            if (strtolower($priority->getDesc()) === $name) {
                return $priority;
            }
            if (method_exists($priority, 'getTag') && strtolower($priority->getTag()) === $name) {
                return $priority;
            }
        }

        return null;
    }

    private function serializeTicket(Ticket $ticket, $withThread)
    {
        $status = $ticket->getStatus();
        $data = array(
            'id'          => (int) $ticket->getId(),
            'number'      => (string) $ticket->getNumber(),
            'subject'     => (string) $ticket->getSubject(),
            'status'      => $status ? (string) $status->getName() : null,
            'status_id'   => (int) $ticket->getStatusId(),
            'state'       => $status ? (string) $status->getState() : null,
            'email'       => (string) $ticket->getEmail(),
            'name'        => $ticket->getName() ? (string) $ticket->getName() : null,
            'created'     => (string) $ticket->getCreateDate(),
            'updated'     => (string) $ticket->getUpdateDate(),
            'dept_id'     => (int) $ticket->getDeptId(),
            'topic_id'    => method_exists($ticket, 'getTopicId') ? (int) $ticket->getTopicId() : null,
            'staff_id'    => method_exists($ticket, 'getStaffId') ? (int) $ticket->getStaffId() : null,
            'team_id'     => method_exists($ticket, 'getTeamId') ? (int) $ticket->getTeamId() : null,
            'priority_id' => method_exists($ticket, 'getPriorityId') ? (int) $ticket->getPriorityId() : null,
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
