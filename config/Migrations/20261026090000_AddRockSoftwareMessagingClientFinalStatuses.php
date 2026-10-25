<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * What became of a message after the gateway accepted it, read back from the
 * gateway (`GET /messages/{uuid}`) by the worker:
 *
 * - status types `SENT`, `DELIVERED`, `TRAPPED`, `CANCELLED`, in the
 *   gateway's own words (a gateway `failed`, such as a bounce, is `FAILED`);
 * - `next_status_check_at` (null once there is nothing more to ask),
 *   `status_checked_at`, `delivered_at`, `final_status_at`;
 * - `resent_from_id`: the message a resend was made from.
 */
class AddRockSoftwareMessagingClientFinalStatuses extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('rock_software_messaging_client_outbound_message_status_types')->insert([
            ['value' => 'SENT', 'label' => 'Sent by the provider', 'sort_order' => 60],
            ['value' => 'DELIVERED', 'label' => 'Delivered', 'sort_order' => 70],
            ['value' => 'TRAPPED', 'label' => 'Trapped: a test, reached no one', 'sort_order' => 80],
            ['value' => 'CANCELLED', 'label' => 'Cancelled', 'sort_order' => 90],
        ])->save();

        $this->table('rock_software_messaging_client_outbound_messages')
            ->addColumn('next_status_check_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('status_checked_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('delivered_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('final_status_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('resent_from_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addIndex(['next_status_check_at'], ['name' => 'rsmc_outbound_messages_next_status_check'])
            ->addIndex(['resent_from_id'], ['name' => 'rsmc_outbound_messages_resent_from'])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('rock_software_messaging_client_outbound_messages')
            ->removeIndexByName('rsmc_outbound_messages_next_status_check')
            ->removeIndexByName('rsmc_outbound_messages_resent_from')
            ->removeColumn('next_status_check_at')
            ->removeColumn('status_checked_at')
            ->removeColumn('delivered_at')
            ->removeColumn('final_status_at')
            ->removeColumn('resent_from_id')
            ->update();
        $this->execute(
            "DELETE FROM rock_software_messaging_client_outbound_message_status_types "
            . "WHERE value IN ('SENT', 'DELIVERED', 'TRAPPED', 'CANCELLED')",
        );
    }
}
