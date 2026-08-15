<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseVisitRecorder implements VisitRecorder
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(TrackingRequest $request): void
    {
        $visitor = hex2bin($request->visitorId);
        $ip = inet_pton($request->ipAddress);
        if ($visitor === false || $ip === false) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($request, $visitor, $ip, $now): void {
            $visitId = $this->connection->table('log_visit')->where('idsite', $request->siteId)->where('idvisitor', $visitor)->where('visit_last_action_time', '>=', CarbonImmutable::parse($now)->subMinutes(30)->format('Y-m-d H:i:s'))->value('idvisit');
            if (! is_numeric($visitId)) {
                $visitId = $this->connection->table('log_visit')->insertGetId([
                    'idsite' => $request->siteId, 'idvisitor' => $visitor, 'visit_last_action_time' => $now,
                    'config_id' => substr(hash('sha256', $request->siteId.$request->ipAddress.$request->userAgent, true), 0, 8), 'location_ip' => $ip,
                ], 'idvisit');
            } else {
                $this->connection->table('log_visit')->where('idvisit', (int) $visitId)->update(['visit_last_action_time' => $now]);
            }

            $url = $this->action($request->url, 1);
            $name = $request->actionName === '' ? null : $this->action($request->actionName, 4);
            $this->connection->table('log_link_visit_action')->insert([
                'idsite' => $request->siteId, 'idvisitor' => $visitor, 'idvisit' => (int) $visitId,
                'idaction_url' => $url, 'idaction_name' => $name, 'server_time' => $now, 'idaction_url_ref' => 0,
            ]);
        });
    }

    private function action(string $name, int $type): int
    {
        $hash = (int) sprintf('%u', crc32($name));
        $id = $this->connection->table('log_action')->where(['type' => $type, 'hash' => $hash, 'name' => $name])->value('idaction');

        return is_numeric($id) ? (int) $id : (int) $this->connection->table('log_action')->insertGetId(['name' => $name, 'hash' => $hash, 'type' => $type, 'url_prefix' => 0], 'idaction');
    }
}
