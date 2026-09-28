<?php
/**
 * Plugin configuration for osTicket API Plus.
 */

require_once INCLUDE_DIR . 'class.plugin.php';

class OsticketApiPlusConfig extends PluginConfig
{
    public function getOptions()
    {
        return array(
            'enable_user_api' => new BooleanField(array(
                'id'      => 'enable_user_api',
                'label'   => 'Enable user-scoped API',
                'default' => true,
                'hint'    => 'List / view / reply for tickets owned by a given email address.',
            )),
            'enable_staff_api' => new BooleanField(array(
                'id'      => 'enable_staff_api',
                'label'   => 'Enable staff API',
                'default' => false,
                'hint'    => 'List any tickets, staff replies, status changes, and internal notes. Leave disabled until configured.',
            )),
            'staff_username' => new TextboxField(array(
                'id'       => 'staff_username',
                'label'    => 'Staff username for staff replies',
                'required' => false,
                'hint'     => 'Existing agent username used as author for staff reply / note / status actions.',
                'configuration' => array('length' => 64, 'size' => 40),
            )),
            'default_list_limit' => new TextboxField(array(
                'id'      => 'default_list_limit',
                'label'   => 'Default list page size',
                'default' => '25',
                'hint'    => 'Tickets returned per list request (capped at 100).',
                'configuration' => array('validator' => 'number', 'size' => 6),
            )),
        );
    }

    public function pre_save(&$config, &$errors)
    {
        global $msg;

        if (!empty($config['staff_username'])) {
            $staff = Staff::lookup(array('username' => $config['staff_username']));
            if (!$staff) {
                $errors['err'] = 'Staff username not found. Create the agent first, or clear the field.';
                return false;
            }
        }

        if (!empty($config['enable_staff_api']) && empty($config['staff_username'])) {
            $errors['err'] = 'Staff API requires a valid staff username.';
            return false;
        }

        if (!$errors) {
            $msg = 'Configuration updated successfully';
        }

        return true;
    }
}
