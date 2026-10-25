<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use RockSoftware\MessagingClient\Service\Health;

/**
 * `GET /messaging-client/health`, for a monitor such as Uptime Kuma: JSON
 * `{status: ok|degraded, problems: [...], time}`, no sign-in and no personal
 * data. Always HTTP 200, so a liveness probe keeps working; with `?strict=1`
 * a degraded answer is HTTP 503, for a monitor that should alert on it.
 *
 * It extends CakePHP's controller, not the application's, so the
 * application's own checks never run here; an application that requires
 * sign-in for every path lists it as a public path.
 */
class HealthController extends Controller
{
    /**
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        $this->getRequest()->allowMethod(['get', 'head']);
        $health = (new Health())->check();
        $isStrict = in_array((string)$this->getRequest()->getQuery('strict'), ['1', 'true'], true);
        $status = $isStrict && $health['status'] !== Health::STATUS_OK ? 503 : 200;

        return $this->getResponse()
            ->withStatus($status)
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($health, JSON_UNESCAPED_SLASHES));
    }
}
