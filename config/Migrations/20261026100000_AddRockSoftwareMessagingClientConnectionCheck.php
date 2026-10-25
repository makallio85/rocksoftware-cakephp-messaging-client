<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The latest "Test connection" on the settings, so the page can show the
 * connection's state at every visit: when it was checked, whether the
 * gateway answered and confirmed the token, the token's name, whether it is
 * a test token, whether the gateway's own sending switch was on, and why it
 * failed.
 */
class AddRockSoftwareMessagingClientConnectionCheck extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('rock_software_messaging_client_settings')
            ->addColumn('checked_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('is_connection_ok', 'boolean', ['null' => true, 'default' => null])
            ->addColumn('token_name', 'string', ['limit' => 150, 'null' => true, 'default' => null])
            ->addColumn('is_test_token', 'boolean', ['null' => true, 'default' => null])
            ->addColumn('is_gateway_sending_enabled', 'boolean', ['null' => true, 'default' => null])
            ->addColumn('check_error', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->update();
    }
}
