<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use RockSoftware\MessagingClient\Messenger;
use RockSoftware\MessagingClient\Service\DeliveryGate;
use RockSoftware\MessagingClient\Service\StatusRefresher;

/**
 * Tries again every outbound message still waiting whose next attempt is
 * due, then asks the gateway what became of the messages it accepted
 * (`StatusRefresher`). Waiting messages are tried under the same
 * `external_ref`, so the gateway never sends one twice. The application runs
 * it from its worker loop, every minute or so; a run with nothing due costs a
 * few queries.
 *
 * A waiting message whose channel's sending is off here ends
 * `NOT_SENT_DELIVERY_OFF` and the gateway is never called: a production
 * database restored onto another host reads as off (`DeliveryGate`) and
 * does not send its backlog.
 *
 * A channel switched on at an address other than this process's
 * `App.fullBaseUrl` gets one warning line per run: usually a worker
 * container started without the web container's full base URL.
 */
class DeliverCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'messaging_client deliver';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Send the outbound messages waiting for another attempt.')
            ->addOption('limit', [
                'help' => 'How many messages one run sends at most (default: deliverBatchSize).',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        foreach (DeliveryGate::switches() as $switch) {
            if (!$switch->isSwitchedOnElsewhere()) {
                continue;
            }
            $warning = sprintf(
                'Messaging: %s sending is switched on for %s, but App.fullBaseUrl here is %s, so it reads as off '
                . 'and nothing is sent. Give the worker the same full base URL as the web container.',
                $switch->channel,
                (string)$switch->enabledForUrl,
                $switch->currentUrl ?? '(not set)',
            );
            Log::warning($warning);
            $io->warning($warning);
        }

        $limit = $args->getOption('limit');
        $counts = (new Messenger())->deliverDue(is_string($limit) && $limit !== '' ? max(1, (int)$limit) : null);

        $changed = (new StatusRefresher())->refreshDue();
        if ($changed > 0) {
            $io->out(sprintf('Updated the status of %d outbound message(s) from the gateway.', $changed));
        }

        $total = array_sum($counts);
        if ($total === 0) {
            $io->verbose('No outbound messages were due.');

            return static::CODE_SUCCESS;
        }
        $parts = [];
        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%s %d', $status, $count);
            }
        }
        $io->out(sprintf('Tried %d outbound message(s): %s.', $total, implode(', ', $parts)));

        return static::CODE_SUCCESS;
    }
}
