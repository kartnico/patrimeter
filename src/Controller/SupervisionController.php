<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Supervision\HealthReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Monitoring (docs/supervision.md), without session nor authentication: nginx only accepts the private networks.
 * /sante for an availability probe (JSON "health check" format, 503 if the database does not answer);
 * /sante/metriques in Prometheus's text format, which the OpenTelemetry collector also reads.
 */
final class SupervisionController extends AbstractController
{
    public function __construct(private readonly HealthReport $health) {}

    #[Route('/sante', name: 'app_health', methods: ['GET'], stateless: true)]
    public function health(): JsonResponse
    {
        $report = $this->health->measure();
        $age = static fn(?int $last): ?int => $last === null ? null : $report['now'] - $last;
        $body = [
            'status' => $report['status'],
            'checks' => [
                'database:responseTime' => [['status' => $report['checks']['database'], 'observedValue' => round($report['database_seconds'] * 1000.0, 2), 'observedUnit' => 'ms']],
                'backup:age' => [['status' => $report['checks']['backup'], 'observedValue' => $age($report['last_backup']), 'observedUnit' => 's']],
                'scheduler:age' => [['status' => $report['checks']['scheduler'], 'observedValue' => $age($report['last_maintenance']), 'observedUnit' => 's']],
                'disk:free' => [['status' => $report['checks']['disk'], 'observedValue' => $report['disk_free_bytes'], 'observedUnit' => 'bytes']],
                'update:available' => [['status' => $report['checks']['update'], 'observedValue' => $report['update_available']]],
            ],
        ];

        return new JsonResponse(
            $body,
            $report['status'] === HealthReport::FAIL ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            ['Content-Type' => 'application/health+json'],
        );
    }

    #[Route('/sante/metriques', name: 'app_health_metrics', methods: ['GET'], stateless: true)]
    public function metrics(): Response
    {
        $report = $this->health->measure();
        $metrics = [
            ['patrimeter_up', 'État global : 1 si aucune vérification n\'échoue.', $report['status'] === HealthReport::FAIL ? 0 : 1],
            ['patrimeter_database_up', '1 si la base SQLite répond.', $report['database_up'] ? 1 : 0],
            ['patrimeter_database_response_seconds', 'Durée d\'une requête élémentaire sur la base.', $report['database_seconds']],
            ['patrimeter_last_backup_timestamp_seconds', 'Date Unix de la dernière sauvegarde réussie (0 : aucune).', $report['last_backup'] ?? 0],
            ['patrimeter_last_maintenance_timestamp_seconds', 'Date Unix du dernier passage des tâches planifiées (0 : aucun).', $report['last_maintenance'] ?? 0],
        ];
        $metrics[] = ['patrimeter_update_available', '1 si une version plus récente est publiée (vérification quotidienne activée).', $report['update_available'] === null ? 0 : 1];
        if ($report['disk_free_bytes'] !== null) {
            $metrics[] = ['patrimeter_disk_free_bytes', 'Espace libre du disque de var/.', $report['disk_free_bytes']];
        }
        $lines = [];
        foreach ($metrics as [$name, $help, $value]) {
            $lines[] = "# HELP $name $help";
            $lines[] = "# TYPE $name gauge";
            $lines[] = $name . ' ' . (is_float($value) ? sprintf('%.6F', $value) : (string) $value);
        }

        return new Response(implode("\n", $lines) . "\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
