<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Submodule: operations (OPERATIONS-01). Persists status-only audit events without applicant content. */
class OperationsAudit
{
    public static function record(string $event, string $resource, int $id, string $actor, array $details = []): void
    {
        DB::table('career_operations_audit')->insert([
            'event_type' => $event,
            'resource_type' => $resource,
            'resource_id' => $id,
            'actor' => $actor,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public static function system(string $event, string $resource, int $id, array $details = []): void
    {
        try {
            self::record($event, $resource, $id, 'system', $details);
        } catch (\Throwable $exception) {
            // Audit storage failure must not turn an acknowledged provider/callback request into a retry.
            Log::warning('Operations audit event could not be stored', ['event' => $event, 'resource_id' => $id]);
        }
    }
}
